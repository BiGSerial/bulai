import Foundation
import Observation

/// Uma troca da conversa: a pergunta aparece na hora; a resposta chega depois (ou a falha, com "Tentar de novo").
public struct Troca: Identifiable, Sendable, Hashable {
    public var id: Int
    public var pergunta: String
    public var resposta: Resposta?
    public var erro: String?

    public var esperando: Bool { resposta == nil && erro == nil }
}

/// Conversa do Perguntar, como no site: a pergunta seguinte sem nome de remédio ("e com café?") usa as anteriores como
/// contexto. O aparelho manda só os `uuid` das últimas trocas; o servidor relê o histórico da própria pessoa.
@MainActor
@Observable
public final class PerguntarViewModel {
    /// Como no site: as trocas mais antigas saem da conversa.
    public static let maximoDeTrocas = 6
    public static let avisoDePerguntaCurta = "Escreva sua pergunta citando o nome do remédio."

    public private(set) var inicio: InicioDaConversa?
    public private(set) var trocas: [Troca] = []
    public var texto = "" {
        didSet {
            if texto.count > maximo {
                texto = String(texto.prefix(maximo))
            }
        }
    }

    /// Aviso acima da caixa ("Escreva sua pergunta citando o nome do remédio.").
    public var aviso: String?
    /// Remédios escolhidos à mão (correção ou atalho de outro lugar) que valem para a próxima resposta.
    public private(set) var fixos: [Int64]
    public private(set) var removidos: [Int64] = []
    public let busca: BuscaNoCatalogo
    @ObservationIgnored private let dependencias: Dependencias
    @ObservationIgnored private let remedioInicial: Int64?
    @ObservationIgnored private var proximoId = 0
    @ObservationIgnored private var envio: Task<Void, Never>?

    public init(_ dependencias: Dependencias, remedio: Int64? = nil) {
        self.dependencias = dependencias
        remedioInicial = remedio
        fixos = remedio.map { [$0] } ?? []
        busca = BuscaNoCatalogo(api: dependencias.api)
    }

    public var enviando: Bool { trocas.last?.esperando == true }
    public var minimo: Int { inicio?.minimo ?? 5 }
    public var maximo: Int { inicio?.maximo ?? 500 }

    public func carregar() async {
        if remedioInicial == nil, inicio == nil, let guardado = await dependencias.cache.ler("perguntar", como: InicioDaConversa.self) {
            inicio = guardado
        }
        guard case let .success(novo) = await tentar({ try await dependencias.api.inicioDaConversa(remedio: remedioInicial) }) else {
            return
        }
        if remedioInicial == nil {
            await dependencias.cache.gravar("perguntar", novo)
        }
        if texto.isEmpty, let previa = novo.preview {
            texto = "Sobre \(previa.medicine): "
        }
        inicio = novo
    }

    /// Toque numa sugestão: completa a caixa (a pessoa revisa e envia).
    public func sugerir(_ sugestao: String) {
        texto = (texto.trimmingCharacters(in: .whitespaces) + " " + sugestao).trimmingCharacters(in: .whitespaces)
        aviso = nil
    }

    /// Resposta rápida ("Não estou me sentindo bem"): vira a próxima mensagem e já é enviada.
    public func responder(_ resposta: String) {
        texto = resposta
        aviso = nil
        enviar()
    }

    /// Envia a pergunta da caixa; `false` quando não saiu (curta demais ou já esperando outra resposta).
    @discardableResult
    public func enviar() -> Bool {
        let pergunta = texto.replacingOccurrences(of: "\n", with: " ").trimmingCharacters(in: .whitespacesAndNewlines)
        guard !enviando else {
            return false
        }
        guard pergunta.count >= minimo else {
            aviso = PerguntarViewModel.avisoDePerguntaCurta
            return false
        }
        let troca = Troca(id: novoId(), pergunta: pergunta)
        trocas.append(troca)
        texto = ""
        aviso = nil
        removidos = []
        responder(troca)
        return true
    }

    /// A pergunta que falhou (sem conexão, limite) volta a ser enviada.
    public func tentarDeNovo() {
        guard let ultima = trocas.last, ultima.erro != nil else {
            return
        }
        let troca = Troca(id: novoId(), pergunta: ultima.pergunta)
        trocas[trocas.count - 1] = troca
        responder(troca)
    }

