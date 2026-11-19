import Foundation

/// O link do convite vai no QR Code; o código de 8 números serve para quem prefere digitar.
public struct Convite: Codable, Sendable, Hashable {
    public var code: String
    public var url: String
    public var venceEm: String

    enum CodingKeys: String, CodingKey {
        case code, url
        case venceEm = "expires_at"
    }
}

public struct Cuidador: Codable, Sendable, Hashable, Identifiable {
    public var id: Int64
    public var name: String
    public var primeiroNome: String
    public var email: String?
    @Padrao<DicionarioVazio<Bool>> public var permissoes: [String: Bool] = [:]
    public var pediuEm: String?
    public var desde: String?

    enum CodingKeys: String, CodingKey {
        case id, name, email
        case primeiroNome = "first_name"
        case permissoes = "permissions"
        case pediuEm = "requested_at"
        case desde = "since"
    }
}

public struct CuidadorEncerrado: Codable, Sendable, Hashable, Identifiable {
    public var id: Int64
    public var name: String
    public var status: String
    public var terminouEm: String?

    enum CodingKeys: String, CodingKey {
        case id, name, status
        case terminouEm = "ended_at"
    }
}

public struct RotuloDePermissao: Codable, Sendable, Hashable, Identifiable {
    public var key: String
    public var label: String

    public var id: String { key }
}

/// "Quem cuida de mim" (lado do paciente): convite em QR Code, pedidos, quem acompanha e quem já acompanhou.
public struct MeusCuidadores: Codable, Sendable, Hashable {
    @Padrao<Falso> public var consentimento: Bool = false
    public var convite: Convite?
    @Padrao<Falso> public var conviteVencido: Bool = false
    @Padrao<Vazio<Cuidador>> public var pedidos: [Cuidador] = []
    @Padrao<Vazio<Cuidador>> public var ativos: [Cuidador] = []
    @Padrao<Vazio<CuidadorEncerrado>> public var encerrados: [CuidadorEncerrado] = []
    @Padrao<Vazio<RotuloDePermissao>> public var rotulos: [RotuloDePermissao] = []

    enum CodingKeys: String, CodingKey {
        case consentimento = "health_consent"
        case convite = "invite"
        case conviteVencido = "invite_expired"
        case pedidos = "pending"
        case ativos = "active"
        case encerrados = "ended"
        case rotulos = "permission_labels"
    }
}

public struct PessoaAcompanhada: Codable, Sendable, Hashable, Identifiable {
    public var id: Int64
    public var name: String
    public var primeiroNome: String
    @Padrao<Falso> public var podeVer: Bool = false
    @Padrao<Falso> public var podeMarcar: Bool = false
    @Padrao<Verdadeiro> public var consentimento: Bool = true
    @Padrao<Vazio<String>> public var frases: [String] = []
    /// Frase do que a pessoa pode fazer (decidida pelo servidor a partir das permissões).
    @Padrao<TextoVazio> public var acesso: String = ""

    enum CodingKeys: String, CodingKey {
        case id, name
        case primeiroNome = "first_name"
        case podeVer = "can_view"
        case podeMarcar = "can_mark"
        case consentimento = "health_consent"
        case frases = "phrases"
        case acesso = "access_label"
    }
}

/// "Cuidar de alguém" (lado de quem cuida): as pessoas que acompanha, para escolher de quem ver o dia.
public struct QuemEuCuido: Codable, Sendable, Hashable {
    public var message: String?
    @Padrao<Vazio<PessoaAcompanhada>> public var aguardando: [PessoaAcompanhada] = []
    @Padrao<Vazio<PessoaAcompanhada>> public var pessoas: [PessoaAcompanhada] = []

    enum CodingKeys: String, CodingKey {
        case message
        case aguardando = "pending"
        case pessoas = "active"
    }
}

public struct PedidoDeCodigo: Encodable, Sendable {
    public var code: String
}

public struct PedidoDePermissoes: Encodable, Sendable {
    public var permissions: [String: Bool]
}

/// Chaves das permissões de quem cuida (as mesmas do `CaregiverLinkService`).
public enum PermissaoDeCuidador {
    public static let receberAlertas = "can_receive_alerts"
    public static let verRemedios = "can_view_medications"
    public static let marcarEEditar = "can_manage_medications"
    public static let todas = [receberAlertas, verRemedios, marcarEEditar]
}
