import Foundation

// Perguntar, histórico de perguntas, busca no catálogo, receita por foto e bula humanizada.

/// Pedaço de texto da resposta: o servidor já marca o que vai em negrito (remédios, alertas, o que é crítico).
public struct Trecho: Codable, Sendable, Hashable {
    public var text: String
    @Padrao<Falso> public var bold: Bool = false

    public init(text: String, bold: Bool = false) {
        self.text = text
        self.bold = bold
    }
}

public struct ItemDoAlerta: Codable, Sendable, Hashable {
    public var text: String
    public var severity: String?
    public var gravidade: String?

    enum CodingKeys: String, CodingKey {
        case text, severity
        case gravidade = "severity_label"
    }
}

/// Alergia registrada (vermelho, com aviso em destaque) ou interação (amarelo), do motor estruturado.
public struct Alerta: Codable, Sendable, Hashable {
    public var type: String
    @Padrao<Vazio<ItemDoAlerta>> public var items: [ItemDoAlerta] = []
    public var warning: String?

    public var alergia: Bool { type == "alergia" }
}

public struct RemedioCitado: Codable, Sendable, Hashable, Identifiable {
    public var id: Int64
    public var name: String
    @Padrao<Verdadeiro> public var temBula: Bool = true

    enum CodingKeys: String, CodingKey {
        case id, name
        case temBula = "has_leaflet"
    }
}

public struct RemedioSimples: Codable, Sendable, Hashable, Identifiable {
    public var id: Int64
    public var name: String
}

public struct Correcao: Codable, Sendable, Hashable, Identifiable {
    public var id: Int64
    public var name: String
    @Padrao<TextoVazio> public var escrito: String = ""

    enum CodingKeys: String, CodingKey {
        case id, name
        case escrito = "matched_text"
    }
}

public struct Fonte: Codable, Sendable, Hashable {
    public var titulo: String
    public var section: String?
    public var pagina: Int?
    @Padrao<TextoVazio> public var excerpt: String = ""
    public var processed: String?

    enum CodingKeys: String, CodingKey {
        case section, excerpt, processed
        case titulo = "document_title"
        case pagina = "page_start"
    }
}

public struct Interacao: Codable, Sendable, Hashable {
    public var medicine: String
    public var severity: String
    public var gravidade: String?
    @Padrao<Falso> public var divergent: Bool = false

    enum CodingKeys: String, CodingKey {
        case medicine, severity, divergent
        case gravidade = "severity_label"
    }
}

public struct AlertaDeAlergia: Codable, Sendable, Hashable {
    public var allergy: String
    public var ingredient: String
    @Padrao<TextoVazio> public var via: String = ""
}

public struct Posologia: Codable, Sendable, Hashable {
    public var dosage: String?
    public var frequency: String?
    public var prescriber: String?
}

/// Painel "No seu tratamento": o remédio cruzado com o que a pessoa usa e com as alergias dela.
public struct NoTratamento: Codable, Sendable, Hashable {
    public var medicine: String
    @Padrao<Falso> public var consented: Bool = false
    @Padrao<Falso> public var emUso: Bool = false
    public var posology: Posologia?
    @Padrao<Zero> public var totalDeRemedios: Int = 0
    public var maiorGravidade: String?
    @Padrao<Vazio<AlertaDeAlergia>> public var alergias: [AlertaDeAlergia] = []
    @Padrao<Vazio<Interacao>> public var interactions: [Interacao] = []
    @Padrao<Vazio<String>> public var unresolved: [String] = []

    enum CodingKeys: String, CodingKey {
        case medicine, consented, posology, interactions, unresolved
        case emUso = "in_use"
        case totalDeRemedios = "medications_count"
        case maiorGravidade = "highest_severity"
        case alergias = "allergy_alerts"
    }
}

/// Telefone de ajuda da resposta (crise, superdose, sintoma grave): vira botão de ligar.
public struct TelefoneDeAjuda: Codable, Sendable, Hashable {
    public var tel: String
    public var number: String
    public var name: String
    @Padrao<TextoVazio> public var detail: String = ""
}

