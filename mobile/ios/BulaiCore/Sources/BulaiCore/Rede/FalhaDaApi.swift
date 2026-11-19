import Foundation

/// Falhas da API traduzidas para o que a tela mostra (as mesmas frases do app Android).
public enum FalhaDaApi: Error, Equatable, Sendable, LocalizedError {
    /// 422: mensagens por campo (`email`, `password`…) para mostrar embaixo de cada um.
    case validacao(mensagem: String, campos: [String: String])
    /// 401: token recusado; a sessão local é encerrada.
    case naoAutorizado
    /// 403 com `must_change_password`.
    case trocarSenha
    /// 403 sem código: a pessoa não tem permissão (ex.: cuidador só com "ver").
    case proibido(String)
    case naoEncontrado
    /// 429.
    case limite(String)
    /// 409: a ação não vale no estado atual (ex.: falta o consentimento de dados de saúde).
    case conflito(mensagem: String, codigo: String?)
    case semConexao
    case tempoEsgotado
    case servidor(String)
    /// Imagem que não deu para preparar, resposta que não deu para ler etc.
    case local(String)

    public var mensagem: String {
        switch self {
        case let .validacao(mensagem, _): mensagem
        case .naoAutorizado: "Sua sessão terminou. Entre de novo."
        case .trocarSenha: "Troque a senha provisória para continuar."
        case let .proibido(mensagem): mensagem
        case .naoEncontrado: "Não encontramos isso. Pode ter sido apagado."
        case let .limite(mensagem): mensagem
        case let .conflito(mensagem, _): mensagem
        case .semConexao: "Sem conexão com a internet. Confira o Wi-Fi ou os dados móveis."
        case .tempoEsgotado: "O BULA-IA demorou para responder. Confira a internet e tente de novo."
        case let .servidor(mensagem): mensagem
        case let .local(mensagem): mensagem
        }
    }

    public var errorDescription: String? { mensagem }

    /// Mensagens por campo do 422 (vazio nos outros casos).
    public var campos: [String: String] {
        if case let .validacao(_, campos) = self {
            return campos
        }
        return [:]
    }

    /// Código do 409 (`health_consent_required`, `invite_unavailable`…).
    public var codigo: String? {
        if case let .conflito(_, codigo) = self {
            return codigo
        }
        return nil
    }

    /// Falha que pode passar com o tempo (rede): a marcação pendente fica na fila para tentar de novo.
    public var transitoria: Bool {
        switch self {
        case .semConexao, .tempoEsgotado, .limite: true
        case .servidor: true
        default: false
        }
    }

    public static let faltaConsentimento = "health_consent_required"

    /// Converte status HTTP + corpo em falha (mesma regra do `Rede.traduzir` do Android).
    public static func doStatus(_ status: Int, corpo: Data) -> FalhaDaApi {
        let erro = try? JSONDecoder().decode(CorpoDeErro.self, from: corpo)
        switch status {
        case 401:
            return .naoAutorizado
        case 403:
            return erro?.code == "must_change_password" ? .trocarSenha : .proibido(erro?.message ?? "Você não tem permissão para isso.")
        case 404:
            return .naoEncontrado
        case 409:
            return .conflito(mensagem: erro?.message ?? "Isso não pode ser feito agora.", codigo: erro?.code)
        case 422:
            let campos = (erro?.errors ?? [:]).compactMapValues(\.first)
            let primeira = erro?.errors?.sorted { $0.key < $1.key }.first?.value.first
            return .validacao(mensagem: primeira ?? erro?.message ?? "Confira os dados.", campos: campos)
        case 429:
            return .limite(erro?.message ?? "Muitas tentativas. Espere um pouco e tente de novo.")
        default:
            return .servidor("O BULA-IA não respondeu agora. Tente de novo em instantes.")
        }
    }

    /// Converte qualquer erro de uma chamada em ``FalhaDaApi``.
    public static func traduzir(_ erro: Error) -> FalhaDaApi {
        if let falha = erro as? FalhaDaApi {
            return falha
        }
        if let erroDeRede = erro as? URLError {
            return erroDeRede.code == .timedOut ? .tempoEsgotado : .semConexao
        }
        if erro is DecodingError {
            return .servidor("O BULA-IA respondeu de um jeito que este app não entendeu. Atualize o aplicativo.")
        }
        return .servidor("Algo deu errado. Tente de novo.")
    }
}
