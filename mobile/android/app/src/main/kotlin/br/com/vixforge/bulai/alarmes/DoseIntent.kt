package br.com.vixforge.bulai.alarmes

import android.content.Intent
import br.com.vixforge.bulai.dados.Dose

/** Leva os dados de uma dose nos extras de um Intent (alarme, notificação, tela cheia, widget). */
object DoseIntent {
    private const val CHAVE = "dose.chave"
    private const val REMEDIO = "dose.remedio"
    private const val NOME = "dose.nome"
    private const val DOSAGEM = "dose.dosagem"
    private const val HORARIO = "dose.horario"
    private const val ISO = "dose.iso"
    private const val QUANDO = "dose.quando"

    fun escrever(intent: Intent, dose: Dose): Intent = intent
        .putExtra(CHAVE, dose.chave)
        .putExtra(REMEDIO, dose.remedioId)
        .putExtra(NOME, dose.nome)
        .putExtra(DOSAGEM, dose.dosagem)
        .putExtra(HORARIO, dose.horario)
        .putExtra(ISO, dose.agendadaParaIso)
        .putExtra(QUANDO, dose.agendadaPara)

    fun ler(intent: Intent?): Dose? {
        val chave = intent?.getStringExtra(CHAVE) ?: return null

        return Dose(
            chave = chave,
            remedioId = intent.getLongExtra(REMEDIO, 0),
            nome = intent.getStringExtra(NOME) ?: "",
            dosagem = intent.getStringExtra(DOSAGEM),
            horario = intent.getStringExtra(HORARIO) ?: "",
            agendadaParaIso = intent.getStringExtra(ISO) ?: "",
            agendadaPara = intent.getLongExtra(QUANDO, 0),
            situacao = "pending",
            lembrar = true,
        )
    }
}
