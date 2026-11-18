package br.com.vixforge.bulai

import android.content.Context
import android.content.Intent
import android.net.Uri
import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.compose.foundation.layout.Box
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.core.splashscreen.SplashScreen.Companion.installSplashScreen
import br.com.vixforge.bulai.abertura.VideoDeAbertura
import br.com.vixforge.bulai.ui.navegacao.AppBulai
import br.com.vixforge.bulai.ui.navegacao.Rotas
import br.com.vixforge.bulai.ui.tema.TemaBulai
import kotlinx.coroutines.flow.MutableStateFlow

/**
 * Única Activity do app (fora o despertador em tela cheia): telas nativas em Jetpack Compose sobre a API
 * `/api/mobile/v1`. Notificações, widget e atalhos abrem uma tela por `bulai://abrir/{caminho}`.
 */
class MainActivity : ComponentActivity() {
    /** Tela pedida por notificação/atalho/widget, consumida pela navegação. */
    private val rotaPedida = MutableStateFlow<String?>(null)

    private var esperandoVideo by mutableStateOf(false)

    override fun onCreate(savedInstanceState: Bundle?) {
        val telaDoSistema = installSplashScreen()
        enableEdgeToEdge()
        super.onCreate(savedInstanceState)

        val tocarVideo = VideoDeAbertura.deveTocar(intent?.action, intent?.data != null, savedInstanceState != null, VideoDeAbertura.animacoesDesligadas(this))
        esperandoVideo = tocarVideo
        // A tela de abertura do sistema segura até o primeiro quadro do vídeo, para não piscar.
        telaDoSistema.setKeepOnScreenCondition { esperandoVideo }
        if (savedInstanceState == null) {
            rotaPedida.value = Rotas.doCaminho(intent?.data?.takeIf { it.scheme == "bulai" }?.path)
        }

        setContent {
            TemaBulai {
                var mostrarVideo by androidx.compose.runtime.saveable.rememberSaveable { mutableStateOf(tocarVideo) }
                Box {
                    AppBulai(rotaPedida = rotaPedida, aoUsarRota = { rotaPedida.value = null })
                    VideoDeAbertura.Camada(mostrarVideo, aoPrimeiroQuadro = { esperandoVideo = false }, aoTerminar = { mostrarVideo = false })
                }
            }
        }
    }

    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        Rotas.doCaminho(intent.data?.takeIf { it.scheme == "bulai" }?.path)?.let { rotaPedida.value = it }
    }

    override fun onResume() {
        super.onResume()
        container.sessao.conferir()
        br.com.vixforge.bulai.sync.Sincronizacao.agora(this)
    }

    companion object {
        /** Intent que abre o app numa tela (notificações, widget, atalhos): bulai://abrir/hoje. */
        fun abrir(context: Context, caminho: String): Intent =
            Intent(context, MainActivity::class.java)
                .setAction(Intent.ACTION_VIEW)
                .setData(Uri.parse("bulai://abrir" + (if (caminho.startsWith("/")) caminho else "/$caminho")))
                .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TOP or Intent.FLAG_ACTIVITY_SINGLE_TOP)
    }
}
