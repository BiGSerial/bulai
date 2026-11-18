package br.com.vixforge.bulai.abertura

import android.content.Context
import android.content.Intent
import android.media.AudioManager
import android.media.MediaPlayer
import android.net.Uri
import android.provider.Settings
import android.widget.VideoView
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.core.tween
import androidx.compose.animation.fadeOut
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.semantics.hideFromAccessibility
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.viewinterop.AndroidView
import br.com.vixforge.bulai.R
import kotlinx.coroutines.delay

/**
 * Vídeo de abertura (marca Vixforge) ao entrar no app pelo ícone: 3 s do martelo na forja e 1 s parado na logo, já
 * gravados assim em res/raw/abertura.mp4 (sem áudio). As telas carregam por baixo enquanto ele toca.
 */
object VideoDeAbertura {
    /** Se o decodificador travar, a abertura não prende ninguém. */
    const val ESPERA_PRIMEIRO_QUADRO_MS = 1_500L
    private const val LIMITE_MS = 6_000L

    /**
     * Só ao entrar no app pelo ícone (MAIN sem endereço), numa tela nova. Não toca ao voltar do segundo plano, ao girar
     * a tela, por notificação de dose, atalho, widget ou link, nem com as animações do sistema desligadas
     * (acessibilidade: "remover animações").
     */
    fun deveTocar(acao: String?, temEndereco: Boolean, telaRecriada: Boolean, animacoesDesligadas: Boolean): Boolean =
        acao == Intent.ACTION_MAIN && !temEndereco && !telaRecriada && !animacoesDesligadas

    fun animacoesDesligadas(context: Context): Boolean =
        Settings.Global.getFloat(context.contentResolver, Settings.Global.ANIMATOR_DURATION_SCALE, 1f) == 0f

    /**
     * Camada por cima de tudo. [aoPrimeiroQuadro] libera a tela de abertura do sistema; [aoTerminar] some com a camada.
     */
    @Composable
    fun Camada(visivel: Boolean, aoPrimeiroQuadro: () -> Unit, aoTerminar: () -> Unit) {
        var terminou by remember { mutableStateOf(false) }
        val encerrar = {
            aoPrimeiroQuadro()
            terminou = true
        }
        LaunchedEffect(visivel) {
            if (visivel) {
                delay(ESPERA_PRIMEIRO_QUADRO_MS)
                aoPrimeiroQuadro()
                delay(LIMITE_MS - ESPERA_PRIMEIRO_QUADRO_MS)
                encerrar()
            }
        }
        AnimatedVisibility(visivel && !terminou, exit = fadeOut(tween(250))) {
            DisposableEffect(Unit) { onDispose { aoTerminar() } }
            Box(Modifier.fillMaxSize().semantics { hideFromAccessibility() }, contentAlignment = Alignment.Center) {
                // Mesmo cinza das bordas do vídeo: em telas mais altas que 9:16 as faixas não aparecem.
                Box(Modifier.fillMaxSize().background(Brush.verticalGradient(listOf(Color(0xFFABA398), Color(0xFFB3AAA2)))))
                AndroidView(
                    factory = { context ->
                        VideoView(context).apply {
                            // Vídeo mudo: não pede o foco de áudio, para não pausar a música de quem abre o app.
                            setAudioFocusRequest(AudioManager.AUDIOFOCUS_NONE)
                            setOnInfoListener { _, oQue, _ ->
                                if (oQue == MediaPlayer.MEDIA_INFO_VIDEO_RENDERING_START) aoPrimeiroQuadro()
                                false
                            }
                            setOnCompletionListener { encerrar() }
                            setOnErrorListener { _, _, _ ->
                                encerrar()
                                true
                            }
                            setVideoURI(Uri.parse("android.resource://${context.packageName}/${R.raw.abertura}"))
                            start()
                        }
                    },
                    onRelease = { it.stopPlayback() },
                    modifier = Modifier.fillMaxWidth(),
                )
            }
        }
    }
}
