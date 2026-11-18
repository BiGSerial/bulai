package br.com.vixforge.bulai.ui.bula

import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.animateContentSize
import androidx.compose.animation.expandVertically
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.shrinkVertically
import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.consumeWindowInsets
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.Chat
import androidx.compose.material.icons.automirrored.rounded.MenuBook
import androidx.compose.material.icons.automirrored.rounded.VolumeUp
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.ExpandLess
import androidx.compose.material.icons.rounded.ExpandMore
import androidx.compose.material.icons.rounded.Favorite
import androidx.compose.material.icons.rounded.Info
import androidx.compose.material.icons.rounded.Schedule
import androidx.compose.material.icons.rounded.StopCircle
import androidx.compose.material.icons.rounded.WarningAmber
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.pulltorefresh.PullToRefreshBox
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.semantics.LiveRegionMode
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.liveRegion
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.lifecycle.ViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.lifecycle.viewModelScope
import br.com.vixforge.bulai.dados.PerguntasRepositorio
import br.com.vixforge.bulai.dados.api.Bula
import br.com.vixforge.bulai.dados.api.SecaoDaBula
import br.com.vixforge.bulai.ui.base.Carga
import br.com.vixforge.bulai.ui.base.telaViewModel
import br.com.vixforge.bulai.ui.componentes.BotaoPrincipal
import br.com.vixforge.bulai.ui.componentes.Cartao
import br.com.vixforge.bulai.ui.componentes.CartaoEsqueleto
import br.com.vixforge.bulai.ui.componentes.FaixaDeErro
import br.com.vixforge.bulai.ui.perguntar.PainelDoTratamento
import br.com.vixforge.bulai.ui.sistema.rememberLeitor
import br.com.vixforge.bulai.ui.tema.TemaBulai
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

/** Abre na hora (guardada no aparelho ou do servidor); se ainda não existe, mostra o esqueleto enquanto o servidor prepara. */
class BulaViewModel(private val repositorio: PerguntasRepositorio, private val remedio: Long) : ViewModel() {
    private val _estado = MutableStateFlow(Carga<Bula>())
    val estado = _estado.asStateFlow()

    init {
        viewModelScope.launch {
            repositorio.bulaGuardada(remedio)?.let { guardada -> _estado.update { if (it.dados == null) it.copy(dados = guardada) else it } }
            carregar()
        }
    }

    fun carregar() {
        viewModelScope.launch {
            _estado.update { it.copy(atualizando = true, erro = null) }
            repositorio.bula(remedio)
                .onSuccess { bula ->
                    _estado.value = Carga(bula, atualizando = bula.preparando)
                    if (bula.preparando) {
                        gerar()
                    }
                }
                .onFailure { falha -> _estado.update { it.copy(atualizando = false, erro = falha.message) } }
        }
    }

    private suspend fun gerar() {
        repositorio.gerarBula(remedio)
            .onSuccess { _estado.value = Carga(it) }
            .onFailure { falha -> _estado.update { it.copy(atualizando = false, erro = falha.message) } }
    }
}

