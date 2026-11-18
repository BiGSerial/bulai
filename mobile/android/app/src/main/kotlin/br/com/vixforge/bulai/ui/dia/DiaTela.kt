package br.com.vixforge.bulai.ui.dia

import androidx.compose.animation.AnimatedContent
import androidx.compose.animation.core.Animatable
import androidx.compose.animation.core.FastOutSlowInEasing
import androidx.compose.animation.core.Spring
import androidx.compose.animation.core.spring
import androidx.compose.animation.core.tween
import androidx.compose.material.icons.rounded.ThumbUp
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.draw.drawBehind
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.layout.LayoutCoordinates
import androidx.compose.ui.layout.onGloballyPositioned
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.semantics.LiveRegionMode
import androidx.compose.ui.semantics.clearAndSetSemantics
import androidx.compose.ui.semantics.liveRegion
import br.com.vixforge.bulai.abertura.VideoDeAbertura
import kotlinx.coroutines.delay
import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.animateContentSize
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.togetherWith
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
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
import androidx.compose.foundation.pager.HorizontalPager
import androidx.compose.foundation.pager.rememberPagerState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.Undo
import androidx.compose.material.icons.rounded.Bedtime
import androidx.compose.material.icons.rounded.Check
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.DoNotDisturbOn
import androidx.compose.material.icons.rounded.EventBusy
import androidx.compose.material.icons.rounded.Schedule
import androidx.compose.material.icons.rounded.WarningAmber
import androidx.compose.material.icons.rounded.WbSunny
import androidx.compose.material.icons.rounded.WbTwilight
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.PrimaryTabRow
import androidx.compose.material3.Surface
import androidx.compose.material3.Tab
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.pulltorefresh.PullToRefreshBox
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.snapshotFlow
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.hapticfeedback.HapticFeedbackType
import androidx.compose.ui.platform.LocalHapticFeedback
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.semantics.stateDescription
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.LifecycleResumeEffect
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import br.com.vixforge.bulai.dados.api.Dia
import br.com.vixforge.bulai.dados.api.Dose
import br.com.vixforge.bulai.dados.api.RemedioSeNecessario
import br.com.vixforge.bulai.ui.base.Carga
import br.com.vixforge.bulai.ui.base.telaViewModel
import br.com.vixforge.bulai.ui.componentes.Bloco
import br.com.vixforge.bulai.ui.componentes.Cartao
import br.com.vixforge.bulai.ui.componentes.EstadoVazio
import br.com.vixforge.bulai.ui.componentes.FaixaDeErro
import br.com.vixforge.bulai.ui.componentes.LinhaEsqueleto
import br.com.vixforge.bulai.ui.componentes.TelaEsqueleto
import br.com.vixforge.bulai.ui.tema.TemaBulai
import kotlinx.coroutines.launch

private val TITULOS = mapOf(-1 to "Ontem", 0 to "Hoje", 1 to "Amanhã")

@Composable
fun DiaTela(recado: (DiaViewModel.Recado) -> Unit, preenchimento: PaddingValues) {
    val vm = telaViewModel { DiaViewModel(it.tratamento) }
    val dias by vm.dias.collectAsStateWithLifecycle()
    val enviando by vm.enviando.collectAsStateWithLifecycle()
    val paginas = rememberPagerState(initialPage = 1) { DiaViewModel.DESLOCAMENTOS.size }
    val escopo = rememberCoroutineScope()
    LaunchedEffect(Unit) { vm.recados.collect(recado) }
    // Cada dia é buscado quando aparece na tela (deslizar ou tocar na aba).
    LaunchedEffect(paginas) { snapshotFlow { paginas.settledPage }.collect { vm.atualizar(DiaViewModel.DESLOCAMENTOS[it]) } }
    LifecycleResumeEffect(Unit) {
        vm.atualizar(DiaViewModel.DESLOCAMENTOS[paginas.currentPage])
        onPauseOrDispose { }
    }

    Column(Modifier.fillMaxSize().padding(top = preenchimento.calculateTopPadding())) {
        PrimaryTabRow(selectedTabIndex = paginas.currentPage, containerColor = MaterialTheme.colorScheme.background) {
            DiaViewModel.DESLOCAMENTOS.forEachIndexed { indice, deslocamento ->
                Tab(
                    selected = paginas.currentPage == indice,
                    onClick = { escopo.launch { paginas.animateScrollToPage(indice) } },
                    text = { Text(TITULOS.getValue(deslocamento), style = MaterialTheme.typography.titleSmall) },
                    modifier = Modifier.heightIn(min = 56.dp),
                )
            }
        }
        HorizontalPager(paginas, Modifier.fillMaxSize(), beyondViewportPageCount = 1, key = { DiaViewModel.DESLOCAMENTOS[it] }) { pagina ->
            val deslocamento = DiaViewModel.DESLOCAMENTOS[pagina]
            PaginaDoDia(
                carga = dias.getValue(deslocamento),
                enviando = enviando,
                preenchimentoInferior = preenchimento.calculateBottomPadding(),
                aoAtualizar = { vm.atualizar(deslocamento) },
                aoTomar = { vm.tomei(deslocamento, it) },
                aoPular = { vm.pular(deslocamento, it) },
                aoDesfazer = { vm.desfazer(deslocamento, it) },
            )
        }
    }
}

