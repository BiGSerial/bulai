package br.com.vixforge.bulai.ui.interacoes

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import br.com.vixforge.bulai.dados.PerfilRepositorio
import br.com.vixforge.bulai.dados.PerguntasRepositorio
import br.com.vixforge.bulai.dados.api.ConsultaAnterior
import br.com.vixforge.bulai.dados.api.ConsultaDeInteracoes
import br.com.vixforge.bulai.dados.api.RemedioDaConsulta
import br.com.vixforge.bulai.dados.api.RemedioDoCatalogo
import br.com.vixforge.bulai.ui.base.Carga
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.MutableSharedFlow
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.SharedFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asSharedFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch

data class EstadoDaPesquisa(
    val carregando: Boolean = true,
    val escolhidos: List<RemedioDaConsulta> = emptyList(),
    val maximo: Int = 5,
    val historico: List<ConsultaAnterior> = emptyList(),
    val buscando: Boolean = false,
    val resultados: List<RemedioDoCatalogo>? = null,
    val verificando: Boolean = false,
    val mensagem: String? = null,
)

/** Montar a lista (os remédios em uso já entram), buscar mais e verificar; e o histórico das consultas. */
class PesquisaViewModel(private val repositorio: PerfilRepositorio, private val catalogo: PerguntasRepositorio, comRemedio: Long?) : ViewModel() {
    private val _estado = MutableStateFlow(EstadoDaPesquisa())
    val estado: StateFlow<EstadoDaPesquisa> = _estado.asStateFlow()

    private val _abrir = MutableSharedFlow<String>(extraBufferCapacity = 1)
    /** uuid da consulta criada, para abrir o resultado. */
    val abrir: SharedFlow<String> = _abrir.asSharedFlow()

    private var busca: Job? = null

    init {
        viewModelScope.launch {
            repositorio.interacoes()
                .onSuccess { inicio -> _estado.update { it.copy(carregando = false, escolhidos = inicio.meus, maximo = inicio.max, historico = inicio.historico) } }
                .onFailure { falha -> _estado.update { it.copy(carregando = false, mensagem = falha.message) } }
            comRemedio?.let(::adicionar)
        }
    }

    fun atualizarHistorico() {
        viewModelScope.launch { repositorio.interacoes().onSuccess { inicio -> _estado.update { it.copy(historico = inicio.historico) } } }
    }

    fun buscar(termo: String) {
        busca?.cancel()
        if (termo.trim().length < 3) {
            _estado.update { it.copy(resultados = null, buscando = false) }
            return
        }
        busca = viewModelScope.launch {
            delay(300)
            _estado.update { it.copy(buscando = true) }
            catalogo.buscarRemedios(termo, _estado.value.escolhidos.map { it.id })
                .onSuccess { achados -> _estado.update { it.copy(resultados = achados.items, buscando = false) } }
                .onFailure { _estado.update { it.copy(buscando = false) } }
        }
    }

    fun fecharBusca() {
        busca?.cancel()
        _estado.update { it.copy(resultados = null, buscando = false) }
    }

    /** O servidor decide se o remédio pode entrar (repetido, limite) e devolve o alerta de alergia. */
    fun adicionar(remedio: Long) {
        fecharBusca()
        viewModelScope.launch {
            repositorio.remedioDaConsulta(remedio, _estado.value.escolhidos.map { it.id })
                .onSuccess { item -> _estado.update { it.copy(escolhidos = it.escolhidos + item, mensagem = null) } }
                .onFailure { falha -> _estado.update { it.copy(mensagem = falha.message) } }
        }
    }

    fun tirar(remedio: Long) = _estado.update { it.copy(escolhidos = it.escolhidos.filterNot { item -> item.id == remedio }, mensagem = null) }

    fun verificar() {
        val atual = _estado.value
        if (atual.verificando) {
            return
        }
        _estado.update { it.copy(verificando = true, mensagem = null) }
        viewModelScope.launch {
            repositorio.verificar(atual.escolhidos.map { it.id })
                .onSuccess { consulta -> _abrir.tryEmit(consulta.uuid) }
                .onFailure { falha -> _estado.update { it.copy(mensagem = falha.message) } }
            _estado.update { it.copy(verificando = false) }
        }
    }

    fun apagar(uuid: String) {
        viewModelScope.launch {
            repositorio.apagarConsulta(uuid).onSuccess { _estado.update { it.copy(historico = it.historico.filterNot { item -> item.uuid == uuid }) } }
        }
    }
}

/** Resultado de uma consulta: acompanha a análise a cada 2 s até terminar. */
class ResultadoViewModel(private val repositorio: PerfilRepositorio, private val uuid: String) : ViewModel() {
    private val _carga = MutableStateFlow(Carga<ConsultaDeInteracoes>())
    val carga: StateFlow<Carga<ConsultaDeInteracoes>> = _carga.asStateFlow()

    private val _abrir = MutableSharedFlow<String>(extraBufferCapacity = 1)
    val abrir: SharedFlow<String> = _abrir.asSharedFlow()

    private var acompanhando: Job? = null

    init {
        acompanhar()
    }

    fun acompanhar() {
        acompanhando?.cancel()
        acompanhando = viewModelScope.launch {
            while (isActive) {
                repositorio.consulta(uuid)
                    .onSuccess { _carga.value = Carga(it) }
                    .onFailure { falha -> _carga.update { it.copy(erro = falha.message) } }
                if (_carga.value.dados?.running == false) {
                    break
                }
                delay(2_000)
            }
        }
    }

    fun repetir() {
        viewModelScope.launch {
            repositorio.repetir(uuid)
                .onSuccess { _abrir.tryEmit(it.uuid) }
                .onFailure { falha -> _carga.update { it.copy(erro = falha.message) } }
        }
    }
}
