import Foundation

/// Datas da API (ISO 8601 com fuso) e textos curtos em português.
public enum Datas {
    public static let ptBR = Locale(identifier: "pt_BR")

    /// Lê `2026-11-05T08:00:00-03:00` (com ou sem fração de segundo).
    public static func iso(_ texto: String?) -> Date? {
        guard let texto, !texto.isEmpty else {
            return nil
        }
        let leitor = ISO8601DateFormatter()
        leitor.formatOptions = [.withInternetDateTime]
        if let data = leitor.date(from: texto) {
            return data
        }
        leitor.formatOptions = [.withInternetDateTime, .withFractionalSeconds]
        return leitor.date(from: texto)
    }

    /// Momento atual no formato que o servidor aceita em `recorded_at` e `desde`.
    public static func isoAgora(_ agora: Date = Date()) -> String {
        let escritor = ISO8601DateFormatter()
        escritor.formatOptions = [.withInternetDateTime]
        escritor.timeZone = TimeZone.current
        return escritor.string(from: agora)
    }

    /// "05/11 às 08:00" no fuso do aparelho.
    public static func curta(_ texto: String?, fuso: TimeZone = .current) -> String {
        guard let data = iso(texto) else {
            return texto ?? ""
        }
        return formatar(data, "dd/MM 'às' HH:mm", fuso: fuso)
    }

    /// "5 de nov. às 08:00".
    public static func longa(_ texto: String?, fuso: TimeZone = .current) -> String {
        guard let data = iso(texto) else {
            return texto ?? ""
        }
        return formatar(data, "d 'de' MMM 'às' HH:mm", fuso: fuso)
    }

    /// "08:21" (hora da marcação "Tomei 08:21").
    public static func hora(_ texto: String?, fuso: TimeZone = .current) -> String? {
        guard let data = iso(texto) else {
            return nil
        }
        return formatar(data, "HH:mm", fuso: fuso)
    }

    public static func formatar(_ data: Date, _ padrao: String, fuso: TimeZone = .current) -> String {
        let formatador = DateFormatter()
        formatador.locale = ptBR
        formatador.timeZone = fuso
        formatador.dateFormat = padrao
        return formatador.string(from: data)
    }

    /// `aaaa-mm-dd` (datas do formulário "Dose e horários") → `dd/mm/aaaa` para mostrar.
    public static func dataDoFormulario(_ texto: String?) -> String? {
        guard let texto, texto.count >= 10 else {
            return nil
        }
        let partes = texto.prefix(10).split(separator: "-")
        guard partes.count == 3 else {
            return texto
        }
        return "\(partes[2])/\(partes[1])/\(partes[0])"
    }

    /// `Date` escolhida no seletor → `aaaa-mm-dd` (no fuso do aparelho).
    public static func paraFormulario(_ data: Date, fuso: TimeZone = .current) -> String {
        formatar(data, "yyyy-MM-dd", fuso: fuso)
    }

    /// `aaaa-mm-dd` → `Date` (meio-dia, para não mudar de dia com o fuso).
    public static func doFormulario(_ texto: String?, fuso: TimeZone = .current) -> Date? {
        guard let texto, texto.count >= 10 else {
            return nil
        }
        let formatador = DateFormatter()
        formatador.locale = Locale(identifier: "en_US_POSIX")
        formatador.timeZone = fuso
        formatador.dateFormat = "yyyy-MM-dd HH:mm"
        return formatador.date(from: String(texto.prefix(10)) + " 12:00")
    }

    /// Saudação pelo horário do aparelho, como no Início do Android.
    public static func saudacao(_ agora: Date = Date(), calendario: Calendar = .current) -> String {
        switch calendario.component(.hour, from: agora) {
        case 5..<12: return "Bom dia"
        case 12..<18: return "Boa tarde"
        default: return "Boa noite"
        }
    }
}

/// Período do dia de um horário "HH:MM" (a mesma divisão do site e do Android).
public enum PeriodoDoDia: String, Sendable, CaseIterable {
    case manha, tarde, noite, madrugada

    public init?(horario: String) {
        guard let hora = Int(horario.prefix(2)) else {
            return nil
        }
        switch hora {
        case 5...11: self = .manha
        case 12...17: self = .tarde
        case 18...23: self = .noite
        default: self = .madrugada
        }
    }

    public var nome: String {
        switch self {
        case .manha: "Manhã"
        case .tarde: "Tarde"
        case .noite: "Noite"
        case .madrugada: "Madrugada"
        }
    }

    /// Nome do SF Symbol do período.
    public var simbolo: String {
        switch self {
        case .manha: "sunrise.fill"
        case .tarde: "sun.max.fill"
        case .noite: "moon.stars.fill"
        case .madrugada: "moon.zzz.fill"
        }
    }
}
