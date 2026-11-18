package br.com.vixforge.bulai.sync

import android.content.Context
import androidx.work.Worker
import androidx.work.WorkerParameters
import br.com.vixforge.bulai.alarmes.Notificacoes
import br.com.vixforge.bulai.dados.ApiClient
import br.com.vixforge.bulai.dados.Preferencias
import org.json.JSONObject
import java.io.IOException

/**
 * Busca a agenda (hoje e amanhã) e os avisos novos. Avisos de dose (`kind = dose`) não viram notificação aqui: quem
 * avisa a dose é o alarme local, na hora exata. Os outros (cuidador, fim de tratamento, remédio ligado ao catálogo) sim.
 */
class SincronizarWorker(context: Context, params: WorkerParameters) : Worker(context, params) {
    override fun doWork(): Result {
        val preferencias = Preferencias(applicationContext)
        if (!preferencias.conectado) {
            return Result.success()
        }
        val api = ApiClient(applicationContext)

        return try {
            Sincronizacao.salvarAgenda(applicationContext, api.agenda())
            mostrarAvisosNovos(api, preferencias)
            Result.success()
        } catch (e: ApiClient.NaoAutorizado) {
            Sincronizacao.desconectarLocalmente(applicationContext)
            Result.failure()
        } catch (e: ApiClient.Recusado) {
            Result.failure()
        } catch (e: IOException) {
            if (runAttemptCount < 5) Result.retry() else Result.failure()
        }
    }

    private fun mostrarAvisosNovos(api: ApiClient, preferencias: Preferencias) {
        val resposta = JSONObject(api.avisos(preferencias.avisosDesde))
        val avisos = resposta.getJSONArray("notices")
        val jaMostrados = preferencias.avisosMostrados
        val idsDaResposta = mutableSetOf<String>()
        for (i in avisos.length() - 1 downTo 0) {
            val aviso = avisos.getJSONObject(i)
            val id = aviso.getString("id")
            idsDaResposta += id
            if (aviso.optString("kind") == "dose" || id in jaMostrados) {
                continue
            }
            Notificacoes.mostrarAviso(applicationContext, id, aviso.optString("title"), aviso.optString("body"), aviso.optString("url", "/avisos"))
        }
        preferencias.avisosMostrados = idsDaResposta
        preferencias.avisosDesde = resposta.optString("checked_at").ifBlank { null } ?: preferencias.avisosDesde
    }
}
