import Foundation
#if canImport(FoundationNetworking)
import FoundationNetworking
#endif
@testable import BulaiCore

/// Resposta JSON real do servidor, capturada dos testes Laravel do app (`Tests/BulaiCoreTests/Contratos`).
func contrato(_ nome: String) -> Data {
    let url = Bundle.module.url(forResource: nome, withExtension: "json", subdirectory: "Contratos")!
    return try! Data(contentsOf: url)
}

func json(_ texto: String) -> Data {
    Data(texto.utf8)
}

/// Transporte falso: responde por "MÉTODO caminho" (sem `/api/mobile/v1/`) e guarda os pedidos feitos.
final class TransporteFalso: Transporte, @unchecked Sendable {
    enum Resposta {
        case http(Int, Data)
        case erro(URLError.Code)
    }

    private let trava = NSLock()
    private var respostas: [String: [Resposta]] = [:]
    private(set) var pedidos: [URLRequest] = []

    /// Cada chamada consome uma resposta da fila; a última fica valendo para as próximas.
    func responder(_ rota: String, _ status: Int = 200, _ corpo: Data = json("{}")) {
        trava.withLock { respostas[rota, default: []].append(.http(status, corpo)) }
    }

    func falhar(_ rota: String, _ codigo: URLError.Code) {
        trava.withLock { respostas[rota, default: []].append(.erro(codigo)) }
    }

    func limpar(_ rota: String) {
        trava.withLock { respostas[rota] = nil }
    }

    var feitos: [String] {
        trava.withLock { pedidos.map(TransporteFalso.rota) }
    }

    func ultimo(_ rota: String) -> URLRequest? {
        trava.withLock { pedidos.last { TransporteFalso.rota($0) == rota } }
    }

    func corpo(_ rota: String) -> [String: Any]? {
        guard let dados = ultimo(rota)?.httpBody else {
            return nil
        }
        return try? JSONSerialization.jsonObject(with: dados) as? [String: Any]
    }

    static func rota(_ pedido: URLRequest) -> String {
        let caminho = pedido.url!.path.replacingOccurrences(of: "/api/mobile/v1/", with: "")
        return "\(pedido.httpMethod ?? "GET") \(caminho)"
    }

    func enviar(_ pedido: URLRequest) async throws -> (Data, HTTPURLResponse) {
        let rota = TransporteFalso.rota(pedido)
        let resposta: Resposta? = trava.withLock {
            pedidos.append(pedido)
            guard var fila = respostas[rota], !fila.isEmpty else {
                return nil
            }
            let primeira = fila.removeFirst()
            respostas[rota] = fila.isEmpty ? [primeira] : fila
            return primeira
        }
        switch resposta {
        case let .http(status, corpo):
            return (corpo, HTTPURLResponse(url: pedido.url!, statusCode: status, httpVersion: "HTTP/1.1", headerFields: ["Content-Type": "application/json"])!)
        case let .erro(codigo):
            throw URLError(codigo)
        case nil:
            return (json("{\"message\":\"rota sem resposta no teste: \(rota)\"}"), HTTPURLResponse(url: pedido.url!, statusCode: 500, httpVersion: "HTTP/1.1", headerFields: nil)!)
        }
    }
}

/// Notificações falsas: guardam o que seria agendado no iOS.
final class NotificacoesFalsas: ServicoDeNotificacoes, @unchecked Sendable {
    private let trava = NSLock()
    private var _lembretes: [LembretePlanejado] = []
    private var _avisos: [String] = []
    private var _cancelados: [String] = []
    private var _testes = 0
    private var _removeuTudo = 0

    var lembretes: [LembretePlanejado] { trava.withLock { _lembretes } }
    var avisos: [String] { trava.withLock { _avisos } }
    var cancelados: [String] { trava.withLock { _cancelados } }
    var removeuTudo: Int { trava.withLock { _removeuTudo } }

    func substituirLembretes(_ lembretes: [LembretePlanejado], adiarMinutos _: Int) async {
        trava.withLock { _lembretes = lembretes }
    }

    func cancelarLembretes(chave: String) async {
        trava.withLock { _cancelados.append(chave) }
    }

    func mostrarAviso(id: String, titulo _: String, corpo _: String, caminho _: String) async {
        trava.withLock { _avisos.append(id) }
    }

