package br.com.vixforge.bulai

import android.Manifest
import android.annotation.SuppressLint
import android.app.DownloadManager
import android.content.ActivityNotFoundException
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.os.Environment
import android.provider.MediaStore
import android.provider.Settings
import android.view.View
import android.webkit.CookieManager
import android.webkit.PermissionRequest
import android.webkit.URLUtil
import android.webkit.ValueCallback
import android.webkit.WebChromeClient
import android.webkit.WebResourceError
import android.webkit.WebResourceRequest
import android.webkit.WebSettings
import android.webkit.WebView
import android.webkit.WebViewClient
import android.widget.ProgressBar
import android.widget.Toast
import androidx.activity.ComponentActivity
import androidx.activity.OnBackPressedCallback
import androidx.activity.enableEdgeToEdge
import androidx.activity.result.contract.ActivityResultContracts
import androidx.core.content.ContextCompat
import androidx.core.content.FileProvider
import androidx.core.splashscreen.SplashScreen.Companion.installSplashScreen
import androidx.core.view.ViewCompat
import androidx.core.view.WindowInsetsCompat
import androidx.core.view.updatePadding
import br.com.vixforge.bulai.abertura.VideoDeAbertura
import br.com.vixforge.bulai.sync.Sincronizacao
import br.com.vixforge.bulai.web.Ditado
import br.com.vixforge.bulai.web.PonteNativa
import org.json.JSONObject
import java.io.File

/**
 * O aplicativo é o próprio BULA-IA (as mesmas telas do PWA) num WebView, com o que o navegador não dá:
 * alarmes exatos das doses, câmera para a receita, ditado por voz, download do resumo para o médico, compartilhar,
 * atalhos e widget. Só o endereço do BULA-IA abre aqui dentro; qualquer outro link vai para o aplicativo certo.
 */
class MainActivity : ComponentActivity(), PonteNativa.Hospedeiro {
    private lateinit var web: WebView
    private lateinit var progresso: ProgressBar
    private lateinit var ditado: Ditado
    private var abertura: VideoDeAbertura? = null
    private val base: Uri = Uri.parse(BuildConfig.BASE_URL)

    @Volatile
    override var paginaConfiavel: Boolean = false
        private set

    private var arquivosPendentes: ValueCallback<Array<Uri>>? = null
    private var fotoDaCamera: Uri? = null
    private var aoConcederMicrofone: (() -> Unit)? = null
    private var aoNegarMicrofone: (() -> Unit)? = null

    private val escolherArquivo = registerForActivityResult(ActivityResultContracts.StartActivityForResult()) { resultado ->
        val retorno = arquivosPendentes ?: return@registerForActivityResult
        arquivosPendentes = null
        val escolhidos = when {
            resultado.resultCode != RESULT_OK -> null
            resultado.data?.clipData != null -> resultado.data!!.clipData!!.let { clip -> Array(clip.itemCount) { clip.getItemAt(it).uri } }
            resultado.data?.data != null -> arrayOf(resultado.data!!.data!!)
            fotoDaCamera != null -> arrayOf(fotoDaCamera!!)
            else -> null
        }
        retorno.onReceiveValue(escolhidos)
    }

    private val pedirMicrofone = registerForActivityResult(ActivityResultContracts.RequestPermission()) { concedida ->
        if (concedida) aoConcederMicrofone?.invoke() else aoNegarMicrofone?.invoke()
        aoConcederMicrofone = null
        aoNegarMicrofone = null
    }

    private val pedirNotificacoes = registerForActivityResult(ActivityResultContracts.RequestPermission()) {
        avisarPaginaQueMudou()
    }

    @SuppressLint("SetJavaScriptEnabled")
    override fun onCreate(savedInstanceState: Bundle?) {
        val telaDoSistema = installSplashScreen()
        enableEdgeToEdge()
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_main)
        if (VideoDeAbertura.deveTocar(intent?.action, intent?.data != null, savedInstanceState != null, VideoDeAbertura.animacoesDesligadas(this))) {
            abertura = VideoDeAbertura(this, findViewById(R.id.abertura), findViewById(R.id.abertura_video)).also { video ->
                telaDoSistema.setKeepOnScreenCondition { video.aguardandoPrimeiroQuadro }
                video.tocar()
            }
        }
        web = findViewById(R.id.web)
        progresso = findViewById(R.id.progresso)
        ditado = Ditado(this, ::emitirVoz) { conceder, negar -> comMicrofone(conceder, negar) }

