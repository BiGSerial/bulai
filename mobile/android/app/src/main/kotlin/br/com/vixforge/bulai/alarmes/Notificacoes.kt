package br.com.vixforge.bulai.alarmes

import android.annotation.SuppressLint
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.media.AudioAttributes
import android.media.RingtoneManager
import android.net.Uri
import android.os.Build
import androidx.core.app.NotificationCompat
import androidx.core.app.NotificationManagerCompat
import androidx.core.content.ContextCompat
import br.com.vixforge.bulai.MainActivity
import br.com.vixforge.bulai.R
import br.com.vixforge.bulai.dados.Dose
import br.com.vixforge.bulai.dados.Preferencias

/** Canais e notificações do aplicativo: dose (lembrete), dose em modo despertador (tela cheia) e avisos do servidor. */
object Notificacoes {
    const val CANAL_DOSES = "doses"
    const val CANAL_DESPERTADOR = "despertador"
    const val CANAL_AVISOS = "avisos"

    /** Pedido para a tela cheia do alarme se fechar (a pessoa respondeu pela notificação). */
    const val ACAO_FECHAR_ALARME = "br.com.vixforge.bulai.FECHAR_ALARME"

    fun criarCanais(context: Context) {
        val gerenciador = context.getSystemService(NotificationManager::class.java)
        val alarme = RingtoneManager.getDefaultUri(RingtoneManager.TYPE_ALARM) ?: RingtoneManager.getDefaultUri(RingtoneManager.TYPE_NOTIFICATION)
        gerenciador.createNotificationChannels(
            listOf(
                NotificationChannel(CANAL_DOSES, context.getString(R.string.canal_doses), NotificationManager.IMPORTANCE_HIGH).apply {
                    description = context.getString(R.string.canal_doses_descricao)
                    enableVibration(true)
                    vibrationPattern = longArrayOf(0, 400, 200, 400)
                    lockscreenVisibility = NotificationCompat.VISIBILITY_PRIVATE
                },
                NotificationChannel(CANAL_DESPERTADOR, context.getString(R.string.canal_despertador), NotificationManager.IMPORTANCE_HIGH).apply {
                    description = context.getString(R.string.canal_despertador_descricao)
                    setSound(alarme, AudioAttributes.Builder().setUsage(AudioAttributes.USAGE_ALARM).setContentType(AudioAttributes.CONTENT_TYPE_SONIFICATION).build())
                    enableVibration(true)
                    vibrationPattern = longArrayOf(0, 800, 400, 800)
                    setBypassDnd(true)
                },
                NotificationChannel(CANAL_AVISOS, context.getString(R.string.canal_avisos), NotificationManager.IMPORTANCE_DEFAULT).apply {
                    description = context.getString(R.string.canal_avisos_descricao)
                },
            ),
        )
    }

    fun podeNotificar(context: Context): Boolean = NotificationManagerCompat.from(context).areNotificationsEnabled()

    fun podeTelaCheia(context: Context): Boolean =
        Build.VERSION.SDK_INT < Build.VERSION_CODES.UPSIDE_DOWN_CAKE || context.getSystemService(NotificationManager::class.java).canUseFullScreenIntent()

