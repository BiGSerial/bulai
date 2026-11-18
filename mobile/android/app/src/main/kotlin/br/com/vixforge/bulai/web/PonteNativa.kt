package br.com.vixforge.bulai.web

import android.content.Context
import android.os.Build
import android.os.PowerManager
import android.webkit.JavascriptInterface
import br.com.vixforge.bulai.BuildConfig
import br.com.vixforge.bulai.alarmes.AgendadorDeAlarmes
import br.com.vixforge.bulai.alarmes.Notificacoes
import br.com.vixforge.bulai.dados.Preferencias
import br.com.vixforge.bulai.sync.Sincronizacao
import org.json.JSONObject
import java.time.Instant
import java.time.LocalDate
import java.time.ZoneId

/**
 * `window.BulaiNative`: o que a página (resources/js/nativo.js) pode pedir à parte nativa. Os métodos rodam na thread
 * da ponte do WebView; tudo o que mexe em tela vai para o [Hospedeiro]. Só respondem quando a página aberta é do
 * próprio BULA-IA ([Hospedeiro.paginaConfiavel]); links externos nunca abrem dentro do aplicativo.
 */
class PonteNativa(context: Context, private val hospedeiro: Hospedeiro) {
    private val contexto = context.applicationContext
    private val preferencias = Preferencias(contexto)

    interface Hospedeiro {
        val paginaConfiavel: Boolean

        fun pedirPermissao(tipo: String)

        fun compartilhar(titulo: String, texto: String, url: String)

        fun ditado(): Ditado
    }

    @JavascriptInterface
    fun versao(): String = BuildConfig.VERSION_NAME

    @JavascriptInterface
    fun instalacao(): String = if (hospedeiro.paginaConfiavel) preferencias.instalacao else ""

    @JavascriptInterface
    fun aparelho(): String = "${Build.MANUFACTURER} ${Build.MODEL}".trim().take(120)

    @JavascriptInterface
    fun usuarioConectado(): String = if (hospedeiro.paginaConfiavel && preferencias.conectado) preferencias.usuario else ""

    @JavascriptInterface
    fun conectar(token: String, usuario: String) {
        if (!hospedeiro.paginaConfiavel || token.isBlank() || usuario.isBlank()) {
            return
        }
        Sincronizacao.conectar(contexto, token, usuario)
        if (!Notificacoes.podeNotificar(contexto) && !preferencias.pediuNotificacoes) {
            preferencias.pediuNotificacoes = true
            hospedeiro.pedirPermissao("notificacoes")
        }
    }

    @JavascriptInterface
    fun desconectar() {
        if (hospedeiro.paginaConfiavel) {
            Sincronizacao.desconectar(contexto)
        }
    }

    @JavascriptInterface
    fun sincronizar() {
        if (hospedeiro.paginaConfiavel) {
            Sincronizacao.agora(contexto)
        }
    }

    @JavascriptInterface
    fun estado(): String {
        if (!hospedeiro.paginaConfiavel) {
            return "null"
        }
        val energia = contexto.getSystemService(PowerManager::class.java)
        val proximo = AgendadorDeAlarmes.proximo(contexto)?.let { (dose, quando) ->
            val dia = Instant.ofEpochMilli(quando).atZone(ZoneId.systemDefault()).toLocalDate()
            val hora = Instant.ofEpochMilli(quando).atZone(ZoneId.systemDefault()).toLocalTime().toString().take(5)
            (if (dia.isAfter(LocalDate.now())) "amanhã às $hora" else "hoje às $hora") + " · " + dose.nome
        }

        return JSONObject()
            .put("conectado", preferencias.conectado)
            .put("notificacoes", Notificacoes.podeNotificar(contexto))
            .put("alarmesExatos", AgendadorDeAlarmes.podeAgendarExato(contexto))
            .put("telaCheia", Notificacoes.podeTelaCheia(contexto))
            .put("bateriaLivre", energia.isIgnoringBatteryOptimizations(contexto.packageName))
            .put("modoAlarme", preferencias.modoAlarme)
            .put("adiarMinutos", preferencias.adiarMinutos)
            .put("proximoAlarme", proximo ?: JSONObject.NULL)
            .put("versao", BuildConfig.VERSION_NAME)
            .toString()
    }

    /** tipo: notificacoes | alarmes | tela_cheia | bateria | aplicativo */
    @JavascriptInterface
    fun pedirPermissao(tipo: String) {
        if (hospedeiro.paginaConfiavel) {
            hospedeiro.pedirPermissao(tipo)
        }
    }

    @JavascriptInterface
    fun definirModoAlarme(ligado: Boolean) {
        if (!hospedeiro.paginaConfiavel) {
            return
        }
        preferencias.modoAlarme = ligado
        AgendadorDeAlarmes.reagendar(contexto)
        if (ligado && !Notificacoes.podeTelaCheia(contexto)) {
            hospedeiro.pedirPermissao("tela_cheia")
        }
    }

    @JavascriptInterface
    fun definirAdiamento(minutos: Int) {
        if (hospedeiro.paginaConfiavel) {
            preferencias.adiarMinutos = minutos
        }
    }

    @JavascriptInterface
    fun testarAlarme() {
        if (hospedeiro.paginaConfiavel) {
            AgendadorDeAlarmes.testar(contexto)
        }
    }

    @JavascriptInterface
    fun compartilhar(titulo: String, texto: String, url: String) {
        if (hospedeiro.paginaConfiavel) {
            hospedeiro.compartilhar(titulo, texto, url)
        }
    }

    @JavascriptInterface
    fun ouvirIniciar(idioma: String) {
        if (hospedeiro.paginaConfiavel) {
            hospedeiro.ditado().iniciar(idioma.ifBlank { "pt-BR" })
        }
    }

    @JavascriptInterface
    fun ouvirParar() {
        hospedeiro.ditado().parar()
    }

    @JavascriptInterface
    fun ouvirCancelar() {
        hospedeiro.ditado().cancelar()
    }

    @JavascriptInterface
    fun ditadoDisponivel(): Boolean = hospedeiro.ditado().disponivel()
}
