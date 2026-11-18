package br.com.vixforge.bulai.dados.api

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonObject

// Formato das respostas de /api/mobile/v1 (app/Http/Controllers/Api/Mobile, docs/app-android.md).

@Serializable
data class Usuario(
    val id: Long,
    val name: String,
    @SerialName("first_name") val primeiroNome: String,
    val email: String,
    @SerialName("must_change_password") val precisaTrocarSenha: Boolean,
    @SerialName("health_consent") val consentimento: Boolean,
    val timezone: String,
    @SerialName("is_admin") val admin: Boolean = false,
)

@Serializable
data class PedidoDeEntrada(
    val email: String,
    val password: String,
    @SerialName("installation_id") val instalacao: String,
    @SerialName("device_name") val aparelho: String,
    @SerialName("app_version") val versao: String,
)

@Serializable
data class RespostaDeEntrada(val token: String, val user: Usuario)

@Serializable
data class RespostaDoUsuario(val user: Usuario)

@Serializable
data class PedidoDeCadastro(
    val name: String,
    val email: String,
    val password: String,
    @SerialName("password_confirmation") val confirmacao: String,
    val terms: Boolean,
)

@Serializable
data class PedidoDeEmail(val email: String)

@Serializable
data class PedidoDeNovaSenha(val password: String, @SerialName("password_confirmation") val confirmacao: String)

@Serializable
data class Mensagem(val message: String)

@Serializable
data class Dose(
    val key: String,
    @SerialName("medication_id") val remedioId: Long,
    val name: String,
    val dosage: String? = null,
    val notes: String? = null,
    val time: String,
    val period: String = "manha",
    @SerialName("scheduled_for") val agendadaPara: String,
    val status: String,
    val dynamic: Boolean = false,
    @SerialName("adjusted_from") val ajustadaDe: String? = null,
    @SerialName("taken_at") val tomadaEm: String? = null,
    @SerialName("is_today") val ehHoje: Boolean = true,
) {
    val feita: Boolean get() = status == TOMADA || status == PULADA

    companion object {
        const val PENDENTE = "pending"
        const val AGORA = "due"
        const val ATRASADA = "late"
        const val TOMADA = "taken"
        const val PULADA = "skipped"
    }
}

@Serializable
data class Progresso(val total: Int = 0, val taken: Int = 0, val done: Int = 0)

@Serializable
data class PessoaCuidada(@SerialName("link_id") val vinculo: Long, val name: String, @SerialName("first_name") val primeiroNome: String)

@Serializable
data class Inicio(
    @SerialName("first_name") val primeiroNome: String,
    @SerialName("date_label") val data: String,
    @SerialName("health_consent") val consentimento: Boolean,
    @SerialName("has_medications") val temRemedios: Boolean,
    @SerialName("next_dose") val proxima: Dose? = null,
    val progress: Progresso = Progresso(),
    @SerialName("unread_notices") val avisosNaoLidos: Int = 0,
    @SerialName("caring_for") val cuidando: List<PessoaCuidada> = emptyList(),
)

@Serializable
data class ResumoDoDia(val total: Int = 0, val done: Int = 0, val taken: Int = 0, val late: Int = 0)

@Serializable
data class RemedioSeNecessario(@SerialName("medication_id") val remedioId: Long, val name: String, val dosage: String? = null)

@Serializable
data class Dia(
    val offset: Int,
    val date: String,
    @SerialName("date_label") val data: String,
    @SerialName("health_consent") val consentimento: Boolean,
    @SerialName("has_medications") val temRemedios: Boolean,
    @SerialName("has_dynamic") val temDinamico: Boolean = false,
    val summary: ResumoDoDia = ResumoDoDia(),
    val doses: List<Dose> = emptyList(),
    @SerialName("as_needed") val seNecessario: List<RemedioSeNecessario> = emptyList(),
)

@Serializable
data class PedidoDeMarcacao(
    @SerialName("medication_id") val remedioId: Long,
    @SerialName("scheduled_for") val agendadaPara: String,
    val status: String? = null,
    val dia: Int = 0,
)

@Serializable
data class Destino(val route: String, val params: JsonElement = JsonObject(emptyMap()))

@Serializable
data class Aviso(
    val id: String,
    val kind: String,
    val title: String,
    val body: String,
    val destination: Destino,
    @SerialName("created_at") val criadoEm: String? = null,
    val read: Boolean = false,
    val `when`: String = "",
    val action: String = "",
)

@Serializable
data class PreferenciasDeAviso(val reminders: Boolean = true, val email: Boolean = false, val timezone: String = "America/Sao_Paulo")

@Serializable
data class Fuso(val id: String, val label: String)

@Serializable
data class CentralDeAvisos(
    val unread: Int,
    @SerialName("health_consent") val consentimento: Boolean,
    val preferences: PreferenciasDeAviso,
    val timezones: List<Fuso> = emptyList(),
    val notices: List<Aviso> = emptyList(),
)

@Serializable
data class AlteracaoDePreferencias(val reminders: Boolean? = null, val email: Boolean? = null, val timezone: String? = null)

@Serializable
data class RespostaDeAbertura(val destination: Destino)

/** Corpo de erro do Laravel: `message` e, na validação (422), `errors` campo → mensagens. */
@Serializable
data class CorpoDeErro(val message: String? = null, val code: String? = null, val errors: Map<String, List<String>> = emptyMap())

/** Para respostas que o app não precisa ler. */
typealias Qualquer = JsonElement
