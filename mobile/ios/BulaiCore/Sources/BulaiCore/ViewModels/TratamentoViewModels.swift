import Foundation
import Observation

/// Início: saudação, cartão do dia com o próximo remédio e quem a pessoa acompanha.
@MainActor
@Observable
public final class InicioViewModel {
    public private(set) var carga = Carga<Inicio>()
    public private(set) var marcando = false
    public var recado: Recado?
    @ObservationIgnored private let dependencias: Dependencias
    @ObservationIgnored private var tarefa: Task<Void, Never>?

    public init(_ dependencias: Dependencias) {
        self.dependencias = dependencias
    }

    public func carregar() async {
        if carga.dados == nil, let guardado = await dependencias.cache.ler("inicio", como: Inicio.self) {
            carga.dados = guardado
        }
        await atualizar()
    }

    /// Busca de novo (ao voltar para a tela, puxar para baixo); os dados atuais continuam na tela.
    public func atualizar() async {
        if let tarefa {
            return await tarefa.value
        }
        let nova = Task {
            carga.atualizando = true
            switch await tentar({ try await dependencias.api.inicio() }) {
            case let .success(inicio):
                carga = Carga(dados: inicio)
                await dependencias.cache.gravar("inicio", inicio)
            case let .failure(falha):
                carga.atualizando = false
                carga.erro = falha.mensagem
            }
        }
        tarefa = nova
        await nova.value
        tarefa = nil
    }

    /// "Tomei" no cartão do dia: a tela mostra na hora e confirma com o servidor; se falhar, volta e avisa.
    public func tomei(_ dose: Dose) async {
        guard !marcando else {
            return
        }
        marcando = true
        let antes = carga.dados
        if var dados = carga.dados {
            dados.proxima = nil
            dados.progress.taken += 1
            dados.progress.done += 1
            carga.dados = dados
        }
        let pedido = PedidoDeMarcacao(remedioId: dose.remedioId, agendadaPara: dose.agendadaPara, status: Dose.tomada, dia: 0)
        switch await tentar({ try await dependencias.api.marcar(pedido) }) {
        case let .success(dia):
            await dependencias.cache.gravar("dia0", dia)
            dependencias.pedirSincronizacao()
            recado = Recado("Anotado: você tomou \(dose.name).")
            marcando = false
            await atualizar()
        case let .failure(falha):
            carga.dados = antes
            recado = Recado(falha.mensagem)
            marcando = false
        }
    }
}

/// De onde vem o dia mostrado na tela Hoje: o da própria pessoa ou o de quem ela cuida.
@MainActor
public protocol FonteDoDia: AnyObject {
    /// Primeiro nome de quem toma os remédios, quando não é a própria pessoa (muda as frases da tela).
    var deQuem: String? { get }
    func guardado(_ deslocamento: Int) async -> Dia?
    func dia(_ deslocamento: Int) async throws -> Dia
    func marcar(_ dose: Dose, situacao: String, deslocamento: Int) async throws -> Dia
    func desmarcar(_ dose: Dose, deslocamento: Int) async throws -> Dia
}

/// O tratamento da própria pessoa: cada marcação pede uma sincronização (uma dose marcada não lembra mais).
@MainActor
public final class MeuDia: FonteDoDia {
    private let dependencias: Dependencias

    public init(_ dependencias: Dependencias) {
        self.dependencias = dependencias
    }

    public var deQuem: String? { nil }

    public func guardado(_ deslocamento: Int) async -> Dia? {
        await dependencias.cache.ler("dia\(deslocamento)", como: Dia.self)
    }

    public func dia(_ deslocamento: Int) async throws -> Dia {
        let dia = try await dependencias.api.dia(deslocamento)
        await dependencias.cache.gravar("dia\(dia.offset)", dia)
        return dia
    }

    public func marcar(_ dose: Dose, situacao: String, deslocamento: Int) async throws -> Dia {
        try await mudou(dependencias.api.marcar(PedidoDeMarcacao(remedioId: dose.remedioId, agendadaPara: dose.agendadaPara, status: situacao, dia: deslocamento)))
    }

    public func desmarcar(_ dose: Dose, deslocamento: Int) async throws -> Dia {
        try await mudou(dependencias.api.desmarcar(PedidoDeMarcacao(remedioId: dose.remedioId, agendadaPara: dose.agendadaPara, status: nil, dia: deslocamento)))
    }

    private func mudou(_ dia: Dia) async -> Dia {
        await dependencias.cache.gravar("dia\(dia.offset)", dia)
        dependencias.pedirSincronizacao()
        return dia
    }
}

