import Foundation

/// Abas do menu inferior (as mesmas do Android e do PWA).
public enum Aba: String, Sendable, Hashable, CaseIterable {
    case inicio, perguntar, hoje, conta

    public var titulo: String {
        switch self {
        case .inicio: "Início"
        case .perguntar: "Perguntar"
        case .hoje: "Hoje"
        case .conta: "Conta"
        }
    }

    public var simbolo: String {
        switch self {
        case .inicio: "house.fill"
        case .perguntar: "bubble.left.and.text.bubble.right.fill"
        case .hoje: "pills.fill"
        case .conta: "person.crop.circle.fill"
        }
    }
}

/// Telas do app. Os nomes de rota do site (`consulta.hoje`…) e os caminhos (`/hoje`) chegam por avisos, notificações,
/// widget e atalhos (`bulai://abrir/{caminho}`).
public enum Rota: Hashable, Sendable {
    case aba(Aba)
    case avisos
    case perguntas
    case pergunta(String)
    case receita
    case revisarReceita(Int64)
    case bula(Int64)
    case perguntarSobre(Int64)
    case cuidadores
    case cuidar
    case cuidando(Int64, nome: String?)
    case cuidandoRemedios(Int64)
    case perfil(aba: Int)
    case bulas
    case interacoes(com: Int64?)
    case resultado(String)

    public static let inicio = Rota.aba(.inicio)

    /// Título das telas empilhadas (as abas mostram a marca).
    public var titulo: String {
        switch self {
        case let .aba(aba): aba.titulo
        case .avisos: "Avisos"
        case .perguntas: "Minhas perguntas"
        case .pergunta: "Pergunta anterior"
        case .receita: "Fotografar receita"
        case .revisarReceita: "Conferir a receita"
        case .bula, .bulas: "Bula simples"
        case .perguntarSobre: "Perguntar"
        case .cuidadores: "Quem cuida de mim"
        case .cuidar: "Cuidar de alguém"
        case let .cuidando(_, nome): nome.map { "Remédios de \($0)" } ?? "Remédios de quem você cuida"
        case .cuidandoRemedios: "Remédios e horários"
        case .perfil: "Meus remédios"
        case .interacoes, .resultado: "Interações"
        }
    }

    /// Aviso do servidor → tela do app (o que ainda não existe no app abre o Início).
    public static func doDestino(_ destino: Destino) -> Rota {
        let parametro = destino.parametro
        switch destino.route {
        case "consulta.hoje": return .aba(.hoje)
        case "consulta.avisos": return .avisos
        case "consulta.perguntar": return .aba(.perguntar)
        case "consulta.historico": return .perguntas
        case "consulta.pergunta": return parametro("question").map(Rota.pergunta) ?? .perguntas
        case "consulta.bula": return parametro("medicine").flatMap { Int64($0) }.map(Rota.bula) ?? .inicio
        case "consulta.receita.nova": return .receita
        case "consulta.receita.revisar": return parametro("prescription").flatMap { Int64($0) }.map(Rota.revisarReceita) ?? .receita
        case "consulta.cuidadores": return .cuidadores
        case "consulta.cuidar": return .cuidar
        case "consulta.cuidando.hoje": return parametro("vinculo").flatMap { Int64($0) }.map { .cuidando($0, nome: nil) } ?? .cuidar
        case "consulta.cuidando.remedios": return parametro("vinculo").flatMap { Int64($0) }.map(Rota.cuidandoRemedios) ?? .cuidar
        case "consulta.perfil": return .perfil(aba: 0)
        case "consulta.bulas": return .bulas
        case "consulta.pesquisa": return .interacoes(com: nil)
        case "consulta.resultado": return parametro("consultation").map(Rota.resultado) ?? .interacoes(com: nil)
        case "consulta.conta": return .aba(.conta)
        default: return .inicio
        }
    }

    /// Caminho de `bulai://abrir/{caminho}` (notificação, widget, atalho) ou da `url` de um aviso → tela do app.
    public static func doCaminho(_ caminho: String?) -> Rota? {
        guard var limpo = caminho?.trimmingCharacters(in: CharacterSet(charactersIn: "/ ")) else {
            return nil
        }
        if let corte = limpo.firstIndex(of: "?") {
            limpo = String(limpo[..<corte])
        }
        limpo = limpo.trimmingCharacters(in: CharacterSet(charactersIn: "/"))
        switch limpo {
        case "": return nil
        case "hoje": return .aba(.hoje)
        case "avisos": return .avisos
        case "perguntar": return .aba(.perguntar)
        case "historico": return .perguntas
        case "receitas/nova", "receita": return .receita
        case "conta", "account": return .aba(.conta)
        case "inicio": return .inicio
        case "cuidadores": return .cuidadores
        case "cuidar": return .cuidar
        case "perfil": return .perfil(aba: 0)
        case "bulas": return .bulas
        case "consulta", "interacoes", "pesquisa": return .interacoes(com: nil)
        default: break
        }
        let partes = limpo.split(separator: "/").map(String.init)
        switch (partes.first, partes.count) {
        case ("receitas", 3) where partes[2] == "revisar":
            return Int64(partes[1]).map(Rota.revisarReceita) ?? .inicio
        case ("bula", 2), ("bulas", 2):
            return Int64(partes[1]).map(Rota.bula) ?? .inicio
        case ("cuidando", 3) where partes[2] == "remedios":
            return Int64(partes[1]).map(Rota.cuidandoRemedios) ?? .inicio
        case ("cuidando", 2), ("cuidando", 3):
            return Int64(partes[1]).map { .cuidando($0, nome: nil) } ?? .inicio
        case ("consulta", 2), ("resultado", 2), ("interacoes", 2):
            return partes[1].count == 36 ? .resultado(partes[1]) : .inicio
        case ("perguntas", 2), ("pergunta", 2):
            return .pergunta(partes[1])
        case ("historico", 3) where partes[1] == "perguntas":
            return .pergunta(partes[2])
        default:
            return .inicio
        }
    }

    /// Link `bulai://abrir/...` de um caminho (para notificações e widget).
    public static func link(_ caminho: String) -> URL {
        URL(string: "bulai://abrir/" + caminho.trimmingCharacters(in: CharacterSet(charactersIn: "/")))!
    }

    /// `bulai://abrir/hoje` → caminho `hoje`.
    public static func doLink(_ url: URL) -> Rota? {
        guard url.scheme == "bulai" else {
            return nil
        }
        let caminho = ([url.host].compactMap { $0 } + url.pathComponents.filter { $0 != "/" })
        guard caminho.first == "abrir" else {
            return doCaminho(caminho.joined(separator: "/"))
        }
        return doCaminho(caminho.dropFirst().joined(separator: "/"))
    }
}
