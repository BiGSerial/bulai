package br.com.vixforge.bulai.dados.api

import okhttp3.MultipartBody
import retrofit2.http.Body
import retrofit2.http.GET
import retrofit2.http.HTTP
import retrofit2.http.Multipart
import retrofit2.http.POST
import retrofit2.http.PUT
import retrofit2.http.Part
import retrofit2.http.Path
import retrofit2.http.Query

/** Endpoints de /api/mobile/v1 usados pelas telas (routes/api.php). */
interface BulaiApi {
    @POST("entrar")
    suspend fun entrar(@Body pedido: PedidoDeEntrada): RespostaDeEntrada

    @POST("cadastro")
    suspend fun cadastrar(@Body pedido: PedidoDeCadastro): Mensagem

    @POST("esqueci-senha")
    suspend fun esqueciSenha(@Body pedido: PedidoDeEmail): Mensagem

    @GET("eu")
    suspend fun eu(): RespostaDoUsuario

    @POST("primeiro-acesso")
    suspend fun primeiroAcesso(@Body pedido: PedidoDeNovaSenha): RespostaDoUsuario

    @HTTP(method = "DELETE", path = "aparelho")
    suspend fun sair(): Qualquer

    @GET("inicio")
    suspend fun inicio(): Inicio

    @GET("dia")
    suspend fun dia(@Query("dia") deslocamento: Int): Dia

    @POST("dia/doses")
    suspend fun marcar(@Body pedido: PedidoDeMarcacao): Dia

    @HTTP(method = "DELETE", path = "dia/doses", hasBody = true)
    suspend fun desmarcar(@Body pedido: PedidoDeMarcacao): Dia

    @GET("avisos")
    suspend fun avisos(): CentralDeAvisos

    @POST("avisos/{id}/abrir")
    suspend fun abrirAviso(@Path("id") id: String): RespostaDeAbertura

    @POST("avisos/lidos")
    suspend fun marcarAvisosLidos(): CentralDeAvisos

    @HTTP(method = "DELETE", path = "avisos/{id}")
    suspend fun apagarAviso(@Path("id") id: String): CentralDeAvisos

    @HTTP(method = "DELETE", path = "avisos")
    suspend fun apagarAvisos(): CentralDeAvisos

    @PUT("avisos/preferencias")
    suspend fun preferencias(@Body alteracao: AlteracaoDePreferencias): CentralDeAvisos

    @GET("remedios")
    suspend fun buscarRemedios(@Query("q") termo: String, @Query("limit") limite: Int = 6): BuscaDeRemedios

    @GET("perguntar")
    suspend fun inicioDaConversa(@Query("medicamento") remedio: Long? = null): InicioDaConversa

    @POST("perguntar")
    suspend fun perguntar(@Body pedido: PedidoDePergunta): RespostaDaPergunta

    @GET("perguntas")
    suspend fun perguntas(@Query("page") pagina: Int = 1): HistoricoDePerguntas

    @GET("perguntas/{uuid}")
    suspend fun pergunta(@Path("uuid") uuid: String): RespostaDaPergunta

    @HTTP(method = "DELETE", path = "perguntas/{uuid}")
    suspend fun apagarPergunta(@Path("uuid") uuid: String): Qualquer

    @Multipart
    @POST("receitas")
    suspend fun enviarReceita(@Part foto: MultipartBody.Part): RespostaDaReceita

    @GET("receitas/{id}")
    suspend fun receita(@Path("id") id: Long): RespostaDaReceita

    @POST("receitas/horarios")
    suspend fun horarios(@Body pedido: PedidoDeHorarios): RespostaDeHorarios

    @POST("receitas/{id}/confirmar")
    suspend fun confirmarReceita(@Path("id") id: Long, @Body pedido: PedidoDeConfirmacao): ResultadoDaConfirmacao

    @HTTP(method = "DELETE", path = "receitas/{id}")
    suspend fun descartarReceita(@Path("id") id: Long): Mensagem

    @GET("bulas/{id}")
    suspend fun bula(@Path("id") remedio: Long): RespostaDaBula

    @POST("bulas/{id}/gerar")
    suspend fun gerarBula(@Path("id") remedio: Long): RespostaDaBula

    @GET("conta")
    suspend fun conta(): RespostaDoUsuario

    @PUT("conta/nome")
    suspend fun mudarNomeDeChamada(@Body pedido: PedidoDeNomeDeChamada): RespostaDoUsuario

    @PUT("conta/senha")
    suspend fun trocarSenha(@Body pedido: PedidoDeTrocaDeSenha): Mensagem
}
