package br.com.vixforge.bulai.alarmes

import android.content.Context
import android.os.PowerManager
import br.com.vixforge.bulai.dados.Preferencias
import java.time.Instant
import java.time.LocalDate
import java.time.ZoneId

/** Como estão as permissões e as preferências dos alarmes neste celular (seção "Alarmes neste celular" de Avisos). */
data class SituacaoDosAlarmes(
    val notificacoes: Boolean,
    val alarmesExatos: Boolean,
    val telaCheia: Boolean,
    val bateriaLivre: Boolean,
    val modoDespertador: Boolean,
    val adiarMinutos: Int,
    val proximoAlarme: String?,
) {
    /** Tudo liberado para o alarme tocar na hora, mesmo com o celular parado. */
    val tudoCerto: Boolean get() = notificacoes && alarmesExatos && bateriaLivre && (!modoDespertador || telaCheia)
}

object AjustesDoCelular {
    val OPCOES_DE_ADIAMENTO = listOf(5, 10, 15, 30)

    fun situacao(context: Context): SituacaoDosAlarmes {
        val preferencias = Preferencias(context)
        val energia = context.getSystemService(PowerManager::class.java)
        val proximo = AgendadorDeAlarmes.proximo(context)?.let { (dose, quando) ->
            val momento = Instant.ofEpochMilli(quando).atZone(ZoneId.systemDefault())
            val hora = momento.toLocalTime().toString().take(5)
            (if (momento.toLocalDate().isAfter(LocalDate.now())) "Amanhã às $hora" else "Hoje às $hora") + " · " + dose.nome
        }

        return SituacaoDosAlarmes(
            notificacoes = Notificacoes.podeNotificar(context),
            alarmesExatos = AgendadorDeAlarmes.podeAgendarExato(context),
            telaCheia = Notificacoes.podeTelaCheia(context),
            bateriaLivre = energia.isIgnoringBatteryOptimizations(context.packageName),
            modoDespertador = preferencias.modoAlarme,
            adiarMinutos = preferencias.adiarMinutos,
            proximoAlarme = proximo,
        )
    }

    fun definirModoDespertador(context: Context, ligado: Boolean) {
        Preferencias(context).modoAlarme = ligado
        AgendadorDeAlarmes.reagendar(context)
    }

    fun definirAdiamento(context: Context, minutos: Int) {
        Preferencias(context).adiarMinutos = minutos
    }

    fun testar(context: Context) = AgendadorDeAlarmes.testar(context)
}
