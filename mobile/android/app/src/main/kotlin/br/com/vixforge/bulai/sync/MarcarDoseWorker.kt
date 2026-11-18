package br.com.vixforge.bulai.sync

import android.content.Context
import androidx.work.BackoffPolicy
import androidx.work.Constraints
import androidx.work.ExistingWorkPolicy
import androidx.work.NetworkType
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.WorkManager
import androidx.work.Worker
import androidx.work.WorkerParameters
import androidx.work.workDataOf
import br.com.vixforge.bulai.dados.ApiClient
import br.com.vixforge.bulai.dados.Dose
import br.com.vixforge.bulai.dados.Preferencias
import java.io.IOException
import java.util.concurrent.TimeUnit

/**
 * Envia "Tomei"/"Pulei" ao servidor com o horário REAL do toque (`recorded_at`), mesmo que a rede só volte depois:
 * remédios de programação dinâmica contam a próxima dose a partir desse horário. A resposta já traz a agenda nova.
 */
class MarcarDoseWorker(context: Context, params: WorkerParameters) : Worker(context, params) {
    override fun doWork(): Result {
        val chave = inputData.getString(CHAVE) ?: return Result.failure()
        val preferencias = Preferencias(applicationContext)
        if (!preferencias.conectado) {
            return Result.failure()
        }

        return try {
            val agenda = ApiClient(applicationContext).marcar(
                inputData.getLong(REMEDIO, 0),
                inputData.getString(AGENDADA) ?: return Result.failure(),
                inputData.getString(SITUACAO) ?: Dose.SITUACAO_TOMADA,
                inputData.getString(MARCADA_EM) ?: return Result.failure(),
            )
            Sincronizacao.salvarAgenda(applicationContext, agenda)
            Result.success()
        } catch (e: ApiClient.NaoAutorizado) {
            Sincronizacao.desconectarLocalmente(applicationContext)
            Result.failure()
        } catch (e: ApiClient.Recusado) {
            // O servidor não aceitou (fora da janela, remédio apagado): a dose volta a aparecer como pendente.
            preferencias.marcadasLocalmente = preferencias.marcadasLocalmente - chave
            Result.failure()
        } catch (e: IOException) {
            Result.retry()
        }
    }

    companion object {
        private const val CHAVE = "chave"
        private const val REMEDIO = "remedio"
        private const val AGENDADA = "agendada"
        private const val SITUACAO = "situacao"
        private const val MARCADA_EM = "marcada_em"

        fun enfileirar(context: Context, dose: Dose, situacao: String, marcadaEmIso: String) {
            val pedido = OneTimeWorkRequestBuilder<MarcarDoseWorker>()
                .setInputData(workDataOf(CHAVE to dose.chave, REMEDIO to dose.remedioId, AGENDADA to dose.agendadaParaIso, SITUACAO to situacao, MARCADA_EM to marcadaEmIso))
                .setConstraints(Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build())
                .setBackoffCriteria(BackoffPolicy.EXPONENTIAL, 30, TimeUnit.SECONDS)
                .build()
            WorkManager.getInstance(context).enqueueUniqueWork("marcar-" + dose.chave, ExistingWorkPolicy.REPLACE, pedido)
        }
    }
}
