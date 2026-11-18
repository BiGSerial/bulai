package br.com.vixforge.bulai.dados.api

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

/** "Meus remédios" (`GET /perfil`): sem consentimento, as listas vêm vazias. */
@Serializable
data class Perfil(
    @SerialName("health_consent") val consentimento: Boolean = false,
    @SerialName("medications") val remedios: List<RemedioDoPerfil> = emptyList(),
    @SerialName("ended") val encerrados: List<RemedioDoPerfil> = emptyList(),
    @SerialName("allergies") val alergias: List<Alergia> = emptyList(),
    @SerialName("prescriptions") val receitas: List<ReceitaDoPerfil> = emptyList(),
    val message: String? = null,
    @SerialName("medication_id") val remedioNovo: Long? = null,
)

@Serializable
data class RemedioDoPerfil(
    val id: Long,
    val name: String,
    @SerialName("medicine_id") val remedioDoCatalogo: Long? = null,
    @SerialName("in_catalog") val noCatalogo: Boolean = true,
    @SerialName("active_ingredient") val principioAtivo: String? = null,
    val dosage: String? = null,
    @SerialName("when") val quando: String = "",
    /** Horários (ou o intervalo) e a nota, já resolvidos pelo servidor. */
    @SerialName("schedule") val horarios: ResumoDosHorarios = ResumoDosHorarios(),
    val frequency: String? = null,
    @SerialName("started_label") val inicio: String? = null,
    @SerialName("ends_label") val fim: String? = null,
    @SerialName("prescriber") val prescritor: String? = null,
    val notes: String? = null,
    @SerialName("reminders_enabled") val lembretes: Boolean = true,
    @SerialName("posology") val posologia: DoseEHorarios = DoseEHorarios(),
)

@Serializable
data class ResumoDosHorarios(val chips: List<String> = emptyList(), val note: String? = null, val missing: Boolean = false)

/** O formulário "Dose e horários" (as mesmas chaves do site). */
@Serializable
data class DoseEHorarios(
    val dosage: String = "",
    val frequency: String = "",
    @SerialName("started_at") val inicio: String? = null,
    @SerialName("ends_at") val fim: String? = null,
    @SerialName("prescription_id") val receita: Long? = null,
    val notes: String = "",
    val times: List<String> = emptyList(),
    @SerialName("as_needed") val seNecessario: Boolean = false,
    @SerialName("reminders_enabled") val lembretes: Boolean = true,
    @SerialName("interval_hours") val intervaloEmHoras: Int? = null,
    @SerialName("schedule_mode") val modo: String = MODO_FIXO,
)

/** Horários fixos todos os dias. */
const val MODO_FIXO = "fixed"

/** Contar a partir de quando a pessoa toma ("de 8 em 8 horas"). */
const val MODO_INTERVALO = "interval"

@Serializable
data class Alergia(val id: Long, val substance: String, val reaction: String? = null)

@Serializable
data class ReceitaDoPerfil(
    val id: Long,
    val prescriber: String,
    val registration: String? = null,
    @SerialName("issued_label") val data: String? = null,
    val notes: String? = null,
    @SerialName("medications") val remedios: List<String> = emptyList(),
)

@Serializable
data class PedidoDeRemedio(@SerialName("medicine_id") val remedio: Long? = null, val name: String? = null)

@Serializable
data class PedidoDeDoseEHorarios(val posology: DoseEHorarios)

@Serializable
data class PedidoDeSugestao(val frequency: String, val posology: DoseEHorarios)

@Serializable
data class RespostaDaSugestao(val posology: DoseEHorarios)

@Serializable
data class PedidoDeAlergia(val substance: String, val reaction: String? = null)

@Serializable
data class PedidoDeReceitaDoPerfil(val prescriber: String, val registration: String? = null, @SerialName("issued_at") val data: String? = null, val notes: String? = null)

/** Remédios de quem a pessoa cuida (`GET /cuidando/{id}/remedios`). */
@Serializable
data class RemediosDeQuemCuido(
    @SerialName("caring_for") val cuidandoDe: CuidandoDe,
    @SerialName("can_edit") val podeEditar: Boolean = false,
    val intro: String = "",
    @SerialName("medications") val remedios: List<RemedioDoPerfil> = emptyList(),
    val message: String? = null,
)

// ---- Interações ----

@Serializable
data class InicioDasInteracoes(
    @SerialName("mine") val meus: List<RemedioDaConsulta> = emptyList(),
    val max: Int = 5,
    @SerialName("history") val historico: List<ConsultaAnterior> = emptyList(),
)

@Serializable
data class RemedioDaConsulta(val id: Long, val name: String, val ingredients: String = "", val identified: Boolean = true, val alerts: List<String> = emptyList())

@Serializable
data class ConsultaAnterior(val uuid: String, val status: String, val medicines: List<String> = emptyList(), @SerialName("pair_count") val pares: Int = 0, @SerialName("created_at") val criadaEm: String)

@Serializable
data class PedidoDeConsulta(@SerialName("medicine_ids") val remedios: List<Long>)

@Serializable
data class ConsultaDeInteracoes(
    val uuid: String,
    val status: String,
    val running: Boolean = false,
    val waiting: Boolean = false,
    val failed: Boolean = false,
    val failure: String? = null,
    val medicines: List<String> = emptyList(),
    val total: Int = 0,
    val done: Int = 0,
    @SerialName("allergy_alerts") val alertas: List<AlertaDeAlergiaNaConsulta> = emptyList(),
    val pairs: List<ParDeRemedios> = emptyList(),
)

@Serializable
data class AlertaDeAlergiaNaConsulta(val medicine: String, val text: String)

@Serializable
data class ParDeRemedios(
    val id: Long,
    @SerialName("label_a") val a: String,
    @SerialName("label_b") val b: String,
    /** major, moderate, minor, unclassified, none, inconclusive */
    val level: String,
    val tone: String,
    val phrase: String,
    val badge: String? = null,
    val explanation: String? = null,
    val evidences: List<EvidenciaDoPar> = emptyList(),
    val limitations: List<String> = emptyList(),
    val sources: String? = null,
)

@Serializable
data class EvidenciaDoPar(val title: String, val section: String, val page: Int? = null, val excerpt: String? = null)

// ---- Bulas ----

@Serializable
data class ListaDeBulas(
    val available: List<BulaPronta> = emptyList(),
    val results: List<RemedioComBula> = emptyList(),
    val limited: Boolean = false,
)

@Serializable
data class BulaPronta(val id: Long, val name: String)

@Serializable
data class RemedioComBula(val id: Long, val name: String, val ingredients: String = "", @SerialName("has_leaflet") val temBula: Boolean = false)
