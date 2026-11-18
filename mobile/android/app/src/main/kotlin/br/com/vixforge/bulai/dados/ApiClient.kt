package br.com.vixforge.bulai.dados

import android.content.Context
import android.net.Uri
import br.com.vixforge.bulai.BuildConfig
import org.json.JSONObject
import java.io.IOException
import java.net.HttpURLConnection
import java.net.URL

/**
 * Cliente da API do aparelho (`/api/mobile/v1`, docs/app-android.md). Sem bibliotecas: HttpURLConnection + org.json.
 * Roda sempre fora da thread principal (WorkManager ou thread própria).
 */
class ApiClient(context: Context) {
    private val preferencias = Preferencias(context)

    /** O servidor recusou o token (revogado, conta desativada): o aparelho precisa ser ligado de novo. */
    class NaoAutorizado : IOException("token do aparelho recusado")

    /** Resposta 4xx que não adianta repetir (dados inválidos, remédio de outra pessoa). */
    class Recusado(val codigo: Int) : IOException("HTTP $codigo")

    fun agenda(): String = chamar("GET", "/agenda")

    fun marcar(remedioId: Long, agendadaParaIso: String, situacao: String, marcadaEmIso: String): String =
        chamar("POST", "/doses", JSONObject().put("medication_id", remedioId).put("scheduled_for", agendadaParaIso).put("status", situacao).put("recorded_at", marcadaEmIso))

    fun avisos(desde: String?): String =
        chamar("GET", if (desde == null) "/avisos" else "/avisos?desde=" + Uri.encode(desde))

    /** Recebe o token porque o aparelho já foi limpo localmente quando isto roda (Sincronizacao.desconectar). */
    fun desconectar(token: String) {
        chamar("DELETE", "/aparelho", tokenExplicito = token)
    }

    private fun chamar(metodo: String, caminho: String, corpo: JSONObject? = null, tokenExplicito: String? = null): String {
        val token = tokenExplicito ?: preferencias.token ?: throw NaoAutorizado()
        val conexao = (URL(BuildConfig.BASE_URL + "/api/mobile/v1" + caminho).openConnection() as HttpURLConnection).apply {
            requestMethod = metodo
            connectTimeout = 15_000
            readTimeout = 20_000
            setRequestProperty("Authorization", "Bearer $token")
            setRequestProperty("Accept", "application/json")
            setRequestProperty("User-Agent", "BulaiAndroid/${BuildConfig.VERSION_NAME}")
            if (corpo != null) {
                doOutput = true
                setRequestProperty("Content-Type", "application/json; charset=utf-8")
            }
        }
        try {
            corpo?.let { conexao.outputStream.use { saida -> saida.write(it.toString().toByteArray()) } }
            val codigo = conexao.responseCode
            when {
                codigo == 401 -> throw NaoAutorizado()
                codigo in 400..499 && codigo != 408 && codigo != 429 -> throw Recusado(codigo)
                codigo !in 200..299 -> throw IOException("HTTP $codigo")
            }

            return conexao.inputStream.bufferedReader().use { it.readText() }
        } finally {
            conexao.disconnect()
        }
    }
}
