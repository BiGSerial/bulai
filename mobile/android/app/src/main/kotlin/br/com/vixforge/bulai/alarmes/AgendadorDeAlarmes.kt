package br.com.vixforge.bulai.alarmes

import android.app.AlarmManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.net.Uri
import android.os.Build
import br.com.vixforge.bulai.MainActivity
import br.com.vixforge.bulai.R
import br.com.vixforge.bulai.dados.Agenda
import br.com.vixforge.bulai.dados.Dose
import br.com.vixforge.bulai.dados.Preferencias

/**
 * Alarmes das doses no AlarmManager, montados a partir da última agenda recebida (funciona sem internet).
 *
 * Regras: toca cada dose com `remind = true` ainda não marcada (nem no servidor, nem por este aparelho); uma dose cujo
 * horário passou há até 15 minutos sem tocar (celular desligado) toca logo; dose adiada toca no novo horário; uma dose
 * que já tocou não toca de novo numa nova sincronização. Modo despertador usa `setAlarmClock` (o mais confiável, com o
 * ícone de alarme na barra); senão `setExactAndAllowWhileIdle`; sem a permissão de hora exata, o Android pode atrasar.
 */
object AgendadorDeAlarmes {
    private const val JANELA_DE_ATRASO_MS = 15 * 60 * 1000L
    private const val PREFIXO_TESTE = "teste|"

    fun reagendar(context: Context) {
        val preferencias = Preferencias(context)
        cancelarTodos(context, preferencias)
        if (!preferencias.conectado) {
            return
        }
        val planejados = planejar(preferencias)
        planejados.forEach { (dose, quando) -> agendar(context, dose, quando, preferencias.modoAlarme) }
        preferencias.agendados = planejados.map { it.first.chave }.toSet()

        // Limpeza: só interessa lembrar o que ainda está na agenda (hoje e amanhã).
        val chaves = Agenda.ler(preferencias.agendaJson)?.doses?.map { it.chave }?.toSet() ?: emptySet()
        preferencias.tocados = preferencias.tocados.intersect(chaves)
        preferencias.adiados = preferencias.adiados.filterKeys { it in chaves }
    }

    /** Próximo alarme que vai tocar (para a tela de Avisos e o widget). */
    fun proximo(context: Context): Pair<Dose, Long>? = planejar(Preferencias(context)).minByOrNull { it.second }

    fun cancelarTodos(context: Context, preferencias: Preferencias = Preferencias(context)) {
        val alarmManager = context.getSystemService(AlarmManager::class.java)
        preferencias.agendados.forEach { chave ->
            PendingIntent.getBroadcast(context, 0, intentDoAlarme(context, chave), PendingIntent.FLAG_NO_CREATE or PendingIntent.FLAG_IMMUTABLE)?.let {
                alarmManager.cancel(it)
                it.cancel()
            }
        }
        preferencias.agendados = emptySet()
    }

    /** Toca um alarme de teste daqui a alguns segundos, com as mesmas regras de som e tela do alarme de verdade. */
    fun testar(context: Context, emSegundos: Int = 5) {
        val agora = System.currentTimeMillis()
        val dose = Dose(
            chave = PREFIXO_TESTE + agora,
            remedioId = 0,
            nome = context.getString(R.string.alarme_teste_nome),
            dosagem = context.getString(R.string.alarme_teste_dose),
            horario = java.text.SimpleDateFormat("HH:mm", java.util.Locale.forLanguageTag("pt-BR")).format(java.util.Date(agora + emSegundos * 1000L)),
            agendadaParaIso = "",
            agendadaPara = agora + emSegundos * 1000L,
            situacao = "pending",
            lembrar = true,
        )
        agendar(context, dose, dose.agendadaPara, Preferencias(context).modoAlarme)
    }

    fun eTeste(chave: String): Boolean = chave.startsWith(PREFIXO_TESTE)

    fun podeAgendarExato(context: Context): Boolean =
        Build.VERSION.SDK_INT < Build.VERSION_CODES.S || context.getSystemService(AlarmManager::class.java).canScheduleExactAlarms()

    private fun planejar(preferencias: Preferencias): List<Pair<Dose, Long>> {
        val agenda = Agenda.ler(preferencias.agendaJson) ?: return emptyList()

        return planejar(agenda, System.currentTimeMillis(), preferencias.marcadasLocalmente, preferencias.tocados, preferencias.adiados)
    }

    /** Regra pura de quando cada dose toca (testada em AgendadorDeAlarmesTest). */
    internal fun planejar(agenda: Agenda, agora: Long, marcadas: Set<String>, tocados: Set<String>, adiados: Map<String, Long>): List<Pair<Dose, Long>> =
        agenda.doses.mapNotNull { dose ->
            if (!dose.lembrar || dose.marcada || dose.chave in marcadas) {
                return@mapNotNull null
            }
            val adiadoPara = adiados[dose.chave]
            val quando = when {
                adiadoPara != null -> maxOf(adiadoPara, agora + 1_000)
                dose.chave in tocados -> return@mapNotNull null
                dose.agendadaPara > agora -> dose.agendadaPara
                dose.agendadaPara > agora - JANELA_DE_ATRASO_MS -> agora + 2_000
                else -> return@mapNotNull null
            }
            dose to quando
        }

    private fun agendar(context: Context, dose: Dose, quando: Long, modoAlarme: Boolean) {
        val alarmManager = context.getSystemService(AlarmManager::class.java)
        val intent = DoseIntent.escrever(intentDoAlarme(context, dose.chave), dose)
        val pendente = PendingIntent.getBroadcast(context, 0, intent, PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE)
        val exato = podeAgendarExato(context)
        try {
            when {
                exato && modoAlarme -> {
                    val abrir = PendingIntent.getActivity(context, 0, MainActivity.abrir(context, "/hoje"), PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE)
                    alarmManager.setAlarmClock(AlarmManager.AlarmClockInfo(quando, abrir), pendente)
                }
                exato -> alarmManager.setExactAndAllowWhileIdle(AlarmManager.RTC_WAKEUP, quando, pendente)
                else -> alarmManager.setAndAllowWhileIdle(AlarmManager.RTC_WAKEUP, quando, pendente)
            }
        } catch (e: SecurityException) {
            // Permissão de hora exata retirada entre a checagem e o pedido: cai no alarme aproximado.
            alarmManager.setAndAllowWhileIdle(AlarmManager.RTC_WAKEUP, quando, pendente)
        }
    }

    /** Um alarme por dose: o `data` diferencia os PendingIntents (o Android compara action, data e componente). */
    private fun intentDoAlarme(context: Context, chave: String): Intent =
        Intent(context, AlarmeReceiver::class.java).setData(Uri.parse("bulai://alarme/" + Uri.encode(chave)))
}
