package br.com.vixforge.bulai.ui.sistema

import android.app.Activity
import android.content.ActivityNotFoundException
import android.content.Intent
import android.speech.RecognizerIntent
import android.speech.tts.TextToSpeech
import android.speech.tts.UtteranceProgressListener
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.State
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.ui.platform.LocalContext
import java.util.Locale

/**
 * Ditado pelo reconhecedor de voz do próprio Android (a tela de "fale agora" do sistema): não pede permissão de
 * microfone ao BULA-IA. Devolve `null` quando o aparelho não tem reconhecedor (aí vale o microfone do teclado).
 */
@Composable
fun rememberDitado(aoReconhecer: (String) -> Unit): (() -> Unit)? {
    val context = LocalContext.current
    val pedido = remember {
        Intent(RecognizerIntent.ACTION_RECOGNIZE_SPEECH)
            .putExtra(RecognizerIntent.EXTRA_LANGUAGE_MODEL, RecognizerIntent.LANGUAGE_MODEL_FREE_FORM)
            .putExtra(RecognizerIntent.EXTRA_LANGUAGE, "pt-BR")
            .putExtra(RecognizerIntent.EXTRA_PROMPT, "Fale sua pergunta, citando o nome do remédio")
    }
    val disponivel = remember { pedido.resolveActivity(context.packageManager) != null }
    val lancador = rememberLauncherForActivityResult(ActivityResultContracts.StartActivityForResult()) { resultado ->
        if (resultado.resultCode == Activity.RESULT_OK) {
            resultado.data?.getStringArrayListExtra(RecognizerIntent.EXTRA_RESULTS)?.firstOrNull()?.takeIf { it.isNotBlank() }?.let(aoReconhecer)
        }
    }
    if (!disponivel) {
        return null
    }

    return {
        try {
            lancador.launch(pedido)
        } catch (_: ActivityNotFoundException) {
            // O reconhecedor sumiu (desinstalado/desativado): nada a fazer; o teclado continua com o microfone.
        }
    }
}

/** "Ouvir" a resposta com a voz do Android, em português do Brasil e um pouco mais devagar. */
class Leitor internal constructor() {
    private val estado = mutableStateOf(false)
    internal var tts: TextToSpeech? = null
    internal var pronto = false

    /** Verdadeiro enquanto lê em voz alta (o botão vira "Parar de ouvir"). */
    val falando: State<Boolean> get() = estado

    fun alternar(texto: String) {
        val motor = tts ?: return
        if (estado.value) {
            motor.stop()
            estado.value = false
            return
        }
        if (pronto) {
            estado.value = true
            motor.speak(texto, TextToSpeech.QUEUE_FLUSH, null, "resposta")
        }
    }

    internal fun terminou() {
        estado.value = false
    }
}

@Composable
fun rememberLeitor(): Leitor {
    val context = LocalContext.current
    val leitor = remember { Leitor() }
    DisposableEffect(Unit) {
        lateinit var motor: TextToSpeech
        motor = TextToSpeech(context.applicationContext) { status ->
            if (status == TextToSpeech.SUCCESS) {
                motor.language = Locale.forLanguageTag("pt-BR")
                motor.setSpeechRate(0.95f)
                leitor.pronto = true
            }
        }
        motor.setOnUtteranceProgressListener(object : UtteranceProgressListener() {
            override fun onStart(utteranceId: String?) = Unit

            override fun onDone(utteranceId: String?) = leitor.terminou()

            @Deprecated("Exigido pela API antiga do Android")
            override fun onError(utteranceId: String?) = leitor.terminou()
        })
        leitor.tts = motor
        onDispose {
            motor.stop()
            motor.shutdown()
            leitor.tts = null
            leitor.terminou()
        }
    }

    return leitor
}
