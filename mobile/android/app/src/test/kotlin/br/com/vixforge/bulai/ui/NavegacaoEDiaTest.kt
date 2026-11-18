package br.com.vixforge.bulai.ui

import br.com.vixforge.bulai.dados.api.Destino
import br.com.vixforge.bulai.dados.api.Dia
import br.com.vixforge.bulai.dados.api.Dose
import br.com.vixforge.bulai.dados.api.ResumoDoDia
import br.com.vixforge.bulai.ui.dia.comSituacao
import br.com.vixforge.bulai.ui.navegacao.Rotas
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test

class NavegacaoEDiaTest {
    @Test
    fun avisosENotificacoesAbremATelaCerta() {
        assertEquals(Rotas.DIA, Rotas.doDestino(Destino("consulta.hoje")))
        assertEquals(Rotas.AVISOS, Rotas.doDestino(Destino("consulta.avisos")))
        assertEquals(Rotas.INICIO, Rotas.doDestino(Destino("consulta.perfil")))
        assertEquals(Rotas.DIA, Rotas.doCaminho("/hoje"))
        assertEquals(Rotas.AVISOS, Rotas.doCaminho("/avisos/"))
        assertNull(Rotas.doCaminho(null))
    }

    @Test
    fun marcarNaTelaAtualizaODiaAntesDoServidor() {
        val dose = { chave: String, situacao: String -> Dose(chave, 1, "Losartana", time = "08:00", agendadaPara = "x", status = situacao) }
        val dia = Dia(0, "2026-11-05", "Quinta", true, true, summary = ResumoDoDia(3, 1, 1, 1), doses = listOf(dose("a", Dose.TOMADA), dose("b", Dose.ATRASADA), dose("c", Dose.PENDENTE)))

        val depois = dia.comSituacao("b", Dose.TOMADA)

        assertEquals(listOf(Dose.TOMADA, Dose.TOMADA, Dose.PENDENTE), depois.doses.map { it.status })
        assertEquals(ResumoDoDia(total = 3, done = 2, taken = 2, late = 0), depois.summary)
    }
}
