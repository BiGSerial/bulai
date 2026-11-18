package br.com.vixforge.bulai.dados

import br.com.vixforge.bulai.dados.api.CabecalhoDaReceita
import br.com.vixforge.bulai.dados.api.FalhaDaApi
import br.com.vixforge.bulai.dados.api.ItemDaReceita
import br.com.vixforge.bulai.dados.api.PedidoDeConfirmacao
import br.com.vixforge.bulai.dados.api.PedidoDePergunta
import br.com.vixforge.bulai.dados.api.PedidoDeEntrada
import br.com.vixforge.bulai.dados.api.Rede
import br.com.vixforge.bulai.dados.api.chamar
import kotlinx.coroutines.test.runTest
import mockwebserver3.MockResponse
import mockwebserver3.MockWebServer
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test

/** Contrato com /api/mobile/v1: cabeçalhos, leitura do JSON e tradução dos erros do Laravel. */
class RedeTest {
    private val servidor = MockWebServer()
    private var perdeuAcesso = 0

    @Before
    fun iniciar() = servidor.start()

    @After
    fun parar() = servidor.close()

    private fun api(token: String? = "bulai_app_x") = Rede.criar({ token }, { perdeuAcesso++ }, servidor.url("/api/mobile/v1/").toString())

    @Test
    fun enviaOTokenELeAResposta() = runTest {
        servidor.enqueue(MockResponse.Builder().body("""{"first_name":"Maria","date_label":"Quinta","health_consent":true,"has_medications":true,"next_dose":{"key":"1|x","medication_id":1,"name":"Losartana","time":"08:00","scheduled_for":"2026-11-05T08:00:00-03:00","status":"due","extra":"ignorado"},"progress":{"total":2,"taken":0,"done":0},"unread_notices":3,"caring_for":[]}""").build())

        val inicio = api().inicio()

        val pedido = servidor.takeRequest()
        assertEquals("/api/mobile/v1/inicio", pedido.url.encodedPath)
        assertEquals("Bearer bulai_app_x", pedido.headers["Authorization"])
        assertEquals("application/json", pedido.headers["Accept"])
        assertEquals("Losartana", inicio.proxima?.name)
        assertEquals(3, inicio.avisosNaoLidos)
    }

    @Test
    fun validacaoViraMensagensPorCampo() = runTest {
        servidor.enqueue(MockResponse.Builder().code(422).body("""{"message":"Dados inválidos","errors":{"email":["Essas credenciais não correspondem."],"password":["Obrigatória."]}}""").build())

        val falha = chamar { api(null).entrar(PedidoDeEntrada("a@b.c", "x", "id", "Pixel", "1.0")) }.exceptionOrNull()

        assertTrue(falha is FalhaDaApi.Validacao)
        assertEquals("Essas credenciais não correspondem.", (falha as FalhaDaApi.Validacao).campos["email"])
        assertEquals(null, servidor.takeRequest().headers["Authorization"])
    }

    @Test
    fun tokenRecusadoEncerraASessao() = runTest {
        servidor.enqueue(MockResponse.Builder().code(401).body("""{"message":"Aparelho desconectado."}""").build())

        val falha = chamar { api().inicio() }.exceptionOrNull()

        assertTrue(falha is FalhaDaApi.NaoAutorizado)
        assertEquals(1, perdeuAcesso)
    }

    @Test
    fun senhaProvisoriaLimiteESemRede() = runTest {
        servidor.enqueue(MockResponse.Builder().code(403).body("""{"message":"Troque a senha.","code":"must_change_password"}""").build())
        servidor.enqueue(MockResponse.Builder().code(429).body("""{"message":"Espere 1 minuto."}""").build())

        assertTrue(chamar { api().inicio() }.exceptionOrNull() is FalhaDaApi.TrocarSenha)
        assertEquals("Espere 1 minuto.", chamar { api().inicio() }.exceptionOrNull()?.message)

        servidor.close()
        assertTrue(chamar { api().inicio() }.exceptionOrNull() is FalhaDaApi.SemConexao)
    }

    @Test
    fun aConfirmacaoDaReceitaMandaOsCamposNoValorPadrao() = runTest {
        servidor.enqueue(MockResponse.Builder().body("""{"confirmed":1,"unmatched":0,"message":"1 remédio da receita entrou no seu tratamento."}""").build())

        val resultado = api().confirmarReceita(7, PedidoDeConfirmacao(CabecalhoDaReceita("Dra. Ana"), listOf(ItemDaReceita("Dipirona 500 mg", seNecessario = true))))

        val corpo = servidor.takeRequest().body?.utf8().orEmpty()
        assertTrue(corpo, corpo.contains("\"schedule_mode\":\"fixed\""))
        assertTrue(corpo, corpo.contains("\"skip\":false"))
        assertTrue("nulos não vão", !corpo.contains("medicine_id"))
        assertEquals("1 remédio da receita entrou no seu tratamento.", resultado.message)
    }

    @Test
    fun aRespostaDoPerguntarChegaEmTrechosEO409ViraConflito() = runTest {
        servidor.enqueue(MockResponse.Builder().body("""{"answer":{"uuid":"u1","question":"omeprazol em jejum?","status":"answered","paragraphs":[[{"text":"O ","bold":false},{"text":"omeprazol","bold":true},{"text":" vai em jejum.","bold":false}]],"medicines":[{"id":3,"name":"OMEPRAZOL","has_leaflet":true}],"can_listen":true}}""").build())
        servidor.enqueue(MockResponse.Builder().code(409).body("""{"message":"Aceite o consentimento.","code":"health_consent_required"}""").build())

        val resposta = api().perguntar(PedidoDePergunta("omeprazol em jejum?", turns = listOf("u0"))).answer
        assertEquals("O omeprazol vai em jejum.", resposta.textoCorrido)
        assertTrue(resposta.paragraphs[0][1].bold)
        assertTrue(servidor.takeRequest().body?.utf8().orEmpty().contains("\"turns\":[\"u0\"]"))

        val falha = chamar { api().receita(1) }.exceptionOrNull()
        assertTrue(falha is FalhaDaApi.Conflito && falha.codigo == "health_consent_required")
    }
}