/// Bloco de ajuda identificável pela situação (o mesmo do site): título, texto curto, tom (`acolhimento`, `urgencia`,
/// `cuidado`) e os telefones. Sem título: "Precisa de ajuda agora? Toque para ligar."
public struct GrupoDeAjuda: Codable, Sendable, Hashable {
    public var title: String?
    public var text: String?
    @Padrao<Texto<Urgencia>> public var tone: String = "urgencia"
    @Padrao<Vazio<TelefoneDeAjuda>> public var telefones: [TelefoneDeAjuda] = []

    enum CodingKeys: String, CodingKey {
        case title, text, tone
        case telefones = "lines"
    }
}

public struct Resposta: Codable, Sendable, Hashable {
    public var uuid: String?
    @Padrao<TextoVazio> public var question: String = ""
    public var status: String
    @Padrao<Texto<Neutro>> public var tone: String = "neutral"
    public var urgency: String?
    @Padrao<Vazio<[Trecho]>> public var paragraphs: [[Trecho]] = []
    public var alert: Alerta?
    public var source: String?
    @Padrao<Falso> public var podeOuvir: Bool = false
    @Padrao<Falso> public var crisis: Bool = false
    @Padrao<Vazio<GrupoDeAjuda>> public var ajuda: [GrupoDeAjuda] = []
    @Padrao<Vazio<String>> public var suggestions: [String] = []
    /// Respostas rápidas (pedido vago de ajuda): cada uma é enviada como a próxima mensagem.
    @Padrao<Vazio<String>> public var respostasRapidas: [String] = []
    @Padrao<Vazio<String>> public var outrosRemedios: [String] = []
    @Padrao<Vazio<RemedioSimples>> public var guesses: [RemedioSimples] = []
    @Padrao<Vazio<RemedioSimples>> public var meusRemedios: [RemedioSimples] = []
    @Padrao<Vazio<Correcao>> public var corrections: [Correcao] = []
    @Padrao<Vazio<RemedioCitado>> public var medicines: [RemedioCitado] = []
    @Padrao<Vazio<Fonte>> public var sources: [Fonte] = []
    @Padrao<Vazio<NoTratamento>> public var treatments: [NoTratamento] = []
    @Padrao<Verdadeiro> public var liberaFixos: Bool = true
    public var perguntadaEm: String?

    public static let respondida = "answered"
    public static let precisaDeInformacao = "needs_info"
    public static let semRemedio = "no_medicine"
    public static let bloqueada = "blocked"
    public static let apoio = "support"

    /// Respondida com base nas bulas (a procedência fica em "Fontes utilizadas").
    public var foiRespondida: Bool { status == Resposta.respondida || status == Resposta.precisaDeInformacao }

    /// Texto corrido (para "Ouvir").
    public var textoCorrido: String {
        paragraphs.map { $0.map(\.text).joined() }.joined(separator: "\n\n")
    }

    enum CodingKeys: String, CodingKey {
        case uuid, question, status, tone, urgency, paragraphs, alert, source, crisis, suggestions, guesses, corrections, medicines, sources, treatments
        case podeOuvir = "can_listen"
        case ajuda = "help_groups"
        case respostasRapidas = "quick_replies"
        case outrosRemedios = "other_medicines"
        case meusRemedios = "my_medicines"
        case liberaFixos = "releases_fixed"
        case perguntadaEm = "asked_at"
    }
}

public struct RespostaDaPergunta: Codable, Sendable {
    public var answer: Resposta
}

public struct PedidoDePergunta: Encodable, Sendable, Equatable {
    public var question: String
    public var fixed: [Int64]
    public var removed: [Int64]
    public var turns: [String]

    public init(question: String, fixed: [Int64] = [], removed: [Int64] = [], turns: [String] = []) {
        self.question = question
        self.fixed = fixed
        self.removed = removed
        self.turns = turns
    }
}

public struct InicioDaConversa: Codable, Sendable, Hashable {
    @Padrao<Vazio<String>> public var examples: [String] = []
    @Padrao<Vazio<String>> public var emUso: [String] = []
    @Padrao<Inteiro<Cinco>> public var minimo: Int = 5
    @Padrao<Inteiro<Quinhentos>> public var maximo: Int = 500
    @Padrao<Inteiro<Noventa>> public var retencao: Int = 90
    public var preview: NoTratamento?