/// O dia de uma pessoa acompanhada (modo cuidador), com cache próprio. As permissões são do servidor (403 sem elas).
@MainActor
public final class DiaDeQuemCuido: FonteDoDia {
    private let dependencias: Dependencias
    public let vinculo: Int64
    public let deQuem: String?

    public init(_ dependencias: Dependencias, vinculo: Int64, primeiroNome: String?) {
        self.dependencias = dependencias
        self.vinculo = vinculo
        deQuem = primeiroNome
    }

    public func guardado(_ deslocamento: Int) async -> Dia? {
        await dependencias.cache.ler(chave(deslocamento), como: Dia.self)
    }

    public func dia(_ deslocamento: Int) async throws -> Dia {
        try await guardar(dependencias.api.diaDeQuemCuido(vinculo, deslocamento: deslocamento))
    }

    public func marcar(_ dose: Dose, situacao: String, deslocamento: Int) async throws -> Dia {
        try await guardar(dependencias.api.marcarParaQuemCuido(vinculo, PedidoDeMarcacao(remedioId: dose.remedioId, agendadaPara: dose.agendadaPara, status: situacao, dia: deslocamento)))
    }

    public func desmarcar(_ dose: Dose, deslocamento: Int) async throws -> Dia {
        try await guardar(dependencias.api.desmarcarParaQuemCuido(vinculo, PedidoDeMarcacao(remedioId: dose.remedioId, agendadaPara: dose.agendadaPara, status: nil, dia: deslocamento)))
    }

    private func guardar(_ dia: Dia) async -> Dia {
        await dependencias.cache.gravar(chave(dia.offset), dia)
        return dia
    }

    private func chave(_ deslocamento: Int) -> String {
        "cuidando-\(vinculo)-dia\(deslocamento)"
    }
}

/// Ontem (-1), hoje (0) e amanhã (1), cada um com a sua carga; marcações aparecem na hora e se confirmam depois.
@MainActor
@Observable
public final class DiaViewModel {
    public static let deslocamentos = [-1, 0, 1]

    public private(set) var dias: [Int: Carga<Dia>] = [-1: Carga(), 0: Carga(), 1: Carga()]
    /// Doses com marcação a caminho do servidor (os botões dela ficam ocupados).
    public private(set) var enviando: Set<String> = []
    /// Dose que acabou de ser marcada como tomada (a tela faz o efeito do "Tomei").
    public private(set) var acabouDeTomar: String?
    public var recado: Recado?
    @ObservationIgnored public let fonte: FonteDoDia
    @ObservationIgnored private var cargas: [Int: Task<Void, Never>] = [:]

    public init(fonte: FonteDoDia) {
        self.fonte = fonte
    }

    public func carga(_ deslocamento: Int) -> Carga<Dia> {
        dias[deslocamento] ?? Carga()
    }

    public func carregar() async {
        for deslocamento in DiaViewModel.deslocamentos where dias[deslocamento]?.dados == nil {
            if let guardado = await fonte.guardado(deslocamento) {
                dias[deslocamento]?.dados = guardado
            }
        }
        await atualizar(0)
    }

    public func atualizar(_ deslocamento: Int) async {
        if let atual = cargas[deslocamento] {
            return await atual.value
        }
        let tarefa = Task {
            dias[deslocamento]?.atualizando = true
            switch await tentar({ try await fonte.dia(deslocamento) }) {
            case let .success(dia):
                dias[deslocamento] = Carga(dados: dia)
            case let .failure(falha):
                dias[deslocamento]?.atualizando = false
                dias[deslocamento]?.erro = falha.mensagem
            }
        }
        cargas[deslocamento] = tarefa
        await tarefa.value
        cargas[deslocamento] = nil
    }

    public func tomei(_ dose: Dose, deslocamento: Int) async {
        await marcar(dose, situacao: Dose.tomada, deslocamento: deslocamento)
    }

    public func pular(_ dose: Dose, deslocamento: Int) async {
        await marcar(dose, situacao: Dose.pulada, deslocamento: deslocamento)
    }

    public func desfazer(_ dose: Dose, deslocamento: Int) async {
        await enviar(dose, deslocamento: deslocamento, situacaoNaTela: Dose.pendente, chamada: { try await self.fonte.desmarcar(dose, deslocamento: deslocamento) }) {
            Recado("Marcação de \(dose.name) desfeita.")
        }
    }