    /// "Não é esse?": troca o remédio entendido pelo escolhido e refaz a mesma pergunta.
    public func corrigir(_ remedio: Int64) {
        fixos = [remedio]
        removidos = []
        busca.fechar()
        refazer()
    }

    /// "Não era esse remédio": tira o remédio entendido por engano e refaz a mesma pergunta sem ele.
    public func remover(_ remedio: Int64) {
        if !removidos.contains(remedio) {
            removidos.append(remedio)
        }
        fixos.removeAll { $0 == remedio }
        refazer()
    }

    public func novaConversa() {
        envio?.cancel()
        trocas = []
        texto = ""
        aviso = nil
        fixos = []
        removidos = []
    }

    /// Espera a resposta em andamento (testes e o "voo" da pergunta).
    public func aguardar() async {
        await envio?.value
    }

    private func refazer() {
        guard let ultima = trocas.last, !ultima.esperando else {
            return
        }
        let troca = Troca(id: novoId(), pergunta: ultima.pergunta)
        trocas[trocas.count - 1] = troca
        responder(troca)
    }

    private func responder(_ troca: Troca) {
        let anteriores = Array(trocas.filter { $0.id != troca.id }.compactMap { $0.resposta?.uuid }.suffix(PerguntarViewModel.maximoDeTrocas))
        let pedido = PedidoDePergunta(question: troca.pergunta, fixed: fixos, removed: removidos, turns: anteriores)
        envio = Task {
            let resultado = await tentar { try await dependencias.api.perguntar(pedido) }
            guard !Task.isCancelled, let indice = trocas.firstIndex(where: { $0.id == troca.id }) else {
                return
            }
            switch resultado {
            case let .success(resposta):
                trocas[indice].resposta = resposta
                if trocas.count > PerguntarViewModel.maximoDeTrocas + 1 {
                    trocas.removeFirst(trocas.count - PerguntarViewModel.maximoDeTrocas - 1)
                }
                if resposta.liberaFixos {
                    fixos = []
                }
            case let .failure(falha):
                trocas[indice].erro = falha.mensagem
            }
        }
    }

    private func novoId() -> Int {
        proximoId += 1
        return proximoId
    }
}

/// Minhas perguntas: histórico paginado, apagar.
@MainActor
@Observable
public final class HistoricoViewModel {
    public private(set) var carga = Carga<HistoricoDePerguntas>()
    public private(set) var carregandoMais = false
    public var recado: Recado?
    @ObservationIgnored private let api: ClienteDaApi

    public init(api: ClienteDaApi) {
        self.api = api
    }

    public func carregar() async {
        carga.atualizando = true
        switch await tentar({ try await api.perguntas(pagina: 1) }) {
        case let .success(historico):
            carga = Carga(dados: historico)
        case let .failure(falha):
            carga.atualizando = false
            carga.erro = falha.mensagem
        }
    }

    public func carregarMais() async {
        guard !carregandoMais, let atual = carga.dados, let pagina = atual.proximaPagina else {
            return
        }
        carregandoMais = true
        if case let .success(mais) = await tentar({ try await api.perguntas(pagina: pagina) }) {
            var juntas = atual
            juntas.items += mais.items
            juntas.proximaPagina = mais.proximaPagina
            carga.dados = juntas
        }
        carregandoMais = false
    }

    public func apagar(_ pergunta: PerguntaAnterior) async {
        let antes = carga.dados
        carga.dados?.items.removeAll { $0.uuid == pergunta.uuid }
        if case .failure = await tentar({ try await api.apagarPergunta(pergunta.uuid) }) {
            carga.dados = antes
            recado = Recado("Não deu para apagar agora.")
        }
    }

    /// Frase do selo de cada pergunta, como no Android.
    public static func situacao(_ status: String) -> String {
        switch status {
        case Resposta.respondida: "Respondida"
        case Resposta.precisaDeInformacao: "Pediu mais informações"
        case Resposta.bloqueada: "Não pude ajudar"
        case Resposta.semRemedio: "Remédio não reconhecido"
        case "no_leaflet", "not_identified": "Sem bula cadastrada"
        case "no_evidence": "Sem orientação clara na bula"
        case "guidance": "Orientação de uso do sistema"
        case Resposta.apoio: "Indiquei onde encontrar apoio"
        case "unavailable": "Não consegui responder"
        default: "Respondida"
        }
    }
}

