import Foundation

/// Atalhos do formulário "Dose e horários" (os mesmos do site): "quando tomar" e doses comuns.
public struct AtalhosDePosologia: Codable, Sendable, Hashable {
    @Padrao<Vazio<String>> public var frequencias: [String] = []
    @Padrao<Vazio<String>> public var doses: [String] = []

    public init(frequencias: [String] = [], doses: [String] = []) {
        self.frequencias = frequencias
        self.doses = doses
    }

    enum CodingKeys: String, CodingKey {
        case doses
        case frequencias = "frequencies"
    }
}

public enum AtalhosVazios: FornecePadrao { public static var valor: AtalhosDePosologia { AtalhosDePosologia() } }

public struct ResumoDosHorarios: Codable, Sendable, Hashable {
    @Padrao<Vazio<String>> public var chips: [String] = []
    public var note: String?
    @Padrao<Falso> public var missing: Bool = false

    public init(chips: [String] = [], note: String? = nil, missing: Bool = false) {
        self.chips = chips
        self.note = note
        self.missing = missing
    }
}

public enum ResumoDosHorariosVazio: FornecePadrao { public static var valor: ResumoDosHorarios { ResumoDosHorarios() } }

/// O formulário "Dose e horários" (as mesmas chaves do site).
public struct DoseEHorarios: Codable, Sendable, Hashable {
    @Padrao<TextoVazio> public var dosage: String = ""
    @Padrao<TextoVazio> public var frequency: String = ""
    public var inicio: String?
    public var fim: String?
    public var receita: Int64?
    @Padrao<TextoVazio> public var notes: String = ""
    @Padrao<Vazio<String>> public var times: [String] = []
    @Padrao<Falso> public var seNecessario: Bool = false
    @Padrao<Verdadeiro> public var lembretes: Bool = true
    public var intervaloEmHoras: Int?
    @Padrao<Texto<ModoFixo>> public var modo: String = DoseEHorarios.modoFixo

    /// Horários fixos todos os dias.
    public static let modoFixo = "fixed"
    /// Contar a partir de quando a pessoa toma ("de 8 em 8 horas").
    public static let modoIntervalo = "interval"

    public var dinamico: Bool { intervaloEmHoras != nil && modo == DoseEHorarios.modoIntervalo }

    public init(dosage: String = "", frequency: String = "", inicio: String? = nil, fim: String? = nil, receita: Int64? = nil, notes: String = "", times: [String] = [], seNecessario: Bool = false, lembretes: Bool = true, intervaloEmHoras: Int? = nil, modo: String = DoseEHorarios.modoFixo) {
        self.dosage = dosage
        self.frequency = frequency
        self.inicio = inicio
        self.fim = fim
        self.receita = receita
        self.notes = notes
        self.times = times
        self.seNecessario = seNecessario
        self.lembretes = lembretes
        self.intervaloEmHoras = intervaloEmHoras
        self.modo = modo
    }

    enum CodingKeys: String, CodingKey {
        case dosage, frequency, notes, times
        case inicio = "started_at"
        case fim = "ends_at"
        case receita = "prescription_id"
        case seNecessario = "as_needed"
        case lembretes = "reminders_enabled"
        case intervaloEmHoras = "interval_hours"
        case modo = "schedule_mode"
    }
}

public enum DoseEHorariosVazia: FornecePadrao { public static var valor: DoseEHorarios { DoseEHorarios() } }

public struct RemedioDoPerfil: Codable, Sendable, Hashable, Identifiable {
    public var id: Int64
    public var name: String
    public var remedioDoCatalogo: Int64?
    @Padrao<Verdadeiro> public var noCatalogo: Bool = true
    public var principioAtivo: String?
    public var dosage: String?
    @Padrao<TextoVazio> public var quando: String = ""
    /// Horários (ou o intervalo) e a nota, já resolvidos pelo servidor.
    @Padrao<ResumoDosHorariosVazio> public var horarios: ResumoDosHorarios = ResumoDosHorarios()
    public var frequency: String?
    public var inicio: String?
    public var fim: String?
    public var prescritor: String?
    public var notes: String?
    @Padrao<Verdadeiro> public var lembretes: Bool = true
    @Padrao<DoseEHorariosVazia> public var posologia: DoseEHorarios = DoseEHorarios()

