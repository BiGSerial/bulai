package br.com.vixforge.bulai.dados.api

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

/** "Quem cuida de mim" (lado do paciente): convite em QR Code, pedidos, quem acompanha e quem já acompanhou. */
@Serializable
data class MeusCuidadores(
    @SerialName("health_consent") val consentimento: Boolean = false,
    @SerialName("invite") val convite: Convite? = null,
    @SerialName("invite_expired") val conviteVencido: Boolean = false,
    @SerialName("pending") val pedidos: List<Cuidador> = emptyList(),
    @SerialName("active") val ativos: List<Cuidador> = emptyList(),
    @SerialName("ended") val encerrados: List<CuidadorEncerrado> = emptyList(),
    @SerialName("permission_labels") val rotulos: List<RotuloDePermissao> = emptyList(),
)

/** O link do convite vai no QR Code; o código de 8 números serve para quem prefere digitar. */
@Serializable
data class Convite(val code: String, val url: String, @SerialName("expires_at") val venceEm: String)

@Serializable
data class Cuidador(
    val id: Long,
    val name: String,
    @SerialName("first_name") val primeiroNome: String,
    val email: String? = null,
    @SerialName("permissions") val permissoes: Map<String, Boolean> = emptyMap(),
    @SerialName("requested_at") val pediuEm: String? = null,
    @SerialName("since") val desde: String? = null,
)

@Serializable
data class CuidadorEncerrado(val id: Long, val name: String, val status: String, @SerialName("ended_at") val terminouEm: String? = null)

@Serializable
data class RotuloDePermissao(val key: String, val label: String)

/** "Cuidar de alguém" (lado de quem cuida): as pessoas que acompanha, para escolher de quem ver o dia. */
@Serializable
data class QuemEuCuido(
    val message: String? = null,
    @SerialName("pending") val aguardando: List<PessoaAcompanhada> = emptyList(),
    @SerialName("active") val pessoas: List<PessoaAcompanhada> = emptyList(),
)

@Serializable
data class PessoaAcompanhada(
    val id: Long,
    val name: String,
    @SerialName("first_name") val primeiroNome: String,
    @SerialName("can_view") val podeVer: Boolean = false,
    @SerialName("can_mark") val podeMarcar: Boolean = false,
    @SerialName("health_consent") val consentimento: Boolean = true,
    @SerialName("phrases") val frases: List<String> = emptyList(),
)

/** Dia de quem a pessoa cuida (vem junto do [Dia]). */
@Serializable
data class CuidandoDe(@SerialName("link_id") val vinculo: Long, @SerialName("first_name") val primeiroNome: String)

@Serializable
data class PedidoDeCodigo(val code: String)

@Serializable
data class PedidoDePermissoes(val permissions: Map<String, Boolean>)
