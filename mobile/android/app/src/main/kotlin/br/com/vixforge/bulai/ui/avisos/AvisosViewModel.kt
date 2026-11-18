package br.com.vixforge.bulai.ui.avisos

import android.app.Application
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.viewModelScope
import br.com.vixforge.bulai.alarmes.AjustesDoCelular
import br.com.vixforge.bulai.alarmes.SituacaoDosAlarmes
import br.com.vixforge.bulai.dados.AvisosRepositorio
import br.com.vixforge.bulai.dados.api.AlteracaoDePreferencias
import br.com.vixforge.bulai.dados.api.Aviso
import br.com.vixforge.bulai.dados.api.CentralDeAvisos
import br.com.vixforge.bulai.dados.api.Destino
import br.com.vixforge.bulai.ui.base.Carga
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.flow.MutableSharedFlow
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.SharedFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asSharedFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

class AvisosViewModel(app: Application, private val avisos: AvisosRepositorio) : AndroidViewModel(app) {
    private val _estado = MutableStateFlow(Carga<CentralDeAvisos>())
    val estado: StateFlow<Carga<CentralDeAvisos>> = _estado.asStateFlow()

    private val _alarmes = MutableStateFlow<SituacaoDosAlarmes?>(null)
    val alarmes: StateFlow<SituacaoDosAlarmes?> = _alarmes.asStateFlow()

    private val _recados = MutableSharedFlow<String>(extraBufferCapacity = 4)
    val recados: SharedFlow<String> = _recados.asSharedFlow()

    /** Para onde ir depois de abrir um aviso. */
    private val _destinos = MutableSharedFlow<Destino>(extraBufferCapacity = 1)
    val destinos: SharedFlow<Destino> = _destinos.asSharedFlow()

    private var carga: Job? = null

    init {
        viewModelScope.launch {
            avisos.guardados()?.let { guardado -> _estado.update { if (it.dados == null) it.copy(dados = guardado) else it } }
            atualizar()
        }
    }

    fun atualizar() {
        lerAlarmes()
        if (carga?.isActive == true) {
            return
        }
        carga = viewModelScope.launch {
            _estado.update { it.copy(atualizando = true) }
            avisos.central()
                .onSuccess { _estado.value = Carga(it) }
                .onFailure { falha -> _estado.update { it.copy(atualizando = false, erro = falha.message) } }
        }
    }

    /** Permissões e alarmes leem o sistema (AlarmManager, PowerManager): fora da thread principal. */
    fun lerAlarmes() {
        viewModelScope.launch { _alarmes.value = withContext(Dispatchers.Default) { AjustesDoCelular.situacao(getApplication()) } }
    }

    fun abrir(aviso: Aviso) {
        _estado.update { carga -> carga.copy(dados = carga.dados?.let { c -> c.copy(unread = (c.unread - if (aviso.read) 0 else 1).coerceAtLeast(0), notices = c.notices.map { if (it.id == aviso.id) it.copy(read = true) else it }) }) }
        _destinos.tryEmit(aviso.destination)
        viewModelScope.launch { avisos.abrir(aviso.id) }
    }

    fun apagar(aviso: Aviso) {
        val antes = _estado.value.dados
        _estado.update { carga -> carga.copy(dados = carga.dados?.let { c -> c.copy(notices = c.notices.filterNot { it.id == aviso.id }, unread = if (aviso.read) c.unread else (c.unread - 1).coerceAtLeast(0)) }) }
        executar({ avisos.apagar(aviso.id) }, "Aviso apagado.") { _estado.update { it.copy(dados = antes) } }
    }

    fun marcarTodosLidos() = executar({ avisos.marcarTodosLidos() }, "Todos os avisos foram marcados como lidos.")

    fun apagarTodos() = executar({ avisos.apagarTodos() }, "Todos os avisos foram apagados.")

    fun lembretes(ligados: Boolean) = preferencia(AlteracaoDePreferencias(reminders = ligados), if (ligados) "Lembretes ligados." else "Lembretes desligados. O celular não vai tocar nos horários.") { it.copy(reminders = ligados) }

    fun email(ligado: Boolean) = preferencia(AlteracaoDePreferencias(email = ligado), if (ligado) "Você também receberá os lembretes por e-mail." else "Lembretes só no aplicativo.") { it.copy(email = ligado) }

    fun fuso(id: String) = preferencia(AlteracaoDePreferencias(timezone = id), "Horário ajustado.") { it.copy(timezone = id) }

    fun modoDespertador(ligado: Boolean) {
        AjustesDoCelular.definirModoDespertador(getApplication(), ligado)
        lerAlarmes()
    }

    fun adiamento(minutos: Int) {
        AjustesDoCelular.definirAdiamento(getApplication(), minutos)
        lerAlarmes()
    }

    fun testarAlarme() {
        AjustesDoCelular.testar(getApplication())
        _recados.tryEmit("O alarme de teste vai tocar em alguns segundos.")
    }

    private fun preferencia(alteracao: AlteracaoDePreferencias, mensagem: String, naTela: (br.com.vixforge.bulai.dados.api.PreferenciasDeAviso) -> br.com.vixforge.bulai.dados.api.PreferenciasDeAviso) {
        val antes = _estado.value.dados
        _estado.update { carga -> carga.copy(dados = carga.dados?.let { it.copy(preferences = naTela(it.preferences)) }) }
        executar({ avisos.preferencias(alteracao) }, mensagem) { _estado.update { it.copy(dados = antes) } }
    }

    private fun executar(chamada: suspend () -> Result<CentralDeAvisos>, sucesso: String, aoFalhar: () -> Unit = {}) {
        viewModelScope.launch {
            chamada()
                .onSuccess {
                    _estado.value = Carga(it)
                    _recados.tryEmit(sucesso)
                    lerAlarmes()
                }
                .onFailure { falha ->
                    aoFalhar()
                    _recados.tryEmit(falha.message ?: "Não deu certo agora. Tente de novo.")
                }
        }
    }
}
