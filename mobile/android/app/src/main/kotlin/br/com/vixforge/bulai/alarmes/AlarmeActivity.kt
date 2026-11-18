package br.com.vixforge.bulai.alarmes

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.content.IntentFilter
import android.media.AudioAttributes
import android.media.MediaPlayer
import android.media.RingtoneManager
import android.os.Build
import android.os.Bundle
import android.os.Handler
import android.os.Looper
import android.os.VibrationEffect
import android.os.Vibrator
import android.os.VibratorManager
import android.view.WindowManager
import android.widget.Button
import android.widget.TextView
import androidx.activity.ComponentActivity
import androidx.core.content.ContextCompat
import br.com.vixforge.bulai.R
import br.com.vixforge.bulai.dados.Dose
import br.com.vixforge.bulai.dados.Preferencias

/**
 * Despertador do remédio: tela cheia por cima da tela bloqueada, som de alarme em repetição e vibração até a pessoa
 * responder (ou por até 2 minutos; a notificação continua lá). Os botões fazem o mesmo que os da notificação.
 */
class AlarmeActivity : ComponentActivity() {
    private var dose: Dose? = null
    private var som: MediaPlayer? = null
    private var vibrador: Vibrator? = null
    private val relogio = Handler(Looper.getMainLooper())
    private val encerrarSozinho = Runnable { pararDeTocar() }

    private val fechar = object : BroadcastReceiver() {
        override fun onReceive(context: Context, intent: Intent) {
            val chave = intent.getStringExtra("chave")
            if (chave == null || chave == dose?.chave) {
                finish()
            }
        }
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O_MR1) {
            @Suppress("DEPRECATION")
            window.addFlags(WindowManager.LayoutParams.FLAG_SHOW_WHEN_LOCKED or WindowManager.LayoutParams.FLAG_TURN_SCREEN_ON)
        }
        window.addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON)
        setContentView(R.layout.activity_alarme)
        ContextCompat.registerReceiver(this, fechar, IntentFilter(Notificacoes.ACAO_FECHAR_ALARME), ContextCompat.RECEIVER_NOT_EXPORTED)

        findViewById<Button>(R.id.alarme_tomei).setOnClickListener { responder(AcaoDaDoseReceiver.TOMEI) }
        findViewById<Button>(R.id.alarme_adiar).setOnClickListener { responder(AcaoDaDoseReceiver.ADIAR) }
        findViewById<Button>(R.id.alarme_pular).setOnClickListener { responder(AcaoDaDoseReceiver.PULAR) }
        mostrar(intent)
    }

    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        mostrar(intent)
    }

    private fun mostrar(intent: Intent?) {
        val atual = DoseIntent.ler(intent) ?: run {
            finish()
            return
        }
        dose = atual
        findViewById<TextView>(R.id.alarme_horario).text = atual.horario
        findViewById<TextView>(R.id.alarme_nome).text = atual.nome
        findViewById<TextView>(R.id.alarme_dose).text = atual.dosagem ?: ""
        findViewById<Button>(R.id.alarme_adiar).text = getString(R.string.acao_adiar, Preferencias(this).adiarMinutos)
        tocar()
    }

    private fun responder(acao: String) {
        val atual = dose ?: return
        sendBroadcast(DoseIntent.escrever(Intent(this, AcaoDaDoseReceiver::class.java).setAction(acao), atual))
        finish()
    }

    private fun tocar() {
        pararDeTocar()
        val uri = RingtoneManager.getDefaultUri(RingtoneManager.TYPE_ALARM) ?: RingtoneManager.getDefaultUri(RingtoneManager.TYPE_NOTIFICATION)
        som = try {
            MediaPlayer().apply {
                setAudioAttributes(AudioAttributes.Builder().setUsage(AudioAttributes.USAGE_ALARM).setContentType(AudioAttributes.CONTENT_TYPE_SONIFICATION).build())
                setDataSource(this@AlarmeActivity, uri)
                isLooping = true
                prepare()
                start()
            }
        } catch (e: Exception) {
            null
        }
        vibrador = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
            getSystemService(VibratorManager::class.java)?.defaultVibrator
        } else {
            @Suppress("DEPRECATION")
            getSystemService(Vibrator::class.java)
        }
        vibrador?.vibrate(VibrationEffect.createWaveform(longArrayOf(0, 800, 600), 0))
        relogio.postDelayed(encerrarSozinho, DURACAO_MAXIMA_MS)
    }

    private fun pararDeTocar() {
        relogio.removeCallbacks(encerrarSozinho)
        som?.run {
            try {
                stop()
            } catch (e: IllegalStateException) {
                // já parado
            }
            release()
        }
        som = null
        vibrador?.cancel()
    }

    override fun onDestroy() {
        pararDeTocar()
        unregisterReceiver(fechar)
        super.onDestroy()
    }

    private companion object {
        const val DURACAO_MAXIMA_MS = 2 * 60 * 1000L
    }
}
