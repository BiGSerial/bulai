package br.com.vixforge.bulai.dados.api

import okhttp3.MultipartBody
import okhttp3.ResponseBody
import retrofit2.http.Body
import retrofit2.http.GET
import retrofit2.http.HTTP
import retrofit2.http.Multipart
import retrofit2.http.POST
import retrofit2.http.PUT
import retrofit2.http.Part
import retrofit2.http.Path
import retrofit2.http.Query
import retrofit2.http.Streaming

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
    suspend fun buscarRemedios(@Query("q") termo: String, @Query("limit") limite: Int = 6, @Query("exclude") excluir: String? = null): BuscaDeRemedios

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

    @HTTP(method = "DELETE", path = "conta", hasBody = true)
    suspend fun apagarConta(@Body pedido: PedidoDeExclusao): Mensagem

    // Quem cuida de mim (paciente)
    @GET("cuidadores")
    suspend fun cuidadores(): MeusCuidadores

    @POST("cuidadores/convite")
    suspend fun gerarConvite(): MeusCuidadores

    @HTTP(method = "DELETE", path = "cuidadores/convite")
    suspend fun cancelarConvite(): MeusCuidadores

    @POST("cuidadores/{id}/aprovar")
    suspend fun aprovarCuidador(@Path("id") vinculo: Long, @Body pedido: PedidoDePermissoes): MeusCuidadores

    @POST("cuidadores/{id}/recusar")
    suspend fun recusarCuidador(@Path("id") vinculo: Long): MeusCuidadores

    @PUT("cuidadores/{id}/permissoes")
    suspend fun mudarPermissoes(@Path("id") vinculo: Long, @Body pedido: PedidoDePermissoes): MeusCuidadores

    @HTTP(method = "DELETE", path = "cuidadores/{id}")
    suspend fun tirarAcesso(@Path("id") vinculo: Long): MeusCuidadores

    // Cuidar de alguém (quem cuida)
    @GET("cuidando")
    suspend fun quemEuCuido(): QuemEuCuido

    @POST("cuidando")
    suspend fun usarConvite(@Body pedido: PedidoDeCodigo): QuemEuCuido

    @HTTP(method = "DELETE", path = "cuidando/{id}")
    suspend fun deixarDeAcompanhar(@Path("id") vinculo: Long): QuemEuCuido

    @GET("cuidando/{id}/dia")
    suspend fun diaDeQuemCuido(@Path("id") vinculo: Long, @Query("dia") deslocamento: Int): Dia

    @POST("cuidando/{id}/dia/doses")
    suspend fun marcarParaQuemCuido(@Path("id") vinculo: Long, @Body pedido: PedidoDeMarcacao): Dia

    @HTTP(method = "DELETE", path = "cuidando/{id}/dia/doses", hasBody = true)
    suspend fun desmarcarParaQuemCuido(@Path("id") vinculo: Long, @Body pedido: PedidoDeMarcacao): Dia

    // Resumo para o médico
    @Streaming
    @GET("resumo.pdf")
    suspend fun resumoParaOMedico(): ResponseBody

    // Meus remédios
    @GET("perfil")
    suspend fun perfil(): Perfil

    @POST("perfil/consentimento")
    suspend fun consentir(): Perfil

    @HTTP(method = "DELETE", path = "perfil/dados-de-saude")
    suspend fun apagarDadosDeSaude(): Perfil

    @POST("perfil/remedios")
    suspend fun adicionarRemedio(@Body pedido: PedidoDeRemedio): Perfil

    @PUT("perfil/remedios/{id}")
    suspend fun salvarPosologia(@Path("id") remedio: Long, @Body pedido: PedidoDeDoseEHorarios): Perfil

    @HTTP(method = "DELETE", path = "perfil/remedios/{id}")
    suspend fun tirarRemedio(@Path("id") remedio: Long): Perfil

    @POST("perfil/receitas")
    suspend fun adicionarReceitaDoPerfil(@Body pedido: PedidoDeReceitaDoPerfil): Perfil

    @HTTP(method = "DELETE", path = "perfil/receitas/{id}")
    suspend fun tirarReceita(@Path("id") receita: Long): Perfil

    @POST("perfil/alergias")
    suspend fun adicionarAlergia(@Body pedido: PedidoDeAlergia): Perfil

    @HTTP(method = "DELETE", path = "perfil/alergias/{id}")
    suspend fun tirarAlergia(@Path("id") alergia: Long): Perfil

    @POST("posologia/sugestao")
    suspend fun sugerirHorarios(@Body pedido: PedidoDeSugestao): RespostaDaSugestao

    @GET("cuidando/{id}/remedios")
    suspend fun remediosDeQuemCuido(@Path("id") vinculo: Long): RemediosDeQuemCuido

    @PUT("cuidando/{id}/remedios/{remedio}")
    suspend fun salvarPosologiaDeQuemCuido(@Path("id") vinculo: Long, @Path("remedio") remedio: Long, @Body pedido: PedidoDeDoseEHorarios): RemediosDeQuemCuido

    // Interações e bulas
    @GET("interacoes")
    suspend fun interacoes(): InicioDasInteracoes

    @GET("interacoes/remedios/{id}")
    suspend fun remedioDaConsulta(@Path("id") remedio: Long, @Query("selected") escolhidos: String): RemedioDaConsulta

    @POST("interacoes")
    suspend fun verificarInteracoes(@Body pedido: PedidoDeConsulta): ConsultaDeInteracoes

    @GET("interacoes/{uuid}")
    suspend fun consulta(@Path("uuid") uuid: String): ConsultaDeInteracoes

    @POST("interacoes/{uuid}/repetir")
    suspend fun repetirConsulta(@Path("uuid") uuid: String): ConsultaDeInteracoes

    @HTTP(method = "DELETE", path = "interacoes/{uuid}")
    suspend fun apagarConsulta(@Path("uuid") uuid: String): Mensagem

    @GET("bulas")
    suspend fun bulas(@Query("q") termo: String? = null): ListaDeBulas
}
