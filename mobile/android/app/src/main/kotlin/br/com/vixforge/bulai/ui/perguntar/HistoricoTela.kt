package br.com.vixforge.bulai.ui.perguntar

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.DeleteOutline
import androidx.compose.material.icons.rounded.Forum
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.material3.pulltorefresh.PullToRefreshBox
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.derivedStateOf
import androidx.compose.runtime.getValue
import androidx.compose.runtime.remember
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.lifecycle.ViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.lifecycle.viewModelScope
import br.com.vixforge.bulai.dados.PerguntasRepositorio
import br.com.vixforge.bulai.dados.api.PerguntaAnterior
import br.com.vixforge.bulai.dados.api.Resposta
import br.com.vixforge.bulai.ui.base.Carga
import br.com.vixforge.bulai.ui.base.telaViewModel
import br.com.vixforge.bulai.ui.componentes.Cartao
import br.com.vixforge.bulai.ui.componentes.CartaoEsqueleto
import br.com.vixforge.bulai.ui.componentes.EstadoVazio
import br.com.vixforge.bulai.ui.componentes.FaixaDeErro
import br.com.vixforge.bulai.ui.sistema.rememberLeitor
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch
import java.time.OffsetDateTime
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import java.util.Locale

data class ListaDePerguntas(val itens: List<PerguntaAnterior>, val proxima: Int?, val retencao: Int)

class HistoricoViewModel(private val repositorio: PerguntasRepositorio) : ViewModel() {
    private val _estado = MutableStateFlow(Carga<ListaDePerguntas>())
    val estado = _estado.asStateFlow()
    private var carregandoMais = false

    init {
        atualizar()
    }

    fun atualizar() {
        viewModelScope.launch {
            _estado.update { it.copy(atualizando = true) }
            repositorio.historico(1)
                .onSuccess { _estado.value = Carga(ListaDePerguntas(it.items, it.proximaPagina, it.retencao)) }
                .onFailure { falha -> _estado.update { it.copy(atualizando = false, erro = falha.message) } }
        }
    }

    /** Rolagem infinita: a próxima página entra quando a lista chega perto do fim. */
    fun carregarMais() {
        val atual = _estado.value.dados ?: return
        val pagina = atual.proxima ?: return
        if (carregandoMais) {
            return
        }
        carregandoMais = true
        viewModelScope.launch {
            repositorio.historico(pagina).onSuccess { nova ->
                _estado.update { carga -> carga.copy(dados = carga.dados?.copy(itens = (carga.dados.itens + nova.items).distinctBy { it.uuid }, proxima = nova.proximaPagina)) }
            }
            carregandoMais = false
        }
    }

    /** Some da lista na hora; se o servidor recusar, volta. */
    fun apagar(pergunta: PerguntaAnterior, aoFalhar: (String) -> Unit) {
        val antes = _estado.value.dados ?: return
        _estado.update { it.copy(dados = antes.copy(itens = antes.itens - pergunta)) }
        viewModelScope.launch {
            repositorio.apagar(pergunta.uuid).onFailure { falha ->
                _estado.update { it.copy(dados = antes) }
                aoFalhar(falha.message ?: "Não deu para apagar agora.")
            }
        }
    }
}

private val FORMATO = DateTimeFormatter.ofPattern("d 'de' MMM 'às' HH:mm", Locale.forLanguageTag("pt-BR"))

fun quando(iso: String): String = runCatching { OffsetDateTime.parse(iso).atZoneSameInstant(ZoneId.systemDefault()).format(FORMATO) }.getOrDefault("")

