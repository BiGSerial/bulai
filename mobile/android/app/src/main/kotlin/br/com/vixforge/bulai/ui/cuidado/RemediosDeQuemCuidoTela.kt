package br.com.vixforge.bulai.ui.cuidado

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.material3.pulltorefresh.PullToRefreshBox
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import androidx.lifecycle.ViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.lifecycle.viewModelScope
import br.com.vixforge.bulai.dados.PerfilRepositorio
import br.com.vixforge.bulai.dados.api.DoseEHorarios
import br.com.vixforge.bulai.dados.api.RemedioDoPerfil
import br.com.vixforge.bulai.dados.api.RemediosDeQuemCuido
import br.com.vixforge.bulai.ui.base.Carga
import br.com.vixforge.bulai.ui.base.telaViewModel
import br.com.vixforge.bulai.ui.componentes.FaixaDeErro
import br.com.vixforge.bulai.ui.componentes.LinhaEsqueleto
import br.com.vixforge.bulai.ui.componentes.TelaEsqueleto
import br.com.vixforge.bulai.ui.perfil.CartaoDoRemedio
import br.com.vixforge.bulai.ui.perfil.EditorDePosologia
import kotlinx.coroutines.flow.MutableSharedFlow
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.SharedFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asSharedFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

/** Remédios de quem a pessoa cuida, com "Dose e horários" quando ela tem a permissão "marcar e editar". */
class RemediosDeQuemCuidoViewModel(private val perfil: PerfilRepositorio, private val vinculo: Long) : ViewModel() {
    private val _carga = MutableStateFlow(Carga<RemediosDeQuemCuido>())
    val carga: StateFlow<Carga<RemediosDeQuemCuido>> = _carga.asStateFlow()
    private val _editando = MutableStateFlow<RemedioDoPerfil?>(null)
    val editando: StateFlow<RemedioDoPerfil?> = _editando.asStateFlow()
    private val _salvando = MutableStateFlow(false)
    val salvando: StateFlow<Boolean> = _salvando.asStateFlow()
    private val _erro = MutableStateFlow<String?>(null)
    val erro: StateFlow<String?> = _erro.asStateFlow()
    private val _recados = MutableSharedFlow<String>(extraBufferCapacity = 2)
    val recados: SharedFlow<String> = _recados.asSharedFlow()

    init {
        atualizar()
    }

    fun atualizar() {
        viewModelScope.launch {
            _carga.update { it.copy(atualizando = true) }
            perfil.remediosDeQuemCuido(vinculo)
                .onSuccess { _carga.value = Carga(it) }
                .onFailure { falha -> _carga.update { it.copy(atualizando = false, erro = falha.message) } }
        }
    }

    fun editar(remedio: RemedioDoPerfil?) {
        _erro.value = null
        _editando.value = remedio
    }

    suspend fun sugerir(frequencia: String, posologia: DoseEHorarios): DoseEHorarios? = perfil.sugerirHorarios(frequencia, posologia).getOrNull()

    fun salvar(posologia: DoseEHorarios) {
        val remedio = _editando.value ?: return
        _salvando.value = true
        viewModelScope.launch {
            perfil.salvarPosologiaDeQuemCuido(vinculo, remedio.id, posologia)
                .onSuccess { novo ->
                    _carga.value = Carga(novo)
                    _editando.value = null
                    novo.message?.let(_recados::tryEmit)
                }
                .onFailure { _erro.value = it.message ?: "Não deu para salvar agora." }
            _salvando.value = false
        }
    }
}

@Composable
fun RemediosDeQuemCuidoTela(vinculo: Long, preenchimento: PaddingValues, recado: (String) -> Unit) {
    val vm = telaViewModel { RemediosDeQuemCuidoViewModel(it.perfil, vinculo) }
    val carga by vm.carga.collectAsStateWithLifecycle()
    val editando by vm.editando.collectAsStateWithLifecycle()
    val salvando by vm.salvando.collectAsStateWithLifecycle()
    val erro by vm.erro.collectAsStateWithLifecycle()
    val largura = Modifier.widthIn(max = 640.dp)
    LaunchedEffect(Unit) { vm.recados.collect(recado) }

    PullToRefreshBox(carga.atualizando && carga.dados != null, vm::atualizar, Modifier.fillMaxSize()) {
        LazyColumn(
            Modifier.fillMaxSize(),
            contentPadding = PaddingValues(start = 20.dp, end = 20.dp, top = preenchimento.calculateTopPadding() + 8.dp, bottom = preenchimento.calculateBottomPadding() + 24.dp),
            verticalArrangement = Arrangement.spacedBy(12.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            item(key = "erro") { FaixaDeErro(carga.erro, largura, vm::atualizar) }
            val dados = carga.dados
            if (dados == null) {
                if (carga.esqueleto) item(key = "esqueleto") { TelaEsqueleto(largura) { repeat(4) { LinhaEsqueleto() } } }
            } else {
                item(key = "explica") {
                    Text(
                        dados.intro,
                        style = MaterialTheme.typography.bodyLarge,
                        modifier = largura.fillMaxWidth(),
                    )
                }
                if (dados.remedios.isEmpty()) {
                    item(key = "vazio") { Text("${dados.cuidandoDe.primeiroNome} ainda não tem remédios cadastrados.", style = MaterialTheme.typography.bodyLarge, color = MaterialTheme.colorScheme.onSurfaceVariant, modifier = largura.fillMaxWidth()) }
                }
                items(dados.remedios, key = { it.id }) { remedio ->
                    CartaoDoRemedio(remedio, if (dados.podeEditar) ({ vm.editar(remedio) }) else null, null, null, largura)
                }
            }
        }
    }

    editando?.let { remedio ->
        EditorDePosologia(remedio.name, remedio.posologia, emptyList(), salvando, erro, vm::sugerir, vm::salvar) { vm.editar(null) }
    }
}
