import Foundation

/// Dose do Início e de "Remédios do dia" (`GET /inicio`, `GET /dia`).
public struct Dose: Codable, Sendable, Hashable, Identifiable {
    public var key: String
    public var remedioId: Int64
    public var name: String
    public var dosage: String?
    public var notes: String?
    public var time: String
    @Padrao<Texto<Manha>> public var period: String = "manha"
    public var agendadaPara: String
    public var status: String
    @Padrao<Falso> public var dynamic: Bool = false
    public var ajustadaDe: String?
    public var tomadaEm: String?
    @Padrao<Verdadeiro> public var ehHoje: Bool = true
    /// Início da espera pela dose (a anterior do dia, ou o começo do dia): a barra do cartão "Próximo" enche daí até o horário.
    public var desde: String?

    public var id: String { key }

    public var feita: Bool { status == Dose.tomada || status == Dose.pulada }

    public static let pendente = "pending"
    public static let agora = "due"
    public static let atrasada = "late"
    public static let tomada = "taken"
    public static let pulada = "skipped"

    enum CodingKeys: String, CodingKey {
        case key, name, dosage, notes, time, period, status, dynamic
        case remedioId = "medication_id"
        case agendadaPara = "scheduled_for"
        case ajustadaDe = "adjusted_from"
        case tomadaEm = "taken_at"
        case ehHoje = "is_today"
        case desde = "since"
    }

    public init(key: String, remedioId: Int64, name: String, dosage: String? = nil, notes: String? = nil, time: String, period: String = "manha", agendadaPara: String, status: String, dynamic: Bool = false, ajustadaDe: String? = nil, tomadaEm: String? = nil, ehHoje: Bool = true, desde: String? = nil) {
        self.key = key
        self.remedioId = remedioId
        self.name = name
        self.dosage = dosage
        self.notes = notes
        self.time = time
        self.period = period
        self.agendadaPara = agendadaPara
        self.status = status
        self.dynamic = dynamic
        self.ajustadaDe = ajustadaDe
        self.tomadaEm = tomadaEm
        self.ehHoje = ehHoje
        self.desde = desde
    }
}

public struct Progresso: Codable, Sendable, Hashable {
    @Padrao<Zero> public var total: Int = 0
    @Padrao<Zero> public var taken: Int = 0
    @Padrao<Zero> public var done: Int = 0

    public init(total: Int = 0, taken: Int = 0, done: Int = 0) {
        self.total = total
        self.taken = taken
        self.done = done
    }
}

public enum ProgressoVazio: FornecePadrao { public static var valor: Progresso { Progresso() } }

public struct PessoaCuidada: Codable, Sendable, Hashable, Identifiable {
    public var vinculo: Int64
    public var name: String
    public var primeiroNome: String

    public var id: Int64 { vinculo }

    enum CodingKeys: String, CodingKey {
        case name
        case vinculo = "link_id"
        case primeiroNome = "first_name"
    }
}

public struct Inicio: Codable, Sendable, Hashable {
    public var primeiroNome: String
    public var data: String
    public var consentimento: Bool
    public var temRemedios: Bool
    public var proxima: Dose?
    @Padrao<ProgressoVazio> public var progress: Progresso = Progresso()
    @Padrao<Zero> public var avisosNaoLidos: Int = 0
    @Padrao<Vazio<PessoaCuidada>> public var cuidando: [PessoaCuidada] = []

    enum CodingKeys: String, CodingKey {
        case progress
        case primeiroNome = "first_name"
        case data = "date_label"
        case consentimento = "health_consent"
        case temRemedios = "has_medications"
        case proxima = "next_dose"
        case avisosNaoLidos = "unread_notices"
        case cuidando = "caring_for"
    }
}

public struct ResumoDoDia: Codable, Sendable, Hashable {
    @Padrao<Zero> public var total: Int = 0
    @Padrao<Zero> public var done: Int = 0
    @Padrao<Zero> public var taken: Int = 0
    @Padrao<Zero> public var late: Int = 0

    public init(total: Int = 0, done: Int = 0, taken: Int = 0, late: Int = 0) {
        self.total = total
        self.done = done
        self.taken = taken
        self.late = late
    }
}

public enum ResumoVazio: FornecePadrao { public static var valor: ResumoDoDia { ResumoDoDia() } }

public struct RemedioSeNecessario: Codable, Sendable, Hashable, Identifiable {
    public var remedioId: Int64
    public var name: String
    public var dosage: String?

    public var id: Int64 { remedioId }

    enum CodingKeys: String, CodingKey {
        case name, dosage
        case remedioId = "medication_id"
    }
}

/// Dia de quem a pessoa cuida (vem junto do ``Dia``).
public struct CuidandoDe: Codable, Sendable, Hashable {
    public var vinculo: Int64
    public var primeiroNome: String
    @Padrao<TextoVazio> public var banner: String = ""

    enum CodingKeys: String, CodingKey {
        case banner
        case vinculo = "link_id"
        case primeiroNome = "first_name"
    }
}

public struct Dia: Codable, Sendable, Hashable {
    public var offset: Int
    public var date: String
    public var data: String
    public var consentimento: Bool
    public var temRemedios: Bool
    @Padrao<Falso> public var temDinamico: Bool = false
    @Padrao<ResumoVazio> public var summary: ResumoDoDia = ResumoDoDia()
    @Padrao<Vazio<Dose>> public var doses: [Dose] = []
    @Padrao<Vazio<RemedioSeNecessario>> public var seNecessario: [RemedioSeNecessario] = []
    /// Preenchido quando é o dia de quem a pessoa cuida (modo cuidador).
    public var cuidandoDe: CuidandoDe?
    /// No modo cuidador, só marca doses quem recebeu a permissão "marcar e editar".
    @Padrao<Verdadeiro> public var podeMarcar: Bool = true

    enum CodingKeys: String, CodingKey {
        case offset, date, summary, doses
        case data = "date_label"
        case consentimento = "health_consent"
        case temRemedios = "has_medications"
        case temDinamico = "has_dynamic"
        case seNecessario = "as_needed"
        case cuidandoDe = "caring_for"
        case podeMarcar = "can_mark"
    }

    /// Retorno imediato do toque (só experiência): a dose e o resumo mudam na hora; a resposta do servidor, que é quem
    /// decide, substitui este cálculo logo em seguida (ou o desfaz, se recusar).
    public func comSituacao(chave: String, situacao: String) -> Dia {
        var copia = self
        copia.doses = doses.map { dose in
            var nova = dose
            if dose.key == chave {
                nova.status = situacao
            }
            return nova
        }
        copia.summary.done = copia.doses.filter(\.feita).count
        copia.summary.taken = copia.doses.filter { $0.status == Dose.tomada }.count
        copia.summary.late = copia.doses.filter { $0.status == Dose.atrasada }.count
        return copia
    }
}

public struct PedidoDeMarcacao: Encodable, Sendable {
    public var remedioId: Int64
    public var agendadaPara: String
    public var status: String?
    public var dia: Int

    enum CodingKeys: String, CodingKey {
        case status, dia
        case remedioId = "medication_id"
        case agendadaPara = "scheduled_for"
    }
}