    enum CodingKeys: String, CodingKey {
        case examples, preview
        case emUso = "in_use"
        case minimo = "min_length"
        case maximo = "max_length"
        case retencao = "retention_days"
    }
}

public struct PerguntaAnterior: Codable, Sendable, Hashable, Identifiable {
    public var uuid: String
    public var question: String
    public var medicines: String?
    public var status: String
    public var em: String

    public var id: String { uuid }

    enum CodingKeys: String, CodingKey {
        case uuid, question, medicines, status
        case em = "asked_at"
    }
}

public struct HistoricoDePerguntas: Codable, Sendable, Hashable {
    @Padrao<Inteiro<Noventa>> public var retencao: Int = 90
    @Padrao<Vazio<PerguntaAnterior>> public var items: [PerguntaAnterior] = []
    public var proximaPagina: Int?

    enum CodingKeys: String, CodingKey {
        case items
        case retencao = "retention_days"
        case proximaPagina = "next_page"
    }
}

public struct RemedioDoCatalogo: Codable, Sendable, Hashable, Identifiable {
    public var id: Int64
    public var name: String
    @Padrao<TextoVazio> public var ingredients: String = ""
    public var holder: String?
    @Padrao<Falso> public var inactive: Bool = false
}

public struct BuscaDeRemedios: Codable, Sendable, Hashable {
    @Padrao<Vazio<RemedioDoCatalogo>> public var items: [RemedioDoCatalogo] = []
}

// MARK: - Receita por foto

public struct Candidato: Codable, Sendable, Hashable, Identifiable {
    public var id: Int64
    public var name: String
    @Padrao<TextoVazio> public var ingredients: String = ""
}

/// Horários sugeridos pelo servidor para um "quando tomar".
public struct Horarios: Codable, Sendable, Hashable {
    @Padrao<Vazio<String>> public var times: [String] = []
    @Padrao<Falso> public var seNecessario: Bool = false
    public var intervaloHoras: Int?
    @Padrao<Texto<ModoFixo>> public var modo: String = "fixed"

    enum CodingKeys: String, CodingKey {
        case times
        case seNecessario = "as_needed"
        case intervaloHoras = "interval_hours"
        case modo = "schedule_mode"
    }
}

public struct ItemDaReceita: Codable, Sendable, Hashable {
    public var nomeEscrito: String
    public var remedioId: Int64?
    public var remedioNome: String?
    @Padrao<TextoVazio> public var dosage: String = ""
    @Padrao<TextoVazio> public var frequency: String = ""
    @Padrao<TextoVazio> public var duration: String = ""
    @Padrao<TextoVazio> public var notes: String = ""
    @Padrao<Um> public var confidence: Double = 1
    @Padrao<Vazio<Candidato>> public var candidates: [Candidato] = []
    @Padrao<Falso> public var skip: Bool = false
    @Padrao<Vazio<String>> public var times: [String] = []
    @Padrao<Falso> public var seNecessario: Bool = false
    public var intervaloHoras: Int?
    @Padrao<Texto<ModoFixo>> public var modo: String = ItemDaReceita.modoFixo

    public static let modoFixo = "fixed"
    public static let modoIntervalo = "interval"

    public var incerto: Bool { confidence < 0.6 }
    public var contaDoUltimo: Bool { !seNecessario && intervaloHoras != nil && modo == ItemDaReceita.modoIntervalo }

    public func comHorarios(_ horarios: Horarios) -> ItemDaReceita {
        var copia = self
        copia.times = horarios.times
        copia.seNecessario = horarios.seNecessario
        copia.intervaloHoras = horarios.intervaloHoras
        copia.modo = horarios.modo
        return copia
    }

    enum CodingKeys: String, CodingKey {
        case dosage, frequency, duration, notes, confidence, candidates, skip, times
        case nomeEscrito = "name_as_written"
        case remedioId = "medicine_id"
        case remedioNome = "medicine_name"
        case seNecessario = "as_needed"
        case intervaloHoras = "interval_hours"
        case modo = "schedule_mode"
    }