    enum CodingKeys: String, CodingKey {
        case id, name, dosage, frequency, notes
        case remedioDoCatalogo = "medicine_id"
        case noCatalogo = "in_catalog"
        case principioAtivo = "active_ingredient"
        case quando = "when"
        case horarios = "schedule"
        case inicio = "started_label"
        case fim = "ends_label"
        case prescritor = "prescriber"
        case lembretes = "reminders_enabled"
        case posologia = "posology"
    }
}

public struct Alergia: Codable, Sendable, Hashable, Identifiable {
    public var id: Int64
    public var substance: String
    public var reaction: String?
}

public struct ReceitaDoPerfil: Codable, Sendable, Hashable, Identifiable {
    public var id: Int64
    public var prescriber: String
    public var registration: String?
    public var data: String?
    public var notes: String?
    @Padrao<Vazio<String>> public var remedios: [String] = []

    enum CodingKeys: String, CodingKey {
        case id, prescriber, registration, notes
        case data = "issued_label"
        case remedios = "medications"
    }
}

/// "Meus remédios" (`GET /perfil`): sem consentimento, as listas vêm vazias.
public struct Perfil: Codable, Sendable, Hashable {
    @Padrao<Falso> public var consentimento: Bool = false
    @Padrao<Vazio<RemedioDoPerfil>> public var remedios: [RemedioDoPerfil] = []
    @Padrao<Vazio<RemedioDoPerfil>> public var encerrados: [RemedioDoPerfil] = []
    @Padrao<Vazio<Alergia>> public var alergias: [Alergia] = []
    @Padrao<Vazio<ReceitaDoPerfil>> public var receitas: [ReceitaDoPerfil] = []
    @Padrao<AtalhosVazios> public var atalhos: AtalhosDePosologia = AtalhosDePosologia()
    public var message: String?
    public var remedioNovo: Int64?

    enum CodingKeys: String, CodingKey {
        case message
        case consentimento = "health_consent"
        case remedios = "medications"
        case encerrados = "ended"
        case alergias = "allergies"
        case receitas = "prescriptions"
        case atalhos = "posology_presets"
        case remedioNovo = "medication_id"
    }
}

public struct PedidoDeRemedio: Encodable, Sendable {
    public var remedio: Int64?
    public var name: String?

    enum CodingKeys: String, CodingKey {
        case name
        case remedio = "medicine_id"
    }
}

public struct PedidoDeDoseEHorarios: Encodable, Sendable {
    public var posology: DoseEHorarios
}

/// `preset`: atalho tocado; o servidor refaz os horários do zero.
public struct PedidoDeSugestao: Encodable, Sendable {
    public var frequency: String
    public var posology: DoseEHorarios
    public var preset: Bool
}

public struct RespostaDaSugestao: Codable, Sendable {
    public var posology: DoseEHorarios
}

public struct PedidoDeAlergia: Encodable, Sendable {
    public var substance: String
    public var reaction: String?
}

public struct PedidoDeReceitaDoPerfil: Encodable, Sendable {
    public var prescriber: String
    public var registration: String?
    public var data: String?
    public var notes: String?

    public init(prescriber: String, registration: String? = nil, data: String? = nil, notes: String? = nil) {
        self.prescriber = prescriber
        self.registration = registration
        self.data = data
        self.notes = notes
    }

    enum CodingKeys: String, CodingKey {
        case prescriber, registration, notes
        case data = "issued_at"
    }
}

/// Remédios de quem a pessoa cuida (`GET /cuidando/{id}/remedios`).
public struct RemediosDeQuemCuido: Codable, Sendable, Hashable {
    public var cuidandoDe: CuidandoDe
    @Padrao<Falso> public var podeEditar: Bool = false
    @Padrao<TextoVazio> public var intro: String = ""
    @Padrao<AtalhosVazios> public var atalhos: AtalhosDePosologia = AtalhosDePosologia()
    @Padrao<Vazio<RemedioDoPerfil>> public var remedios: [RemedioDoPerfil] = []
    public var message: String?

    enum CodingKeys: String, CodingKey {
        case intro, message
        case cuidandoDe = "caring_for"
        case podeEditar = "can_edit"
        case atalhos = "posology_presets"
        case remedios = "medications"
    }
}

