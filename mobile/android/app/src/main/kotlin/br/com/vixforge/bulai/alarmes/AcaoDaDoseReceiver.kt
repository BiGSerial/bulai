package br.com.vixforge.bulai.alarmes

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import br.com.vixforge.bulai.dados.Dose
import br.com.vixforge.bulai.dados.Preferencias
import br.com.vixforge.bulai.sync.MarcarDoseWorker
import br.com.vixforge.bulai.widget.ProximaDoseWidget
import java.time.OffsetDateTime

/**
 * "Tomei", "Pular" e "Adiar" vindos da notificação, do alarme em tela cheia ou do widget. A marcação vale na hora no
 * aparelho (o alarme some e não volta) e vai para o servidor pelo WorkManager, que repete até conseguir se faltar rede.
 */
class AcaoDaDoseReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        val dose = DoseIntent.ler(intent) ?: return
        val teste = AgendadorDeAlarmes.eTeste(dose.chave)
        val preferencias = Preferencias(context)
        Notificacoes.cancelarDose(context, dose.chave)

        when (intent.action) {
            TOMEI, PULAR -> if (!teste) {
                preferencias.marcadasLocalmente = preferencias.marcadasLocalmente + dose.chave
                preferencias.adiados = preferencias.adiados - dose.chave
                val situacao = if (intent.action == TOMEI) Dose.SITUACAO_TOMADA else Dose.SITUACAO_PULADA
                MarcarDoseWorker.enfileirar(context, dose, situacao, OffsetDateTime.now().toString())
                AgendadorDeAlarmes.reagendar(context)
            }
            ADIAR -> {
                val quando = System.currentTimeMillis() + preferencias.adiarMinutos * 60_000L
                if (teste) {
                    AgendadorDeAlarmes.testar(context, preferencias.adiarMinutos * 60)
                } else {
                    preferencias.adiados = preferencias.adiados + (dose.chave to quando)
                    AgendadorDeAlarmes.reagendar(context)
                }
            }
        }
        ProximaDoseWidget.atualizar(context)
    }

    companion object {
        const val TOMEI = "br.com.vixforge.bulai.TOMEI"
        const val PULAR = "br.com.vixforge.bulai.PULAR"
        const val ADIAR = "br.com.vixforge.bulai.ADIAR"
    }
}
