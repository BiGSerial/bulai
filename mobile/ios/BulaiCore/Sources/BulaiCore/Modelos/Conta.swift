import Foundation

// Formato das respostas de /api/mobile/v1 (app/Http/Controllers/Api/Mobile, docs/app-android.md e docs/app-ios.md).

public struct Usuario: Codable, Sendable, Hashable {
    public var id: Int64
    public var name: String
    public var primeiroNome: String
    public var nomeDeChamada: String?
    public var email: String
    public var precisaTrocarSenha: Bool
    public var consentimento: Bool
    public var timezone: String
    @Padrao<Falso> public var admin: Bool

    enum CodingKeys: String, CodingKey {
        case id, name, email, timezone
        case primeiroNome = "first_name"
        case nomeDeChamada = "preferred_name"
        case precisaTrocarSenha = "must_change_password"
        case consentimento = "health_consent"
        case admin = "is_admin"
    }

    public init(id: Int64, name: String, primeiroNome: String, nomeDeChamada: String? = nil, email: String, precisaTrocarSenha: Bool, consentimento: Bool, timezone: String, admin: Bool = false) {
        self.id = id
        self.name = name
        self.primeiroNome = primeiroNome
        self.nomeDeChamada = nomeDeChamada
        self.email = email
        self.precisaTrocarSenha = precisaTrocarSenha
        self.consentimento = consentimento
        self.timezone = timezone
        self.admin = admin
    }
}

/// `POST /entrar`: o token é individual por instalação; `platform` diz ao servidor que é o aplicativo iOS.
public struct PedidoDeEntrada: Encodable, Sendable {
    public var email: String
    public var password: String
    public var instalacao: String
    public var plataforma: String
    public var aparelho: String
    public var versao: String

    enum CodingKeys: String, CodingKey {
        case email, password
        case instalacao = "installation_id"
        case plataforma = "platform"
        case aparelho = "device_name"
        case versao = "app_version"
    }
}

public struct RespostaDeEntrada: Codable, Sendable {
    public var token: String
    public var user: Usuario
}

public struct RespostaDoUsuario: Codable, Sendable {
    public var user: Usuario
}

public struct PedidoDeCadastro: Encodable, Sendable {
    public var name: String
    public var email: String
    public var password: String
    public var confirmacao: String
    public var terms: Bool

    enum CodingKeys: String, CodingKey {
        case name, email, password, terms
        case confirmacao = "password_confirmation"
    }
}

public struct PedidoDeEmail: Encodable, Sendable {
    public var email: String
}

public struct PedidoDeNovaSenha: Encodable, Sendable {
    public var password: String
    public var confirmacao: String

    enum CodingKeys: String, CodingKey {
        case password
        case confirmacao = "password_confirmation"
    }
}

public struct Mensagem: Codable, Sendable, Hashable {
    public var message: String
}

public struct PedidoDeNomeDeChamada: Encodable, Sendable {
    public var nome: String?

    enum CodingKeys: String, CodingKey {
        case nome = "preferred_name"
    }

    /// `null` explícito: vazio volta a usar o primeiro nome.
    public func encode(to encoder: Encoder) throws {
        var container = encoder.container(keyedBy: CodingKeys.self)
        try container.encode(nome, forKey: .nome)
    }
}

public struct PedidoDeExclusao: Encodable, Sendable {
    public var password: String
}

public struct PedidoDeTrocaDeSenha: Encodable, Sendable {
    public var atual: String
    public var password: String
    public var confirmacao: String

    enum CodingKeys: String, CodingKey {
        case password
        case atual = "current_password"
        case confirmacao = "password_confirmation"
    }
}

/// Corpo de erro do Laravel: `message` e, na validação (422), `errors` campo → mensagens.
public struct CorpoDeErro: Decodable, Sendable {
    public var message: String?
    public var code: String?
    public var errors: [String: [String]]?
}

/// Resposta que o app não precisa ler.
public struct Qualquer: Decodable, Sendable {
    public init(from decoder: Decoder) throws {}
    public init() {}
}