    public init(nomeEscrito: String, remedioId: Int64? = nil, remedioNome: String? = nil, dosage: String = "", frequency: String = "", times: [String] = []) {
        self.nomeEscrito = nomeEscrito
        self.remedioId = remedioId
        self.remedioNome = remedioNome
        self.dosage = dosage
        self.frequency = frequency
        self.times = times
    }
}

public struct CabecalhoDaReceita: Codable, Sendable, Hashable {
    @Padrao<TextoVazio> public var prescriber: String = ""
    @Padrao<TextoVazio> public var registration: String = ""
    public var date: String?

    public init(prescriber: String = "", registration: String = "", date: String? = nil) {
        self.prescriber = prescriber
        self.registration = registration
        self.date = date
    }
}

public struct Receita: Codable, Sendable, Hashable {
    public var id: Int64
    public var status: String
    @Padrao<Falso> public var reading: Bool = false
    public var codigoDoErro: String?
    public var mensagemDoErro: String?
    public var legibility: String?
    @Padrao<Falso> public var textoIgnorado: Bool = false
    public var header: CabecalhoDaReceita?
    @Padrao<Vazio<ItemDaReceita>> public var items: [ItemDaReceita] = []

    public static let emRevisao = "REVIEW"
    public static let confirmada = "CONFIRMED"
    public static let falhou = "FAILED"

    enum CodingKeys: String, CodingKey {
        case id, status, reading, legibility, header, items
        case codigoDoErro = "error_code"
        case mensagemDoErro = "error_message"
        case textoIgnorado = "ignored_text"
    }
}

public struct RespostaDaReceita: Codable, Sendable {
    public var prescription: Receita
}

public struct RespostaDeHorarios: Codable, Sendable {
    public var schedule: Horarios
}

public struct PedidoDeHorarios: Encodable, Sendable {
    public var frequency: String
}

public struct PedidoDeConfirmacao: Encodable, Sendable {
    public var header: CabecalhoDaReceita
    public var items: [ItemDaReceita]
}

public struct ResultadoDaConfirmacao: Codable, Sendable {
    @Padrao<Zero> public var confirmed: Int = 0
    @Padrao<Zero> public var unmatched: Int = 0
    public var message: String
}

// MARK: - Bula humanizada

public struct RemedioDaBula: Codable, Sendable, Hashable {
    public var id: Int64
    public var name: String
    public var ingredient: String?
    public var holder: String?
}

public struct SecaoDaBula: Codable, Sendable, Hashable, Identifiable {
    public var key: String
    public var label: String
    @Padrao<Texto<Marca>> public var tone: String = "brand"
    @Padrao<Vazio<String>> public var claims: [String] = []
    @Padrao<Vazio<String>> public var sources: [String] = []

    public var id: String { key }
}

public struct Risco: Codable, Sendable, Hashable {
    public var text: String
    @Padrao<Texto<Atencao>> public var tone: String = "warning"
}

public struct Bula: Codable, Sendable, Hashable {
    public var medicine: RemedioDaBula
    public var state: String
    public var failure: String?
    public var cover: SecaoDaBula?
    @Padrao<Vazio<SecaoDaBula>> public var sections: [SecaoDaBula] = []
    @Padrao<Vazio<String>> public var missing: [String] = []
    public var prepared: String?
    public var risk: Risco?
    public var treatment: NoTratamento?

    public static let carregando = "carregando"
    public static let pronta = "pronto"
    public static let semBula = "sem_bula"
    public static let naoIdentificado = "nao_identificado"
    public static let limite = "limite"

    public var preparando: Bool { state == Bula.carregando }

    /// Texto para "Ouvir": cada seção com as primeiras afirmações.
    public var textoCorrido: String {
        ([cover].compactMap { $0 } + sections)
            .map { $0.label + ". " + $0.claims.prefix(3).joined(separator: " ") }
            .joined(separator: " ")
    }
}

public struct RespostaDaBula: Codable, Sendable {
    public var leaflet: Bula
}