/** Minhas perguntas: o que a pessoa já perguntou, guardado pelo prazo de retenção, com exclusão individual. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun HistoricoTela(preenchimento: PaddingValues, aoAbrir: (String) -> Unit, recado: (String) -> Unit) {
    val vm = telaViewModel { HistoricoViewModel(it.perguntas) }
    val estado by vm.estado.collectAsStateWithLifecycle()
    val lista = rememberLazyListState()
    val pertoDoFim by remember { derivedStateOf { lista.layoutInfo.visibleItemsInfo.lastOrNull()?.index?.let { it >= lista.layoutInfo.totalItemsCount - 4 } == true } }
    LaunchedEffect(pertoDoFim) { if (pertoDoFim) vm.carregarMais() }

    PullToRefreshBox(isRefreshing = estado.atualizando && estado.dados != null, onRefresh = vm::atualizar, modifier = Modifier.fillMaxSize().padding(preenchimento)) {
        LazyColumn(state = lista, contentPadding = PaddingValues(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp), modifier = Modifier.fillMaxSize()) {
            item(key = "erro") { FaixaDeErro(estado.erro, aoTentarDeNovo = vm::atualizar) }
            val dados = estado.dados
            when {
                estado.esqueleto -> items(5, key = { "esq$it" }) { CartaoEsqueleto() }
                dados == null -> Unit
                dados.itens.isEmpty() -> item(key = "vazio") {
                    EstadoVazio(Icons.Rounded.Forum, "Nenhuma pergunta ainda", "O que você perguntar ao BULA-IA fica aqui por ${dados.retencao} dias. Você pode apagar quando quiser.")
                }
                else -> {
                    item(key = "retencao") {
                        Text("Suas perguntas ficam guardadas por ${dados.retencao} dias e depois são apagadas.", style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
                    }
                    items(dados.itens, key = { it.uuid }) { pergunta ->
                        Cartao(Modifier.animateItem(), aoClicar = { aoAbrir(pergunta.uuid) }) {
                            Box(Modifier.fillMaxWidth()) {
                                Column(Modifier.padding(end = 44.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                                    Text(pergunta.question, style = MaterialTheme.typography.titleMedium, maxLines = 3, overflow = TextOverflow.Ellipsis)
                                    Text(listOfNotNull(pergunta.medicines, rotuloDoStatus(pergunta.status), quando(pergunta.em).takeIf { it.isNotEmpty() }).joinToString(" · "), style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
                                }
                                IconButton(onClick = { vm.apagar(pergunta, recado) }, modifier = Modifier.align(androidx.compose.ui.Alignment.TopEnd)) {
                                    Icon(Icons.Rounded.DeleteOutline, contentDescription = "Apagar esta pergunta")
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}

class PerguntaViewModel(private val repositorio: PerguntasRepositorio, private val uuid: String) : ViewModel() {
    private val _estado = MutableStateFlow(Carga<Resposta>())
    val estado = _estado.asStateFlow()

    init {
        carregar()
    }

    fun carregar() {
        viewModelScope.launch {
            _estado.update { it.copy(atualizando = true, erro = null) }
            repositorio.pergunta(uuid)
                .onSuccess { _estado.value = Carga(it) }
                .onFailure { falha -> _estado.update { it.copy(atualizando = false, erro = falha.message) } }
        }
    }
}

/** Uma pergunta anterior, com a resposta, as fontes e o tratamento como estavam na época. */
@Composable
fun PerguntaRegistradaTela(uuid: String, preenchimento: PaddingValues, aoAbrirBula: (Long) -> Unit = {}) {
    val vm = telaViewModel { PerguntaViewModel(it.perguntas, uuid) }
    val estado by vm.estado.collectAsStateWithLifecycle()
    val leitor = rememberLeitor()
    LazyColumn(Modifier.fillMaxSize().padding(preenchimento), contentPadding = PaddingValues(16.dp), verticalArrangement = Arrangement.spacedBy(14.dp)) {
        item { FaixaDeErro(estado.erro, aoTentarDeNovo = vm::carregar) }
        val resposta = estado.dados
        when {
            estado.esqueleto -> item { CartaoEsqueleto() }
            resposta != null -> {
                item {
                    resposta.perguntadaEm?.let { Text("Perguntado em ${quando(it)}", style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant) }
                }
                item { BalaoDaPessoa(resposta.question) }
                item { BalaoDaResposta(resposta, leitor, acoes = null, aoAbrirBula = aoAbrirBula) }
            }
        }
    }
}
