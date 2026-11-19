import Foundation
import Observation

/// Passo 1: a foto escolhida sobe para a leitura; a tela segue para a revisão quando o servidor aceita.
/// A imagem chega já reduzida e em JPEG (a preparação depende do UIKit e fica no app).
@MainActor
@Observable
public final class NovaReceitaViewModel {
    public private(set) var enviando = false
    public var erro: String?
    public private(set) var semConsentimento = false
    @ObservationIgnored private let api: ClienteDaApi

    public init(api: ClienteDaApi) {
        self.api = api
    }

    /// Devolve o id da receita criada (a tela abre a revisão).
    public func enviar(jpeg: Data?) async -> Int64? {
        guard !enviando else {
            return nil
        }
        guard let jpeg, !jpeg.isEmpty else {
            erro = "Não conseguimos abrir esta imagem. Tente outra foto."
            return nil
        }
        enviando = true
        erro = nil
        semConsentimento = false
        defer { enviando = false }
        switch await tentar({ try await api.enviarReceita(jpeg: jpeg) }) {
        case let .success(receita):
            return receita.id
        case let .failure(falha):
            semConsentimento = falha.codigo == FalhaDaApi.faltaConsentimento
            erro = falha.mensagem
            return nil
        }
    }
}

/// Passo 2: acompanha a leitura (a cada 2 s) e, pronta, a pessoa confere e corrige cada item antes de salvar. Mudar
/// "quando tomar" refaz os horários sugeridos com o mesmo intérprete do site. Nada entra no tratamento sem "Confirmar".
@MainActor
@Observable
public final class RevisarReceitaViewModel {
    public private(set) var receita: Receita?
    public private(set) var erroDeCarga: String?
    public var cabecalho = CabecalhoDaReceita() {
        didSet { erroDoPrescritor = nil }
    }

    public private(set) var itens: [ItemDaReceita] = []
    public var erroDoPrescritor: String?
    public var aviso: String?
    public private(set) var salvando = false
    public let busca: BuscaNoCatalogo
    /// Leitura parada demais vira `PRESCRIPTION_TIMEOUT` no servidor; aqui é só o intervalo das consultas.
    @ObservationIgnored public var intervaloDeConsulta: UInt64 = 2000
    @ObservationIgnored private let dependencias: Dependencias
    @ObservationIgnored public let id: Int64
    @ObservationIgnored private var horarios: [Int: Task<Void, Never>] = [:]

    public init(_ dependencias: Dependencias, id: Int64) {
        self.dependencias = dependencias
        self.id = id
        busca = BuscaNoCatalogo(api: dependencias.api)
    }

    public var lendo: Bool { receita == nil || receita?.reading == true }

    public func acompanhar() async {
        erroDeCarga = nil
        while !Task.isCancelled {
            switch await tentar({ try await dependencias.api.receita(id) }) {
            case let .failure(falha):
                erroDeCarga = falha.mensagem
                return
            case let .success(nova):
                let primeiraVez = receita?.status != Receita.emRevisao && nova.status == Receita.emRevisao
                receita = nova
                if primeiraVez {
                    cabecalho = nova.header ?? CabecalhoDaReceita()
                    itens = nova.items
                }
                if !nova.reading {
                    return
                }
            }
            try? await Task.sleep(nanoseconds: intervaloDeConsulta * 1_000_000)
        }
    }

    public func mudarItem(_ indice: Int, _ mudanca: (inout ItemDaReceita) -> Void) {
        guard itens.indices.contains(indice) else {
            return
        }
        mudanca(&itens[indice])
        aviso = nil
    }

    /// Novo "quando tomar": os horários sugeridos são refeitos depois que a pessoa para de digitar.
    public func mudarQuandoTomar(_ indice: Int, _ texto: String) {
        mudarItem(indice) { $0.frequency = String(texto.prefix(120)) }
        horarios[indice]?.cancel()
        horarios[indice] = Task {
            guard await esperarDigitacao(600) else {
                return
            }
            if case let .success(sugeridos) = await tentar({ try await dependencias.api.horarios(quandoTomar: texto) }), !Task.isCancelled {
                mudarItem(indice) { $0 = $0.comHorarios(sugeridos) }
            }
        }
    }

    public func esperarHorarios(_ indice: Int) async {
        await horarios[indice]?.value
    }

    public func escolher(_ indice: Int, remedio: Int64, nome: String) {
        mudarItem(indice) {
            $0.remedioId = remedio
            $0.remedioNome = nome
            $0.skip = false
        }
        busca.fechar()
    }

    public func manterPeloNome(_ indice: Int) {
        mudarItem(indice) {
            $0.remedioId = nil
            $0.remedioNome = nil
            $0.skip = false
        }
        busca.fechar()
    }

    public func pular(_ indice: Int) {
        mudarItem(indice) { $0.skip.toggle() }
    }

    public func adicionarHorario(_ indice: Int, _ hora: String) {
        mudarItem(indice) { item in
            guard item.times.count < 12 else {
                return
            }
            item.times = Array(Set(item.times + [hora])).sorted()
            item.seNecessario = false
        }
    }

    public func trocarHorario(_ indice: Int, posicao: Int, _ hora: String) {
        mudarItem(indice) { item in
            guard item.times.indices.contains(posicao) else {
                return
            }
            item.times[posicao] = hora
            item.times = Array(Set(item.times)).sorted()
        }
    }

    public func tirarHorario(_ indice: Int, posicao: Int) {
        mudarItem(indice) { item in
            if item.times.indices.contains(posicao) {
                item.times.remove(at: posicao)
            }
        }
    }

    /// Mensagem do servidor ao salvar (a tela abre Hoje com ela), ou `nil` se não deu.
    public func confirmar() async -> String? {
        guard !salvando else {
            return nil
        }
        let prescritor = cabecalho.prescriber.trimmingCharacters(in: .whitespaces)
        guard prescritor.count >= 3 else {
            erroDoPrescritor = prescritor.isEmpty ? "Informe quem prescreveu a receita." : "O nome de quem prescreveu precisa ter pelo menos 3 letras."
            return nil
        }
        salvando = true
        aviso = nil
        defer { salvando = false }
        let pedido = PedidoDeConfirmacao(header: cabecalho, items: itens)
        switch await tentar({ try await dependencias.api.confirmarReceita(id, pedido) }) {
        case let .success(resultado):
            dependencias.pedirSincronizacao()
            return resultado.message
        case let .failure(falha):
            if let campo = falha.campos["prescriber"] {
                erroDoPrescritor = campo
            } else {
                aviso = falha.mensagem
            }
            return nil
        }
    }

    public func descartar() async -> String? {
        switch await tentar({ try await dependencias.api.descartarReceita(id) }) {
        case .success:
            return "Receita descartada. Nada foi salvo."
        case let .failure(falha):
            aviso = falha.mensagem
            return nil
        }
    }
}
