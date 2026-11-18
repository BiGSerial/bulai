package br.com.vixforge.bulai.dados.api

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

// Perguntar, histórico de perguntas, busca no catálogo e receita por foto (app/Http/Controllers/Api/Mobile).

/** Pedaço de texto da resposta: o servidor já marca o que vai em negrito (remédios, alertas, o que é crítico). */
@Serializable
data class Trecho(val text: String, val bold: Boolean = false)

@Serializable
data class ItemDoAlerta(val text: String, val severity: String? = null, @SerialName("severity_label") val gravidade: String? = null)

/** Alergia registrada (vermelho, com aviso em destaque) ou interação (amarelo), do motor estruturado. */
@Serializable
data class Alerta(val type: String, val items: List<ItemDoAlerta> = emptyList(), val warning: String? = null) {
    val alergia: Boolean get() = type == "alergia"
}

@Serializable
data class RemedioCitado(val id: Long, val name: String, @SerialName("has_leaflet") val temBula: Boolean = true)

@Serializable
data class RemedioSimples(val id: Long, val name: String)

@Serializable
data class Correcao(val id: Long, val name: String, @SerialName("matched_text") val escrito: String = "")

@Serializable
data class Fonte(
    @SerialName("document_title") val titulo: String,
    val section: String? = null,
    @SerialName("page_start") val pagina: Int? = null,
    val excerpt: String = "",
    val processed: String? = null,
)

@Serializable
data class Interacao(val medicine: String, val severity: String, @SerialName("severity_label") val gravidade: String? = null, val divergent: Boolean = false)

@Serializable
data class AlertaDeAlergia(val allergy: String, val ingredient: String, val via: String = "")

@Serializable
data class Posologia(val dosage: String? = null, val frequency: String? = null, val prescriber: String? = null)

/** Painel "No seu tratamento": o remédio cruzado com o que a pessoa usa e com as alergias dela. */
@Serializable
data class NoTratamento(
    val medicine: String,
    val consented: Boolean = false,
    @SerialName("in_use") val emUso: Boolean = false,
    val posology: Posologia? = null,
    @SerialName("medications_count") val totalDeRemedios: Int = 0,
    @SerialName("highest_severity") val maiorGravidade: String? = null,
    @SerialName("allergy_alerts") val alergias: List<AlertaDeAlergia> = emptyList(),
    val interactions: List<Interacao> = emptyList(),
    val unresolved: List<String> = emptyList(),
)

@Serializable
data class Resposta(
    val uuid: String? = null,
    val question: String = "",
    val status: String,
    val tone: String = "neutral",
    val urgency: String? = null,
    val paragraphs: List<List<Trecho>> = emptyList(),
    val alert: Alerta? = null,
    val source: String? = null,
    @SerialName("can_listen") val podeOuvir: Boolean = false,
    val crisis: Boolean = false,
    val suggestions: List<String> = emptyList(),
    @SerialName("other_medicines") val outrosRemedios: List<String> = emptyList(),
    val guesses: List<RemedioSimples> = emptyList(),
    @SerialName("my_medicines") val meusRemedios: List<RemedioSimples> = emptyList(),
    val corrections: List<Correcao> = emptyList(),
    val medicines: List<RemedioCitado> = emptyList(),
    val sources: List<Fonte> = emptyList(),
    val treatments: List<NoTratamento> = emptyList(),
    @SerialName("releases_fixed") val liberaFixos: Boolean = true,
    @SerialName("asked_at") val perguntadaEm: String? = null,
) {
    /** Respondida com base nas bulas (a procedência fica em "Fontes utilizadas"). */
    val respondida: Boolean get() = status == RESPONDIDA || status == PRECISA_DE_INFORMACAO

    /** Texto corrido (para "Ouvir"). */
    val textoCorrido: String get() = paragraphs.joinToString("\n\n") { p -> p.joinToString("") { it.text } }

    companion object {
        const val RESPONDIDA = "answered"
        const val PRECISA_DE_INFORMACAO = "needs_info"
        const val SEM_REMEDIO = "no_medicine"
        const val BLOQUEADA = "blocked"
    }
}

@Serializable
data class RespostaDaPergunta(val answer: Resposta)

@Serializable
data class PedidoDePergunta(
    val question: String,
    val fixed: List<Long> = emptyList(),
    val removed: List<Long> = emptyList(),
    val turns: List<String> = emptyList(),
)

@Serializable
data class InicioDaConversa(
    val examples: List<String> = emptyList(),
    @SerialName("in_use") val emUso: List<String> = emptyList(),
    @SerialName("min_length") val minimo: Int = 5,
    @SerialName("max_length") val maximo: Int = 500,
    @SerialName("retention_days") val retencao: Int = 90,
    val preview: NoTratamento? = null,
)

@Serializable
data class PerguntaAnterior(
    val uuid: String,
    val question: String,
    val medicines: String? = null,
    val status: String,
    @SerialName("asked_at") val em: String,
)

