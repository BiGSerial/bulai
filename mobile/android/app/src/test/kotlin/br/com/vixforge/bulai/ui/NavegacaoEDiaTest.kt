package br.com.vixforge.bulai.ui

import br.com.vixforge.bulai.dados.api.Destino
import br.com.vixforge.bulai.dados.api.Dia
import br.com.vixforge.bulai.dados.api.Dose
import br.com.vixforge.bulai.dados.api.ResumoDoDia
import br.com.vixforge.bulai.ui.dia.comSituacao
import br.com.vixforge.bulai.ui.dia.periodoDaDose
import br.com.vixforge.bulai.ui.navegacao.Rotas
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test

class NavegacaoEDiaTest {
    @Test
    fun avisosENotificacoesAbremATelaCerta() {
        assertEquals(Rotas.DIA, Rotas.doDestino(Destino("consulta.hoje")))
        assertEquals(Rotas.AVISOS, Rotas.doDestino(Destino("consulta.avisos")))
        assertEquals(Rotas.INICIO, Rotas.doDestino(Destino("consulta.desconhecida")))
        assertEquals(Rotas.DIA, Rotas.doCaminho("/hoje"))
        assertEquals(Rotas.AVISOS, Rotas.doCaminho("/avisos/"))
        assertNull(Rotas.doCaminho(null))
    }

    @Test
    fun perguntarEReceitaTemRotaPropria() {
        val params = { json: String -> kotlinx.serialization.json.Json.parseToJsonElement(json) }
        assertEquals("receita/42", Rotas.doDestino(Destino("consulta.receita.revisar", params("""{"prescription":42}"""))))
        assertEquals(Rotas.RECEITA, Rotas.doDestino(Destino("consulta.receita.revisar", params("{}"))))
        assertEquals("pergunta/abc", Rotas.doDestino(Destino("consulta.pergunta", params("""{"question":"abc"}"""))))
        assertEquals(Rotas.PERGUNTAR, Rotas.doCaminho("/perguntar?q=dipirona"))
        assertEquals(Rotas.RECEITA, Rotas.doCaminho("receitas/nova"))
        assertEquals("receita/7", Rotas.doCaminho("/receitas/7/revisar"))
        assertEquals("bula/12", Rotas.doCaminho("/bula/12"))
        assertEquals(Rotas.CONTA, Rotas.doCaminho("/conta"))
        assertEquals("Avisos", Rotas.titulo(Rotas.AVISOS))
        assertEquals("bula/12", Rotas.doDestino(Destino("consulta.bula", params("""{"medicine":12}"""))))
        assertEquals("Conferir a receita", Rotas.titulo(Rotas.REVISAR_RECEITA))
        assertNull(Rotas.titulo(Rotas.PERGUNTAR))
    }

    @Test
    fun modoCuidadorTemRotaPropria() {
        assertEquals(Rotas.CUIDADORES, Rotas.doDestino(Destino("consulta.cuidadores")))
        val params = { json: String -> kotlinx.serialization.json.Json.parseToJsonElement(json) }
        assertEquals("cuidando/12", Rotas.doDestino(Destino("consulta.cuidando.hoje", params("""{"vinculo":12}"""))))
        assertEquals(Rotas.CUIDAR, Rotas.doDestino(Destino("consulta.cuidando.hoje")))
        assertEquals("cuidando/7", Rotas.doCaminho("/cuidando/7/hoje"))
        assertEquals(Rotas.CUIDAR, Rotas.doCaminho("/cuidar?codigo=12345678"))
        assertEquals("cuidando/7?nome=Maria%20Jos%C3%A9", Rotas.cuidando(7, "Maria José"))
    }

    @Test
    fun perfilBulasEInteracoesTemRotaPropria() {
        val params = { json: String -> kotlinx.serialization.json.Json.parseToJsonElement(json) }
        assertEquals("perfil?aba=0", Rotas.doDestino(Destino("consulta.perfil")))
        assertEquals(Rotas.BULAS, Rotas.doCaminho("/bulas"))
        assertEquals("interacoes", Rotas.doDestino(Destino("consulta.pesquisa")))
        val uuid = "0b7f8c2e-4a1d-4c3e-9f00-123456789abc"
        assertEquals("interacoes/$uuid", Rotas.doDestino(Destino("consulta.resultado", params("""{"consultation":"$uuid"}"""))))
        assertEquals("interacoes/$uuid", Rotas.doCaminho("/consulta/$uuid"))
        assertEquals("cuidando/3/remedios", Rotas.doCaminho("cuidando/3/remedios"))
        assertEquals("cuidando/3/remedios", Rotas.doDestino(Destino("consulta.cuidando.remedios", params("""{"vinculo":3}"""))))
        assertEquals("interacoes?com=9", Rotas.interacoes(9))
    }

    @Test
    fun marcarNaTelaAtualizaODiaAntesDoServidor() {
        val dose = { chave: String, situacao: String -> Dose(chave, 1, "Losartana", time = "08:00", agendadaPara = "x", status = situacao) }
        val dia = Dia(0, "2026-11-05", "Quinta", true, true, summary = ResumoDoDia(3, 1, 1, 1), doses = listOf(dose("a", Dose.TOMADA), dose("b", Dose.ATRASADA), dose("c", Dose.PENDENTE)))

        val depois = dia.comSituacao("b", Dose.TOMADA)

        assertEquals(listOf(Dose.TOMADA, Dose.TOMADA, Dose.PENDENTE), depois.doses.map { it.status })
        assertEquals(ResumoDoDia(total = 3, done = 2, taken = 2, late = 0), depois.summary)
    }

    @Test
    fun doseDeMadrugadaNaoFicaNaManha() {
        val dose = { hora: String -> Dose(key = hora, remedioId = 1, name = "Vitamina D", time = hora, period = "manha", agendadaPara = "2026-11-19T$hora:00-03:00", status = Dose.PENDENTE) }
        assertEquals("madrugada", periodoDaDose(dose("00:28")))
        assertEquals("manha", periodoDaDose(dose("05:00")))
        assertEquals("tarde", periodoDaDose(dose("12:00")))
        assertEquals("noite", periodoDaDose(dose("18:00")))
    }
}
