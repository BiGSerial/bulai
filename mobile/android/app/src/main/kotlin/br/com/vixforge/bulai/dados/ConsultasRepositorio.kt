@file:Suppress("ExifInterface")

package br.com.vixforge.bulai.dados

import android.content.Context
import android.graphics.Bitmap
import android.graphics.BitmapFactory
import android.graphics.Matrix
import android.media.ExifInterface
import androidx.core.graphics.scale
import android.net.Uri
import br.com.vixforge.bulai.dados.api.Bula
import br.com.vixforge.bulai.dados.api.BulaiApi
import br.com.vixforge.bulai.dados.api.BuscaDeRemedios
import br.com.vixforge.bulai.dados.api.HistoricoDePerguntas
import br.com.vixforge.bulai.dados.api.Horarios
import br.com.vixforge.bulai.dados.api.InicioDaConversa
import br.com.vixforge.bulai.dados.api.PedidoDeConfirmacao
import br.com.vixforge.bulai.dados.api.PedidoDeHorarios
import br.com.vixforge.bulai.dados.api.PedidoDePergunta
import br.com.vixforge.bulai.dados.api.Receita
import br.com.vixforge.bulai.dados.api.Resposta
import br.com.vixforge.bulai.dados.api.ResultadoDaConfirmacao
import br.com.vixforge.bulai.dados.api.chamar
import br.com.vixforge.bulai.sync.Sincronizacao
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.MultipartBody
import okhttp3.RequestBody.Companion.toRequestBody
import java.io.ByteArrayOutputStream
import kotlin.math.max

/** Perguntar, histórico de perguntas e busca no catálogo. A conversa fica no aparelho; o servidor guarda o histórico. */
class PerguntasRepositorio(private val api: BulaiApi, private val cache: CacheEmDisco) {
    suspend fun inicioGuardado(): InicioDaConversa? = cache.ler(INICIO, InicioDaConversa.serializer())

    suspend fun inicio(remedio: Long? = null): Result<InicioDaConversa> =
        chamar { api.inicioDaConversa(remedio) }.onSuccess { if (remedio == null) cache.gravar(INICIO, InicioDaConversa.serializer(), it) }

    suspend fun perguntar(pedido: PedidoDePergunta): Result<Resposta> = chamar { api.perguntar(pedido).answer }

    suspend fun historico(pagina: Int = 1): Result<HistoricoDePerguntas> = chamar { api.perguntas(pagina) }

    suspend fun pergunta(uuid: String): Result<Resposta> = chamar { api.pergunta(uuid).answer }

    suspend fun apagar(uuid: String): Result<Unit> = chamar { api.apagarPergunta(uuid) }.map { }

    suspend fun buscarRemedios(termo: String): Result<BuscaDeRemedios> = chamar { api.buscarRemedios(termo.trim()) }

    /** Bula humanizada pronta fica guardada no aparelho: reabrir mostra na hora e atualiza por cima. */
    suspend fun bulaGuardada(remedio: Long): Bula? = cache.ler("bula$remedio", Bula.serializer())

    suspend fun bula(remedio: Long): Result<Bula> = chamar { api.bula(remedio).leaflet }.onSuccess { guardarBula(it) }

    suspend fun gerarBula(remedio: Long): Result<Bula> = chamar { api.gerarBula(remedio).leaflet }.onSuccess { guardarBula(it) }

    private suspend fun guardarBula(bula: Bula) {
        if (bula.state == Bula.PRONTA) {
            cache.gravar("bula${bula.medicine.id}", Bula.serializer(), bula)
        }
    }

    private companion object {
        const val INICIO = "perguntar"
    }
}

/** Receita por foto: a imagem é reduzida e endireitada no aparelho antes de subir (menos dados móveis, leitura igual). */
class ReceitasRepositorio(context: Context, private val api: BulaiApi) {
    private val app = context.applicationContext

    suspend fun enviar(foto: Uri): Result<Receita> {
        val bytes = withContext(Dispatchers.Default) { runCatching { prepararFoto(foto) }.getOrNull() }
            ?: return Result.failure(IllegalArgumentException("Não conseguimos abrir esta imagem. Tente outra foto."))
        val parte = MultipartBody.Part.createFormData("photo", "receita.jpg", bytes.toRequestBody("image/jpeg".toMediaType()))

        return chamar { api.enviarReceita(parte).prescription }
    }

    suspend fun receita(id: Long): Result<Receita> = chamar { api.receita(id).prescription }

    suspend fun horarios(quandoTomar: String): Result<Horarios> = chamar { api.horarios(PedidoDeHorarios(quandoTomar)).schedule }

    /** Os remédios novos entram na agenda dos alarmes do aparelho em seguida. */
    suspend fun confirmar(id: Long, pedido: PedidoDeConfirmacao): Result<ResultadoDaConfirmacao> =
        chamar { api.confirmarReceita(id, pedido) }.onSuccess { Sincronizacao.agora(app) }

    suspend fun descartar(id: Long): Result<Unit> = chamar { api.descartarReceita(id) }.map { }

    /** JPEG de até [LADO_MAXIMO] px no lado maior, já na orientação da foto, qualidade 85. */
    private fun prepararFoto(uri: Uri): ByteArray = ByteArrayOutputStream().use { saida ->
        Fotos.carregar(app, uri, LADO_MAXIMO).compress(Bitmap.CompressFormat.JPEG, 85, saida)
        saida.toByteArray()
    }

    private companion object {
        const val LADO_MAXIMO = 2400
    }
}

/**
 * Abre uma foto (câmera ou galeria) reduzida para o tamanho pedido e endireitada pela orientação gravada no EXIF.
 * O ExifInterface do próprio Android basta a partir do minSdk 26 (lê de stream); evita uma dependência a mais.
 */
object Fotos {
    fun carregar(context: Context, uri: Uri, ladoMaximo: Int): Bitmap {
        val resolver = context.contentResolver
        val limites = BitmapFactory.Options().apply { inJustDecodeBounds = true }
        resolver.openInputStream(uri)?.use { BitmapFactory.decodeStream(it, null, limites) }
        require(limites.outWidth > 0 && limites.outHeight > 0) { "imagem ilegível" }
        var amostra = 1
        while (max(limites.outWidth, limites.outHeight) / (amostra * 2) >= ladoMaximo) {
            amostra *= 2
        }
        var imagem = resolver.openInputStream(uri)?.use { BitmapFactory.decodeStream(it, null, BitmapFactory.Options().apply { inSampleSize = amostra }) }
            ?: error("imagem ilegível")
        val maior = max(imagem.width, imagem.height)
        if (maior > ladoMaximo) {
            val fator = ladoMaximo.toFloat() / maior
            imagem = imagem.scale((imagem.width * fator).toInt(), (imagem.height * fator).toInt())
        }
        val graus = resolver.openInputStream(uri)?.use {
            when (ExifInterface(it).getAttributeInt(ExifInterface.TAG_ORIENTATION, ExifInterface.ORIENTATION_NORMAL)) {
                ExifInterface.ORIENTATION_ROTATE_90 -> 90f
                ExifInterface.ORIENTATION_ROTATE_180 -> 180f
                ExifInterface.ORIENTATION_ROTATE_270 -> 270f
                else -> 0f
            }
        } ?: 0f

        return if (graus == 0f) imagem else Bitmap.createBitmap(imagem, 0, 0, imagem.width, imagem.height, Matrix().apply { postRotate(graus) }, true)
    }
}