@Composable
private fun PaginaDoDia(
    carga: Carga<Dia>,
    enviando: Set<String>,
    preenchimentoInferior: androidx.compose.ui.unit.Dp,
    aoAtualizar: () -> Unit,
    aoTomar: (Dose) -> Unit,
    aoPular: (Dose) -> Unit,
    aoDesfazer: (Dose) -> Unit,
) {
    PullToRefreshBox(isRefreshing = carga.atualizando && carga.dados != null, onRefresh = aoAtualizar, modifier = Modifier.fillMaxSize()) {
        LazyColumn(
            Modifier.fillMaxSize(),
            contentPadding = PaddingValues(start = 20.dp, end = 20.dp, top = 16.dp, bottom = preenchimentoInferior + 24.dp),
            verticalArrangement = Arrangement.spacedBy(12.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            item(key = "erro") { FaixaDeErro(carga.erro, Modifier.widthIn(max = 640.dp), aoAtualizar) }
            val dia = carga.dados
            when {
                dia == null -> if (carga.esqueleto) {
                    item(key = "esqueleto") {
                        TelaEsqueleto(Modifier.widthIn(max = 640.dp)) {
                            Bloco(0.55f, 24.dp)
                            Bloco(1f, 12.dp)
                            repeat(4) { LinhaEsqueleto() }
                        }
                    }
                }
                !dia.consentimento -> item(key = "consentimento") {
                    EstadoVazio(Icons.Rounded.EventBusy, "Autorize no seu perfil", "Para ver os horários dos seus remédios, autorize o uso dos seus dados de saúde no perfil.", Modifier.widthIn(max = 640.dp))
                }
                dia.doses.isEmpty() && dia.seNecessario.isEmpty() -> item(key = "vazio") {
                    EstadoVazio(
                        Icons.Rounded.EventBusy,
                        if (dia.temRemedios) "Nenhum horário neste dia" else "Nenhum remédio cadastrado",
                        if (dia.temRemedios) "Os seus remédios não têm horário programado para ${dia.data.lowercase()}." else "Cadastre seus remédios no perfil para ver os horários aqui.",
                        Modifier.widthIn(max = 640.dp),
                    )
                }
                else -> {
                    item(key = "resumo") { Resumo(dia, Modifier.widthIn(max = 640.dp)) }
                    PERIODOS.forEach { (chave, titulo, icone) ->
                        val doses = dia.doses.filter { it.period == chave }
                        if (doses.isNotEmpty()) {
                            item(key = "periodo-$chave") { CabecalhoDoPeriodo(titulo, icone, Modifier.widthIn(max = 640.dp)) }
                            items(doses, key = { it.key }) { dose ->
                                LinhaDaDose(dose, dose.key in enviando, aoTomar, aoPular, aoDesfazer, Modifier.widthIn(max = 640.dp).animateItem())
                            }
                        }
                    }
                    if (dia.seNecessario.isNotEmpty()) {
                        item(key = "sos") { SeNecessario(dia.seNecessario, Modifier.widthIn(max = 640.dp)) }
                    }
                    if (dia.temDinamico) {
                        item(key = "dinamico") {
                            Text(
                                "Alguns horários são recontados a partir da hora em que você tomou a dose anterior.",
                                style = MaterialTheme.typography.bodySmall,
                                color = MaterialTheme.colorScheme.onSurfaceVariant,
                                modifier = Modifier.widthIn(max = 640.dp).fillMaxWidth(),
                            )
                        }
                    }
                }
            }
        }
    }
}

private val PERIODOS = listOf(
    Triple("manha", "Manhã", Icons.Rounded.WbSunny),
    Triple("tarde", "Tarde", Icons.Rounded.WbTwilight),
    Triple("noite", "Noite", Icons.Rounded.Bedtime),
)

@Composable
private fun Resumo(dia: Dia, modifier: Modifier = Modifier) {
    val extras = TemaBulai.extras
    val resumo = dia.summary
    val fracao by animateFloatAsState(if (resumo.total == 0) 0f else resumo.done / resumo.total.toFloat(), label = "resumo")
    Column(modifier.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(8.dp)) {
        Text(dia.data, style = MaterialTheme.typography.titleLarge, color = MaterialTheme.colorScheme.primary, modifier = Modifier.semantics { heading() })
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text("${resumo.done} de ${resumo.total} anotadas", style = MaterialTheme.typography.bodyLarge, modifier = Modifier.weight(1f))
            if (resumo.late > 0) {
                Icon(Icons.Rounded.WarningAmber, contentDescription = null, tint = extras.alerta, modifier = Modifier.size(20.dp))
                Spacer(Modifier.width(4.dp))
                Text(if (resumo.late == 1) "1 atrasada" else "${resumo.late} atrasadas", style = MaterialTheme.typography.labelLarge, color = extras.alerta)
            }
        }
        LinearProgressIndicator(
            progress = { fracao },
            modifier = Modifier.fillMaxWidth().height(10.dp).clip(CircleShape).semantics { stateDescription = "${resumo.done} de ${resumo.total} doses anotadas" },
            color = extras.sucesso,
            trackColor = MaterialTheme.colorScheme.surfaceVariant,
            drawStopIndicator = {},
        )
    }
}

