package br.com.vixforge.bulai.widget

import android.app.PendingIntent
import android.appwidget.AppWidgetManager
import android.appwidget.AppWidgetProvider
import android.content.ComponentName
import android.content.Context
import android.content.Intent
import android.net.Uri
import android.view.View
import android.widget.RemoteViews
import br.com.vixforge.bulai.MainActivity
import br.com.vixforge.bulai.R
import br.com.vixforge.bulai.alarmes.AcaoDaDoseReceiver
import br.com.vixforge.bulai.alarmes.DoseIntent
import br.com.vixforge.bulai.dados.Agenda
import br.com.vixforge.bulai.dados.Preferencias
import java.time.Instant
import java.time.LocalDate
import java.time.ZoneId

/**
 * Widget "Próximo remédio" na tela inicial: nome e horário da próxima dose ainda não marcada e o botão "Tomei", que só
 * aparece perto do horário (a partir de 1 hora antes) para ninguém marcar sem querer a dose da noite de manhã.
 */
class ProximaDoseWidget : AppWidgetProvider() {
    override fun onUpdate(context: Context, appWidgetManager: AppWidgetManager, appWidgetIds: IntArray) {
        atualizar(context)
    }

    companion object {
        private const val ANTECEDENCIA_DO_BOTAO_MS = 60 * 60 * 1000L

        fun atualizar(context: Context) {
            val gerenciador = AppWidgetManager.getInstance(context) ?: return
            val ids = gerenciador.getAppWidgetIds(ComponentName(context, ProximaDoseWidget::class.java))
            if (ids.isEmpty()) {
                return
            }
            val tela = RemoteViews(context.packageName, R.layout.widget_proxima_dose)
            tela.setOnClickPendingIntent(R.id.widget_raiz, PendingIntent.getActivity(context, 0, MainActivity.abrir(context, "/hoje"), PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE))

            val preferencias = Preferencias(context)
            val agenda = Agenda.ler(preferencias.agendaJson)
            val agora = System.currentTimeMillis()
            val marcadas = preferencias.marcadasLocalmente
            val proxima = agenda?.doses?.firstOrNull { !it.marcada && it.chave !in marcadas && it.agendadaPara > agora - agenda.atrasoMinutos * 60_000L }

            when {
                !preferencias.conectado -> vazio(tela, context.getString(R.string.widget_desconectado))
                proxima == null -> vazio(tela, context.getString(R.string.widget_vazio))
                else -> {
                    val dia = Instant.ofEpochMilli(proxima.agendadaPara).atZone(ZoneId.systemDefault()).toLocalDate()
                    val horario = if (dia.isAfter(LocalDate.now())) context.getString(R.string.widget_amanha, proxima.horario) else context.getString(R.string.widget_horario, proxima.horario)
                    tela.setTextViewText(R.id.widget_nome, proxima.nome)
                    tela.setTextViewText(R.id.widget_horario, listOfNotNull(horario, proxima.dosagem).joinToString(" · "))
                    tela.setViewVisibility(R.id.widget_horario, View.VISIBLE)
                    if (proxima.agendadaPara - agora <= ANTECEDENCIA_DO_BOTAO_MS) {
                        val tomei = DoseIntent.escrever(Intent(context, AcaoDaDoseReceiver::class.java).setAction(AcaoDaDoseReceiver.TOMEI), proxima)
                            .setData(Uri.parse("bulai://widget/" + Uri.encode(proxima.chave)))
                        tela.setOnClickPendingIntent(R.id.widget_tomei, PendingIntent.getBroadcast(context, 0, tomei, PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE))
                        tela.setViewVisibility(R.id.widget_tomei, View.VISIBLE)
                    } else {
                        tela.setViewVisibility(R.id.widget_tomei, View.GONE)
                    }
                }
            }
            gerenciador.updateAppWidget(ids, tela)
        }

        private fun vazio(tela: RemoteViews, texto: String) {
            tela.setTextViewText(R.id.widget_nome, texto)
            tela.setViewVisibility(R.id.widget_horario, View.GONE)
            tela.setViewVisibility(R.id.widget_tomei, View.GONE)
        }
    }
}
