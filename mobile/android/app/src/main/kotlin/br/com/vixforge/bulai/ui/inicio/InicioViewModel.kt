package br.com.vixforge.bulai.ui.inicio

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import br.com.vixforge.bulai.dados.TratamentoRepositorio
import br.com.vixforge.bulai.dados.api.Dose
import br.com.vixforge.bulai.dados.api.Inicio
import br.com.vixforge.bulai.ui.base.Carga
import kotlinx.coroutines.Job
import kotlinx.coroutines.flow.MutableSharedFlow
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.SharedFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asSharedFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

class InicioViewModel(private val tratamento: TratamentoRepositorio) : ViewModel() {
    private val _estado = MutableStateFlow(Carga<Inicio>())
    val estado: StateFlow<Carga<Inicio>> = _estado.asStateFlow()

    /** Mensagens curtas para o snackbar ("Anotado: você tomou…"). */
    private val _recados = MutableSharedFlow<String>(extraBufferCapacity = 4)
    val recados: SharedFlow<String> = _recados.asSharedFlow()

    var marcando = MutableStateFlow(false)
        private set

    private var carga: Job? = null

    init {
        viewModelScope.launch {
            tratamento.inicioGuardado()?.let { guardado -> _estado.update { if (it.dados == null) it.copy(dados = guardado) else it } }
            atualizar()
        }
    }

    /** Busca de novo (ao voltar para a tela, puxar para baixo); os dados atuais continuam na tela. */
    fun atualizar() {
        if (carga?.isActive == true) {
            return
        }
        carga = viewModelScope.launch {
            _estado.update { it.copy(atualizando = true) }
            tratamento.inicio()
                .onSuccess { novo -> _estado.value = Carga(novo) }
                .onFailure { falha -> _estado.update { it.copy(atualizando = false, erro = falha.message) } }
        }
    }

    /** "Tomei" no cartão do dia: a tela mostra na hora e confirma com o servidor; se falhar, volta e avisa. */
    fun tomei(dose: Dose) {
        if (marcando.value) {
            return
        }
        marcando.value = true
        val antes = _estado.value.dados
        _estado.update { carga -> carga.copy(dados = carga.dados?.let { it.copy(proxima = null, progress = it.progress.copy(taken = it.progress.taken + 1, done = it.progress.done + 1)) }) }
        viewModelScope.launch {
            tratamento.marcar(dose, Dose.TOMADA, 0)
                .onSuccess {
                    _recados.tryEmit("Anotado: você tomou ${dose.name}.")
                    carga?.cancel()
                    atualizar()
                }
                .onFailure { falha ->
                    _estado.update { it.copy(dados = antes) }
                    _recados.tryEmit(falha.message ?: "Não deu para anotar agora.")
                }
            marcando.value = false
        }
    }
}
