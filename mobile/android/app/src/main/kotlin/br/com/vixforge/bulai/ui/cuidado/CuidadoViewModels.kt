package br.com.vixforge.bulai.ui.cuidado

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import br.com.vixforge.bulai.dados.CuidadoRepositorio
import br.com.vixforge.bulai.dados.api.MeusCuidadores
import br.com.vixforge.bulai.dados.api.QuemEuCuido
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

/** "Quem cuida de mim": convite em QR Code, aprovar/recusar pedidos com as permissões, mudar e tirar acesso. */
class QuemCuidaDeMimViewModel(private val cuidado: CuidadoRepositorio) : ViewModel() {
    private val _estado = MutableStateFlow(Carga<MeusCuidadores>())
    val estado: StateFlow<Carga<MeusCuidadores>> = _estado.asStateFlow()

    /** Vínculo (ou "convite") com ação a caminho do servidor. */
    private val _ocupado = MutableStateFlow<String?>(null)
    val ocupado: StateFlow<String?> = _ocupado.asStateFlow()

    private val _recados = MutableSharedFlow<String>(extraBufferCapacity = 4)
    val recados: SharedFlow<String> = _recados.asSharedFlow()

    init {
        atualizar()
    }

    fun atualizar() {
        viewModelScope.launch {
            _estado.update { it.copy(atualizando = true) }
            cuidado.meusCuidadores()
                .onSuccess { _estado.value = Carga(it) }
                .onFailure { falha -> _estado.update { it.copy(atualizando = false, erro = falha.message) } }
        }
    }

    fun gerarConvite() = agir("convite", null) { cuidado.gerarConvite() }

    fun cancelarConvite() = agir("convite", "Convite cancelado. O QR Code não vale mais.") { cuidado.cancelarConvite() }

    fun aprovar(vinculo: Long, nome: String, permissoes: Map<String, Boolean>) =
        agir("$vinculo", "$nome agora acompanha você, com as permissões que você marcou.") { cuidado.aprovar(vinculo, permissoes) }

    fun recusar(vinculo: Long) = agir("$vinculo", "Pedido recusado. Nada foi compartilhado.") { cuidado.recusar(vinculo) }

    fun mudarPermissoes(vinculo: Long, nome: String, permissoes: Map<String, Boolean>) =
        agir("$vinculo", "Permissões de $nome atualizadas.") { cuidado.mudarPermissoes(vinculo, permissoes) }

    fun tirarAcesso(vinculo: Long, nome: String) = agir("$vinculo", "$nome não tem mais acesso aos seus remédios.") { cuidado.tirarAcesso(vinculo) }

    private fun agir(chave: String, sucesso: String?, chamada: suspend () -> Result<MeusCuidadores>) {
        if (_ocupado.value != null) {
            return
        }
        _ocupado.value = chave
        viewModelScope.launch {
            chamada()
                .onSuccess { novo ->
                    _estado.value = Carga(novo)
                    sucesso?.let(_recados::tryEmit)
                }
                .onFailure { falha -> _recados.tryEmit(falha.message ?: "Não deu agora. Tente de novo.") }
            _ocupado.value = null
        }
    }
}

/** "Cuidar de alguém": as pessoas que a pessoa acompanha (para escolher de quem ver o dia), ler o QR e sair do vínculo. */
class CuidarDeAlguemViewModel(private val cuidado: CuidadoRepositorio) : ViewModel() {
    private val _estado = MutableStateFlow(Carga<QuemEuCuido>())
    val estado: StateFlow<Carga<QuemEuCuido>> = _estado.asStateFlow()

    private val _enviando = MutableStateFlow(false)
    val enviando: StateFlow<Boolean> = _enviando.asStateFlow()

    /** Erro do código lido ou digitado (aparece embaixo do campo). */
    private val _erroDoCodigo = MutableStateFlow<String?>(null)
    val erroDoCodigo: StateFlow<String?> = _erroDoCodigo.asStateFlow()

    private val _recados = MutableSharedFlow<String>(extraBufferCapacity = 4)
    val recados: SharedFlow<String> = _recados.asSharedFlow()

    init {
        viewModelScope.launch {
            cuidado.quemEuCuidoGuardado()?.let { guardado -> _estado.update { if (it.dados == null) it.copy(dados = guardado) else it } }
            atualizar()
        }
    }

    private var carga: Job? = null

    fun atualizar() {
        if (carga?.isActive == true) {
            return
        }
        carga = viewModelScope.launch {
            _estado.update { it.copy(atualizando = true) }
            cuidado.quemEuCuido()
                .onSuccess { _estado.value = Carga(it) }
                .onFailure { falha -> _estado.update { it.copy(atualizando = false, erro = falha.message) } }
        }
    }

    /** [codigo] é o link lido no QR Code ou os 8 números digitados. */
    fun usarConvite(codigo: String, aoConseguir: () -> Unit = {}) {
        if (_enviando.value || codigo.isBlank()) {
            return
        }
        _enviando.value = true
        _erroDoCodigo.value = null
        viewModelScope.launch {
            cuidado.usarConvite(codigo)
                .onSuccess { novo ->
                    _estado.value = Carga(novo)
                    novo.message?.let(_recados::tryEmit)
                    aoConseguir()
                }
                .onFailure { _erroDoCodigo.value = it.message ?: "Não deu para usar este código agora." }
            _enviando.value = false
        }
    }

    fun deixarDeAcompanhar(vinculo: Long, nome: String) {
        viewModelScope.launch {
            cuidado.deixarDeAcompanhar(vinculo)
                .onSuccess {
                    _estado.value = Carga(it)
                    _recados.tryEmit("Você deixou de acompanhar $nome.")
                }
                .onFailure { _recados.tryEmit(it.message ?: "Não deu agora. Tente de novo.") }
        }
    }
}
