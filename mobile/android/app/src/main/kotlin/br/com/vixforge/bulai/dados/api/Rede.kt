package br.com.vixforge.bulai.dados.api

import br.com.vixforge.bulai.BuildConfig
import kotlinx.serialization.json.Json
import okhttp3.Interceptor
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import retrofit2.HttpException
import retrofit2.Retrofit
import retrofit2.converter.kotlinx.serialization.asConverterFactory
import java.io.IOException
import java.util.concurrent.TimeUnit

/** Falhas da API traduzidas para o que a tela mostra. */
sealed class FalhaDaApi(mensagem: String) : Exception(mensagem) {
    /** 422: mensagens por campo (`email`, `password`…) para mostrar embaixo de cada um. */
    class Validacao(mensagem: String, val campos: Map<String, String>) : FalhaDaApi(mensagem)

    /** 401: token recusado; a sessão local é encerrada. */
    class NaoAutorizado : FalhaDaApi("Sua sessão terminou. Entre de novo.")

    /** 403 com `must_change_password`. */
    class TrocarSenha : FalhaDaApi("Troque a senha provisória para continuar.")

    class Limite(mensagem: String) : FalhaDaApi(mensagem)

    /** 409: a ação não vale no estado atual (ex.: falta o consentimento de dados de saúde). */
    class Conflito(mensagem: String, val codigo: String?) : FalhaDaApi(mensagem)

    class SemConexao : FalhaDaApi("Sem conexão com a internet. Confira o Wi-Fi ou os dados móveis.")

    class Servidor(mensagem: String) : FalhaDaApi(mensagem)
}

object Rede {
    val json = Json {
        ignoreUnknownKeys = true
        // Campos no valor padrão também vão para o servidor (ex.: `schedule_mode: "fixed"` de um item da receita).
        encodeDefaults = true
        explicitNulls = false
        coerceInputValues = true
    }

    /**
     * @param token lido a cada chamada (muda ao entrar e sair)
     * @param aoPerderAcesso chamado quando o servidor recusa o token (401)
     */
    fun criar(token: () -> String?, aoPerderAcesso: () -> Unit, endereco: String = BuildConfig.BASE_URL + "/api/mobile/v1/"): BulaiApi {
        val cabecalhos = Interceptor { cadeia ->
            val pedido = cadeia.request().newBuilder()
                .header("Accept", "application/json")
                .header("User-Agent", "BulaiAndroid/${BuildConfig.VERSION_NAME}")
                .apply { token()?.let { header("Authorization", "Bearer $it") } }
                .build()
            // Pergunta, leitura de receita e bula humanizada passam pela IA: esperam mais que as outras telas.
            val lenta = pedido.method == "POST" && pedido.url.encodedPath.let { it.endsWith("/perguntar") || it.endsWith("/receitas") || it.endsWith("/gerar") }
            val resposta = if (lenta) cadeia.withReadTimeout(90, TimeUnit.SECONDS).withWriteTimeout(60, TimeUnit.SECONDS).proceed(pedido) else cadeia.proceed(pedido)
            if (resposta.code == 401 && pedido.header("Authorization") != null) {
                aoPerderAcesso()
            }
            resposta
        }
        val cliente = OkHttpClient.Builder()
            .addInterceptor(cabecalhos)
            .connectTimeout(15, TimeUnit.SECONDS)
            .readTimeout(30, TimeUnit.SECONDS)
            .retryOnConnectionFailure(true)
            .build()

        return Retrofit.Builder()
            .baseUrl(endereco)
            .client(cliente)
            .addConverterFactory(json.asConverterFactory("application/json".toMediaType()))
            .build()
            .create(BulaiApi::class.java)
    }

    /** Converte qualquer exceção de uma chamada em [FalhaDaApi]. */
    fun traduzir(erro: Throwable): FalhaDaApi = when (erro) {
        is FalhaDaApi -> erro
        is HttpException -> {
            val corpo = erro.response()?.errorBody()?.string()?.let { texto ->
                runCatching { json.decodeFromString(CorpoDeErro.serializer(), texto) }.getOrNull()
            }
            when (erro.code()) {
                401 -> FalhaDaApi.NaoAutorizado()
                403 -> if (corpo?.code == "must_change_password") FalhaDaApi.TrocarSenha() else FalhaDaApi.Servidor(corpo?.message ?: "Você não tem permissão para isso.")
                422 -> FalhaDaApi.Validacao(
                    corpo?.errors?.values?.firstOrNull()?.firstOrNull() ?: corpo?.message ?: "Confira os dados.",
                    corpo?.errors?.mapValues { it.value.firstOrNull().orEmpty() }.orEmpty(),
                )
                409 -> FalhaDaApi.Conflito(corpo?.message ?: "Isso não pode ser feito agora.", corpo?.code)
                404 -> FalhaDaApi.Servidor("Não encontramos isso. Pode ter sido apagado.")
                429 -> FalhaDaApi.Limite(corpo?.message ?: "Muitas tentativas. Espere um pouco e tente de novo.")
                else -> FalhaDaApi.Servidor("O BULA-IA não respondeu agora. Tente de novo em instantes.")
            }
        }
        is IOException -> FalhaDaApi.SemConexao()
        else -> FalhaDaApi.Servidor("Algo deu errado. Tente de novo.")
    }
}

/** Executa a chamada e devolve [Result] com a falha já traduzida. */
suspend fun <T> chamar(bloco: suspend () -> T): Result<T> = try {
    Result.success(bloco())
} catch (e: kotlinx.coroutines.CancellationException) {
    throw e
} catch (e: Throwable) {
    Result.failure(Rede.traduzir(e))
}