@Composable
private fun CabecalhoDoPeriodo(titulo: String, icone: ImageVector, modifier: Modifier = Modifier) {
    Row(modifier.fillMaxWidth().padding(top = 8.dp), verticalAlignment = Alignment.CenterVertically) {
        Icon(icone, contentDescription = null, tint = MaterialTheme.colorScheme.secondary)
        Spacer(Modifier.width(8.dp))
        Text(titulo, style = MaterialTheme.typography.titleMedium, color = MaterialTheme.colorScheme.onSurfaceVariant, modifier = Modifier.semantics { heading() })
    }
}

/**
 * Cartão de uma dose. "Tomei" tem efeito próprio: o verde nasce num círculo no botão e cobre o cartão, um joinha salta no
 * centro e some, e "Tomado" aparece em seguida. Desfazer (ou o servidor recusar) recolhe o verde de volta para o botão.
 * Com as animações do sistema desligadas, o cartão muda direto.
 */
@Composable
private fun LinhaDaDose(
    dose: Dose,
    ocupada: Boolean,
    aoTomar: (Dose) -> Unit,
    aoPular: (Dose) -> Unit,
    aoDesfazer: (Dose) -> Unit,
    modifier: Modifier = Modifier,
) {
    val extras = TemaBulai.extras
    val haptico = LocalHapticFeedback.current
    val context = LocalContext.current
    val semAnimacao = remember { VideoDeAbertura.animacoesDesligadas(context) }
    val tomada = dose.status == Dose.TOMADA
    val base by animateColorAsState(if (dose.status == Dose.ATRASADA) extras.alertaFundo else MaterialTheme.colorScheme.surface, label = "fundo")
    val verde = extras.sucessoFundo

    // Onde o círculo nasce (centro do botão Tomei, em px do cartão) e o quanto já cobriu (0 a 1).
    var caixa by remember { mutableStateOf<LayoutCoordinates?>(null) }
    var origem by remember(dose.key) { mutableStateOf<Offset?>(null) }
    var tocou by remember(dose.key) { mutableStateOf(false) }
    val cobertura = remember(dose.key) { Animatable(if (tomada) 1f else 0f) }
    val joinhaEscala = remember(dose.key) { Animatable(0f) }
    val joinhaAlfa = remember(dose.key) { Animatable(0f) }

    LaunchedEffect(tomada) {
        when {
            tomada && cobertura.value < 1f && tocou && !semAnimacao -> {
                launch { cobertura.animateTo(1f, tween(620, easing = FastOutSlowInEasing)) }
                // Joinha: salta (mola) logo que o verde começa, fica um instante e some devagar.
                delay(160)
                joinhaAlfa.snapTo(1f)
                joinhaEscala.snapTo(0.2f)
                joinhaEscala.animateTo(1f, spring(dampingRatio = Spring.DampingRatioMediumBouncy, stiffness = Spring.StiffnessLow))
                delay(450)
                joinhaAlfa.animateTo(0f, tween(450))
                joinhaEscala.snapTo(0f)
                tocou = false
            }
            tomada -> cobertura.snapTo(1f)
            !semAnimacao && cobertura.value > 0f -> cobertura.animateTo(0f, tween(420, easing = FastOutSlowInEasing))
            else -> cobertura.snapTo(0f)
        }
    }

    val descricao = when (dose.status) {
        Dose.TOMADA -> "Tomado"
        Dose.PULADA -> "Pulado"
        Dose.ATRASADA -> "Atrasado"
        Dose.AGORA -> "Na hora"
        else -> "Pendente"
    }
    Box(
        modifier
            .clip(MaterialTheme.shapes.large)
            .onGloballyPositioned { caixa = it }
            .drawBehind {
                drawRect(base)
                if (cobertura.value > 0f) {
                    val centro = origem ?: Offset(size.width * 0.3f, size.height * 0.8f)
                    // Raio até o canto mais distante: com 100% o verde cobre o cartão inteiro.
                    val raio = listOf(Offset.Zero, Offset(size.width, 0f), Offset(0f, size.height), Offset(size.width, size.height))
                        .maxOf { (it - centro).getDistance() }
                    drawCircle(verde, radius = raio * cobertura.value, center = centro)
                }
            },
    ) {
        Cartao(Modifier.animateContentSize(), cor = Color.Transparent) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                SeloDoHorario(dose)
                Spacer(Modifier.width(14.dp))
                Column(Modifier.weight(1f)) {
                    Text(dose.name, style = MaterialTheme.typography.titleMedium, color = MaterialTheme.colorScheme.onSurface)
                    dose.dosage?.takeIf { it.isNotBlank() }?.let { Text(it, style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant) }
                    dose.ajustadaDe?.let { Text("Ajustado pela dose das $it", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant) }
                    // "Tomado" entra com fade-in depois que o verde já cobriu boa parte do cartão.
                    AnimatedContent(
                        descricao,
                        transitionSpec = { fadeIn(tween(420, delayMillis = if (targetState == "Tomado") 380 else 0)) togetherWith fadeOut(tween(160)) },
                        label = "situacao",
                    ) { texto ->
                        Text(
                            texto,
                            style = MaterialTheme.typography.labelMedium,
                            color = when (dose.status) {
                                Dose.ATRASADA -> extras.alerta
                                Dose.TOMADA -> extras.sucesso
                                else -> MaterialTheme.colorScheme.onSurfaceVariant
                            },
                            modifier = Modifier.semantics { stateDescription = texto; liveRegion = LiveRegionMode.Polite },
                        )
                    }
                }
                if (ocupada) {
                    CircularProgressIndicator(Modifier.size(24.dp), strokeWidth = 3.dp)
                }
            }
            AnimatedContent(dose.feita, transitionSpec = { fadeIn(tween(300, delayMillis = 250)) togetherWith fadeOut(tween(150)) }, label = "acoes") { feita ->
                if (feita) {
                    TextButton(onClick = { aoDesfazer(dose) }, enabled = !ocupada, modifier = Modifier.heightIn(min = 48.dp)) {
                        Icon(Icons.AutoMirrored.Rounded.Undo, contentDescription = null)
                        Spacer(Modifier.width(8.dp))
                        Text("Desfazer", style = MaterialTheme.typography.labelLarge)
                    }
                } else {
                    Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                        androidx.compose.material3.Button(
                            onClick = {
                                haptico.performHapticFeedback(HapticFeedbackType.Confirm)
                                tocou = true
                                aoTomar(dose)
                            },
                            enabled = !ocupada,
                            modifier = Modifier.weight(1f).heightIn(min = 56.dp).onGloballyPositioned { botao ->
                                caixa?.takeIf { it.isAttached && botao.isAttached }?.let { origem = it.localPositionOf(botao, Offset(botao.size.width / 2f, botao.size.height / 2f)) }
                            },
                            shape = MaterialTheme.shapes.medium,
                            colors = androidx.compose.material3.ButtonDefaults.buttonColors(containerColor = MaterialTheme.colorScheme.secondary, contentColor = MaterialTheme.colorScheme.onSecondary),
                        ) {
                            Icon(Icons.Rounded.Check, contentDescription = null)
                            Spacer(Modifier.width(8.dp))
                            Text("Tomei", style = MaterialTheme.typography.labelLarge)
                        }
                        androidx.compose.material3.OutlinedButton(
                            onClick = { aoPular(dose) },
                            enabled = !ocupada,
                            modifier = Modifier.heightIn(min = 56.dp),
                            shape = MaterialTheme.shapes.medium,
                        ) { Text("Pular", style = MaterialTheme.typography.labelLarge) }
                    }
                }
            }
        }
        // Joinha no centro do cartão: só durante o efeito, sem receber toques nem ser lido pelo TalkBack.
        if (joinhaAlfa.value > 0f) {
            Box(
                Modifier.matchParentSize().clearAndSetSemantics { },
                contentAlignment = Alignment.Center,
            ) {
                Box(
                    Modifier.size(84.dp).graphicsLayer {
                        scaleX = joinhaEscala.value
                        scaleY = joinhaEscala.value
                        alpha = joinhaAlfa.value
                        rotationZ = (1f - joinhaEscala.value.coerceAtMost(1f)) * -25f
                    }.clip(CircleShape).background(extras.sucesso),
                    contentAlignment = Alignment.Center,
                ) {
                    Icon(Icons.Rounded.ThumbUp, contentDescription = null, tint = MaterialTheme.colorScheme.surface, modifier = Modifier.size(46.dp))
                }
            }
        }
    }
}