/** Bula humanizada (bula simples): capa com "Para que serve", alerta pessoal, seções por cor, fontes e o tratamento. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun BulaTela(remedio: Long, preenchimento: PaddingValues, aoPerguntar: (Long) -> Unit) {
    val vm = telaViewModel { BulaViewModel(it.perguntas, remedio) }
    val estado by vm.estado.collectAsStateWithLifecycle()
    val bula = estado.dados
    val largura = Modifier.widthIn(max = 640.dp)

    Column(Modifier.fillMaxSize().padding(preenchimento).consumeWindowInsets(preenchimento)) {
        PullToRefreshBox(isRefreshing = estado.atualizando && bula != null && !bula.preparando, onRefresh = vm::carregar, modifier = Modifier.weight(1f)) {
            LazyColumn(
                Modifier.fillMaxSize(),
                contentPadding = PaddingValues(16.dp),
                verticalArrangement = Arrangement.spacedBy(14.dp),
                horizontalAlignment = Alignment.CenterHorizontally,
            ) {
                item(key = "erro") { Box(largura) { FaixaDeErro(estado.erro, aoTentarDeNovo = vm::carregar) } }
                if (bula == null) {
                    if (estado.esqueleto) {
                        items(4, key = { "esq$it" }) { CartaoEsqueleto(largura, comBotao = false) }
                    }
                    return@LazyColumn
                }
                item(key = "capa") { Capa(bula, largura) }
                bula.risk?.let { risco -> item(key = "risco") { Risco(risco.text, risco.tone == "danger", largura) } }
                when (bula.state) {
                    Bula.NAO_IDENTIFICADO -> item(key = "aviso") { Aviso("Não identificamos o princípio ativo (a substância) deste remédio no catálogo, então não conseguimos localizar a bula certa. Consulte a bula da embalagem ou um farmacêutico.", largura) }
                    Bula.SEM_BULA -> item(key = "aviso") { Aviso("Ainda não há bula deste remédio cadastrada no BULA-IA. Não montamos resumos “de memória”: consulte a bula na embalagem ou o farmacêutico.", largura) }
                    Bula.LIMITE -> item(key = "aviso") { Aviso("Você atingiu o limite de bulas geradas por hora. Tente novamente mais tarde; as bulas já preparadas continuam disponíveis.", largura) }
                    Bula.CARREGANDO -> {
                        item(key = "preparando") {
                            Cartao(largura.semantics { liveRegion = LiveRegionMode.Polite }, cor = MaterialTheme.colorScheme.primaryContainer) {
                                Row(verticalAlignment = Alignment.CenterVertically) {
                                    CircularProgressIndicator(Modifier.size(26.dp), strokeWidth = 3.dp)
                                    Spacer(Modifier.width(14.dp))
                                    Text("Preparando a bula humanizada…", style = MaterialTheme.typography.titleMedium)
                                }
                                Text("Leva alguns segundos na primeira vez; depois abre na hora.", style = MaterialTheme.typography.bodyMedium)
                            }
                        }
                        items(3, key = { "prep$it" }) { CartaoEsqueleto(largura, comBotao = false) }
                    }
                    else -> {
                        if (bula.failure != null) {
                            item(key = "falha") {
                                Aviso(
                                    "Não foi possível montar a bula humanizada com segurança agora. " +
                                        (if (bula.failure == "unavailable") "O serviço está indisponível ou a bula não trouxe trechos suficientes." else "O resumo não passou na conferência automática e foi descartado.") +
                                        " Você ainda pode fazer uma pergunta ou consultar a bula da embalagem.",
                                    largura,
                                )
                            }
                        }
                        items(bula.sections, key = { it.key }) { secao -> Secao(secao, abertaNoInicio = bula.sections.indexOf(secao) < 2, modifier = largura.animateContentSize()) }
                        bula.cover?.let { capa -> item(key = "fonte-capa") { DeOndeVeio("De onde veio “${capa.label}”?", capa.sources, largura.fillMaxWidth()) } }
                        if (bula.missing.isNotEmpty()) {
                            item(key = "faltam") {
                                Text("A bula cadastrada não trouxe trechos suficientes para: ${bula.missing.joinToString(", ")}. Consulte a bula completa ou um farmacêutico.", style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant, modifier = largura)
                            }
                        }
                        bula.prepared?.let { nota -> item(key = "nota") { Text(nota, style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant, modifier = largura) } }
                    }
                }
                bula.treatment?.let { tratamento -> item(key = "tratamento") { PainelDoTratamento(tratamento, largura) } }
            }
        }
        Surface(tonalElevation = 3.dp, shadowElevation = 8.dp) {
            Box(Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 12.dp), contentAlignment = Alignment.Center) {
                BotaoPrincipal("Perguntar sobre este remédio", { aoPerguntar(remedio) }, modifier = largura, destaque = true, icone = Icons.AutoMirrored.Rounded.Chat)
            }
        }
    }
}

@Composable
private fun Capa(bula: Bula, modifier: Modifier) {
    val leitor = rememberLeitor()
    val falando by leitor.falando
    Surface(modifier.fillMaxWidth(), shape = MaterialTheme.shapes.extraLarge, color = MaterialTheme.colorScheme.primaryContainer) {
        Column(Modifier.padding(20.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            Row(verticalAlignment = Alignment.Top) {
                Icon(Icons.AutoMirrored.Rounded.MenuBook, contentDescription = null, modifier = Modifier.size(34.dp), tint = MaterialTheme.colorScheme.primary)
                Spacer(Modifier.width(12.dp))
                Column {
                    Text("BULA SIMPLES", style = MaterialTheme.typography.labelLarge, color = MaterialTheme.colorScheme.onPrimaryContainer.copy(alpha = 0.75f))
                    Text(bula.medicine.name, style = MaterialTheme.typography.headlineSmall, fontWeight = FontWeight.SemiBold, modifier = Modifier.semantics { heading() })
                    Text(bula.medicine.ingredient ?: "substância não informada", style = MaterialTheme.typography.bodyLarge, color = MaterialTheme.colorScheme.onPrimaryContainer.copy(alpha = 0.8f))
                }
            }
            bula.cover?.let { capa ->
                Surface(shape = MaterialTheme.shapes.large, color = MaterialTheme.colorScheme.surface.copy(alpha = 0.55f)) {
                    Column(Modifier.fillMaxWidth().padding(14.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                        Text(capa.label.uppercase(), style = MaterialTheme.typography.labelLarge, color = MaterialTheme.colorScheme.onSurfaceVariant)
                        capa.claims.forEach { afirmacao ->
                            Row(verticalAlignment = Alignment.Top) {
                                Icon(Icons.Rounded.CheckCircle, contentDescription = null, tint = MaterialTheme.colorScheme.secondary, modifier = Modifier.size(22.dp).padding(top = 3.dp))
                                Spacer(Modifier.width(10.dp))
                                Text(afirmacao, style = MaterialTheme.typography.bodyLarge)
                            }
                        }
                    }
                }
            }
            if (bula.state == Bula.PRONTA && (bula.cover != null || bula.sections.isNotEmpty())) {
                OutlinedButton(onClick = { leitor.alternar(bula.textoCorrido) }, modifier = Modifier.fillMaxWidth().heightIn(min = 52.dp)) {
                    Icon(if (falando) Icons.Rounded.StopCircle else Icons.AutoMirrored.Rounded.VolumeUp, contentDescription = null)
                    Spacer(Modifier.width(8.dp))
                    Text(if (falando) "Parar de ouvir" else "Ouvir a bula simples", style = MaterialTheme.typography.labelLarge)
                }
            }
        }
    }
}

@Composable
private fun Risco(texto: String, grave: Boolean, modifier: Modifier) {
    val (fundo, borda) = if (grave) MaterialTheme.colorScheme.errorContainer to MaterialTheme.colorScheme.error else MaterialTheme.colorScheme.secondaryContainer to MaterialTheme.colorScheme.secondary
    Surface(modifier.fillMaxWidth().semantics { liveRegion = LiveRegionMode.Polite }, shape = MaterialTheme.shapes.large, color = fundo, border = BorderStroke(2.dp, borda)) {
        Row(Modifier.padding(16.dp), verticalAlignment = Alignment.Top) {
            Icon(Icons.Rounded.WarningAmber, contentDescription = null, tint = borda)
            Spacer(Modifier.width(12.dp))
            Text(texto, style = MaterialTheme.typography.titleMedium)
        }
    }
}

@Composable
private fun Aviso(texto: String, modifier: Modifier) {
    Cartao(modifier, cor = MaterialTheme.colorScheme.secondaryContainer) { Text(texto, style = MaterialTheme.typography.bodyLarge) }
}

/** Cor e ícone de cada seção, como no site (o servidor manda o tom). */
@Composable
private fun estiloDaSecao(secao: SecaoDaBula): Pair<Color, ImageVector> = when (secao.tone) {
    "success" -> TemaBulai.extras.sucesso to Icons.Rounded.CheckCircle
    "warning" -> MaterialTheme.colorScheme.secondary to (if (secao.key == "efeitos_colaterais") Icons.Rounded.Favorite else Icons.Rounded.WarningAmber)
    "danger" -> MaterialTheme.colorScheme.error to Icons.Rounded.WarningAmber
    else -> MaterialTheme.colorScheme.primary to (if (secao.key == "como_usar") Icons.Rounded.Schedule else Icons.Rounded.Info)
}

