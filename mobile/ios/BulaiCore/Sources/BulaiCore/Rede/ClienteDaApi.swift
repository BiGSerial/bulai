import Foundation
#if canImport(FoundationNetworking)
import FoundationNetworking
#endif

/// Quem leva o pedido HTTP até o servidor (URLSession no app; um falso nos testes).
public protocol Transporte: Sendable {
    func enviar(_ pedido: URLRequest) async throws -> (Data, HTTPURLResponse)
}

/// Transporte do app: URLSession efêmera (sem cache em disco, sem cookies), só HTTPS em produção (ATS).
public struct TransporteURLSession: Transporte {
    private let sessao: URLSession

    public init() {
        let configuracao = URLSessionConfiguration.ephemeral
        configuracao.timeoutIntervalForRequest = 30
        configuracao.timeoutIntervalForResource = 120
        configuracao.httpCookieStorage = nil
        configuracao.urlCache = nil
        configuracao.requestCachePolicy = .reloadIgnoringLocalCacheData
        sessao = URLSession(configuration: configuracao)
    }

    public func enviar(_ pedido: URLRequest) async throws -> (Data, HTTPURLResponse) {
        let (dados, resposta) = try await sessao.data(for: pedido)
        guard let http = resposta as? HTTPURLResponse else {
            throw URLError(.badServerResponse)
        }
        return (dados, http)
    }
}

/// Token do aparelho em memória, lido a cada chamada (muda ao entrar e sair). A cópia persistente fica no Keychain.
public final class CaixaDoToken: @unchecked Sendable {
    private let trava = NSLock()
    private var valor: String?

    public init(_ valor: String? = nil) {
        self.valor = valor
    }

    public var token: String? {
        get { trava.withLock { valor } }
        set { trava.withLock { valor = newValue } }
    }
}

/// Arquivo para o `multipart/form-data` (foto da receita).
public struct Anexo: Sendable {
    public var campo: String
    public var nomeDoArquivo: String
    public var tipo: String
    public var dados: Data

    public init(campo: String, nomeDoArquivo: String, tipo: String, dados: Data) {
        self.campo = campo
        self.nomeDoArquivo = nomeDoArquivo
        self.tipo = tipo
        self.dados = dados
    }
}

/// Cliente de `/api/mobile/v1`: JSON, `Authorization: Bearer` com o token do aparelho e erros traduzidos.
/// Um 401 em chamada autenticada avisa ``aoPerderAcesso`` (a sessão local é encerrada e os lembretes cancelados).
/// Nada de corpo, token ou dado de saúde vai para logs.
public final class ClienteDaApi: Sendable {
    public let base: URL
    /// Endereço do servidor (sem `/api/mobile/v1`).
    public let origem: URL
    public let caixa: CaixaDoToken
    private let transporte: Transporte
    private let versao: String
    private let agenteDeUsuario: String
    private let aoPerderAcesso: @Sendable () async -> Void

    /// Pergunta, leitura de receita e bula humanizada passam pela IA: esperam mais que as outras telas.
    public static let tempoNormal: TimeInterval = 30
    public static let tempoLento: TimeInterval = 90

    public init(base: URL, caixa: CaixaDoToken, transporte: Transporte = TransporteURLSession(), versao: String, aoPerderAcesso: @escaping @Sendable () async -> Void = {}) {
        self.base = base.appendingPathComponent("api/mobile/v1")
        origem = base
        self.caixa = caixa
        self.transporte = transporte
        self.versao = versao
        agenteDeUsuario = "BulaiIOS/\(versao)"
        self.aoPerderAcesso = aoPerderAcesso
    }

    /// Outro cliente com um token fixo (avisar o servidor da saída depois que a sessão local já foi limpa).
    public func comToken(_ token: String) -> ClienteDaApi {
        ClienteDaApi(base: origem, caixa: CaixaDoToken(token), transporte: transporte, versao: versao)
    }

    // MARK: - Pedidos

