package br.com.vixforge.bulai.abertura

import android.content.Intent
import android.media.AudioManager
import android.media.MediaPlayer
import android.net.Uri
import android.os.Handler
import android.os.Looper
import android.provider.Settings
import android.view.View
import android.widget.VideoView
import androidx.activity.ComponentActivity
import br.com.vixforge.bulai.R

/**
 * Vídeo de abertura (marca Vixforge) ao entrar no app pelo ícone: 3 s do martelo na forja e 1 s parado na logo,
 * já gravados assim em res/raw/abertura.mp4 (sem áudio). A página carrega por baixo enquanto ele toca.
 *
 * A tela de abertura do sistema (core-splashscreen) segura até o primeiro quadro aparecer, para não piscar preto.
 */
class VideoDeAbertura(
    private val activity: ComponentActivity,
    private val camada: View,
    private val video: VideoView,
) {
    private val principal = Handler(Looper.getMainLooper())

    /** Enquanto true, a tela de abertura do sistema continua na frente (ver `setKeepOnScreenCondition`). */
    @Volatile
    var aguardandoPrimeiroQuadro: Boolean = false
        private set

    fun tocar() {
        aguardandoPrimeiroQuadro = true
        camada.visibility = View.VISIBLE
        // Vídeo mudo: não pede o foco de áudio, para não pausar a música de quem abre o app.
        video.setAudioFocusRequest(AudioManager.AUDIOFOCUS_NONE)
        video.setOnInfoListener { _, oQue, _ ->
            if (oQue == MediaPlayer.MEDIA_INFO_VIDEO_RENDERING_START) {
                aguardandoPrimeiroQuadro = false
            }
            false
        }
        video.setOnCompletionListener { encerrar() }
        video.setOnErrorListener { _, _, _ ->
            encerrar()
            true
        }
        video.setVideoURI(Uri.parse("android.resource://${activity.packageName}/${R.raw.abertura}"))
        video.start()
        // Se o decodificador travar, a abertura não prende ninguém: libera a tela do sistema e, depois, o app.
        principal.postDelayed({ aguardandoPrimeiroQuadro = false }, ESPERA_PRIMEIRO_QUADRO_MS)
        principal.postDelayed({ encerrar() }, LIMITE_MS)
    }

    private fun encerrar() {
        aguardandoPrimeiroQuadro = false
        principal.removeCallbacksAndMessages(null)
        if (camada.visibility != View.VISIBLE) {
            return
        }
        camada.animate().alpha(0f).setDuration(SAIDA_MS).withEndAction {
            video.stopPlayback()
            camada.visibility = View.GONE
        }.start()
    }

    fun liberar() {
        principal.removeCallbacksAndMessages(null)
        video.stopPlayback()
    }

    companion object {
        private const val ESPERA_PRIMEIRO_QUADRO_MS = 1_500L
        private const val LIMITE_MS = 6_000L
        private const val SAIDA_MS = 250L

        /**
         * Só ao entrar no app pelo ícone (MAIN sem endereço), numa tela nova. Não toca ao voltar do segundo plano,
         * ao girar a tela, por notificação de dose, atalho, widget ou link, nem com as animações do sistema desligadas
         * (acessibilidade: "remover animações").
         */
        fun deveTocar(acao: String?, temEndereco: Boolean, telaRecriada: Boolean, animacoesDesligadas: Boolean): Boolean =
            acao == Intent.ACTION_MAIN && !temEndereco && !telaRecriada && !animacoesDesligadas

        fun animacoesDesligadas(activity: ComponentActivity): Boolean =
            Settings.Global.getFloat(activity.contentResolver, Settings.Global.ANIMATOR_DURATION_SCALE, 1f) == 0f
    }
}
