package br.com.vixforge.bulai.ui.perfil

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import br.com.vixforge.bulai.dados.PerfilRepositorio
import br.com.vixforge.bulai.dados.PerguntasRepositorio
import br.com.vixforge.bulai.dados.api.PedidoDeReceitaDoPerfil
import br.com.vixforge.bulai.dados.api.Perfil
import br.com.vixforge.bulai.dados.api.DoseEHorarios
import br.com.vixforge.bulai.dados.api.RemedioDoCatalogo
import br.com.vixforge.bulai.dados.api.RemedioDoPerfil
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
import kotlinx.coroutines.launch

/** Estado das ações de "Meus remédios" (busca, editor de dose e horários, formulários). */
data class EstadoDoPerfil(
    val ocupado: Boolean = false,
    val erro: String? = null,
    val buscando: Boolean = false,
    val resultados: List<RemedioDoCatalogo>? = null,
    /** Remédio com "Dose e horários" aberto. */
    val editando: RemedioDoPerfil? = null,
)

/** "Meus remédios": consentimento, remédios com dose e horários, receitas e alergias, com as regras do site. */
class PerfilViewModel(private val perfil: PerfilRepositorio, private val catalogo: PerguntasRepositorio) : ViewModel() {
    private val _carga = MutableStateFlow(Carga<Perfil>())
    val carga: StateFlow<Carga<Perfil>> = _carga.asStateFlow()

    private val _estado = MutableStateFlow(EstadoDoPerfil())
    val estado: StateFlow<EstadoDoPerfil> = _estado.asStateFlow()

    private val _recados = MutableSharedFlow<String>(extraBufferCapacity = 4)
    val recados: SharedFlow<String> = _recados.asSharedFlow()

    private var busca: Job? = null

    init {
        viewModelScope.launch {
            perfil.guardado()?.let { guardado -> _carga.update { if (it.dados == null) it.copy(dados = guardado) else it } }
            atualizar()
        }
    }

    fun atualizar() {
        viewModelScope.launch {
            _carga.update { it.copy(atualizando = true) }
            perfil.perfil()
                .onSuccess { _carga.value = Carga(it) }
                .onFailure { falha -> _carga.update { it.copy(atualizando = false, erro = falha.message) } }
        }
    }

    fun consentir() = agir({ perfil.consentir() })

    fun apagarDadosDeSaude() = agir({ perfil.apagarDadosDeSaude() })

    fun buscar(termo: String) {
        busca?.cancel()
        if (termo.trim().length < 3) {
            _estado.update { it.copy(resultados = null, buscando = false) }
            return
        }
        busca = viewModelScope.launch {
            delay(300)
            _estado.update { it.copy(buscando = true) }
            catalogo.buscarRemedios(termo)
                .onSuccess { achados -> _estado.update { it.copy(resultados = achados.items, buscando = false) } }
                .onFailure { _estado.update { it.copy(buscando = false) } }
        }
    }

    fun fecharBusca() {
        busca?.cancel()
        _estado.update { it.copy(resultados = null, buscando = false) }
    }

    /** Remédio do catálogo (ou pelo nome): entra na lista e já abre "Dose e horários". */
    fun adicionar(doCatalogo: Long?, nome: String?) {
        fecharBusca()
        agir({ if (doCatalogo != null) perfil.adicionarDoCatalogo(doCatalogo) else perfil.adicionarPeloNome(nome.orEmpty()) }) { novo ->
            novo.remedios.firstOrNull { it.id == novo.remedioNovo }?.let { remedio -> _estado.update { it.copy(editando = remedio) } }
        }
    }

    fun editar(remedio: RemedioDoPerfil?) = _estado.update { it.copy(editando = remedio, erro = null) }

    suspend fun sugerir(frequencia: String, posologia: DoseEHorarios, atalho: Boolean): DoseEHorarios? = perfil.sugerirHorarios(frequencia, posologia, atalho).getOrNull()

    fun salvar(posologia: DoseEHorarios) {
        val remedio = _estado.value.editando ?: return
        agir({ perfil.salvarPosologia(remedio.id, posologia) }) { _estado.update { it.copy(editando = null) } }
    }

    fun tirarRemedio(remedio: RemedioDoPerfil) = agir({ perfil.tirarRemedio(remedio.id) })

    fun adicionarReceita(pedido: PedidoDeReceitaDoPerfil, aoSalvar: () -> Unit) = agir({ perfil.adicionarReceita(pedido) }) { aoSalvar() }

    fun tirarReceita(id: Long) = agir({ perfil.tirarReceita(id) })

    fun adicionarAlergia(substancia: String, reacao: String?, aoSalvar: () -> Unit) = agir({ perfil.adicionarAlergia(substancia, reacao) }) { aoSalvar() }

    fun tirarAlergia(id: Long) = agir({ perfil.tirarAlergia(id) })

    /** As regras e as frases vêm do servidor: o app mostra a `message` da resposta (ou o erro dela). */
    private fun agir(chamada: suspend () -> Result<Perfil>, aoConseguir: (Perfil) -> Unit = {}) {
        if (_estado.value.ocupado) {
            return
        }
        _estado.update { it.copy(ocupado = true, erro = null) }
        viewModelScope.launch {
            chamada()
                .onSuccess { novo ->
                    _carga.value = Carga(novo)
                    _estado.update { it.copy(ocupado = false) }
                    aoConseguir(novo)
                    novo.message?.let(_recados::tryEmit)
                }
                .onFailure { falha ->
                    val texto = falha.message ?: "Não deu agora. Tente de novo."
                    _estado.update { it.copy(ocupado = false, erro = texto) }
                    if (_estado.value.editando == null) {
                        _recados.tryEmit(texto)
                    }
                }
        }
    }
}
