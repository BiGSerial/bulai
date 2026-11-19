import Foundation

/// Onde o token do aparelho fica guardado (Keychain no app; memória nos testes).
public protocol Cofre: Sendable {
    func ler() -> String?
    func gravar(_ token: String)
    func apagar()
}

/// Cofre em memória (testes e pré-visualizações).
public final class CofreEmMemoria: Cofre, @unchecked Sendable {
    private let trava = NSLock()
    private var token: String?

    public init(_ token: String? = nil) {
        self.token = token
    }

    public func ler() -> String? { trava.withLock { token } }
    public func gravar(_ token: String) { trava.withLock { self.token = token } }
    public func apagar() { trava.withLock { token = nil } }
}

/// Tema do aplicativo: fica no aparelho (não é da conta).
public enum Tema: String, Sendable, CaseIterable, Identifiable {
    case sistema, claro, escuro

    public var id: String { rawValue }

    public var nome: String {
        switch self {
        case .sistema: "Igual ao celular"
        case .claro: "Sempre claro"
        case .escuro: "Sempre escuro"
        }
    }
}

/// Marcação feita pela notificação ou pelo widget que ainda não chegou ao servidor.
public struct MarcacaoPendente: Codable, Sendable, Equatable {
    public var chave: String
    public var remedioId: Int64
    public var agendadaPara: String
    public var status: String
    /// Hora REAL do toque: remédios de programação dinâmica contam a próxima dose a partir dela.
    public var marcadaEm: String

    public init(chave: String, remedioId: Int64, agendadaPara: String, status: String, marcadaEm: String) {
        self.chave = chave
        self.remedioId = remedioId
        self.agendadaPara = agendadaPara
        self.status = status
        self.marcadaEm = marcadaEm
    }
}

/// Estado local do aplicativo (UserDefaults do App Group, para o widget ler a agenda): conta conectada, última agenda,
/// preferências dos lembretes e marcações ainda não enviadas. O token NÃO fica aqui (vai para o ``Cofre``).
public final class PreferenciasLocais: @unchecked Sendable {
    private let padroes: UserDefaults

    public init(_ padroes: UserDefaults) {
        self.padroes = padroes
    }

    /// UserDefaults do App Group (compartilhado com o widget); sem o grupo configurado, o padrão do app.
    public convenience init(grupo: String?) {
        self.init(grupo.flatMap { UserDefaults(suiteName: $0) } ?? .standard)
    }

    private enum Chave {
        static let instalacao = "instalacao"
        static let usuario = "usuario"
        static let conta = "conta"
        static let agenda = "agenda"
        static let agendaEm = "agenda_em"
        static let marcadas = "marcadas_localmente"
        static let adiados = "adiados"
        static let agendados = "agendados"
        static let fila = "fila_de_marcacoes"
        static let avisosDesde = "avisos_desde"
        static let avisosMostrados = "avisos_mostrados"
        static let tema = "tema"
        static let insistir = "insistir"
        static let adiar = "adiar_minutos"
        static let pediuNotificacoes = "pediu_notificacoes"
        static let conectado = "conectado"
    }

    /// Identificador desta instalação (o servidor troca o token se a mesma instalação entrar de novo).
    public var instalacao: String {
        if let atual = padroes.string(forKey: Chave.instalacao) {
            return atual
        }
        let nova = UUID().uuidString.lowercased()
        padroes.set(nova, forKey: Chave.instalacao)
        return nova
    }

    public var usuario: String {
        get { padroes.string(forKey: Chave.usuario) ?? "" }
        set { padroes.set(newValue, forKey: Chave.usuario) }
    }

    /// Há conta conectada (o widget e as ações da notificação leem isto sem abrir o Keychain).
    public var conectado: Bool {
        get { padroes.bool(forKey: Chave.conectado) }
        set { padroes.set(newValue, forKey: Chave.conectado) }
    }

    /// Conta conectada, para abrir o app sem esperar a rede.
    public var conta: Usuario? {
        get { ler(Usuario.self, Chave.conta) }
        set { gravar(newValue, Chave.conta) }
    }