        // Barra de status, de navegação e teclado: a página ocupa só a área livre (a cor da marca fica por trás).
        ViewCompat.setOnApplyWindowInsetsListener(findViewById(R.id.raiz)) { vista, insets ->
            val barras = insets.getInsets(WindowInsetsCompat.Type.systemBars() or WindowInsetsCompat.Type.ime() or WindowInsetsCompat.Type.displayCutout())
            vista.updatePadding(left = barras.left, top = barras.top, right = barras.right, bottom = barras.bottom)
            WindowInsetsCompat.CONSUMED
        }

        WebView.setWebContentsDebuggingEnabled(BuildConfig.DEBUG)
        CookieManager.getInstance().apply {
            setAcceptCookie(true)
            setAcceptThirdPartyCookies(web, false)
        }
        web.settings.apply {
            javaScriptEnabled = true
            domStorageEnabled = true
            allowFileAccess = false
            allowContentAccess = true
            mixedContentMode = WebSettings.MIXED_CONTENT_NEVER_ALLOW
            mediaPlaybackRequiresUserGesture = true
            setSupportMultipleWindows(false)
            // Mesma escala de fonte do Android (pessoas que usam letra grande): o PWA já foi ajustado para isso.
            textZoom = (resources.configuration.fontScale * 100).toInt()
            userAgentString = "$userAgentString BulaiAndroid/${BuildConfig.VERSION_NAME}"
        }
        web.addJavascriptInterface(PonteNativa(this, this), "BulaiNative")
        web.webViewClient = Cliente()
        web.webChromeClient = Cromo()
        web.setDownloadListener { url, userAgent, contentDisposition, mimeType, _ -> baixar(url, userAgent, contentDisposition, mimeType) }

        onBackPressedDispatcher.addCallback(this, object : OnBackPressedCallback(true) {
            override fun handleOnBackPressed() {
                if (web.canGoBack()) {
                    web.goBack()
                } else {
                    finish()
                }
            }
        })

