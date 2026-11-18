package br.com.vixforge.bulai.alarmes

import android.app.AlarmManager
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import br.com.vixforge.bulai.sync.Sincronizacao
import br.com.vixforge.bulai.widget.ProximaDoseWidget

/**
 * O Android apaga os alarmes ao reiniciar e ao atualizar o aplicativo; mudar a hora ou o fuso desloca todos. Aqui eles
 * são remontados a partir da agenda guardada e uma sincronização é pedida (quando houver rede).
 */
class ReinicioReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        if (intent.action !in ACOES) {
            return
        }
        AgendadorDeAlarmes.reagendar(context)
        Sincronizacao.agora(context)
        ProximaDoseWidget.atualizar(context)
    }

    private companion object {
        val ACOES = setOf(
            Intent.ACTION_BOOT_COMPLETED,
            Intent.ACTION_MY_PACKAGE_REPLACED,
            Intent.ACTION_TIME_CHANGED,
            Intent.ACTION_TIMEZONE_CHANGED,
            AlarmManager.ACTION_SCHEDULE_EXACT_ALARM_PERMISSION_STATE_CHANGED,
        )
    }
}