    public func pedir<R: Decodable>(_ metodo: String, _ caminho: String, consulta: [URLQueryItem] = [], corpo: (any Encodable)? = nil, lento: Bool = false, como _: R.Type = R.self) async throws -> R {
        var pedido = montar(metodo, caminho, consulta: consulta, lento: lento)
        if let corpo {
            pedido.setValue("application/json", forHTTPHeaderField: "Content-Type")
            pedido.httpBody = try ClienteDaApi.codificador.encode(corpo)
        }
        let dados = try await executar(pedido)
        return try decodificar(R.self, dados)
    }

    /// `multipart/form-data` com um arquivo (foto da receita).
    public func enviarArquivo<R: Decodable>(_ caminho: String, anexo: Anexo, como _: R.Type = R.self) async throws -> R {
        var pedido = montar("POST", caminho, consulta: [], lento: true)
        let fronteira = "BulaiFronteira\(UUID().uuidString)"
        pedido.setValue("multipart/form-data; boundary=\(fronteira)", forHTTPHeaderField: "Content-Type")
        var corpo = Data()
        corpo.append(Data("--\(fronteira)\r\n".utf8))
        corpo.append(Data("Content-Disposition: form-data; name=\"\(anexo.campo)\"; filename=\"\(anexo.nomeDoArquivo)\"\r\n".utf8))
        corpo.append(Data("Content-Type: \(anexo.tipo)\r\n\r\n".utf8))
        corpo.append(anexo.dados)
        corpo.append(Data("\r\n--\(fronteira)--\r\n".utf8))
        pedido.httpBody = corpo
        let dados = try await executar(pedido)
        return try decodificar(R.self, dados)
    }

    /// Resposta binária (PDF do resumo para o médico).
    public func baixar(_ caminho: String) async throws -> Data {
        var pedido = montar("GET", caminho, consulta: [], lento: true)
        pedido.setValue("application/pdf, application/json", forHTTPHeaderField: "Accept")
        return try await executar(pedido)
    }

    private func montar(_ metodo: String, _ caminho: String, consulta: [URLQueryItem], lento: Bool) -> URLRequest {
        var componentes = URLComponents(url: base.appendingPathComponent(caminho), resolvingAgainstBaseURL: false)!
        let itens = consulta.filter { $0.value != nil }
        if !itens.isEmpty {
            componentes.queryItems = itens
        }
        var pedido = URLRequest(url: componentes.url!)
        pedido.httpMethod = metodo
        pedido.timeoutInterval = lento ? ClienteDaApi.tempoLento : ClienteDaApi.tempoNormal
        pedido.cachePolicy = .reloadIgnoringLocalCacheData
        pedido.setValue("application/json", forHTTPHeaderField: "Accept")
        pedido.setValue(agenteDeUsuario, forHTTPHeaderField: "User-Agent")
        if let token = caixa.token {
            pedido.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization")
        }
        return pedido
    }

    private func executar(_ pedido: URLRequest) async throws -> Data {
        let dados: Data
        let resposta: HTTPURLResponse
        do {
            (dados, resposta) = try await transporte.enviar(pedido)
        } catch {
            throw FalhaDaApi.traduzir(error)
        }
        guard (200 ..< 300).contains(resposta.statusCode) else {
            let falha = FalhaDaApi.doStatus(resposta.statusCode, corpo: dados)
            if falha == .naoAutorizado, pedido.value(forHTTPHeaderField: "Authorization") != nil {
                await aoPerderAcesso()
            }
            throw falha
        }
        return dados
    }

    private func decodificar<R: Decodable>(_ tipo: R.Type, _ dados: Data) throws -> R {
        if tipo == Qualquer.self {
            return Qualquer() as! R
        }
        do {
            return try ClienteDaApi.decodificador.decode(tipo, from: dados)
        } catch {
            throw FalhaDaApi.traduzir(error)
        }
    }

    static let codificador: JSONEncoder = {
        let codificador = JSONEncoder()
        codificador.outputFormatting = [.sortedKeys]
        return codificador
    }()

    static let decodificador = JSONDecoder()
}
