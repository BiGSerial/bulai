package br.com.vixforge.bulai.ui.dia

import androidx.compose.material.icons.rounded.SkipNext
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.platform.LocalConfiguration

import br.com.vixforge.bulai.ui.componentes.nomeAmigavel
import androidx.compose.material.icons.rounded.Medication
import br.com.vixforge.bulai.dados.api.VezQueTomou
import androidx.compose.foundation.layout.offset
import androidx.compose.animation.shrinkVertically
import androidx.compose.animation.expandVertically
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.AnimatedContent
import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.border
import androidx.compose.foundation.layout.IntrinsicSize
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.lazy.itemsIndexed
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextDecoration
import java.time.OffsetDateTime
import java.time.format.DateTimeFormatter
import androidx.compose.animation.core.Animatable
import androidx.compose.animation.core.FastOutSlowInEasing
import androidx.compose.animation.core.keyframes
import androidx.compose.animation.core.Spring
import androidx.compose.animation.core.spring
import androidx.compose.animation.core.tween
import androidx.compose.material.icons.rounded.ThumbUp
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.draw.drawBehind
import androidx.compose.ui.draw.drawWithContent
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
import androidx.compose.material.icons.rounded.Favorite
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

/**
 * Remédios de ontem, hoje e amanhã. Com [vinculo], é o dia de quem a pessoa cuida (modo cuidador): uma faixa diz de quem
 * são os remédios, e "Tomei"/"Pular" só aparecem com a permissão "marcar e editar".
 */
@Composable
fun DiaTela(
    recado: (DiaViewModel.Recado) -> Unit,
    preenchimento: PaddingValues,
    vinculo: Long? = null,
    nome: String? = null,
    aoAbrirPerfil: () -> Unit = {},
    aoVerRemediosDeQuemCuido: () -> Unit = {},
) {
    val vm = telaViewModel { if (vinculo != null) DiaViewModel(it.cuidado.fonte(vinculo, nome)) else DiaViewModel(it.tratamento) }
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
                cuidando = vinculo != null,
                aoAbrirPerfil = aoAbrirPerfil,
                aoVerRemediosDeQuemCuido = aoVerRemediosDeQuemCuido,
                enviando = enviando,
                preenchimentoInferior = preenchimento.calculateBottomPadding(),
                aoAtualizar = { vm.atualizar(deslocamento) },
                aoTomar = { vm.tomei(deslocamento, it) },
                aoPular = { vm.pular(deslocamento, it) },
                aoDesfazer = { vm.desfazer(deslocamento, it) },
                // "Tomei agora" só faz sentido hoje.
                aoTomarSeNecessario = if (deslocamento == 0) vm::tomeiSeNecessario else null,
                aoDesfazerSeNecessario = vm::desfazerSeNecessario,
            )
        }
    }
}