@Serializable
data class HistoricoDePerguntas(
    @SerialName("retention_days") val retencao: Int = 90,
    val items: List<PerguntaAnterior> = emptyList(),
    @SerialName("next_page") val proximaPagina: Int? = null,
)

@Serializable
data class RemedioDoCatalogo(val id: Long, val name: String, val ingredients: String = "", val holder: String? = null, val inactive: Boolean = false)

@Serializable
data class BuscaDeRemedios(val items: List<RemedioDoCatalogo> = emptyList())

// Receita por foto

@Serializable
data class Candidato(val id: Long, val name: String, val ingredients: String = "")

@Serializable
data class ItemDaReceita(
    @SerialName("name_as_written") val nomeEscrito: String,
    @SerialName("medicine_id") val remedioId: Long? = null,
    @SerialName("medicine_name") val remedioNome: String? = null,
    val dosage: String = "",
    val frequency: String = "",
    val duration: String = "",
    val notes: String = "",
    val confidence: Double = 1.0,
    val candidates: List<Candidato> = emptyList(),
    val skip: Boolean = false,
    val times: List<String> = emptyList(),
    @SerialName("as_needed") val seNecessario: Boolean = false,
    @SerialName("interval_hours") val intervaloHoras: Int? = null,
    @SerialName("schedule_mode") val modo: String = MODO_FIXO,
) {
    val incerto: Boolean get() = confidence < 0.6
    val contaDoUltimo: Boolean get() = !seNecessario && intervaloHoras != null && modo == MODO_INTERVALO

    fun comHorarios(h: Horarios) = copy(times = h.times, seNecessario = h.seNecessario, intervaloHoras = h.intervaloHoras, modo = h.modo)

    companion object {
        const val MODO_FIXO = "fixed"
        const val MODO_INTERVALO = "interval"
    }
}

@Serializable
data class CabecalhoDaReceita(val prescriber: String = "", val registration: String = "", val date: String? = null)

@Serializable
data class Receita(
    val id: Long,
    val status: String,
    val reading: Boolean = false,
    @SerialName("error_code") val codigoDoErro: String? = null,
    @SerialName("error_message") val mensagemDoErro: String? = null,
    val legibility: String? = null,
    @SerialName("ignored_text") val textoIgnorado: Boolean = false,
    val header: CabecalhoDaReceita? = null,
    val items: List<ItemDaReceita> = emptyList(),
) {
    companion object {
        const val EM_REVISAO = "REVIEW"
        const val CONFIRMADA = "CONFIRMED"
        const val FALHOU = "FAILED"
    }
}

@Serializable
data class RespostaDaReceita(val prescription: Receita)

@Serializable
data class Horarios(
    val times: List<String> = emptyList(),
    @SerialName("as_needed") val seNecessario: Boolean = false,
    @SerialName("interval_hours") val intervaloHoras: Int? = null,
    @SerialName("schedule_mode") val modo: String = ItemDaReceita.MODO_FIXO,
)

@Serializable
data class RespostaDeHorarios(val schedule: Horarios)

@Serializable
data class PedidoDeHorarios(val frequency: String)

@Serializable
data class PedidoDeConfirmacao(val header: CabecalhoDaReceita, val items: List<ItemDaReceita>)

@Serializable
data class ResultadoDaConfirmacao(val confirmed: Int = 0, val unmatched: Int = 0, val message: String)

// Bula humanizada

@Serializable
data class RemedioDaBula(val id: Long, val name: String, val ingredient: String? = null, val holder: String? = null)

@Serializable
data class SecaoDaBula(val key: String, val label: String, val tone: String = "brand", val claims: List<String> = emptyList(), val sources: List<String> = emptyList())

@Serializable
data class Risco(val text: String, val tone: String = "warning")

@Serializable
data class Bula(
    val medicine: RemedioDaBula,
    val state: String,
    val failure: String? = null,
    val cover: SecaoDaBula? = null,
    val sections: List<SecaoDaBula> = emptyList(),
    val missing: List<String> = emptyList(),
    val prepared: String? = null,
    val risk: Risco? = null,
    val treatment: NoTratamento? = null,
) {
    val preparando: Boolean get() = state == CARREGANDO

    /** Texto para "Ouvir": cada seção com as primeiras afirmações. */
    val textoCorrido: String
        get() = listOfNotNull(cover, *sections.toTypedArray()).joinToString(" ") { secao -> secao.label + ". " + secao.claims.take(3).joinToString(" ") }

    companion object {
        const val CARREGANDO = "carregando"
        const val PRONTA = "pronto"
        const val SEM_BULA = "sem_bula"
        const val NAO_IDENTIFICADO = "nao_identificado"
        const val LIMITE = "limite"
    }
}

@Serializable
data class RespostaDaBula(val leaflet: Bula)
