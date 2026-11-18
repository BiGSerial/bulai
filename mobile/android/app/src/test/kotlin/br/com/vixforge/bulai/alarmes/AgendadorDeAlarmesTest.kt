package br.com.vixforge.bulai.alarmes

import br.com.vixforge.bulai.dados.Agenda
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test
import java.time.OffsetDateTime

/** Quando cada dose toca, a partir da agenda do servidor e do que o aparelho já fez (marcou, tocou, adiou). */
class AgendadorDeAlarmesTest {
    private val agora = OffsetDateTime.parse("2026-11-05T09:00:00-03:00").toInstant().toEpochMilli()

    private fun agenda(vararg doses: String) = Agenda.ler("""{"reminders_enabled": true, "late_after_minutes": 60, "lead_minutes": 10, "doses": [${doses.joinToString(",")}]}""")!!

    private fun dose(chave: String, quando: String, status: String = "pending", lembrar: Boolean = true) =
        """{"key": "$chave", "medication_id": 7, "name": "Losartana", "dosage": "1 comprimido", "time": "${quando.substring(11, 16)}", "scheduled_for": "$quando", "status": "$status", "dynamic": false, "remind": $lembrar}"""

    @Test
    fun le_a_agenda_do_servidor_em_ordem_de_horario() {
        val lida = agenda(dose("b", "2026-11-05T20:00:00-03:00"), dose("a", "2026-11-05T08:00:00-03:00", "taken"))

        assertEquals(listOf("a", "b"), lida.doses.map { it.chave })
        assertTrue(lida.doses.first().marcada)
        assertEquals(OffsetDateTime.parse("2026-11-05T23:00:00Z").toInstant().toEpochMilli(), lida.doses.last().agendadaPara)
        assertEquals("2026-11-05T20:00:00-03:00", lida.doses.last().agendadaParaIso)
    }

    @Test
    fun agenda_invalida_nao_derruba_o_aplicativo() {
        assertNull(Agenda.ler("{}"))
        assertNull(Agenda.ler(null))
    }

    @Test
    fun toca_so_doses_futuras_pendentes_com_lembrete() {
        val planejado = AgendadorDeAlarmes.planejar(
            agenda(
                dose("futura", "2026-11-05T12:00:00-03:00"),
                dose("tomada", "2026-11-05T13:00:00-03:00", "taken"),
                dose("pulada", "2026-11-05T14:00:00-03:00", "skipped"),
                dose("sem-lembrete", "2026-11-05T15:00:00-03:00", lembrar = false),
                dose("marcada-no-celular", "2026-11-05T16:00:00-03:00"),
                dose("antiga", "2026-11-05T07:00:00-03:00"),
            ),
            agora,
            marcadas = setOf("marcada-no-celular"),
            tocados = emptySet(),
            adiados = emptyMap(),
        )

        assertEquals(listOf("futura"), planejado.map { it.first.chave })
        assertEquals(planejado.first().first.agendadaPara, planejado.first().second)
    }

    @Test
    fun dose_que_passou_ha_pouco_sem_tocar_toca_logo_e_nao_repete_depois_de_tocar() {
        val recente = agenda(dose("recente", "2026-11-05T08:50:00-03:00"))

        val primeira = AgendadorDeAlarmes.planejar(recente, agora, emptySet(), emptySet(), emptyMap())
        assertEquals(agora + 2_000, primeira.single().second)

        assertTrue(AgendadorDeAlarmes.planejar(recente, agora, emptySet(), setOf("recente"), emptyMap()).isEmpty())
    }

    @Test
    fun dose_adiada_toca_no_novo_horario_mesmo_ja_tendo_tocado() {
        val adiadoPara = agora + 10 * 60_000L
        val planejado = AgendadorDeAlarmes.planejar(agenda(dose("adiada", "2026-11-05T08:55:00-03:00")), agora, emptySet(), setOf("adiada"), mapOf("adiada" to adiadoPara))

        assertEquals(adiadoPara, planejado.single().second)
    }

    @Test
    fun dose_adiada_e_depois_marcada_nao_toca() {
        val planejado = AgendadorDeAlarmes.planejar(agenda(dose("adiada", "2026-11-05T08:55:00-03:00")), agora, setOf("adiada"), emptySet(), mapOf("adiada" to agora + 60_000))

        assertTrue(planejado.isEmpty())
    }
}
