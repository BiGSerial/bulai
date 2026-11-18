package br.com.vixforge.bulai.ui.bula

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.MenuBook
import androidx.compose.material.icons.rounded.Search
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.ListItem
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import androidx.lifecycle.ViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.lifecycle.viewModelScope
import br.com.vixforge.bulai.dados.PerfilRepositorio
import br.com.vixforge.bulai.dados.api.BulaPronta
import br.com.vixforge.bulai.dados.api.RemedioComBula
import br.com.vixforge.bulai.ui.base.telaViewModel
import br.com.vixforge.bulai.ui.componentes.FaixaDeErro
import br.com.vixforge.bulai.ui.componentes.LinhaEsqueleto
import br.com.vixforge.bulai.ui.componentes.TelaEsqueleto
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch
import java.text.Normalizer

data class EstadoDasBulas(
    val prontas: List<BulaPronta>? = null,
    val resultados: List<RemedioComBula>? = null,
    val buscando: Boolean = false,
    val limitado: Boolean = false,
    val erro: String? = null,
)

/** As bulas prontas filtram no aparelho a cada letra; com 3 letras ou mais, a busca no catálogo diz quais têm bula. */
class BulasViewModel(private val repositorio: PerfilRepositorio) : ViewModel() {
    private val _estado = MutableStateFlow(EstadoDasBulas())
    val estado: StateFlow<EstadoDasBulas> = _estado.asStateFlow()
    private var busca: Job? = null

    init {
        carregar()
    }

    fun carregar() {
        viewModelScope.launch {
            repositorio.bulas()
                .onSuccess { lista -> _estado.update { it.copy(prontas = lista.available, erro = null) } }
                .onFailure { falha -> _estado.update { it.copy(erro = falha.message) } }
        }
    }

    fun buscar(termo: String) {
        busca?.cancel()
        if (termo.trim().length < 3) {
            _estado.update { it.copy(resultados = null, buscando = false, limitado = false) }
            return
        }
        busca = viewModelScope.launch {
            delay(350)
            _estado.update { it.copy(buscando = true) }
            repositorio.bulas(termo)
                .onSuccess { lista -> _estado.update { it.copy(resultados = lista.results, limitado = lista.limited, buscando = false) } }
                .onFailure { _estado.update { it.copy(buscando = false) } }
        }
    }
}

private fun semAcento(texto: String) = Normalizer.normalize(texto, Normalizer.Form.NFD).replace(Regex("\\p{M}+"), "").lowercase()

/** Bula simples: um lugar só para achar a bula humanizada de um remédio. */
@Composable
fun BulasTela(preenchimento: PaddingValues, aoAbrirBula: (Long) -> Unit) {
    val vm = telaViewModel { BulasViewModel(it.perfil) }
    val estado by vm.estado.collectAsStateWithLifecycle()
    var termo by rememberSaveable { mutableStateOf("") }
    val largura = Modifier.widthIn(max = 640.dp)
    val filtradas = estado.prontas.orEmpty().filter { termo.isBlank() || semAcento(it.name).contains(semAcento(termo.trim())) }

    LazyColumn(
        Modifier.fillMaxSize(),
        contentPadding = PaddingValues(start = 20.dp, end = 20.dp, top = preenchimento.calculateTopPadding() + 8.dp, bottom = preenchimento.calculateBottomPadding() + 24.dp),
        verticalArrangement = Arrangement.spacedBy(8.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        item(key = "busca") {
            Column(largura.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Text("A bula explicada em linguagem simples: para que serve, como usar, cuidados e efeitos.", style = MaterialTheme.typography.bodyLarge)
                OutlinedTextField(
                    value = termo,
                    onValueChange = { termo = it; vm.buscar(it) },
                    modifier = Modifier.fillMaxWidth(),
                    placeholder = { Text("Nome do remédio") },
                    leadingIcon = { Icon(Icons.Rounded.Search, contentDescription = null) },
                    trailingIcon = if (estado.buscando) {
                        { CircularProgressIndicator(Modifier.size(22.dp), strokeWidth = 2.dp) }
                    } else {
                        null
                    },
                    singleLine = true,
                    textStyle = MaterialTheme.typography.bodyLarge,
                    shape = MaterialTheme.shapes.medium,
                )
            }
        }
        item(key = "erro") { FaixaDeErro(estado.erro, largura, vm::carregar) }
        if (estado.limitado) {
            item(key = "limite") { Text("Muitas buscas em pouco tempo. Espere um minuto.", color = MaterialTheme.colorScheme.error, modifier = largura.fillMaxWidth()) }
        }
        estado.resultados?.let { resultados ->
            item(key = "catalogo") { Titulo("No catálogo", largura) }
            if (resultados.isEmpty()) {
                item(key = "nada") { Text("Nenhum remédio encontrado para “$termo”.", style = MaterialTheme.typography.bodyLarge, modifier = largura.fillMaxWidth()) }
            }
            items(resultados, key = { "r-${it.id}" }) { remedio ->
                Linha(remedio.name, listOfNotNull(remedio.ingredients.lowercase().takeIf { it.isNotBlank() }, if (remedio.temBula) "bula simples pronta" else "a bula simples é preparada na hora").joinToString(" · "), largura) { aoAbrirBula(remedio.id) }
            }
        }
        if (estado.prontas == null && estado.erro == null) {
            item(key = "esqueleto") { TelaEsqueleto(largura) { repeat(6) { LinhaEsqueleto() } } }
        } else if (filtradas.isNotEmpty()) {
            item(key = "prontas") { Titulo(if (termo.isBlank()) "Bulas prontas (${filtradas.size})" else "Bulas prontas", largura) }
            items(filtradas, key = { "p-${it.id}" }) { bula -> Linha(bula.name, null, largura) { aoAbrirBula(bula.id) } }
        }
    }
}

@Composable
private fun Titulo(texto: String, modifier: Modifier) {
    Text(texto, style = MaterialTheme.typography.titleMedium, color = MaterialTheme.colorScheme.onSurfaceVariant, modifier = modifier.fillMaxWidth().padding(top = 8.dp))
}

@Composable
private fun Linha(nome: String, apoio: String?, modifier: Modifier, aoAbrir: () -> Unit) {
    Surface(onClick = aoAbrir, shape = MaterialTheme.shapes.medium, color = MaterialTheme.colorScheme.surface, modifier = modifier.fillMaxWidth()) {
        ListItem(
            leadingContent = { Icon(Icons.AutoMirrored.Rounded.MenuBook, contentDescription = null, tint = MaterialTheme.colorScheme.primary) },
            headlineContent = { Text(nome, style = MaterialTheme.typography.titleMedium) },
            supportingContent = apoio?.let { { Text(it, style = MaterialTheme.typography.bodyMedium) } },
        )
    }
}