    public var agenda: AgendaDoAparelho? {
        get { ler(AgendaDoAparelho.self, Chave.agenda) }
        set {
            gravar(newValue, Chave.agenda)
            padroes.set(Date().timeIntervalSince1970, forKey: Chave.agendaEm)
        }
    }

    /// Doses marcadas pelo aparelho (notificação, widget) que o servidor ainda não confirmou.
    public var marcadasLocalmente: Set<String> {
        get { Set(padroes.stringArray(forKey: Chave.marcadas) ?? []) }
        set { padroes.set(Array(newValue).sorted(), forKey: Chave.marcadas) }
    }

    /// Doses adiadas: chave → novo horário.
    public var adiados: [String: Date] {
        get { datas(Chave.adiados) }
        set { gravarDatas(newValue, Chave.adiados) }
    }

    /// Lembretes já entregues ao sistema: chave → horário do lembrete (um lembrete que já passou não volta a tocar).
    public var agendados: [String: Date] {
        get { datas(Chave.agendados) }
        set { gravarDatas(newValue, Chave.agendados) }
    }

    public var filaDeMarcacoes: [MarcacaoPendente] {
        get { ler([MarcacaoPendente].self, Chave.fila) ?? [] }
        set { gravar(newValue, Chave.fila) }
    }

    /// `checked_at` da última consulta de avisos: o servidor só devolve o que chegou depois.
    public var avisosDesde: String? {
        get { padroes.string(forKey: Chave.avisosDesde) }
        set { padroes.set(newValue, forKey: Chave.avisosDesde) }
    }

    public var avisosMostrados: Set<String> {
        get { Set(padroes.stringArray(forKey: Chave.avisosMostrados) ?? []) }
        set { padroes.set(Array(newValue).sorted(), forKey: Chave.avisosMostrados) }
    }

    public var tema: Tema {
        get { Tema(rawValue: padroes.string(forKey: Chave.tema) ?? "") ?? .sistema }
        set { padroes.set(newValue.rawValue, forKey: Chave.tema) }
    }

    /// Repetir o lembrete enquanto a dose não for marcada (a alternativa do iOS ao "modo despertador").
    public var insistir: Bool {
        get { padroes.bool(forKey: Chave.insistir) }
        set { padroes.set(newValue, forKey: Chave.insistir) }
    }

    public var adiarMinutos: Int {
        get {
            let valor = padroes.integer(forKey: Chave.adiar)
            return valor == 0 ? 10 : valor
        }
        set { padroes.set(min(max(newValue, 1), 120), forKey: Chave.adiar) }
    }

    public var pediuNotificacoes: Bool {
        get { padroes.bool(forKey: Chave.pediuNotificacoes) }
        set { padroes.set(newValue, forKey: Chave.pediuNotificacoes) }
    }

    /// Saída da conta: apaga tudo o que é do paciente; a instalação, o tema e as preferências dos lembretes ficam.
    public func limparConta() {
        for chave in [Chave.usuario, Chave.conta, Chave.agenda, Chave.agendaEm, Chave.marcadas, Chave.adiados, Chave.agendados, Chave.fila, Chave.avisosDesde, Chave.avisosMostrados, Chave.conectado] {
            padroes.removeObject(forKey: chave)
        }
    }

    private func ler<T: Decodable>(_ tipo: T.Type, _ chave: String) -> T? {
        guard let dados = padroes.data(forKey: chave) else {
            return nil
        }
        return try? JSONDecoder().decode(tipo, from: dados)
    }

    private func gravar<T: Encodable>(_ valor: T?, _ chave: String) {
        guard let valor, let dados = try? JSONEncoder().encode(valor) else {
            padroes.removeObject(forKey: chave)
            return
        }
        padroes.set(dados, forKey: chave)
    }

    private func datas(_ chave: String) -> [String: Date] {
        ((padroes.dictionary(forKey: chave) as? [String: Double]) ?? [:]).mapValues { Date(timeIntervalSince1970: $0) }
    }

    private func gravarDatas(_ valor: [String: Date], _ chave: String) {
        padroes.set(valor.mapValues(\.timeIntervalSince1970), forKey: chave)
    }
}
