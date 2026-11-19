import Foundation

/// Fornece o valor usado quando a chave não vem no JSON (ou vem `null`), como os valores padrão do app Android.
public protocol FornecePadrao {
    associatedtype Valor: Codable & Sendable & Hashable
    static var valor: Valor { get }
}

/// Campo com valor padrão: a resposta pode omitir a chave sem quebrar a leitura (o servidor evolui sem travar o app).
@propertyWrapper
public struct Padrao<P: FornecePadrao>: Codable, Sendable, Hashable {
    public var wrappedValue: P.Valor

    public init(wrappedValue: P.Valor) {
        self.wrappedValue = wrappedValue
    }

    public init() {
        wrappedValue = P.valor
    }

    public init(from decoder: Decoder) throws {
        let container = try decoder.singleValueContainer()
        wrappedValue = container.decodeNil() ? P.valor : try container.decode(P.Valor.self)
    }

    public func encode(to encoder: Encoder) throws {
        var container = encoder.singleValueContainer()
        try container.encode(wrappedValue)
    }
}

public extension KeyedDecodingContainer {
    func decode<P>(_ tipo: Padrao<P>.Type, forKey chave: Key) throws -> Padrao<P> {
        try decodeIfPresent(tipo, forKey: chave) ?? Padrao()
    }
}

public enum Falso: FornecePadrao { public static var valor: Bool { false } }
public enum Verdadeiro: FornecePadrao { public static var valor: Bool { true } }
public enum Zero: FornecePadrao { public static var valor: Int { 0 } }
public enum TextoVazio: FornecePadrao { public static var valor: String { "" } }
public enum Vazio<T: Codable & Sendable & Hashable>: FornecePadrao { public static var valor: [T] { [] } }
public enum DicionarioVazio<T: Codable & Sendable & Hashable>: FornecePadrao { public static var valor: [String: T] { [:] } }

/// Valor padrão inteiro escolhido no tipo (ex.: `Inteiro<Cinco>`), para limites que o servidor costuma mandar.
public protocol ConstanteInteira { static var valor: Int { get } }
public enum Inteiro<C: ConstanteInteira>: FornecePadrao { public static var valor: Int { C.valor } }
public enum Cinco: ConstanteInteira { public static var valor: Int { 5 } }
public enum Quinhentos: ConstanteInteira { public static var valor: Int { 500 } }
public enum Noventa: ConstanteInteira { public static var valor: Int { 90 } }
public enum Sessenta: ConstanteInteira { public static var valor: Int { 60 } }
public enum Dez: ConstanteInteira { public static var valor: Int { 10 } }

/// Texto padrão escolhido no tipo (ex.: `Texto<Manha>`).
public protocol ConstanteDeTexto { static var valor: String { get } }
public enum Texto<C: ConstanteDeTexto>: FornecePadrao { public static var valor: String { C.valor } }
public enum Manha: ConstanteDeTexto { public static var valor: String { "manha" } }
public enum Neutro: ConstanteDeTexto { public static var valor: String { "neutral" } }
public enum Urgencia: ConstanteDeTexto { public static var valor: String { "urgencia" } }
public enum Marca: ConstanteDeTexto { public static var valor: String { "brand" } }
public enum Atencao: ConstanteDeTexto { public static var valor: String { "warning" } }
public enum ModoFixo: ConstanteDeTexto { public static var valor: String { "fixed" } }
public enum FusoDeSaoPaulo: ConstanteDeTexto { public static var valor: String { "America/Sao_Paulo" } }

/// Número de ponto flutuante padrão 1 (confiança da leitura da receita).
public enum Um: FornecePadrao { public static var valor: Double { 1 } }