// MARK: - Interações

public struct RemedioDaConsulta: Codable, Sendable, Hashable, Identifiable {
    public var id: Int64
    public var name: String
    @Padrao<TextoVazio> public var ingredients: String = ""
    @Padrao<Verdadeiro> public var identified: Bool = true
    @Padrao<Vazio<String>> public var alerts: [String] = []
}

public struct ConsultaAnterior: Codable, Sendable, Hashable, Identifiable {
    public var uuid: String
    public var status: String
    @Padrao<Vazio<String>> public var medicines: [String] = []
    @Padrao<Zero> public var pares: Int = 0
    public var criadaEm: String

    public var id: String { uuid }

    enum CodingKeys: String, CodingKey {
        case uuid, status, medicines
        case pares = "pair_count"
        case criadaEm = "created_at"
    }
}

public struct InicioDasInteracoes: Codable, Sendable, Hashable {
    @Padrao<Vazio<RemedioDaConsulta>> public var meus: [RemedioDaConsulta] = []
    @Padrao<Inteiro<Cinco>> public var max: Int = 5
    @Padrao<Vazio<ConsultaAnterior>> public var historico: [ConsultaAnterior] = []

    enum CodingKeys: String, CodingKey {
        case max
        case meus = "mine"
        case historico = "history"
    }
}

public struct PedidoDeConsulta: Encodable, Sendable {
    public var remedios: [Int64]

    enum CodingKeys: String, CodingKey {
        case remedios = "medicine_ids"
    }
}

public struct AlertaDeAlergiaNaConsulta: Codable, Sendable, Hashable {
    public var medicine: String
    public var text: String
}

public struct EvidenciaDoPar: Codable, Sendable, Hashable {
    public var title: String
    public var section: String
    public var page: Int?
    public var excerpt: String?
}

public struct ParDeRemedios: Codable, Sendable, Hashable, Identifiable {
    public var id: Int64
    public var a: String
    public var b: String
    /// major, moderate, minor, unclassified, none, inconclusive
    public var level: String
    public var tone: String
    public var phrase: String
    public var badge: String?
    public var explanation: String?
    @Padrao<Vazio<EvidenciaDoPar>> public var evidences: [EvidenciaDoPar] = []
    @Padrao<Vazio<String>> public var limitations: [String] = []
    public var sources: String?

    enum CodingKeys: String, CodingKey {
        case id, level, tone, phrase, badge, explanation, evidences, limitations, sources
        case a = "label_a"
        case b = "label_b"
    }
}

public struct ConsultaDeInteracoes: Codable, Sendable, Hashable {
    public var uuid: String
    public var status: String
    @Padrao<Falso> public var running: Bool = false
    @Padrao<Falso> public var waiting: Bool = false
    @Padrao<Falso> public var failed: Bool = false
    public var failure: String?
    @Padrao<Vazio<String>> public var medicines: [String] = []
    @Padrao<Zero> public var total: Int = 0
    @Padrao<Zero> public var done: Int = 0
    @Padrao<Vazio<AlertaDeAlergiaNaConsulta>> public var alertas: [AlertaDeAlergiaNaConsulta] = []
    @Padrao<Vazio<ParDeRemedios>> public var pairs: [ParDeRemedios] = []

    enum CodingKeys: String, CodingKey {
        case uuid, status, running, waiting, failed, failure, medicines, total, done, pairs
        case alertas = "allergy_alerts"
    }
}

// MARK: - Bulas

public struct BulaPronta: Codable, Sendable, Hashable, Identifiable {
    public var id: Int64
    public var name: String
}

public struct RemedioComBula: Codable, Sendable, Hashable, Identifiable {
    public var id: Int64
    public var name: String
    @Padrao<TextoVazio> public var ingredients: String = ""
    @Padrao<Falso> public var temBula: Bool = false

    enum CodingKeys: String, CodingKey {
        case id, name, ingredients
        case temBula = "has_leaflet"
    }
}

public struct ListaDeBulas: Codable, Sendable, Hashable {
    @Padrao<Vazio<BulaPronta>> public var available: [BulaPronta] = []
    @Padrao<Vazio<RemedioComBula>> public var results: [RemedioComBula] = []
    @Padrao<Falso> public var limited: Bool = false
}