@Composable
private fun PaginaDoDia(
    carga: Carga<Dia>,
    cuidando: Boolean,
    aoAbrirPerfil: () -> Unit,
    aoVerRemediosDeQuemCuido: () -> Unit,
    enviando: Set<String>,
    preenchimentoInferior: androidx.compose.ui.unit.Dp,
    aoAtualizar: () -> Unit,
    aoTomar: (Dose) -> Unit,
    aoPular: (Dose) -> Unit,
    aoDesfazer: (Dose) -> Unit,
    aoTomarSeNecessario: ((RemedioSeNecessario) -> Unit)? = null,
    aoDesfazerSeNecessario: (RemedioSeNecessario, VezQueTomou) -> Unit = { _, _ -> },
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
            dia?.cuidandoDe?.let { quem ->
                item(key = "cuidando") { FaixaDoCuidador(quem.primeiroNome, quem.banner, dia.podeMarcar, aoVerRemediosDeQuemCuido, Modifier.widthIn(max = 640.dp)) }
            }
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
                    if (cuidando) {
                        EstadoVazio(Icons.Rounded.EventBusy, "Remédios indisponíveis", "${dia.cuidandoDe?.primeiroNome ?: "A pessoa"} ainda não autorizou guardar os dados de saúde no BULA-IA.", Modifier.widthIn(max = 640.dp))
                    } else {
                        EstadoVazio(Icons.Rounded.EventBusy, "Autorize seus dados de saúde", "Para ver os horários dos seus remédios, autorize o uso dos seus dados de saúde.", Modifier.widthIn(max = 640.dp)) {
                            br.com.vixforge.bulai.ui.componentes.BotaoPrincipal("Abrir Meus remédios", aoAbrirPerfil)
                        }
                    }
                }
                dia.doses.isEmpty() && dia.seNecessario.isEmpty() -> item(key = "vazio") {
                    EstadoVazio(
                        Icons.Rounded.EventBusy,
                        if (dia.temRemedios) "Nenhum horário neste dia" else "Nenhum remédio cadastrado",
                        when {
                            dia.temRemedios -> "Os remédios não têm horário programado para ${dia.data.lowercase()}."
                            cuidando -> "${dia.cuidandoDe?.primeiroNome ?: "A pessoa"} ainda não cadastrou remédios."
                            else -> "Cadastre seus remédios no perfil para ver os horários aqui."
                        },
                        Modifier.widthIn(max = 640.dp),
                    )
                }
                else -> {
                    item(key = "resumo") { Resumo(dia, Modifier.widthIn(max = 640.dp)) }
                    PERIODOS.forEach { (chave, titulo, icone) ->
                        val doses = dia.doses.filter { periodoDaDose(it) == chave }
                        if (doses.isNotEmpty()) {
                            item(key = "periodo-$chave") { CabecalhoDoPeriodo(titulo, icone, Modifier.widthIn(max = 640.dp)) }
                            itemsIndexed(doses, key = { _, dose -> dose.key }) { indice, dose ->
                                LinhaDaDose(dose, dose.key in enviando, indice == 0, indice == doses.lastIndex, dia.podeMarcar, dia.cuidandoDe != null, aoTomar, aoPular, aoDesfazer, Modifier.widthIn(max = 640.dp).animateItem())
                            }
                        }
                    }
                    if (dia.seNecessario.isNotEmpty()) {
                        item(key = "sos") {
                            SeNecessario(
                                dia.seNecessario,
                                enviando = enviando,
                                aoTomar = aoTomarSeNecessario?.takeIf { dia.podeMarcar },
                                aoDesfazer = aoDesfazerSeNecessario.takeIf { dia.podeMarcar && dia.offset == 0 },
                                deOutraPessoa = dia.cuidandoDe != null,
                                modifier = Modifier.widthIn(max = 640.dp),
                            )
                        }
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

/** Faixa no topo do dia de quem a pessoa cuida. */
@Composable
private fun FaixaDoCuidador(nome: String, faixa: String, podeMarcar: Boolean, aoVerRemedios: () -> Unit, modifier: Modifier = Modifier) {
    androidx.compose.material3.Surface(color = MaterialTheme.colorScheme.secondaryContainer, shape = MaterialTheme.shapes.large, modifier = modifier.fillMaxWidth()) {
        Row(Modifier.padding(horizontal = 16.dp, vertical = 12.dp), verticalAlignment = Alignment.CenterVertically) {
            Icon(Icons.Rounded.Favorite, contentDescription = null, tint = MaterialTheme.colorScheme.onSecondaryContainer)
            Spacer(Modifier.width(12.dp))
            Column {
                Text("Remédios de $nome", style = MaterialTheme.typography.titleMedium, color = MaterialTheme.colorScheme.onSecondaryContainer)
                Text(
                    faixa,
                    style = MaterialTheme.typography.bodyMedium,
                    color = MaterialTheme.colorScheme.onSecondaryContainer,
                )
                TextButton(onClick = aoVerRemedios, modifier = Modifier.heightIn(min = 48.dp), contentPadding = PaddingValues(0.dp)) {
                    Text(if (podeMarcar) "Remédios, dose e horários" else "Ver remédios e horários", color = MaterialTheme.colorScheme.onSecondaryContainer, style = MaterialTheme.typography.labelLarge)
                }
            }
        }
    }
}

private val PERIODOS = listOf(
    Triple("madrugada", "Madrugada", Icons.Rounded.Bedtime),
    Triple("manha", "Manhã", Icons.Rounded.WbSunny),
    Triple("tarde", "Tarde", Icons.Rounded.WbTwilight),
    Triple("noite", "Noite", Icons.Rounded.Bedtime),
)

/**
 * Período da dose pela hora, como no site: antes das 5h é madrugada (uma dose às 00:30 não fica em "Manhã"). A API manda
 * "period" só com manhã, tarde e noite; separar a madrugada aqui mantém as versões anteriores do app mostrando todas as doses.
 */
internal fun periodoDaDose(dose: Dose): String {
    val hora = dose.time.take(2).toIntOrNull() ?: return dose.period
    return when {
        hora < 5 -> "madrugada"
        hora < 12 -> "manha"
        hora < 18 -> "tarde"
        else -> "noite"
    }
}

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
    primeira: Boolean,
    ultima: Boolean,
    podeMarcar: Boolean,
    deOutraPessoa: Boolean,
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
    val joinhaGiro = remember(dose.key) { Animatable(0f) }
    val joinhaPulo = remember(dose.key) { Animatable(0f) }
    // Véu: o verde por cima dos textos enquanto o efeito acontece; ao clarear, os textos voltam sobre o fundo verde.
    val veu = remember(dose.key) { Animatable(0f) }

    LaunchedEffect(tomada) {
        when {
            tomada && cobertura.value < 1f && tocou && !semAnimacao -> {
                veu.snapTo(1f)
                launch { cobertura.animateTo(1f, tween(520, easing = FastOutSlowInEasing)) }
                // Joinha: salta com mola quando o verde começa a cobrir…
                delay(140)
                joinhaGiro.snapTo(0f)
                joinhaPulo.snapTo(0f)
                joinhaAlfa.snapTo(1f)
                joinhaEscala.snapTo(0.2f)
                launch { joinhaEscala.animateTo(1f, spring(dampingRatio = 0.38f, stiffness = Spring.StiffnessMediumLow)) }
                delay(220)
                // …dá um pulinho e balança (como um "joinha!" de verdade)…
                val pulo = launch {
                    joinhaPulo.animateTo(0f, keyframes {
                        durationMillis = 620
                        -34f at 140 using FastOutSlowInEasing
                        0f at 300
                        -14f at 420
                        0f at 520
                    })
                }
                joinhaGiro.animateTo(0f, keyframes {
                    durationMillis = 720
                    -20f at 110
                    16f at 240
                    -11f at 370
                    7f at 490
                    -3f at 600
                })
                pulo.join()
                // …e o véu clareia junto com o joinha: os textos voltam por cima do verde.
                launch { veu.animateTo(0f, tween(420)) }
                joinhaAlfa.animateTo(0f, tween(420))
                joinhaEscala.snapTo(0f)
                tocou = false
            }
            tomada -> cobertura.snapTo(1f)
            !semAnimacao && cobertura.value > 0f -> {
                veu.snapTo(0f)
                cobertura.animateTo(0f, tween(420, easing = FastOutSlowInEasing))
            }
            else -> cobertura.snapTo(0f)
        }
    }

    // Selo do estado, como no site: "A tomar", "Agora", "Atrasado", "Pulei", "Tomei 08:47".
    // Hora em que tomou, no fuso do tratamento (o mesmo do horário agendado), não no do celular.
    val tomadaAs = dose.tomadaEm?.let {
        runCatching {
            val fuso = OffsetDateTime.parse(dose.agendadaPara).offset
            OffsetDateTime.parse(it).withOffsetSameInstant(fuso).toLocalTime().format(HORA)
        }.getOrNull()
    }
    val descricao = when (dose.status) {
        Dose.TOMADA -> (if (deOutraPessoa) "Tomou" else "Tomei") + (tomadaAs?.let { " $it" } ?: "")
        Dose.PULADA -> if (deOutraPessoa) "Pulou" else "Pulei"
        Dose.ATRASADA -> "Atrasado"
        Dose.AGORA -> "Agora"
        else -> "A tomar"
    }
    val corDoEstado = when (dose.status) {
        Dose.TOMADA -> extras.sucesso
        Dose.PULADA -> MaterialTheme.colorScheme.outline
        Dose.ATRASADA -> MaterialTheme.colorScheme.error
        Dose.AGORA -> MaterialTheme.colorScheme.secondary
        else -> MaterialTheme.colorScheme.primary
    }
    val borda = when (dose.status) {
        Dose.ATRASADA -> BorderStroke(1.5.dp, MaterialTheme.colorScheme.error.copy(alpha = 0.6f))
        Dose.AGORA -> BorderStroke(2.dp, MaterialTheme.colorScheme.secondary)
        Dose.TOMADA -> BorderStroke(1.dp, extras.sucesso.copy(alpha = 0.35f))
        else -> BorderStroke(1.dp, MaterialTheme.colorScheme.outlineVariant)
    }
    val trilho = MaterialTheme.colorScheme.outlineVariant
    // Linha do tempo (como no site): horário à esquerda, bolinha no trilho e o cartão do remédio à direita.
    Row(modifier.height(IntrinsicSize.Min)) {
        Text(
            dose.time,
            style = MaterialTheme.typography.titleMedium,
            fontWeight = FontWeight.Bold,
            textAlign = TextAlign.End,
            color = MaterialTheme.colorScheme.onSurface.copy(alpha = if (dose.feita) 0.6f else 1f),
            modifier = Modifier.width(54.dp).padding(top = 14.dp),
        )
        Box(
            Modifier.width(24.dp).fillMaxHeight().drawBehind {
                val x = size.width / 2
                val bolinha = 25.dp.toPx()
                // O trilho passa por cima do espaço entre os cartões (6 dp para cada lado) para ficar contínuo.
                val de = if (primeira) bolinha else -6.dp.toPx()
                val ate = if (ultima) bolinha else size.height + 6.dp.toPx()
                if (ate > de) {
                    drawLine(trilho, Offset(x, de), Offset(x, ate), strokeWidth = 2.dp.toPx())
                }
            },
            contentAlignment = Alignment.TopCenter,
        ) {
            val preenchida = dose.status != Dose.PENDENTE
            Box(
                Modifier.padding(top = 18.dp).size(16.dp).clip(CircleShape)
                    .background(corDoEstado.copy(alpha = 0.22f)).padding(3.dp).clip(CircleShape)
                    .background(if (preenchida) corDoEstado else MaterialTheme.colorScheme.surface)
                    .border(if (preenchida) 0.dp else 2.dp, MaterialTheme.colorScheme.outline, CircleShape),
            )
        }
    Box(
        Modifier
            .weight(1f)
            .clip(MaterialTheme.shapes.large)
            .border(borda, MaterialTheme.shapes.large)
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
        Box(
            Modifier.drawWithContent {
                drawContent()
                if (veu.value > 0f && cobertura.value > 0f) {
                    val centro = origem ?: Offset(size.width * 0.3f, size.height * 0.8f)
                    val raio = listOf(Offset.Zero, Offset(size.width, 0f), Offset(0f, size.height), Offset(size.width, size.height))
                        .maxOf { (it - centro).getDistance() }
                    drawCircle(verde.copy(alpha = veu.value), radius = raio * cobertura.value, center = centro)
                }
            },
        ) {
        Column(Modifier.animateContentSize().padding(14.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            // Nome e selo na mesma linha; a dose fica embaixo, na largura toda, para não ser espremida pelo selo.
            Column(verticalArrangement = Arrangement.spacedBy(2.dp)) {
                Row(verticalAlignment = Alignment.Top) {
                    Text(
                        nomeAmigavel(dose.name),
                        style = MaterialTheme.typography.titleMedium,
                        fontWeight = FontWeight.SemiBold,
                        color = MaterialTheme.colorScheme.onSurface.copy(alpha = if (dose.status == Dose.PULADA) 0.7f else 1f),
                        textDecoration = if (dose.status == Dose.PULADA) TextDecoration.LineThrough else null,
                        modifier = Modifier.weight(1f).padding(top = 2.dp),
                    )
                    Spacer(Modifier.width(8.dp))
                    if (ocupada) {
                        CircularProgressIndicator(Modifier.padding(top = 2.dp).size(22.dp), strokeWidth = 3.dp)
                    } else if (dose.status == Dose.AGORA || dose.status == Dose.ATRASADA) {
                        // Selo só para "Agora" e "Atrasado". "A tomar" é o normal, e "Tomei 08:47"/"Pulei" vão para a
                        // linha de baixo: em telas de 360 dp o selo espremia o nome ("metformin-a").
                        // O selo muda com fade (o "Tomei 08:47" entra depois que o verde já cobriu o cartão).
                        AnimatedContent(
                            descricao,
                            transitionSpec = { fadeIn(tween(420, delayMillis = if (targetState.startsWith("Tom")) 380 else 0)) togetherWith fadeOut(tween(160)) },
                            label = "situacao",
                        ) { texto ->
                            Surface(shape = CircleShape, color = corDoEstado.copy(alpha = 0.12f), contentColor = corDoEstado, border = BorderStroke(1.dp, corDoEstado.copy(alpha = 0.35f))) {
                                Text(
                                    texto,
                                    style = MaterialTheme.typography.labelMedium,
                                    maxLines = 1,
                                    softWrap = false,
                                    modifier = Modifier.padding(horizontal = 10.dp, vertical = 4.dp).semantics { stateDescription = texto; liveRegion = LiveRegionMode.Polite },
                                )
                            }
                        }
                    }
                }
                Text(dose.dosage?.takeIf { it.isNotBlank() } ?: "Dose não informada", style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
            }
            // Anotada: o que foi feito e quando, com "Desfazer" ao lado, numa linha só embaixo.
            if (dose.feita && !ocupada) {
                // Numa linha quando cabe; em telas estreitas (texto grande, 360 dp) o "Desfazer" desce para a linha de
                // baixo, sem quebrar "Tomei às 08:47" no meio.
                Box(Modifier.fillMaxWidth()) {
                    val situacao: @Composable () -> Unit = {
                        Row(verticalAlignment = Alignment.CenterVertically, modifier = Modifier.heightIn(min = 40.dp).semantics(mergeDescendants = true) { liveRegion = LiveRegionMode.Polite }) {
                            Icon(if (tomada) Icons.Rounded.Check else Icons.Rounded.SkipNext, contentDescription = null, tint = corDoEstado, modifier = Modifier.size(20.dp))
                            Spacer(Modifier.width(6.dp))
                            Text(
                                if (tomada) (if (deOutraPessoa) "Tomou" else "Tomei") + (tomadaAs?.let { " às $it" } ?: "") else (if (deOutraPessoa) "Pulou" else "Pulei"),
                                style = MaterialTheme.typography.bodyLarge,
                                fontWeight = FontWeight.SemiBold,
                                color = corDoEstado,
                                maxLines = 1,
                                softWrap = false,
                            )
                        }
                    }
                    val desfazer: @Composable () -> Unit = {
                        TextButton(onClick = { aoDesfazer(dose) }, modifier = Modifier.heightIn(min = 48.dp), contentPadding = PaddingValues(horizontal = 8.dp)) {
                            Icon(Icons.AutoMirrored.Rounded.Undo, contentDescription = null, modifier = Modifier.size(20.dp))
                            Spacer(Modifier.width(6.dp))
                            Text("Desfazer", style = MaterialTheme.typography.labelLarge, maxLines = 1, softWrap = false)
                        }
                    }
                    // Largura do cartão pela tela (a linha do tempo mede a altura por intrínsecos, que não aceitam
                    // BoxWithConstraints/FlowRow): tela menos margens, horário e trilho. "Tomei às 08:47" + "Desfazer"
                    // pedem ~270 dp com a fonte padrão e crescem com a fonte do sistema.
                    val largura = minOf(LocalConfiguration.current.screenWidthDp, 640) - 32 - 78 - 30
                    val cabeEmUmaLinha = largura >= 270 * LocalDensity.current.fontScale
                    if (!podeMarcar) {
                        situacao()
                    } else if (cabeEmUmaLinha) {
                        Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.SpaceBetween) {
                            situacao()
                            Box(Modifier.offset(x = 8.dp)) { desfazer() }
                        }
                    } else {
                        Column {
                            situacao()
                            Box(Modifier.offset(x = (-8).dp)) { desfazer() }
                        }
                    }
                }
            }
            dose.ajustadaDe?.let { Text("Ajustado: contado a partir das $it, quando você tomou a anterior", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant) }
            AnimatedVisibility(podeMarcar && !dose.feita, enter = fadeIn(tween(300, delayMillis = 250)) + expandVertically(), exit = fadeOut(tween(150)) + shrinkVertically()) {
                    Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                        androidx.compose.material3.Button(
                            onClick = {
                                haptico.performHapticFeedback(HapticFeedbackType.Confirm)
                                tocou = true
                                aoTomar(dose)
                            },
                            enabled = !ocupada,
                            modifier = Modifier.weight(2f).heightIn(min = 52.dp).onGloballyPositioned { botao ->
                                caixa?.takeIf { it.isAttached && botao.isAttached }?.let { origem = it.localPositionOf(botao, Offset(botao.size.width / 2f, botao.size.height / 2f)) }
                            },
                            shape = MaterialTheme.shapes.medium,
                            contentPadding = PaddingValues(horizontal = 12.dp),
                            colors = androidx.compose.material3.ButtonDefaults.buttonColors(containerColor = extras.sucesso, contentColor = MaterialTheme.colorScheme.surface),
                        ) {
                            Icon(Icons.Rounded.Check, contentDescription = null)
                            Spacer(Modifier.width(6.dp))
                            Text(if (deOutraPessoa) "Tomou" else "Tomei", style = MaterialTheme.typography.labelLarge, maxLines = 1, softWrap = false)
                        }
                        androidx.compose.material3.OutlinedButton(
                            onClick = { aoPular(dose) },
                            enabled = !ocupada,
                            modifier = Modifier.weight(1f).heightIn(min = 52.dp),
                            shape = MaterialTheme.shapes.medium,
                            contentPadding = PaddingValues(horizontal = 6.dp),
                        ) { Text("Pular", style = MaterialTheme.typography.labelLarge, maxLines = 1, softWrap = false) }
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
                        rotationZ = joinhaGiro.value + (1f - joinhaEscala.value.coerceAtMost(1f)) * -25f
                        translationY = joinhaPulo.value * density
                    }.clip(CircleShape).background(extras.sucesso),
                    contentAlignment = Alignment.Center,
                ) {
                    Icon(Icons.Rounded.ThumbUp, contentDescription = null, tint = MaterialTheme.colorScheme.surface, modifier = Modifier.size(46.dp))
                }
            }
        }
    }
    }
}

/**
 * "Só quando precisar": sem horário programado, cada remédio tem "Tomei agora" (o servidor anota o instante) e a lista das
 * vezes do dia, cada uma com "Desfazer". Assim a pessoa registra a dor de cabeça das 15h sem inventar um horário.
 */
@Composable
private fun SeNecessario(
    remedios: List<RemedioSeNecessario>,
    enviando: Set<String>,
    aoTomar: ((RemedioSeNecessario) -> Unit)?,
    aoDesfazer: ((RemedioSeNecessario, VezQueTomou) -> Unit)?,
    deOutraPessoa: Boolean,
    modifier: Modifier = Modifier,
) {
    val extras = TemaBulai.extras
    Column(modifier.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(10.dp)) {
        Row(Modifier.padding(top = 8.dp), verticalAlignment = Alignment.CenterVertically) {
            Icon(Icons.Rounded.Medication, contentDescription = null, tint = MaterialTheme.colorScheme.secondary)
            Spacer(Modifier.width(8.dp))
            Text("Só quando precisar", style = MaterialTheme.typography.titleMedium, color = MaterialTheme.colorScheme.onSurfaceVariant, modifier = Modifier.semantics { heading() })
        }
        remedios.forEach { remedio ->
            val ocupado = "sos-${remedio.remedioId}" in enviando
            Surface(shape = MaterialTheme.shapes.large, color = MaterialTheme.colorScheme.surface, border = BorderStroke(1.dp, MaterialTheme.colorScheme.outlineVariant), modifier = Modifier.fillMaxWidth()) {
                Column(Modifier.animateContentSize().padding(14.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                    Column {
                        Text(nomeAmigavel(remedio.name), style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.SemiBold)
                        Text(remedio.dosage?.takeIf { it.isNotBlank() } ?: "Sem horário fixo", style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
                    }
                    remedio.tomadasHoje.forEach { vez ->
                        Row(
                            Modifier.fillMaxWidth().clip(MaterialTheme.shapes.medium).background(extras.sucessoFundo).padding(start = 12.dp),
                            verticalAlignment = Alignment.CenterVertically,
                        ) {
                            Icon(Icons.Rounded.Check, contentDescription = null, tint = extras.sucesso, modifier = Modifier.size(20.dp))
                            Spacer(Modifier.width(8.dp))
                            Text((if (deOutraPessoa) "Tomou às " else "Tomei às ") + vez.time, style = MaterialTheme.typography.bodyLarge, modifier = Modifier.weight(1f).padding(vertical = 12.dp))
                            if (aoDesfazer != null) {
                                TextButton(onClick = { aoDesfazer(remedio, vez) }, modifier = Modifier.heightIn(min = 48.dp)) {
                                    Icon(Icons.AutoMirrored.Rounded.Undo, contentDescription = null, modifier = Modifier.size(20.dp))
                                    Spacer(Modifier.width(6.dp))
                                    Text("Desfazer", style = MaterialTheme.typography.labelLarge)
                                }
                            }
                        }
                    }
                    if (aoTomar != null) {
                        androidx.compose.material3.Button(
                            onClick = { aoTomar(remedio) },
                            enabled = !ocupado,
                            modifier = Modifier.fillMaxWidth().heightIn(min = 52.dp),
                            shape = MaterialTheme.shapes.medium,
                            colors = androidx.compose.material3.ButtonDefaults.buttonColors(containerColor = extras.sucesso, contentColor = MaterialTheme.colorScheme.surface),
                        ) {
                            if (ocupado) {
                                CircularProgressIndicator(Modifier.size(22.dp), strokeWidth = 3.dp, color = MaterialTheme.colorScheme.surface)
                            } else {
                                Icon(Icons.Rounded.Check, contentDescription = null)
                                Spacer(Modifier.width(8.dp))
                                Text(if (deOutraPessoa) "Tomou agora" else "Tomei agora", style = MaterialTheme.typography.labelLarge)
                            }
                        }
                    }
                }
            }
        }
    }
}

private val HORA: DateTimeFormatter = DateTimeFormatter.ofPattern("HH:mm")