    private func marcar(_ dose: Dose, situacao: String, deslocamento: Int) async {
        await enviar(dose, deslocamento: deslocamento, situacaoNaTela: situacao, chamada: { try await self.fonte.marcar(dose, situacao: situacao, deslocamento: deslocamento) }) { [fonte] in
            let quem = fonte.deQuem.map { "\($0) tomou" } ?? "você tomou"
            let texto = situacao == Dose.tomada ? "Anotado: \(quem) \(dose.name)." : "\(dose.name) pulado neste horário."
            var marcada = dose
            marcada.status = situacao
            return Recado(texto) { [weak self] in
                Task { await self?.desfazer(marcada, deslocamento: deslocamento) }
            }
        }
    }

    private func enviar(_ dose: Dose, deslocamento: Int, situacaoNaTela: String, chamada: () async throws -> Dia, recado montarRecado: () -> Recado) async {
        guard !enviando.contains(dose.key) else {
            return
        }
        let antes = dias[deslocamento]?.dados
        enviando.insert(dose.key)
        dias[deslocamento]?.dados = antes?.comSituacao(chave: dose.key, situacao: situacaoNaTela)
        acabouDeTomar = situacaoNaTela == Dose.tomada ? dose.key : nil
        cargas[deslocamento]?.cancel()
        switch await tentar(chamada) {
        case let .success(dia):
            dias[deslocamento] = Carga(dados: dia)
            recado = montarRecado()
        case let .failure(falha):
            dias[deslocamento]?.dados = antes
            if acabouDeTomar == dose.key {
                acabouDeTomar = nil
            }
            recado = Recado(falha.mensagem.isEmpty ? "Não deu para anotar agora. Tente de novo." : falha.mensagem)
        }
        enviando.remove(dose.key)
    }

    /// Doses do dia agrupadas por período (manhã, tarde, noite), na ordem do servidor.
    public static func porPeriodo(_ doses: [Dose]) -> [(periodo: String, doses: [Dose])] {
        var ordem: [String] = []
        var grupos: [String: [Dose]] = [:]
        for dose in doses {
            if grupos[dose.period] == nil {
                ordem.append(dose.period)
            }
            grupos[dose.period, default: []].append(dose)
        }
        return ordem.map { ($0, grupos[$0] ?? []) }
    }
}

/// Central de avisos (sininho): abrir, apagar, marcar como lidos.
@MainActor
@Observable
public final class AvisosViewModel {
    public private(set) var carga = Carga<CentralDeAvisos>()
    public var recado: Recado?
    /// Para onde ir depois de abrir um aviso.
    public var destino: Rota?
    @ObservationIgnored private let dependencias: Dependencias

    public init(_ dependencias: Dependencias) {
        self.dependencias = dependencias
    }

    public func carregar() async {
        if carga.dados == nil, let guardada = await dependencias.cache.ler("avisos", como: CentralDeAvisos.self) {
            carga.dados = guardada
        }
        await atualizar()
    }

    public func atualizar() async {
        carga.atualizando = true
        switch await tentar({ try await dependencias.api.avisos() }) {
        case let .success(central):
            carga = Carga(dados: central)
            await dependencias.cache.gravar("avisos", central)
        case let .failure(falha):
            carga.atualizando = false
            carga.erro = falha.mensagem
        }
    }

    public func abrir(_ aviso: Aviso) async {
        if var dados = carga.dados {
            if !aviso.read {
                dados.unread = max(dados.unread - 1, 0)
            }
            dados.notices = dados.notices.map { item in
                var lido = item
                if item.id == aviso.id {
                    lido.read = true
                }
                return lido
            }
            carga.dados = dados
        }
        destino = Rota.doDestino(aviso.destination)
        _ = await tentar { try await dependencias.api.abrirAviso(aviso.id) }
    }

    public func apagar(_ aviso: Aviso) async {
        let antes = carga.dados
        if var dados = carga.dados {
            dados.notices.removeAll { $0.id == aviso.id }
            if !aviso.read {
                dados.unread = max(dados.unread - 1, 0)
            }
            carga.dados = dados
        }
        await executar({ try await self.dependencias.api.apagarAviso(aviso.id) }, "Aviso apagado.") { self.carga.dados = antes }
    }

    public func marcarTodosLidos() async {
        await executar({ try await self.dependencias.api.marcarAvisosLidos() }, "Todos os avisos foram marcados como lidos.")
    }

    public func apagarTodos() async {
        await executar({ try await self.dependencias.api.apagarAvisos() }, "Todos os avisos foram apagados.")
    }

    private func executar(_ chamada: () async throws -> CentralDeAvisos, _ sucesso: String, aoFalhar: () -> Void = {}) async {
        switch await tentar(chamada) {
        case let .success(central):
            carga = Carga(dados: central)
            await dependencias.cache.gravar("avisos", central)
            recado = Recado(sucesso)
        case let .failure(falha):
            aoFalhar()
            recado = Recado(falha.mensagem)
        }
    }
}