@Composable
private fun Secao(secao: SecaoDaBula, abertaNoInicio: Boolean, modifier: Modifier) {
    var aberta by rememberSaveable(secao.key) { mutableStateOf(abertaNoInicio) }
    val (cor, icone) = estiloDaSecao(secao)
    Surface(modifier.fillMaxWidth(), shape = MaterialTheme.shapes.large, color = MaterialTheme.colorScheme.surface, border = BorderStroke(1.dp, cor.copy(alpha = 0.45f))) {
        Column {
            Row(
                Modifier.fillMaxWidth().clickable(onClickLabel = if (aberta) "Recolher" else "Abrir") { aberta = !aberta }.padding(horizontal = 16.dp, vertical = 14.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Box(Modifier.size(38.dp), contentAlignment = Alignment.Center) {
                    Surface(shape = CircleShape, color = cor.copy(alpha = 0.15f), modifier = Modifier.size(38.dp)) {}
                    Icon(icone, contentDescription = null, tint = cor, modifier = Modifier.size(22.dp))
                }
                Spacer(Modifier.width(12.dp))
                Text(secao.label, style = MaterialTheme.typography.titleMedium, modifier = Modifier.weight(1f).semantics { heading() })
                Icon(if (aberta) Icons.Rounded.ExpandLess else Icons.Rounded.ExpandMore, contentDescription = null)
            }
            AnimatedVisibility(aberta, enter = expandVertically() + fadeIn(), exit = shrinkVertically() + fadeOut()) {
                Column(Modifier.padding(start = 16.dp, end = 16.dp, bottom = 16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                    secao.claims.forEach { afirmacao ->
                        Row(verticalAlignment = Alignment.Top) {
                            Box(Modifier.padding(top = 10.dp).size(8.dp)) { Surface(shape = CircleShape, color = cor, modifier = Modifier.size(8.dp)) {} }
                            Spacer(Modifier.width(12.dp))
                            Text(afirmacao, style = MaterialTheme.typography.bodyLarge)
                        }
                    }
                    DeOndeVeio("De onde veio?", secao.sources)
                }
            }
        }
    }
}

/** Fontes de uma seção (bula, seção, página), recolhidas como no site. */
@Composable
private fun DeOndeVeio(titulo: String, fontes: List<String>, modifier: Modifier = Modifier) {
    if (fontes.isEmpty()) {
        return
    }
    var aberto by rememberSaveable(titulo, fontes.firstOrNull()) { mutableStateOf(false) }
    Column(modifier) {
        Row(Modifier.clickable { aberto = !aberto }.heightIn(min = 44.dp).padding(vertical = 6.dp), verticalAlignment = Alignment.CenterVertically) {
            Text(titulo, style = MaterialTheme.typography.labelLarge, color = MaterialTheme.colorScheme.primary)
            Icon(if (aberto) Icons.Rounded.ExpandLess else Icons.Rounded.ExpandMore, contentDescription = null, tint = MaterialTheme.colorScheme.primary)
        }
        AnimatedVisibility(aberto) {
            Column(verticalArrangement = Arrangement.spacedBy(4.dp)) {
                fontes.forEach { fonte -> Text("• $fonte", style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant) }
            }
        }
    }
}
