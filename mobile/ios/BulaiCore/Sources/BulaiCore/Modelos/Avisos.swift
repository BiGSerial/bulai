import Foundation

/// Valor de um parâmetro de destino (`params` do aviso). O servidor manda texto ou número; o app usa como texto.
public struct ValorDeParametro: Codable, Sendable, Hashable {
    public var texto: String

    public init(_ texto: String) {
        self.texto = texto
    }

    public init(from decoder: Decoder) throws {
        let container = try decoder.singleValueContainer()
        if let texto = try? container.decode(String.self) {
            self.texto = texto
        } else if let inteiro = try? container.decode(Int64.self) {
            texto = String(inteiro)
        } else if let numero = try? container.decode(Double.self) {
            texto = String(numero)
        } else if let logico = try? container.decode(Bool.self) {
            texto = logico ? "1" : "0"
        } else {
            texto = ""
        }
    }

    public func encode(to encoder: Encoder) throws {
        var container = encoder.singleValueContainer()
        try container.encode(texto)
    }
}

/// Para onde um aviso leva: nome de rota do site (`consulta.hoje`…) e parâmetros. `params` vem sempre como objeto.
public struct Destino: Codable, Sendable, Hashable {
    public var route: String
    public var params: [String: ValorDeParametro]

    public init(route: String, params: [String: String] = [:]) {
        self.route = route
        self.params = params.mapValues(ValorDeParametro.init)
    }

    enum CodingKeys: String, CodingKey {
        case route, params
    }

    public init(from decoder: Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        route = try container.decode(String.self, forKey: .route)
        // `[]` (array vazio do PHP) ou ausência: sem parâmetros.
        params = (try? container.decodeIfPresent([String: ValorDeParametro].self, forKey: .params)) ?? [:]
    }

    public func parametro(_ nome: String) -> String? {
        params[nome]?.texto
    }
}

public struct Aviso: Codable, Sendable, Hashable, Identifiable {
    public var id: String
    public var kind: String
    public var title: String
    public var body: String
    public var destination: Destino
    public var criadoEm: String?
    @Padrao<Falso> public var read: Bool = false
    @Padrao<TextoVazio> public var quando: String = ""
    @Padrao<TextoVazio> public var action: String = ""

    enum CodingKeys: String, CodingKey {
        case id, kind, title, body, destination, read, action
        case criadoEm = "created_at"
        case quando = "when"
    }
}

public struct PreferenciasDeAviso: Codable, Sendable, Hashable {
    @Padrao<Verdadeiro> public var reminders: Bool = true
    @Padrao<Falso> public var email: Bool = false
    @Padrao<Texto<FusoDeSaoPaulo>> public var timezone: String = "America/Sao_Paulo"

    public init(reminders: Bool = true, email: Bool = false, timezone: String = "America/Sao_Paulo") {
        self.reminders = reminders
        self.email = email
        self.timezone = timezone
    }
}

public struct Fuso: Codable, Sendable, Hashable, Identifiable {
    public var id: String
    public var label: String
}

public struct CentralDeAvisos: Codable, Sendable, Hashable {
    public var unread: Int
    public var consentimento: Bool
    public var preferences: PreferenciasDeAviso
    @Padrao<Vazio<Fuso>> public var timezones: [Fuso] = []
    @Padrao<Vazio<Aviso>> public var notices: [Aviso] = []

    enum CodingKeys: String, CodingKey {
        case unread, preferences, timezones, notices
        case consentimento = "health_consent"
    }
}

public struct AlteracaoDePreferencias: Encodable, Sendable {
    public var reminders: Bool?
    public var email: Bool?
    public var timezone: String?

    public init(reminders: Bool? = nil, email: Bool? = nil, timezone: String? = nil) {
        self.reminders = reminders
        self.email = email
        self.timezone = timezone
    }
}

public struct RespostaDeAbertura: Codable, Sendable {
    public var destination: Destino
}

/// `GET /avisos/novos`: avisos não lidos desde a última consulta (para as notificações locais).
public struct AvisosNovos: Codable, Sendable {
    public var checkedAt: String?
    @Padrao<Vazio<AvisoNovo>> public var notices: [AvisoNovo] = []

    enum CodingKeys: String, CodingKey {
        case notices
        case checkedAt = "checked_at"
    }
}

public struct AvisoNovo: Codable, Sendable, Hashable {
    public var id: String
    @Padrao<TextoVazio> public var kind: String = ""
    @Padrao<TextoVazio> public var title: String = ""
    @Padrao<TextoVazio> public var body: String = ""
    public var url: String?
    public var destination: Destino?
}