    @SuppressLint("MissingPermission")
    fun mostrarDose(context: Context, dose: Dose) {
        val preferencias = Preferencias(context)
        val modoAlarme = preferencias.modoAlarme
        val id = idDa(dose.chave)
        val texto = if (dose.dosagem.isNullOrBlank()) context.getString(R.string.dose_texto, dose.horario) else context.getString(R.string.dose_texto_com_dose, dose.horario, dose.dosagem)
        val abrir = PendingIntent.getActivity(context, id, MainActivity.abrir(context, "/hoje"), PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE)
        val publica = NotificationCompat.Builder(context, CANAL_DOSES)
            .setSmallIcon(R.drawable.ic_notificacao)
            .setColor(ContextCompat.getColor(context, R.color.marca))
            .setContentTitle(context.getString(R.string.alarme_hora_do_remedio))
            .build()

        val construtor = NotificationCompat.Builder(context, if (modoAlarme) CANAL_DESPERTADOR else CANAL_DOSES)
            .setSmallIcon(R.drawable.ic_notificacao)
            .setColor(ContextCompat.getColor(context, R.color.marca))
            .setContentTitle(context.getString(R.string.dose_titulo, dose.nome))
            .setContentText(texto)
            .setStyle(NotificationCompat.BigTextStyle().bigText(texto))
            .setPriority(NotificationCompat.PRIORITY_HIGH)
            .setCategory(if (modoAlarme) NotificationCompat.CATEGORY_ALARM else NotificationCompat.CATEGORY_REMINDER)
            .setVisibility(NotificationCompat.VISIBILITY_PRIVATE)
            .setPublicVersion(publica)
            .setContentIntent(abrir)
            .setAutoCancel(true)
            .setWhen(dose.agendadaPara)
            .setShowWhen(true)
            .addAction(0, context.getString(R.string.acao_tomei), acao(context, dose, AcaoDaDoseReceiver.TOMEI))
            .addAction(0, context.getString(R.string.acao_adiar, preferencias.adiarMinutos), acao(context, dose, AcaoDaDoseReceiver.ADIAR))
            .addAction(0, context.getString(R.string.acao_pular), acao(context, dose, AcaoDaDoseReceiver.PULAR))

        if (modoAlarme) {
            val telaCheia = DoseIntent.escrever(Intent(context, AlarmeActivity::class.java), dose).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_NO_USER_ACTION)
            construtor.setFullScreenIntent(PendingIntent.getActivity(context, id, telaCheia, PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE), true)
                .setOngoing(true)
        }

        if (podeNotificar(context)) {
            try {
                NotificationManagerCompat.from(context).notify(id, construtor.build())
            } catch (e: SecurityException) {
                // Permissão retirada no meio do caminho: nada a fazer além de não derrubar o aplicativo.
            }
        }
    }

    @SuppressLint("MissingPermission")
    fun mostrarAviso(context: Context, idDoAviso: String, titulo: String, corpo: String, caminho: String) {
        if (!podeNotificar(context)) {
            return
        }
        val id = idDa("aviso|$idDoAviso")
        val abrir = PendingIntent.getActivity(context, id, MainActivity.abrir(context, caminho), PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE)
        val notificacao = NotificationCompat.Builder(context, CANAL_AVISOS)
            .setSmallIcon(R.drawable.ic_notificacao)
            .setColor(ContextCompat.getColor(context, R.color.marca))
            .setContentTitle(titulo)
            .setContentText(corpo)
            .setStyle(NotificationCompat.BigTextStyle().bigText(corpo))
            .setVisibility(NotificationCompat.VISIBILITY_PRIVATE)
            .setContentIntent(abrir)
            .setAutoCancel(true)
            .build()
        try {
            NotificationManagerCompat.from(context).notify(id, notificacao)
        } catch (e: SecurityException) {
            // idem
        }
    }

    fun cancelarDose(context: Context, chave: String) {
        NotificationManagerCompat.from(context).cancel(idDa(chave))
        context.sendBroadcast(Intent(ACAO_FECHAR_ALARME).setPackage(context.packageName).putExtra("chave", chave))
    }

    fun cancelarTodas(context: Context) {
        NotificationManagerCompat.from(context).cancelAll()
    }

    private fun acao(context: Context, dose: Dose, acao: String): PendingIntent {
        val intent = DoseIntent.escrever(Intent(context, AcaoDaDoseReceiver::class.java).setAction(acao), dose)
            .setData(Uri.parse("bulai://dose/" + Uri.encode(dose.chave) + "/" + acao))

        return PendingIntent.getBroadcast(context, idDa(dose.chave), intent, PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE)
    }

    private fun idDa(chave: String): Int = chave.hashCode()
}
