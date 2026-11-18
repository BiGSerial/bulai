package br.com.vixforge.bulai.dados.api

import retrofit2.http.Body
import retrofit2.http.GET
import retrofit2.http.HTTP
import retrofit2.http.POST
import retrofit2.http.PUT
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
}
