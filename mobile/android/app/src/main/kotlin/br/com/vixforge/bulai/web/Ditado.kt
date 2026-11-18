package br.com.vixforge.bulai.web

import android.content.Context
import android.content.Intent
import android.os.Bundle
import android.os.Handler
import android.os.Looper
import android.speech.RecognitionListener
import android.speech.RecognizerIntent
import android.speech.SpeechRecognizer
import org.json.JSONObject

/**
 * Ditado com o reconhecimento de voz do Android. O WebView não tem a Web Speech API; resources/js/nativo.js instala
 * um `SpeechRecognition` que fala com esta classe, e resources/js/voz.js funciona sem mudança.
 * Eventos para a página: `window.__bulaiVoz(tipo, dados)` com start | result {texto, final} | error {codigo} | end.
 */
class Ditado(private val context: Context, private val emitir: (String, JSONObject) -> Unit, private val pedirMicrofone: (aoConceder: () -> Unit, aoNegar: () -> Unit) -> Unit) {
    private val principal = Handler(Looper.getMainLooper())
    private var reconhecedor: SpeechRecognizer? = null

    fun disponivel(): Boolean = SpeechRecognizer.isRecognitionAvailable(context)

    fun iniciar(idioma: String) = principal.post {
        pedirMicrofone({ comecar(idioma) }, {
            emitir("error", JSONObject().put("codigo", "not-allowed"))
            emitir("end", JSONObject())
        })
    }

    fun parar() = principal.post { reconhecedor?.stopListening() }

    fun cancelar() = principal.post {
        reconhecedor?.cancel()
        liberar()
        emitir("end", JSONObject())
    }

    fun liberar() {
        reconhecedor?.destroy()
        reconhecedor = null
    }

    private fun comecar(idioma: String) {
        liberar()
        if (!disponivel()) {
            emitir("error", JSONObject().put("codigo", "service-not-allowed"))
            emitir("end", JSONObject())
            return
        }
        val novo = SpeechRecognizer.createSpeechRecognizer(context)
        reconhecedor = novo
        novo.setRecognitionListener(object : RecognitionListener {
            override fun onReadyForSpeech(params: Bundle?) = emitir("start", JSONObject())

            override fun onPartialResults(partialResults: Bundle?) {
                texto(partialResults)?.let { emitir("result", JSONObject().put("texto", it).put("final", false)) }
            }

            override fun onResults(results: Bundle?) {
                texto(results)?.let { emitir("result", JSONObject().put("texto", it).put("final", true)) }
                liberar()
                emitir("end", JSONObject())
            }

            override fun onError(error: Int) {
                emitir("error", JSONObject().put("codigo", codigo(error)))
                liberar()
                emitir("end", JSONObject())
            }

            override fun onBeginningOfSpeech() = Unit

            override fun onRmsChanged(rmsdB: Float) = Unit

            override fun onBufferReceived(buffer: ByteArray?) = Unit

            override fun onEndOfSpeech() = Unit

            override fun onEvent(eventType: Int, params: Bundle?) = Unit
        })
        novo.startListening(
            Intent(RecognizerIntent.ACTION_RECOGNIZE_SPEECH)
                .putExtra(RecognizerIntent.EXTRA_LANGUAGE_MODEL, RecognizerIntent.LANGUAGE_MODEL_FREE_FORM)
                .putExtra(RecognizerIntent.EXTRA_LANGUAGE, idioma)
                .putExtra(RecognizerIntent.EXTRA_PARTIAL_RESULTS, true)
                .putExtra(RecognizerIntent.EXTRA_MAX_RESULTS, 1),
        )
    }

    private fun texto(resultados: Bundle?): String? =
        resultados?.getStringArrayList(SpeechRecognizer.RESULTS_RECOGNITION)?.firstOrNull()?.takeIf { it.isNotBlank() }

    /** Mesmos códigos da Web Speech API, que voz.js já sabe explicar ao paciente. */
    private fun codigo(erro: Int): String = when (erro) {
        SpeechRecognizer.ERROR_NO_MATCH, SpeechRecognizer.ERROR_SPEECH_TIMEOUT -> "no-speech"
        SpeechRecognizer.ERROR_INSUFFICIENT_PERMISSIONS -> "not-allowed"
        SpeechRecognizer.ERROR_NETWORK, SpeechRecognizer.ERROR_NETWORK_TIMEOUT, SpeechRecognizer.ERROR_SERVER -> "network"
        SpeechRecognizer.ERROR_AUDIO -> "audio-capture"
        SpeechRecognizer.ERROR_CLIENT, SpeechRecognizer.ERROR_RECOGNIZER_BUSY -> "aborted"
        SpeechRecognizer.ERROR_LANGUAGE_NOT_SUPPORTED, SpeechRecognizer.ERROR_LANGUAGE_UNAVAILABLE -> "language-not-supported"
        else -> "unknown"
    }
}