    func testar(emSegundos _: Int, adiarMinutos _: Int) async {
        trava.withLock { _testes += 1 }
    }

    func removerTudo() async {
        trava.withLock {
            _removeuTudo += 1
            _lembretes = []
        }
    }
}

/// Relógio ajustável para as regras de horário.
final class Relogio: @unchecked Sendable {
    private let trava = NSLock()
    private var agora: Date

    init(_ agora: Date) {
        self.agora = agora
    }

    var data: Date {
        get { trava.withLock { agora } }
        set { trava.withLock { agora = newValue } }
    }
}

func data(_ iso: String) -> Date {
    Datas.iso(iso)!
}

/// App montado com dependências falsas (o mesmo desenho do `Ambiente` do app).
@MainActor
final class AppDeTeste {
    let transporte = TransporteFalso()
    let notificacoes = NotificacoesFalsas()
    let cofre: CofreEmMemoria
    let preferencias: PreferenciasLocais
    let cache: CacheEmDisco
    let sessao: Sessao
    let api: ClienteDaApi
    let sincronizador: Sincronizador
    let dependencias: Dependencias
    let relogio: Relogio
    private(set) var mudancasDaAgenda = 0

    init(token: String? = nil, conta: Usuario? = nil, agora: Date = data("2026-11-05T07:30:00-03:00")) {
        let suite = "bulai-testes-\(UUID().uuidString)"
        preferencias = PreferenciasLocais(UserDefaults(suiteName: suite)!)
        preferencias.conta = conta
        cofre = CofreEmMemoria(token)
        cache = CacheEmDisco(pasta: FileManager.default.temporaryDirectory.appendingPathComponent(suite, isDirectory: true))
        let caixa = CaixaDoToken()
        sessao = Sessao(cofre: cofre, preferencias: preferencias, cache: cache, caixa: caixa)
        let relogio = Relogio(agora)
        self.relogio = relogio
        let sessao = sessao
        api = ClienteDaApi(base: URL(string: "https://bulai.teste")!, caixa: caixa, transporte: transporte, versao: "1.0.0-teste") {
            await sessao.perdeuAcesso()
        }
        var aoMudar: () -> Void = {}
        sincronizador = Sincronizador(api: api, preferencias: preferencias, notificacoes: notificacoes, aoMudarAgenda: { aoMudar() }, relogio: { relogio.data })
        dependencias = Dependencias(api: api, cache: cache, sessao: sessao, preferencias: preferencias, sincronizador: sincronizador, aparelho: "iPhone de teste", versao: "1.0.0-teste")
        let sincronizador = sincronizador
        sessao.aoConectar = { await sincronizador.conectar() }
        sessao.aoDesconectar = { await sincronizador.desconectarLocalmente() }
        aoMudar = { [weak self] in self?.mudancasDaAgenda += 1 }
    }

    static let maria = Usuario(id: 7, name: "Maria Souza", primeiroNome: "Maria", email: "maria@exemplo.com", precisaTrocarSenha: false, consentimento: true, timezone: "America/Sao_Paulo")

    /// Conta já conectada (token no cofre e conta guardada), como ao reabrir o app.
    static func conectado(agora: Date = data("2026-11-05T07:30:00-03:00")) -> AppDeTeste {
        let app = AppDeTeste(token: "bulai_app_guardado", conta: maria, agora: agora)
        app.preferencias.conectado = true
        app.preferencias.usuario = "7"
        return app
    }
}

/// Agenda com as doses dadas (hoje no fuso de São Paulo).
func agenda(_ doses: [DoseDaAgenda], lembretes: Bool = true) -> AgendaDoAparelho {
    AgendaDoAparelho(lembretesLigados: lembretes, doses: doses)
}

func doseDaAgenda(_ chave: String, _ iso: String, status: String = Dose.pendente, lembrar: Bool = true, remedio: Int64 = 1) -> DoseDaAgenda {
    DoseDaAgenda(key: chave, remedioId: remedio, name: "Losartana", dosage: "1 comprimido", time: String(iso.dropFirst(11).prefix(5)), agendadaPara: iso, status: status, remind: lembrar)
}

func codificar(_ valor: some Encodable) -> Data {
    try! JSONEncoder().encode(valor)
}
