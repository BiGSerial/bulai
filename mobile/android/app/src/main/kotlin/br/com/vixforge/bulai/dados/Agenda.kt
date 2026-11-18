package br.com.vixforge.bulai.dados

import org.json.JSONObject
import java.time.OffsetDateTime

/** Uma dose da agenda devolvida por `GET /api/mobile/v1/agenda` (ver App\Services\Treatment\DeviceAgendaService). */
data class Dose(
    val chave: String,
    val remedioId: Long,
    val nome: String,
    val dosagem: String?,
    val horario: String,
    /** `scheduled_for` como veio do servidor (ISO 8601 com fuso), devolvido igual ao marcar. */
    val agendadaParaIso: String,
    val agendadaPara: Long,
    val situacao: String,
    val lembrar: Boolean,
) {
    val marcada: Boolean
        get() = situacao == SITUACAO_TOMADA || situacao == SITUACAO_PULADA

    companion object {
        const val SITUACAO_TOMADA = "taken"
        const val SITUACAO_PULADA = "skipped"
    }
}

data class Agenda(
    val lembretesLigados: Boolean,
    val atrasoMinutos: Int,
    val antecedenciaMinutos: Int,
    val doses: List<Dose>,
) {
    companion object {
        fun ler(json: String?): Agenda? {
            if (json.isNullOrBlank()) {
                return null
            }

            return try {
                val raiz = JSONObject(json)
                val lista = raiz.getJSONArray("doses")
                val doses = (0 until lista.length()).map { i ->
                    val d = lista.getJSONObject(i)
                    val iso = d.getString("scheduled_for")
                    Dose(
                        chave = d.getString("key"),
                        remedioId = d.getLong("medication_id"),
                        nome = d.getString("name"),
                        dosagem = d.optString("dosage").takeUnless { d.isNull("dosage") || it.isBlank() },
                        horario = d.getString("time"),
                        agendadaParaIso = iso,
                        agendadaPara = OffsetDateTime.parse(iso).toInstant().toEpochMilli(),
                        situacao = d.getString("status"),
                        lembrar = d.optBoolean("remind", false),
                    )
                }.sortedBy { it.agendadaPara }
                Agenda(
                    lembretesLigados = raiz.optBoolean("reminders_enabled", false),
                    atrasoMinutos = raiz.optInt("late_after_minutes", 60),
                    antecedenciaMinutos = raiz.optInt("lead_minutes", 10),
                    doses = doses,
                )
            } catch (e: Exception) {
                null
            }
        }
    }
}
