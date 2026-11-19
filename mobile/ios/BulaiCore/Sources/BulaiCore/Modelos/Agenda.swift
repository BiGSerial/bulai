import Foundation

/// Uma dose da agenda devolvida por `GET /agenda` (App\Services\Treatment\DeviceAgendaService): hoje e amanhã.
public struct DoseDaAgenda: Codable, Sendable, Hashable, Identifiable {
    public var key: String
    public var remedioId: Int64
    public var name: String
    public var dosage: String?
    public var time: String
    /// `scheduled_for` como veio do servidor (ISO 8601 com fuso), devolvido igual ao marcar.
    public var agendadaPara: String
    public var status: String
    @Padrao<Falso> public var remind: Bool = false
    @Padrao<Falso> public var dynamic: Bool = false

    public var id: String { key }

    /// Já marcada no servidor (tomada ou pulada).
    public var marcada: Bool { status == Dose.tomada || status == Dose.pulada }

    public var momento: Date? { Datas.iso(agendadaPara) }

    enum CodingKeys: String, CodingKey {
        case key, name, dosage, time, status, remind, dynamic
        case remedioId = "medication_id"
        case agendadaPara = "scheduled_for"
    }

    public init(key: String, remedioId: Int64, name: String, dosage: String? = nil, time: String, agendadaPara: String, status: String, remind: Bool) {
        self.key = key
        self.remedioId = remedioId
        self.name = name
        self.dosage = dosage
        self.time = time
        self.agendadaPara = agendadaPara
        self.status = status
        self.remind = remind
    }
}

public struct AgendaDoAparelho: Codable, Sendable, Hashable {
    public var geradaEm: String?
    public var timezone: String?
    @Padrao<Falso> public var lembretesLigados: Bool = false
    public var motivoDoBloqueio: String?
    @Padrao<Inteiro<Dez>> public var antecedenciaMinutos: Int = 10
    @Padrao<Inteiro<Sessenta>> public var atrasoMinutos: Int = 60
    @Padrao<Zero> public var avisosNaoLidos: Int = 0
    @Padrao<Vazio<DoseDaAgenda>> public var doses: [DoseDaAgenda] = []

    enum CodingKeys: String, CodingKey {
        case timezone, doses
        case geradaEm = "generated_at"
        case lembretesLigados = "reminders_enabled"
        case motivoDoBloqueio = "reminders_blocked_reason"
        case antecedenciaMinutos = "lead_minutes"
        case atrasoMinutos = "late_after_minutes"
        case avisosNaoLidos = "unread_notices"
    }

    public init(lembretesLigados: Bool, atrasoMinutos: Int = 60, doses: [DoseDaAgenda]) {
        self.lembretesLigados = lembretesLigados
        self.atrasoMinutos = atrasoMinutos
        self.doses = doses
    }

    /// Doses em ordem de horário (a agenda pode vir agrupada por remédio).
    public var dosesEmOrdem: [DoseDaAgenda] {
        doses.sorted { ($0.momento ?? .distantFuture) < ($1.momento ?? .distantFuture) }
    }
}

/// `POST /doses`: marcação feita pela notificação ou pelo widget, com o horário REAL do toque (`recorded_at`).
public struct PedidoDeMarcacaoDoAparelho: Encodable, Sendable, Equatable {
    public var remedioId: Int64
    public var agendadaPara: String
    public var status: String
    public var marcadaEm: String

    enum CodingKeys: String, CodingKey {
        case status
        case remedioId = "medication_id"
        case agendadaPara = "scheduled_for"
        case marcadaEm = "recorded_at"
    }
}