@Composable
private fun SeloDoHorario(dose: Dose) {
    val extras = TemaBulai.extras
    val (icone, cor) = when (dose.status) {
        Dose.TOMADA -> Icons.Rounded.CheckCircle to extras.sucesso
        Dose.PULADA -> Icons.Rounded.DoNotDisturbOn to MaterialTheme.colorScheme.onSurfaceVariant
        Dose.ATRASADA -> Icons.Rounded.WarningAmber to extras.alerta
        else -> Icons.Rounded.Schedule to MaterialTheme.colorScheme.primary
    }
    Column(horizontalAlignment = Alignment.CenterHorizontally, modifier = Modifier.width(64.dp)) {
        Text(dose.time, style = MaterialTheme.typography.titleLarge, color = MaterialTheme.colorScheme.onSurface)
        Box(Modifier.size(28.dp).clip(CircleShape).background(cor.copy(alpha = 0.12f)), contentAlignment = Alignment.Center) {
            Icon(icone, contentDescription = null, tint = cor, modifier = Modifier.size(20.dp))
        }
    }
}

@Composable
private fun SeNecessario(remedios: List<RemedioSeNecessario>, modifier: Modifier = Modifier) {
    Cartao(modifier) {
        Text("Quando precisar", style = MaterialTheme.typography.titleMedium, color = MaterialTheme.colorScheme.primary, modifier = Modifier.semantics { heading() })
        remedios.forEach { remedio ->
            Surface(color = MaterialTheme.colorScheme.surfaceVariant, shape = MaterialTheme.shapes.medium) {
                Column(Modifier.fillMaxWidth().padding(14.dp)) {
                    Text(remedio.name, style = MaterialTheme.typography.titleSmall)
                    remedio.dosage?.takeIf { it.isNotBlank() }?.let { Text(it, style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant) }
                }
            }
        }
    }
}
