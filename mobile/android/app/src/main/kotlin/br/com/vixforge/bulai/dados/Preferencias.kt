package br.com.vixforge.bulai.dados

import android.content.Context
import android.content.SharedPreferences
import androidx.core.content.edit
import java.util.UUID

/**
 * Estado local do aplicativo (SharedPreferences privadas, fora de backup):
 * token do aparelho (cifrado), conta conectada, última agenda recebida, preferências do alarme e marcações ainda não enviadas.
 */
class Preferencias(context: Context) {
    private val prefs: SharedPreferences = context.applicationContext.getSharedPreferences(ARQUIVO, Context.MODE_PRIVATE)

    /** Identificador desta instalação (o servidor troca o token se a mesma instalação se registrar de novo). */
    val instalacao: String
        get() = prefs.getString(INSTALACAO, null) ?: UUID.randomUUID().toString().also { prefs.edit { putString(INSTALACAO, it) } }

    /** Token do aparelho, cifrado pelo [Cofre] (Android Keystore). */
    var token: String?
        get() = prefs.getString(TOKEN, null)?.let { Cofre.abrir(it) }
        set(valor) = prefs.edit { putString(TOKEN, valor?.let { Cofre.fechar(it) }) }

    /** Conta conectada (JSON de [br.com.vixforge.bulai.dados.api.Usuario]), para abrir o app sem esperar a rede. */
    var contaJson: String?
        get() = prefs.getString(CONTA, null)
        set(valor) = prefs.edit { putString(CONTA, valor) }

    var usuario: String
        get() = prefs.getString(USUARIO, "") ?: ""
        set(valor) = prefs.edit { putString(USUARIO, valor) }

    val conectado: Boolean
        get() = !token.isNullOrEmpty()

    var agendaJson: String?
        get() = prefs.getString(AGENDA, null)
        set(valor) = prefs.edit { putString(AGENDA, valor).putLong(AGENDA_EM, System.currentTimeMillis()) }

    var modoAlarme: Boolean
        get() = prefs.getBoolean(MODO_ALARME, false)
        set(valor) = prefs.edit { putBoolean(MODO_ALARME, valor) }

    var adiarMinutos: Int
        get() = prefs.getInt(ADIAR, 10)
        set(valor) = prefs.edit { putInt(ADIAR, valor.coerceIn(1, 120)) }

    /** `checked_at` da última consulta de avisos: o servidor só devolve o que chegou depois. */
    var avisosDesde: String?
        get() = prefs.getString(AVISOS_DESDE, null)
        set(valor) = prefs.edit { putString(AVISOS_DESDE, valor) }

    /** Doses marcadas pelo aparelho (notificação, alarme, widget) que o servidor ainda não confirmou. */
    var marcadasLocalmente: Set<String>
        get() = prefs.getStringSet(MARCADAS, emptySet()) ?: emptySet()
        set(valor) = prefs.edit { putStringSet(MARCADAS, valor) }

    /** Alarmes que já tocaram (não tocam de novo numa nova sincronização). */
    var tocados: Set<String>
        get() = prefs.getStringSet(TOCADOS, emptySet()) ?: emptySet()
        set(valor) = prefs.edit { putStringSet(TOCADOS, valor) }

    /** Doses adiadas: chave da dose → novo horário (epoch ms), para sobreviver a um reinício. */
    var adiados: Map<String, Long>
        get() = (prefs.getStringSet(ADIADOS, emptySet()) ?: emptySet()).mapNotNull { linha ->
            val corte = linha.lastIndexOf('@')
            if (corte <= 0) null else linha.substring(0, corte) to (linha.substring(corte + 1).toLongOrNull() ?: return@mapNotNull null)
        }.toMap()
        set(valor) = prefs.edit { putStringSet(ADIADOS, valor.map { (chave, quando) -> "$chave@$quando" }.toSet()) }

    /** Alarmes atualmente marcados no AlarmManager (para cancelar antes de reagendar). */
    var agendados: Set<String>
        get() = prefs.getStringSet(AGENDADOS, emptySet()) ?: emptySet()
        set(valor) = prefs.edit { putStringSet(AGENDADOS, valor) }

    /** Ids de avisos do servidor já mostrados como notificação. */
    var avisosMostrados: Set<String>
        get() = prefs.getStringSet(AVISOS_MOSTRADOS, emptySet()) ?: emptySet()
        set(valor) = prefs.edit { putStringSet(AVISOS_MOSTRADOS, valor) }

    var pediuNotificacoes: Boolean
        get() = prefs.getBoolean(PEDIU_NOTIFICACOES, false)
        set(valor) = prefs.edit { putBoolean(PEDIU_NOTIFICACOES, valor) }

    /** Saída da conta: apaga tudo o que é do paciente; a instalação e as preferências do alarme ficam. */
    fun limparConta() {
        prefs.edit {
            remove(TOKEN)
            remove(USUARIO)
            remove(CONTA)
            remove(AGENDA)
            remove(AGENDA_EM)
            remove(AVISOS_DESDE)
            remove(MARCADAS)
            remove(TOCADOS)
            remove(ADIADOS)
            remove(AVISOS_MOSTRADOS)
        }
    }

    private companion object {
        const val ARQUIVO = "bulai"
        const val INSTALACAO = "instalacao"
        const val TOKEN = "token"
        const val USUARIO = "usuario"
        const val CONTA = "conta"
        const val AGENDA = "agenda"
        const val AGENDA_EM = "agenda_em"
        const val MODO_ALARME = "modo_alarme"
        const val ADIAR = "adiar_minutos"
        const val AVISOS_DESDE = "avisos_desde"
        const val MARCADAS = "marcadas_localmente"
        const val TOCADOS = "tocados"
        const val ADIADOS = "adiados"
        const val AGENDADOS = "agendados"
        const val AVISOS_MOSTRADOS = "avisos_mostrados"
        const val PEDIU_NOTIFICACOES = "pediu_notificacoes"
    }
}
