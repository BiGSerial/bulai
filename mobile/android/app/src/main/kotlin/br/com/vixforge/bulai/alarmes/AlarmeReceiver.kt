package br.com.vixforge.bulai.alarmes

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import br.com.vixforge.bulai.dados.Agenda
import br.com.vixforge.bulai.dados.Preferencias
import br.com.vixforge.bulai.widget.ProximaDoseWidget

/** Hora da dose: confere na agenda local se ainda está pendente e mostra a notificação (ou o despertador). */
class AlarmeReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        val dose = DoseIntent.ler(intent) ?: return
        if (!AgendadorDeAlarmes.eTeste(dose.chave)) {
            val preferencias = Preferencias(context)
            if (!preferencias.conectado) {
                return
            }
            preferencias.adiados = preferencias.adiados - dose.chave
            preferencias.tocados = preferencias.tocados + dose.chave
            preferencias.agendados = preferencias.agendados - dose.chave
            val atual = Agenda.ler(preferencias.agendaJson)?.doses?.firstOrNull { it.chave == dose.chave }
            if (atual == null || atual.marcada || dose.chave in preferencias.marcadasLocalmente) {
                return
            }
        }
        Notificacoes.mostrarDose(context, dose)
        ProximaDoseWidget.atualizar(context)
    }
}
