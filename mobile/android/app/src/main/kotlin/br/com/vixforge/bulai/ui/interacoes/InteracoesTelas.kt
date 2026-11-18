package br.com.vixforge.bulai.ui.interacoes

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.KeyboardArrowRight
import androidx.compose.material.icons.rounded.Add
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.CompareArrows
import androidx.compose.material.icons.rounded.DeleteOutline
import androidx.compose.material.icons.rounded.ExpandLess
import androidx.compose.material.icons.rounded.ExpandMore
import androidx.compose.material.icons.rounded.Refresh
import androidx.compose.material.icons.rounded.WarningAmber
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.liveRegion
import androidx.compose.ui.semantics.LiveRegionMode
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.LifecycleResumeEffect
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import br.com.vixforge.bulai.dados.api.ParDeRemedios
import br.com.vixforge.bulai.dados.api.RemedioDaConsulta
import br.com.vixforge.bulai.ui.base.telaViewModel
import br.com.vixforge.bulai.ui.componentes.Bloco
import br.com.vixforge.bulai.ui.componentes.BotaoPrincipal
import br.com.vixforge.bulai.ui.componentes.BotaoSecundario
import br.com.vixforge.bulai.ui.componentes.Cartao
import br.com.vixforge.bulai.ui.componentes.FaixaDeErro
import br.com.vixforge.bulai.ui.componentes.LinhaEsqueleto
import br.com.vixforge.bulai.ui.componentes.TelaEsqueleto
import br.com.vixforge.bulai.ui.cuidado.dataCurta
import br.com.vixforge.bulai.ui.perguntar.BuscaDeRemedio
import br.com.vixforge.bulai.ui.tema.TemaBulai

private val LARGURA = Modifier.widthIn(max = 640.dp)

/**
 * Interações: os remédios em uso já estão na lista; a pessoa acrescenta outros pela busca e toca em "Verificar". Embaixo,
 * as consultas anteriores. [comRemedio] acrescenta um remédio de saída (vindo da bula simples).
 */
@Composable
fun InteracoesTela(preenchimento: PaddingValues, aoAbrirResultado: (String) -> Unit, comRemedio: Long? = null) {
    val vm = telaViewModel { PesquisaViewModel(it.perfil, it.perguntas, comRemedio) }
    val estado by vm.estado.collectAsStateWithLifecycle()
    var buscar by rememberSaveable { mutableStateOf(false) }
    LaunchedEffect(Unit) { vm.abrir.collect(aoAbrirResultado) }
    LifecycleResumeEffect(Unit) {
        vm.atualizarHistorico()
        onPauseOrDispose { }
    }

    LazyColumn(
        Modifier.fillMaxSize(),
        contentPadding = PaddingValues(start = 20.dp, end = 20.dp, top = preenchimento.calculateTopPadding() + 8.dp, bottom = preenchimento.calculateBottomPadding() + 24.dp),
        verticalArrangement = Arrangement.spacedBy(12.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        item(key = "explica") {
            Text(
                "Veja se dois ou mais remédios podem interagir. Os remédios que você usa já estão na lista.",
                style = MaterialTheme.typography.bodyLarge,
                modifier = LARGURA.fillMaxWidth(),
            )
        }
        if (estado.carregando) {
            item(key = "esqueleto") { TelaEsqueleto(LARGURA) { repeat(3) { LinhaEsqueleto() } } }
        } else {
            item(key = "lista-titulo") {
                Text("Remédios para verificar (${estado.escolhidos.size} de ${estado.maximo})", style = MaterialTheme.typography.titleMedium, color = MaterialTheme.colorScheme.onSurfaceVariant, modifier = LARGURA.fillMaxWidth().semantics { heading() })
            }
            items(estado.escolhidos, key = { "escolhido-${it.id}" }) { remedio -> LinhaDoEscolhido(remedio, { vm.tirar(remedio.id) }, LARGURA.animateItem()) }
            item(key = "acoes") {
                Column(LARGURA.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                    BotaoSecundario("Adicionar remédio", { buscar = true }, Modifier.fillMaxWidth(), icone = Icons.Rounded.Add)
                    estado.mensagem?.let { Text(it, color = MaterialTheme.colorScheme.error, style = MaterialTheme.typography.bodyLarge, modifier = Modifier.semantics { liveRegion = LiveRegionMode.Polite }) }
                    BotaoPrincipal("Verificar interações", vm::verificar, carregando = estado.verificando, destaque = true, icone = Icons.Rounded.CompareArrows)
                }
            }
            if (estado.historico.isNotEmpty()) {
                item(key = "historico-titulo") {
                    Text("Consultas anteriores", style = MaterialTheme.typography.titleMedium, color = MaterialTheme.colorScheme.onSurfaceVariant, modifier = LARGURA.fillMaxWidth().padding(top = 8.dp).semantics { heading() })
                }
                items(estado.historico, key = { "consulta-${it.uuid}" }) { consulta ->
                    Cartao(LARGURA.animateItem(), aoClicar = { aoAbrirResultado(consulta.uuid) }) {
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            Column(Modifier.weight(1f)) {
                                Text(consulta.medicines.joinToString(" + "), style = MaterialTheme.typography.titleMedium)
                                Text(
                                    "${dataCurta(consulta.criadaEm)} · ${when (consulta.status) { "COMPLETED" -> "pronta"; "FAILED" -> "não concluída"; else -> "analisando" }}",
                                    style = MaterialTheme.typography.bodyMedium,
                                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                                )
                            }
                            IconButton(onClick = { vm.apagar(consulta.uuid) }) { Icon(Icons.Rounded.DeleteOutline, contentDescription = "Apagar esta consulta", tint = MaterialTheme.colorScheme.onSurfaceVariant) }
                            Icon(Icons.AutoMirrored.Rounded.KeyboardArrowRight, contentDescription = null, tint = MaterialTheme.colorScheme.onSurfaceVariant)
                        }
                    }
                }
            }
        }
    }

    if (buscar) {
        BuscaDeRemedio(
            buscando = estado.buscando,
            resultados = estado.resultados,
            aoBuscar = vm::buscar,
            aoEscolher = { buscar = false; vm.adicionar(it) },
            aoFechar = { buscar = false; vm.fecharBusca() },
            titulo = "Adicionar remédio",
        )
    }
}

