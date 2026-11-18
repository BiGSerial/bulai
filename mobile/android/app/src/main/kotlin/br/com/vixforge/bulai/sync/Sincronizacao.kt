package br.com.vixforge.bulai.sync

import android.content.Context
import androidx.work.BackoffPolicy
import androidx.work.Constraints
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.ExistingWorkPolicy
import androidx.work.NetworkType
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import br.com.vixforge.bulai.alarmes.AgendadorDeAlarmes
import br.com.vixforge.bulai.alarmes.Notificacoes
import br.com.vixforge.bulai.dados.Agenda
import br.com.vixforge.bulai.dados.ApiClient
import br.com.vixforge.bulai.dados.Preferencias
import br.com.vixforge.bulai.widget.ProximaDoseWidget
import java.time.OffsetDateTime
import java.util.concurrent.TimeUnit
import kotlin.concurrent.thread

/** Ligar/desligar o aparelho da conta e manter a agenda local em dia (WorkManager: periódica + sob demanda). */
object Sincronizacao {
    private const val PERIODICA = "sincronizar-periodico"
    private const val AGORA = "sincronizar-agora"
    private const val INTERVALO_MINUTOS = 30L

    private val comRede = Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build()

    /** A página logada entregou o token deste aparelho (resources/js/nativo.js). */
    fun conectar(context: Context, token: String, usuario: String) {
        val preferencias = Preferencias(context)
        if (preferencias.usuario != usuario) {
            // Outra conta neste celular: nada da anterior pode continuar tocando.
            desconectarLocalmente(context)
        }
        preferencias.token = token
        preferencias.usuario = usuario
        // Avisos antigos já estão na tela Avisos; o celular só notifica o que chegar daqui para frente.
        preferencias.avisosDesde = OffsetDateTime.now().toString()
        agendarPeriodica(context)
        agora(context)
    }

    /** Saída da conta: limpa o aparelho na hora e avisa o servidor em segundo plano (melhor esforço). */
    fun desconectar(context: Context) {
        val token = Preferencias(context).token
        desconectarLocalmente(context)
        if (token != null) {
            thread(name = "bulai-desconectar") {
                try {
                    ApiClient(context).desconectar(token)
                } catch (e: Exception) {
                    // Sem rede: o token fica órfão no servidor até ser revogado pela conta (Encerrar outras sessões).
                }
            }
        }
    }

    fun desconectarLocalmente(context: Context) {
        AgendadorDeAlarmes.cancelarTodos(context)
        Preferencias(context).limparConta()
        Notificacoes.cancelarTodas(context)
        WorkManager.getInstance(context).cancelUniqueWork(PERIODICA)
        WorkManager.getInstance(context).cancelUniqueWork(AGORA)
        ProximaDoseWidget.atualizar(context)
    }

    fun agendarPeriodica(context: Context) {
        val pedido = PeriodicWorkRequestBuilder<SincronizarWorker>(INTERVALO_MINUTOS, TimeUnit.MINUTES).setConstraints(comRede).build()
        WorkManager.getInstance(context).enqueueUniquePeriodicWork(PERIODICA, ExistingPeriodicWorkPolicy.KEEP, pedido)
    }

    fun agora(context: Context) {
        if (!Preferencias(context).conectado) {
            return
        }
        val pedido = OneTimeWorkRequestBuilder<SincronizarWorker>()
            .setConstraints(comRede)
            .setBackoffCriteria(BackoffPolicy.EXPONENTIAL, 30, TimeUnit.SECONDS)
            .build()
        WorkManager.getInstance(context).enqueueUniqueWork(AGORA, ExistingWorkPolicy.REPLACE, pedido)
    }

    /**
     * Guarda a agenda que veio do servidor e remonta os alarmes. Marcações locais que o servidor já confirmou (ou cuja
     * dose saiu da agenda) deixam de ser "pendentes"; as outras continuam valendo até o envio dar certo.
     */
    fun salvarAgenda(context: Context, json: String) {
        val agenda = Agenda.ler(json) ?: return
        val preferencias = Preferencias(context)
        preferencias.agendaJson = json
        val porChave = agenda.doses.associateBy { it.chave }
        preferencias.marcadasLocalmente = preferencias.marcadasLocalmente.filter { chave -> porChave[chave]?.marcada == false }.toSet()
        AgendadorDeAlarmes.reagendar(context)
        ProximaDoseWidget.atualizar(context)
    }
}