        if (savedInstanceState != null) {
            web.restoreState(savedInstanceState)
        } else {
            web.loadUrl(urlPara(intent))
        }
    }

    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        if (intent.data != null) {
            web.loadUrl(urlPara(intent))
        }
    }

    override fun onResume() {
        super.onResume()
        web.onResume()
        // Pode ter voltado das configurações (permissão de alarme, bateria): a tela de Avisos se atualiza.
        avisarPaginaQueMudou()
        Sincronizacao.agora(this)
    }

    override fun onPause() {
        web.onPause()
        CookieManager.getInstance().flush()
        ditado.cancelar()
        super.onPause()
    }

    override fun onSaveInstanceState(outState: Bundle) {
        super.onSaveInstanceState(outState)
        web.saveState(outState)
    }

    override fun onDestroy() {
        abertura?.liberar()
        ditado.liberar()
        web.destroy()
        super.onDestroy()
    }

    // ---- PonteNativa.Hospedeiro ----

    override fun ditado(): Ditado = ditado

    override fun pedirPermissao(tipo: String) = runOnUiThread {
        val pacote = Uri.parse("package:$packageName")
        when (tipo) {
            // 1º pedido e o seguinte a uma recusa: diálogo do sistema. Depois de duas recusas o Android não mostra
            // mais o diálogo; aí só pelas configurações.
            "notificacoes" -> if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU && ContextCompat.checkSelfPermission(this, Manifest.permission.POST_NOTIFICATIONS) != PackageManager.PERMISSION_GRANTED && (!jaPediuNotificacoes() || shouldShowRequestPermissionRationale(Manifest.permission.POST_NOTIFICATIONS))) {
                marcarPedidoDeNotificacoes()
                pedirNotificacoes.launch(Manifest.permission.POST_NOTIFICATIONS)
            } else {
                abrirConfiguracao(Intent(Settings.ACTION_APP_NOTIFICATION_SETTINGS).putExtra(Settings.EXTRA_APP_PACKAGE, packageName))
            }
            "alarmes" -> if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
                abrirConfiguracao(Intent(Settings.ACTION_REQUEST_SCHEDULE_EXACT_ALARM, pacote))
            }
            "tela_cheia" -> if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.UPSIDE_DOWN_CAKE) {
                abrirConfiguracao(Intent(Settings.ACTION_MANAGE_APP_USE_FULL_SCREEN_INTENT, pacote))
            }
            "bateria" -> abrirConfiguracao(Intent(Settings.ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS, pacote), Intent(Settings.ACTION_IGNORE_BATTERY_OPTIMIZATION_SETTINGS))
            else -> abrirConfiguracao(Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS, pacote))
        }
    }

    override fun compartilhar(titulo: String, texto: String, url: String) = runOnUiThread {
        val conteudo = listOf(texto, url).filter { it.isNotBlank() }.joinToString(" ")
        val envio = Intent(Intent.ACTION_SEND).setType("text/plain").putExtra(Intent.EXTRA_TEXT, conteudo).putExtra(Intent.EXTRA_SUBJECT, titulo)
        startActivity(Intent.createChooser(envio, getString(R.string.compartilhar)))
    }

    // ---- Navegação ----

    private inner class Cliente : WebViewClient() {
        override fun shouldOverrideUrlLoading(view: WebView, request: WebResourceRequest): Boolean {
            val destino = request.url
            if (eDoBulai(destino)) {
                return false
            }
            if (destino.scheme == "bulai") {
                view.loadUrl(urlDoCaminho(destino))
                return true
            }
            abrirFora(destino)
            return true
        }

        override fun doUpdateVisitedHistory(view: WebView, url: String?, isReload: Boolean) {
            paginaConfiavel = url != null && eDoBulai(Uri.parse(url))
        }

        override fun onPageStarted(view: WebView, url: String?, favicon: android.graphics.Bitmap?) {
            paginaConfiavel = url != null && eDoBulai(Uri.parse(url))
        }

        override fun onReceivedError(view: WebView, request: WebResourceRequest, error: WebResourceError) {
            if (request.isForMainFrame) {
                // Sem rede e sem o service worker em cache: tela própria, com os alarmes funcionando mesmo assim.
                val pagina = assets.open("offline.html").bufferedReader().use { it.readText() }.replace("{{URL}}", request.url.toString())
                view.loadDataWithBaseURL(null, pagina, "text/html", "utf-8", null)
            }
        }
    }

    private inner class Cromo : WebChromeClient() {
        override fun onProgressChanged(view: WebView, newProgress: Int) {
            progresso.progress = newProgress
            progresso.visibility = if (newProgress in 1..99) View.VISIBLE else View.GONE
        }

        override fun onShowFileChooser(webView: WebView, filePathCallback: ValueCallback<Array<Uri>>, fileChooserParams: FileChooserParams): Boolean {
            arquivosPendentes?.onReceiveValue(null)
            arquivosPendentes = filePathCallback
            val tipos = fileChooserParams.acceptTypes.filter { it.isNotBlank() }.ifEmpty { listOf("*/*") }
            val galeria = Intent(Intent.ACTION_GET_CONTENT).addCategory(Intent.CATEGORY_OPENABLE).setType(if (tipos.size == 1) tipos[0] else "*/*")
                .putExtra(Intent.EXTRA_MIME_TYPES, tipos.toTypedArray())
                .putExtra(Intent.EXTRA_ALLOW_MULTIPLE, fileChooserParams.mode == FileChooserParams.MODE_OPEN_MULTIPLE)
            val extras = mutableListOf<Intent>()
            if (tipos.any { it.startsWith("image") || it == "*/*" }) {
                fotoDaCamera = novaFoto()
                extras += Intent(MediaStore.ACTION_IMAGE_CAPTURE).putExtra(MediaStore.EXTRA_OUTPUT, fotoDaCamera).addFlags(Intent.FLAG_GRANT_WRITE_URI_PERMISSION)
            } else {
                fotoDaCamera = null
            }
            val escolha = if (fileChooserParams.isCaptureEnabled && extras.isNotEmpty()) extras.first() else Intent.createChooser(galeria, getString(R.string.escolher_arquivo)).putExtra(Intent.EXTRA_INITIAL_INTENTS, extras.toTypedArray())

            return try {
                escolherArquivo.launch(escolha)
                true
            } catch (e: ActivityNotFoundException) {
                arquivosPendentes = null
                false
            }
        }

        // getUserMedia (microfone) pedido pela página: só para o BULA-IA e com a permissão do Android.
        override fun onPermissionRequest(request: PermissionRequest) {
            runOnUiThread {
                val audio = PermissionRequest.RESOURCE_AUDIO_CAPTURE
                if (!eDoBulai(request.origin) || audio !in request.resources) {
                    request.deny()
                    return@runOnUiThread
                }
                comMicrofone({ request.grant(arrayOf(audio)) }, { request.deny() })
            }
        }
    }

    // ---- Auxiliares ----

    private fun comMicrofone(aoConceder: () -> Unit, aoNegar: () -> Unit) {
        if (ContextCompat.checkSelfPermission(this, Manifest.permission.RECORD_AUDIO) == PackageManager.PERMISSION_GRANTED) {
            aoConceder()
            return
        }
        aoConcederMicrofone = aoConceder
        aoNegarMicrofone = aoNegar
        pedirMicrofone.launch(Manifest.permission.RECORD_AUDIO)
    }

    private fun emitirVoz(tipo: String, dados: JSONObject) = runOnUiThread {
        web.evaluateJavascript("window.__bulaiVoz && window.__bulaiVoz(${JSONObject.quote(tipo)}, $dados)", null)
    }

    private fun avisarPaginaQueMudou() {
        if (::web.isInitialized) {
            web.evaluateJavascript("window.dispatchEvent(new CustomEvent('bulai-nativo-mudou'))", null)
        }
    }

    private fun eDoBulai(uri: Uri?): Boolean = uri != null && uri.scheme == base.scheme && uri.host == base.host && uri.port == base.port

    private fun urlPara(intent: Intent?): String {
        val dados = intent?.data
        return if (dados?.scheme == "bulai") urlDoCaminho(dados) else BuildConfig.BASE_URL + "/inicio?source=android"
    }

    /** bulai://abrir/hoje?x=1 → {BASE_URL}/hoje?x=1 (só caminhos do próprio BULA-IA). */
    private fun urlDoCaminho(uri: Uri): String {
        val caminho = (uri.path ?: "/").ifBlank { "/" }
        return BuildConfig.BASE_URL + caminho + (uri.encodedQuery?.let { "?$it" } ?: "")
    }

    private fun abrirFora(uri: Uri) {
        try {
            startActivity(Intent(Intent.ACTION_VIEW, uri).addCategory(Intent.CATEGORY_BROWSABLE))
        } catch (e: ActivityNotFoundException) {
            Toast.makeText(this, R.string.sem_app_para_abrir, Toast.LENGTH_SHORT).show()
        }
    }

    private fun abrirConfiguracao(vararg tentativas: Intent) {
        for (tentativa in tentativas) {
            try {
                startActivity(tentativa)
                return
            } catch (e: ActivityNotFoundException) {
                // tenta a próxima
            }
        }
        startActivity(Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS, Uri.parse("package:$packageName")))
    }

    private fun jaPediuNotificacoes(): Boolean = getSharedPreferences("bulai-permissoes", MODE_PRIVATE).getBoolean("notificacoes", false)

    private fun marcarPedidoDeNotificacoes() = getSharedPreferences("bulai-permissoes", MODE_PRIVATE).edit().putBoolean("notificacoes", true).apply()

    /** PDF do resumo para o médico e outros arquivos: DownloadManager com o cookie da sessão. */
    private fun baixar(url: String, userAgent: String, contentDisposition: String?, mimeType: String?) {
        val nome = URLUtil.guessFileName(url, contentDisposition, mimeType)
        val pedido = DownloadManager.Request(Uri.parse(url))
            .setMimeType(mimeType)
            .addRequestHeader("Cookie", CookieManager.getInstance().getCookie(url) ?: "")
            .addRequestHeader("User-Agent", userAgent)
            .setTitle(nome)
            .setNotificationVisibility(DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED)
            .setDestinationInExternalPublicDir(Environment.DIRECTORY_DOWNLOADS, nome)
        getSystemService(DownloadManager::class.java).enqueue(pedido)
        Toast.makeText(this, R.string.baixando, Toast.LENGTH_SHORT).show()
    }

    private fun novaFoto(): Uri {
        val pasta = File(cacheDir, "fotos").apply { mkdirs() }
        val arquivo = File.createTempFile("receita-", ".jpg", pasta)
        return FileProvider.getUriForFile(this, "$packageName.arquivos", arquivo)
    }

    companion object {
        /** Intent que abre o aplicativo num caminho do BULA-IA (notificações, widget, atalhos). */
        fun abrir(context: Context, caminho: String): Intent =
            Intent(context, MainActivity::class.java)
                .setAction(Intent.ACTION_VIEW)
                .setData(Uri.parse("bulai://abrir" + (if (caminho.startsWith("/")) caminho else "/$caminho")))
                .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TOP or Intent.FLAG_ACTIVITY_SINGLE_TOP)
    }
}