@Composable
private fun LinhaDoEscolhido(remedio: RemedioDaConsulta, aoTirar: () -> Unit, modifier: Modifier = Modifier) {
    Cartao(modifier, cor = if (remedio.alerts.isNotEmpty()) MaterialTheme.colorScheme.errorContainer.copy(alpha = 0.35f) else MaterialTheme.colorScheme.surface) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Column(Modifier.weight(1f)) {
                Text(remedio.name, style = MaterialTheme.typography.titleMedium)
                Text(remedio.ingredients.lowercase(), style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
                if (!remedio.identified) {
                    Text("Princípio ativo não identificado: o resultado pode ficar incompleto.", style = MaterialTheme.typography.bodyMedium, color = TemaBulai.extras.alerta)
                }
            }
            IconButton(onClick = aoTirar) { Icon(Icons.Rounded.Close, contentDescription = "Tirar ${remedio.name} da lista") }
        }
        remedio.alerts.forEach { alerta ->
            Row(verticalAlignment = Alignment.Top) {
                Icon(Icons.Rounded.WarningAmber, contentDescription = null, tint = MaterialTheme.colorScheme.error, modifier = Modifier.size(20.dp))
                Spacer(Modifier.width(8.dp))
                Text(alerta, style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.error, fontWeight = FontWeight.SemiBold)
            }
        }
    }
}