/// Uma pergunta anterior, no formato do balão.
@MainActor
@Observable
public final class PerguntaAnteriorViewModel {
    public private(set) var carga = Carga<Resposta>()
    @ObservationIgnored private let api: ClienteDaApi
    @ObservationIgnored private let uuid: String

    public init(api: ClienteDaApi, uuid: String) {
        self.api = api
        self.uuid = uuid
    }

    public func carregar() async {
        carga.atualizando = true
        switch await tentar({ try await api.pergunta(uuid) }) {
        case let .success(resposta):
            carga = Carga(dados: resposta)
        case let .failure(falha):
            carga.atualizando = false
            carga.erro = falha.mensagem
        }
    }
}

/// Bula simples: abre na hora (pronta ou do aparelho); com `carregando`, mostra o esqueleto e pede para gerar.
@MainActor
@Observable
public final class BulaViewModel {
    public private(set) var carga = Carga<Bula>()
    @ObservationIgnored private let dependencias: Dependencias
    @ObservationIgnored public let remedio: Int64

    public init(_ dependencias: Dependencias, remedio: Int64) {
        self.dependencias = dependencias
        self.remedio = remedio
    }

    public func carregar() async {
        if carga.dados == nil, let guardada = await dependencias.cache.ler("bula\(remedio)", como: Bula.self) {
            carga.dados = guardada
        }
        carga.atualizando = true
        carga.erro = nil
        switch await tentar({ try await dependencias.api.bula(remedio) }) {
        case let .success(bula):
            await mostrar(bula)
            if bula.preparando {
                await gerar()
            }
        case let .failure(falha):
            carga.atualizando = false
            carga.erro = falha.mensagem
        }
    }

    private func gerar() async {
        carga.atualizando = true
        switch await tentar({ try await dependencias.api.gerarBula(remedio) }) {
        case let .success(bula):
            await mostrar(bula)
        case let .failure(falha):
            carga.atualizando = false
            carga.erro = falha.mensagem
        }
    }

    /// Bula pronta fica guardada no aparelho: reabrir mostra na hora e atualiza por cima.
    private func mostrar(_ bula: Bula) async {
        carga = Carga(dados: bula)
        if bula.state == Bula.pronta {
            await dependencias.cache.gravar("bula\(bula.medicine.id)", bula)
        }
    }
}

/// Lista de bulas: as prontas filtradas no aparelho a cada letra; com 3 letras, também o catálogo.
@MainActor
@Observable
public final class BulasViewModel {
    public private(set) var prontas: [BulaPronta] = []
    public private(set) var catalogo: [RemedioComBula] = []
    public private(set) var buscando = false
    public private(set) var limitado = false
    public private(set) var erro: String?
    public var termo = "" {
        didSet { buscar() }
    }

    @ObservationIgnored private let api: ClienteDaApi
    @ObservationIgnored private var tarefa: Task<Void, Never>?

    public init(api: ClienteDaApi) {
        self.api = api
    }

    public func carregar() async {
        switch await tentar({ try await api.bulas() }) {
        case let .success(lista):
            prontas = lista.available
            erro = nil
        case let .failure(falha):
            erro = falha.mensagem
        }
    }

    /// Prontas que combinam com o termo, ignorando acentos e maiúsculas.
    public var filtradas: [BulaPronta] {
        let busca = BulasViewModel.normalizar(termo)
        guard !busca.isEmpty else {
            return prontas
        }
        return prontas.filter { BulasViewModel.normalizar($0.name).contains(busca) }
    }

    private func buscar() {
        tarefa?.cancel()
        let atual = termo.trimmingCharacters(in: .whitespaces)
        guard atual.count >= 3 else {
            catalogo = []
            buscando = false
            limitado = false
            return
        }
        tarefa = Task {
            guard await esperarDigitacao(300) else {
                return
            }
            buscando = true
            let resultado = await tentar { try await api.bulas(termo: atual) }
            guard !Task.isCancelled else {
                return
            }
            buscando = false
            switch resultado {
            case let .success(lista):
                catalogo = lista.results
                limitado = lista.limited
            case let .failure(falha):
                if case .limite = falha {
                    limitado = true
                }
            }
        }
    }

    public static func normalizar(_ texto: String) -> String {
        texto.folding(options: [.diacriticInsensitive, .caseInsensitive], locale: Datas.ptBR).trimmingCharacters(in: .whitespaces)
    }
}