/** Resultado da consulta: alerta de alergia, progresso enquanto analisa e um cartão por par com a faixa de cor e a frase. */
@OptIn(ExperimentalLayoutApi::class)
@Composable
fun ResultadoTela(uuid: String, preenchimento: PaddingValues, aoAbrirOutro: (String) -> Unit) {
    val vm = telaViewModel { ResultadoViewModel(it.perfil, uuid) }
    val carga by vm.carga.collectAsStateWithLifecycle()
    LaunchedEffect(Unit) { vm.abrir.collect(aoAbrirOutro) }

    LazyColumn(
        Modifier.fillMaxSize(),
        contentPadding = PaddingValues(start = 20.dp, end = 20.dp, top = preenchimento.calculateTopPadding() + 8.dp, bottom = preenchimento.calculateBottomPadding() + 24.dp),
        verticalArrangement = Arrangement.spacedBy(12.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        item(key = "erro") { FaixaDeErro(carga.erro, LARGURA, vm::acompanhar) }
        val consulta = carga.dados
        if (consulta == null) {
            if (carga.esqueleto) {
                item(key = "esqueleto") {
                    TelaEsqueleto(LARGURA) {
                        Bloco(0.7f, 22.dp)
                        repeat(4) { LinhaEsqueleto() }
                    }
                }
            }
            return@LazyColumn
        }
        item(key = "remedios") {
            FlowRow(LARGURA.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                consulta.medicines.forEach { nome ->
                    Surface(color = MaterialTheme.colorScheme.primaryContainer, shape = CircleShape) {
                        Text(nome, style = MaterialTheme.typography.labelLarge, color = MaterialTheme.colorScheme.onPrimaryContainer, modifier = Modifier.padding(horizontal = 14.dp, vertical = 8.dp))
                    }
                }
            }
        }
        items(consulta.alertas, key = { "alergia-${it.medicine}" }) { alerta ->
            Cartao(LARGURA, cor = MaterialTheme.colorScheme.errorContainer) {
                Row(verticalAlignment = Alignment.Top) {
                    Icon(Icons.Rounded.WarningAmber, contentDescription = null, tint = MaterialTheme.colorScheme.onErrorContainer)
                    Spacer(Modifier.width(10.dp))
                    Column {
                        Text("Atenção à sua alergia: ${alerta.medicine}", style = MaterialTheme.typography.titleMedium, color = MaterialTheme.colorScheme.onErrorContainer)
                        Text(alerta.text, style = MaterialTheme.typography.bodyLarge, color = MaterialTheme.colorScheme.onErrorContainer)
                    }
                }
            }
        }
        when {
            consulta.running -> item(key = "analisando") {
                Cartao(LARGURA.semantics { liveRegion = LiveRegionMode.Polite }, cor = MaterialTheme.colorScheme.primaryContainer.copy(alpha = 0.5f)) {
                    Text(if (consulta.waiting) "Aguardando o início da análise…" else "Analisando…", style = MaterialTheme.typography.titleMedium)
                    LinearProgressIndicator(
                        progress = { if (consulta.total == 0) 0f else consulta.done / consulta.total.toFloat() },
                        modifier = Modifier.fillMaxWidth().height(10.dp).clip(CircleShape),
                        drawStopIndicator = {},
                    )
                    Text("${consulta.done} de ${consulta.total} combinações prontas.", style = MaterialTheme.typography.bodyLarge)
                }
            }
            consulta.failed -> item(key = "falhou") {
                Cartao(LARGURA, cor = MaterialTheme.colorScheme.errorContainer) {
                    Text(consulta.failure ?: "Não foi possível concluir a análise.", style = MaterialTheme.typography.titleMedium, color = MaterialTheme.colorScheme.onErrorContainer)
                    Text("Nenhum resultado parcial deve ser lido como ausência de interação.", style = MaterialTheme.typography.bodyLarge, color = MaterialTheme.colorScheme.onErrorContainer)
                    BotaoPrincipal("Tentar novamente", vm::repetir, icone = Icons.Rounded.Refresh)
                }
            }
        }
        items(consulta.pairs, key = { "par-${it.id}" }) { par -> CartaoDoPar(par, LARGURA.animateItem()) }
        if (!consulta.running && !consulta.failed) {
            item(key = "repetir") { BotaoSecundario("Repetir esta consulta", vm::repetir, LARGURA.fillMaxWidth(), icone = Icons.Rounded.Refresh) }
        }
        item(key = "aviso") {
            Text("O BULA-IA não substitui seu médico ou farmacêutico. Na dúvida, pergunte a eles antes de usar os remédios juntos.", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant, modifier = LARGURA.fillMaxWidth())
        }
    }
}

@Composable
private fun corDoNivel(nivel: String): Color = when (nivel) {
    "major" -> MaterialTheme.colorScheme.error
    "moderate" -> TemaBulai.extras.alerta
    "minor" -> Color(0xFFE6C229)
    "unclassified" -> MaterialTheme.colorScheme.outline
    else -> MaterialTheme.colorScheme.outlineVariant
}

@Composable
private fun CartaoDoPar(par: ParDeRemedios, modifier: Modifier = Modifier) {
    var aberto by rememberSaveable(par.id) { mutableStateOf(false) }
    val cor = corDoNivel(par.level)
    Surface(modifier.fillMaxWidth(), shape = MaterialTheme.shapes.large, color = MaterialTheme.colorScheme.surface, tonalElevation = 1.dp) {
        Column {
            Box(Modifier.fillMaxWidth().height(12.dp).background(cor))
            Column(Modifier.padding(18.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                Text("${par.a} + ${par.b}", style = MaterialTheme.typography.titleLarge, modifier = Modifier.semantics { heading() })
                par.badge?.let {
                    Surface(color = cor.copy(alpha = 0.18f), shape = CircleShape) {
                        Text(it, style = MaterialTheme.typography.labelLarge, modifier = Modifier.padding(horizontal = 12.dp, vertical = 6.dp))
                    }
                }
                Text(par.phrase, style = MaterialTheme.typography.bodyLarge, fontWeight = FontWeight.SemiBold)
                val temDetalhe = par.explanation != null || par.evidences.isNotEmpty() || par.limitations.isNotEmpty()
                if (temDetalhe) {
                    TextButton(onClick = { aberto = !aberto }, modifier = Modifier.heightIn(min = 48.dp)) {
                        Text(if (aberto) "Esconder detalhes" else "O que a bula diz")
                        Spacer(Modifier.width(6.dp))
                        Icon(if (aberto) Icons.Rounded.ExpandLess else Icons.Rounded.ExpandMore, contentDescription = null)
                    }
                }
                if (aberto) {
                    par.explanation?.let {
                        Text(it, style = MaterialTheme.typography.bodyLarge)
                        Text("Baseado na bula oficial, conferido automaticamente.", style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
                    }
                    par.evidences.forEach { evidencia ->
                        Text(
                            "${evidencia.title} · ${evidencia.section}${evidencia.page?.let { " · p. $it" }.orEmpty()}",
                            style = MaterialTheme.typography.bodyMedium,
                            color = MaterialTheme.colorScheme.onSurfaceVariant,
                        )
                        evidencia.excerpt?.let {
                            Text(it, style = MaterialTheme.typography.bodyMedium, modifier = Modifier.fillMaxWidth().clip(MaterialTheme.shapes.small).background(MaterialTheme.colorScheme.surfaceVariant).padding(10.dp))
                        }
                    }
                    par.limitations.forEach { Text("• $it", style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant) }
                    par.sources?.let { Text("Base de interações: $it", style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant) }
                }
            }
        }
    }
}
