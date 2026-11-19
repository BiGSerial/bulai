package br.com.vixforge.bulai.ui.inicio

import br.com.vixforge.bulai.ui.componentes.nomeAmigavel
import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.background
import androidx.compose.material.icons.automirrored.rounded.HelpOutline
import androidx.compose.material.icons.rounded.Check
import androidx.compose.material.icons.rounded.History
import androidx.compose.material.icons.rounded.Schedule
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.geometry.Size
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.StrokeCap
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.text.SpanStyle
import androidx.compose.ui.text.buildAnnotatedString
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.withStyle
import androidx.compose.ui.unit.sp
import br.com.vixforge.bulai.ui.tema.LocalEscuro
import br.com.vixforge.bulai.ui.tema.Marca
import androidx.compose.animation.core.FastOutSlowInEasing
import androidx.compose.animation.core.tween
import androidx.compose.runtime.mutableFloatStateOf
import androidx.compose.runtime.mutableLongStateOf
import androidx.compose.runtime.withFrameNanos
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.draw.drawBehind
import androidx.compose.ui.graphics.Path
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalDensity
import br.com.vixforge.bulai.abertura.VideoDeAbertura
import java.time.OffsetDateTime
import kotlin.math.PI
import kotlin.math.cos
import kotlin.math.sin
import kotlinx.coroutines.delay
import androidx.compose.animation.AnimatedContent
import androidx.compose.animation.animateContentSize
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.togetherWith
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
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.KeyboardArrowRight
import androidx.compose.material.icons.rounded.AlarmOn
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.automirrored.rounded.Chat
import androidx.compose.material.icons.rounded.EventAvailable
import androidx.compose.material.icons.rounded.PhotoCamera
import androidx.compose.material.icons.rounded.Medication
import androidx.compose.material.icons.rounded.CompareArrows
import androidx.compose.material.icons.automirrored.rounded.MenuBook
import androidx.compose.material.icons.rounded.Medication
import androidx.compose.material.icons.rounded.NotificationsActive
import androidx.compose.material.icons.rounded.WarningAmber
import androidx.compose.material3.Badge
import androidx.compose.material3.Icon
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.pulltorefresh.PullToRefreshBox
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.semantics.stateDescription
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.LifecycleResumeEffect
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import br.com.vixforge.bulai.dados.api.Dose
import br.com.vixforge.bulai.dados.api.PessoaAcompanhada
import br.com.vixforge.bulai.ui.cuidado.PessoasQueVoceCuida
import br.com.vixforge.bulai.dados.api.Inicio
import br.com.vixforge.bulai.ui.base.telaViewModel
import br.com.vixforge.bulai.ui.componentes.Bloco
import br.com.vixforge.bulai.ui.componentes.BotaoPrincipal
import br.com.vixforge.bulai.ui.componentes.BotaoSecundario
import br.com.vixforge.bulai.ui.componentes.Cartao
import br.com.vixforge.bulai.ui.componentes.CartaoEsqueleto
import br.com.vixforge.bulai.ui.componentes.FaixaDeErro
import br.com.vixforge.bulai.ui.componentes.TelaEsqueleto
import br.com.vixforge.bulai.ui.tema.TemaBulai
import java.time.LocalTime

@Composable
fun InicioTela(
    recado: (String) -> Unit,
    aoAbrirDia: () -> Unit,
    aoAbrirAvisos: () -> Unit,
    aoAbrirPerguntar: () -> Unit,
    aoFotografarReceita: () -> Unit,
    aoAbrirPerguntas: () -> Unit,
    preenchimento: PaddingValues,
    aoContarAvisos: (Int) -> Unit = {},
    aoAbrirPessoa: (PessoaAcompanhada) -> Unit = {},
    aoAbrirCuidar: () -> Unit = {},
    aoAbrirPerfil: () -> Unit = {},
    aoAbrirInteracoes: () -> Unit = {},
    aoAbrirBulas: () -> Unit = {},
) {
    val vm = telaViewModel { InicioViewModel(it.tratamento) }
    val estado by vm.estado.collectAsStateWithLifecycle()
    val marcando by vm.marcando.collectAsStateWithLifecycle()
    LaunchedEffect(Unit) { vm.recados.collect(recado) }
    LaunchedEffect(estado.dados?.avisosNaoLidos) { estado.dados?.let { aoContarAvisos(it.avisosNaoLidos) } }
    LifecycleResumeEffect(Unit) {
        vm.atualizar()
        onPauseOrDispose { }
    }

    PullToRefreshBox(isRefreshing = estado.atualizando && estado.dados != null, onRefresh = vm::atualizar, modifier = Modifier.fillMaxSize()) {
        LazyColumn(
            Modifier.fillMaxSize(),
            contentPadding = PaddingValues(start = 20.dp, end = 20.dp, top = preenchimento.calculateTopPadding() + 8.dp, bottom = preenchimento.calculateBottomPadding() + 24.dp),
            verticalArrangement = Arrangement.spacedBy(16.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            item(key = "erro") { FaixaDeErro(estado.erro, Modifier.widthIn(max = 640.dp), vm::atualizar) }
            val dados = estado.dados
            if (dados == null) {
                if (estado.esqueleto) {
                    item(key = "esqueleto") { EsqueletoDoInicio() }
                }
            } else {
                item(key = "saudacao") { Saudacao(dados, aoAbrirAvisos) }
                item(key = "cartao") { CartaoDoDia(dados, marcando, vm::tomei, aoAbrirPerfil, Modifier.widthIn(max = 640.dp)) }
                // Quem cuida de alguém escolhe aqui de quem ver os remédios (some para quem não acompanha ninguém).
                item(key = "cuidando") { PessoasQueVoceCuida(aoAbrirPessoa, aoAbrirCuidar, Modifier.widthIn(max = 640.dp)) }
                // Ações grandes, uma por linha (como no site): Perguntar em destaque laranja.
                item(key = "acoes") {
                    val proxima = dados.proxima
                    val textoDoDia = when {
                        proxima == null -> "Veja os horários e marque o que já tomou"
                        proxima.status == Dose.ATRASADA -> "Atrasado: ${proxima.time} · ${nomeAmigavel(proxima.name)}"
                        proxima.ehHoje -> "Próximo: ${proxima.time} · ${nomeAmigavel(proxima.name)}"
                        else -> "Amanhã: ${proxima.time} · ${nomeAmigavel(proxima.name)}"
                    }
                    Column(Modifier.widthIn(max = 640.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                        Acao(Icons.AutoMirrored.Rounded.HelpOutline, "Perguntar sobre um remédio", "Escreva ou fale sua dúvida", aoAbrirPerguntar, destaque = true)
                        Acao(Icons.Rounded.Schedule, "Remédios de hoje", textoDoDia, aoAbrirDia, alerta = proxima?.status == Dose.ATRASADA)
                        Acao(Icons.Rounded.Medication, "Meus remédios", "Dose, horários, receitas e alergias", aoAbrirPerfil, tom = MaterialTheme.colorScheme.primary)
                        Acao(Icons.Rounded.PhotoCamera, "Fotografar minha receita", "O app lê os remédios para você conferir", aoFotografarReceita, tom = TemaBulai.extras.sucesso)
                        Acao(Icons.Rounded.CompareArrows, "Verificar interações", "Veja se seus remédios podem interagir", aoAbrirInteracoes, tom = TemaBulai.extras.alerta)
                        Acao(Icons.AutoMirrored.Rounded.MenuBook, "Bula simples", "A bula explicada em linguagem simples", aoAbrirBulas, tom = MaterialTheme.colorScheme.secondary)
                        Acao(Icons.Rounded.History, "Ver o que já perguntei", "Suas perguntas anteriores", aoAbrirPerguntas, tom = MaterialTheme.colorScheme.onSurfaceVariant)
                    }
                }
            }
        }
    }
}

@Composable
private fun Saudacao(dados: Inicio, aoAbrirAvisos: () -> Unit) {
    val hora = LocalTime.now().hour
    val periodo = when (hora) {
        in 5..11 -> "Bom dia"
        in 12..17 -> "Boa tarde"
        else -> "Boa noite"
    }
    Column(Modifier.fillMaxWidth().widthIn(max = 640.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
        Text(if (dados.primeiroNome.isBlank()) "$periodo!" else "$periodo, ${dados.primeiroNome}!", style = MaterialTheme.typography.headlineMedium, color = MaterialTheme.colorScheme.primary, modifier = Modifier.semantics { heading() })
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text(dados.data, style = MaterialTheme.typography.bodyLarge, color = MaterialTheme.colorScheme.onSurfaceVariant, modifier = Modifier.weight(1f))
            if (dados.avisosNaoLidos > 0) {
                Surface(onClick = aoAbrirAvisos, shape = CircleShape, color = MaterialTheme.colorScheme.secondaryContainer, contentColor = MaterialTheme.colorScheme.onSecondaryContainer) {
                    Row(Modifier.heightIn(min = 44.dp).padding(horizontal = 14.dp), verticalAlignment = Alignment.CenterVertically) {
                        Icon(Icons.Rounded.NotificationsActive, contentDescription = null, modifier = Modifier.size(20.dp))
                        Spacer(Modifier.width(6.dp))
                        Text(if (dados.avisosNaoLidos == 1) "1 aviso novo" else "${dados.avisosNaoLidos} avisos novos", style = MaterialTheme.typography.labelLarge)
                    }
                }
            }
        }
    }
}

/** Cores do cartão do dia (azul da marca, como o do site): no escuro, o azul mais claro do tema. */
private data class CoresDoCartao(val fundo: Color, val texto: Color, val apoio: Color, val destaque: Color)

@Composable
private fun coresDoCartao(): CoresDoCartao {
    val escuro = LocalEscuro.current
    return if (escuro) {
        CoresDoCartao(MaterialTheme.colorScheme.primaryContainer, MaterialTheme.colorScheme.onPrimaryContainer, MaterialTheme.colorScheme.onPrimaryContainer.copy(alpha = 0.75f), MaterialTheme.colorScheme.secondary)
    } else {
        CoresDoCartao(Marca.Azul700, Color.White, Marca.Azul100, Marca.Laranja100)
    }
}

/** Cartão do dia: anel com as doses tomadas, "Hoje · X de Y" e o próximo remédio (com "Tomei" quando está na hora). */
@Composable
private fun CartaoDoDia(dados: Inicio, marcando: Boolean, aoTomar: (Dose) -> Unit, aoAbrirPerfil: () -> Unit, modifier: Modifier = Modifier) {
    val cores = coresDoCartao()
    Surface(modifier.fillMaxWidth().animateContentSize(), shape = MaterialTheme.shapes.extraLarge, color = cores.fundo, contentColor = cores.texto, shadowElevation = 4.dp) {
        Column(Modifier.padding(20.dp), verticalArrangement = Arrangement.spacedBy(16.dp)) {
            when {
                !dados.consentimento -> {
                    Text("Seus remédios", style = MaterialTheme.typography.titleLarge)
                    Text("Para guardar seus remédios e receber lembretes, autorize o uso dos seus dados de saúde.", style = MaterialTheme.typography.bodyLarge, color = cores.apoio)
                    androidx.compose.material3.Button(onClick = aoAbrirPerfil, modifier = Modifier.heightIn(min = 52.dp), colors = androidx.compose.material3.ButtonDefaults.buttonColors(containerColor = cores.texto, contentColor = cores.fundo)) { Text("Autorizar agora", style = MaterialTheme.typography.labelLarge) }
                }
                !dados.temRemedios -> {
                    Text("Nenhum remédio cadastrado", style = MaterialTheme.typography.titleLarge)
                    Text("Fotografe sua receita para ver os horários aqui e receber os alarmes.", style = MaterialTheme.typography.bodyLarge, color = cores.apoio)
                }
                else -> {
                    val progresso = dados.progress
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        AnelDeProgresso(progresso.taken, progresso.total)
                        Spacer(Modifier.width(16.dp))
                        Column(Modifier.weight(1f)) {
                            Text("HOJE", style = MaterialTheme.typography.labelLarge, color = cores.apoio)
                            Text(
                                when {
                                    progresso.total > 0 && progresso.taken == progresso.total -> "Tudo tomado por hoje!"
                                    progresso.total == 1 -> "${progresso.taken} de 1 dose tomada"
                                    else -> "${progresso.taken} de ${progresso.total} doses tomadas"
                                },
                                style = MaterialTheme.typography.headlineSmall,
                                fontWeight = FontWeight.SemiBold,
                            )
                        }
                    }
                    AnimatedContent(dados.proxima, transitionSpec = { fadeIn() togetherWith fadeOut() }, label = "proxima") { proxima ->
                        if (proxima != null) {
                            ProximaDose(proxima, marcando, aoTomar, cores)
                        }
                    }
                }
            }
        }
    }
}

/** Anel com as doses tomadas (como o do site): fundo claro, trilha cinza, avanço em laranja e "4/9 DOSES" no centro. */
@Composable
private fun AnelDeProgresso(feitos: Int, total: Int, modifier: Modifier = Modifier) {
    val fracao by animateFloatAsState(if (total == 0) 0f else feitos / total.toFloat(), tween(900, easing = FastOutSlowInEasing), label = "anel")
    val trilha = Marca.Azul100
    val cor = if (total > 0 && feitos >= total) Marca.Verde else Marca.Laranja500
    Box(
        modifier.size(104.dp).clip(CircleShape).background(Color.White).semantics { contentDescription = "$feitos de $total doses tomadas" },
        contentAlignment = Alignment.Center,
    ) {
        Canvas(Modifier.size(92.dp)) {
            val espessura = 9.dp.toPx()
            val area = Size(size.width - espessura, size.height - espessura)
            val canto = Offset(espessura / 2, espessura / 2)
            drawArc(trilha, 0f, 360f, false, canto, area, style = Stroke(espessura))
            drawArc(cor, -90f, 360f * fracao, false, canto, area, style = Stroke(espessura, cap = StrokeCap.Round))
        }
        Column(horizontalAlignment = Alignment.CenterHorizontally) {
            Text(
                buildAnnotatedString {
                    withStyle(SpanStyle(fontSize = 30.sp, fontWeight = FontWeight.Bold)) { append("$feitos") }
                    withStyle(SpanStyle(fontSize = 16.sp, fontWeight = FontWeight.SemiBold, color = Marca.Azul500)) { append("/$total") }
                },
                color = Marca.Azul900,
            )
            Text("DOSES", style = MaterialTheme.typography.labelSmall, fontWeight = FontWeight.SemiBold, color = Marca.Azul500)
        }
    }
}

/**
 * Próxima dose. O fundo do cartão enche da esquerda para a direita conforme o horário chega (da dose anterior do dia, ou do
 * começo do dia, até o horário dela), com a ponta ondulando como a superfície de um líquido visto de lado. Na hora, cheio.
 */
@Composable
private fun ProximaDose(dose: Dose, marcando: Boolean, aoTomar: (Dose) -> Unit, cores: CoresDoCartao) {
    val extras = TemaBulai.extras
    val atrasada = dose.status == Dose.ATRASADA
    val podeMarcar = dose.status == Dose.AGORA || atrasada
    val context = LocalContext.current
    val semAnimacao = remember { VideoDeAbertura.animacoesDesligadas(context) }

    // O relógio anda sozinho (a cada 20 s) para a barra crescer com a tela aberta.
    var agora by remember { mutableLongStateOf(System.currentTimeMillis()) }
    LaunchedEffect(dose.key) {
        while (true) {
            agora = System.currentTimeMillis()
            delay(20_000)
        }
    }
    val horario = remember(dose.agendadaPara) { runCatching { OffsetDateTime.parse(dose.agendadaPara).toInstant().toEpochMilli() }.getOrNull() }
    val inicio = remember(dose.desde) { dose.desde?.let { runCatching { OffsetDateTime.parse(it).toInstant().toEpochMilli() }.getOrNull() } }
    val alvo = when {
        podeMarcar -> 1f
        horario == null || inicio == null || horario <= inicio -> 0f
        else -> ((agora - inicio).toFloat() / (horario - inicio)).coerceIn(0f, 1f)
    }
    val enchimento by animateFloatAsState(alvo, tween(if (semAnimacao) 0 else 1400, easing = FastOutSlowInEasing), label = "enchimento")
    val faltam = horario?.let { ((it - agora) / 60_000).toInt() }

    // Tempo contínuo (segundos) para a ondulação: sem ciclo fixo, a borda nunca repete o mesmo desenho.
    var tempo by remember { mutableFloatStateOf(0f) }
    if (!semAnimacao && enchimento > 0f && enchimento < 1f) {
        LaunchedEffect(Unit) {
            val inicioDoRelogio = withFrameNanos { it }
            while (true) {
                withFrameNanos { agoraNs -> tempo = (agoraNs - inicioDoRelogio) / 1_000_000_000f }
            }
        }
    }
    // No cartão azul: bloco translúcido e o "líquido" um pouco mais claro (laranja quando atrasado).
    val fundo = if (atrasada) Marca.Laranja500.copy(alpha = 0.18f) else cores.texto.copy(alpha = 0.10f)
    val liquido = if (atrasada) Marca.Laranja500.copy(alpha = 0.35f) else cores.texto.copy(alpha = 0.20f)
    val densidade = LocalDensity.current
    val amplitude = with(densidade) { 9.dp.toPx() }
    val comprimento = with(densidade) { 70.dp.toPx() }

    Column(verticalArrangement = Arrangement.spacedBy(12.dp)) {
        Box(
            Modifier.fillMaxWidth().clip(MaterialTheme.shapes.medium).drawBehind {
                drawRect(fundo)
                if (enchimento <= 0f) {
                    return@drawBehind
                }
                if (enchimento >= 1f) {
                    drawRect(liquido)
                    return@drawBehind
                }
                // Ponta de líquido balançando no lugar: três ondas estacionárias (comprimentos e ritmos diferentes, sem relação
                // entre si) somadas. Cada uma sobe e desce sem andar; juntas, a borda fica irregular e nunca se repete.
                val borda = size.width * enchimento
                val t = tempo
                fun deslocamento(y: Float): Float {
                    val k = 2 * PI.toFloat() * y / comprimento
                    return amplitude * (
                        0.55f * sin(k * 0.9f + 0.6f) * sin(t * 1.7f) +
                            0.35f * sin(k * 1.7f + 2.1f) * sin(t * 2.9f + 1.3f) +
                            0.25f * sin(k * 2.6f + 4.0f) * cos(t * 4.3f + 0.4f) +
                            0.15f * sin(k * 0.45f) * sin(t * 0.8f + 2.2f)
                        )
                }
                val caminho = Path().apply {
                    moveTo(0f, 0f)
                    var y = 0f
                    while (y <= size.height) {
                        lineTo(borda + deslocamento(y), y)
                        y += 2f
                    }
                    lineTo(borda + deslocamento(size.height), size.height)
                    lineTo(0f, size.height)
                    close()
                }
                drawPath(caminho, liquido)
            },
        ) {
            Row(Modifier.fillMaxWidth().padding(16.dp), verticalAlignment = Alignment.CenterVertically) {
                Icon(if (atrasada) Icons.Rounded.WarningAmber else Icons.Rounded.Schedule, contentDescription = null, tint = cores.texto, modifier = Modifier.size(32.dp))
                Spacer(Modifier.width(14.dp))
                Column(Modifier.weight(1f)) {
                    Text(
                        when {
                            atrasada -> "Atrasado · ${dose.time}"
                            dose.ehHoje -> "Próximo · ${dose.time}"
                            else -> "Amanhã · ${dose.time}"
                        },
                        style = MaterialTheme.typography.labelLarge,
                        color = if (atrasada) cores.destaque else cores.apoio,
                    )
                    Text(nomeAmigavel(dose.name), style = MaterialTheme.typography.titleLarge, color = cores.texto)
                    dose.dosage?.takeIf { it.isNotBlank() }?.let { Text(it, style = MaterialTheme.typography.bodyMedium, color = cores.apoio) }
                    dose.ajustadaDe?.let { Text("Ajustado pela dose das $it", style = MaterialTheme.typography.bodySmall, color = cores.apoio) }
                    if (!podeMarcar && faltam != null && faltam > 0) {
                        Text(
                            "Faltam " + when {
                                faltam < 60 -> "$faltam min"
                                faltam % 60 == 0 -> "${faltam / 60} h"
                                else -> "${faltam / 60} h ${faltam % 60} min"
                            },
                            style = MaterialTheme.typography.labelLarge,
                            color = cores.destaque,
                        )
                    }
                }
            }
        }
        if (podeMarcar) {
            BotaoPrincipal("Marcar como tomado", { aoTomar(dose) }, carregando = marcando, destaque = true, icone = Icons.Rounded.Check)
        }
    }
}

/** Ação grande do Início (como no site): ícone em círculo, título, apoio e seta; [destaque] = laranja (Perguntar). */
@Composable
private fun Acao(icone: ImageVector, titulo: String, apoio: String, aoClicar: () -> Unit, destaque: Boolean = false, alerta: Boolean = false, tom: Color = MaterialTheme.colorScheme.primary) {
    val fundo = if (destaque) MaterialTheme.colorScheme.secondary else MaterialTheme.colorScheme.surface
    val texto = if (destaque) MaterialTheme.colorScheme.onSecondary else MaterialTheme.colorScheme.onSurface
    val corDoIcone = when {
        destaque -> MaterialTheme.colorScheme.onSecondary
        alerta -> MaterialTheme.colorScheme.error
        else -> tom
    }
    Surface(
        onClick = aoClicar,
        shape = MaterialTheme.shapes.large,
        color = fundo,
        contentColor = texto,
        border = if (destaque) null else BorderStroke(1.dp, MaterialTheme.colorScheme.outlineVariant.copy(alpha = 0.7f)),
        shadowElevation = if (destaque) 3.dp else 1.dp,
        modifier = Modifier.fillMaxWidth(),
    ) {
        Row(Modifier.heightIn(min = 88.dp).padding(horizontal = 16.dp, vertical = 14.dp), verticalAlignment = Alignment.CenterVertically) {
            Box(Modifier.size(56.dp).clip(CircleShape).background(if (destaque) Color.White.copy(alpha = 0.2f) else corDoIcone.copy(alpha = 0.12f)), contentAlignment = Alignment.Center) {
                Icon(icone, contentDescription = null, tint = corDoIcone, modifier = Modifier.size(30.dp))
            }
            Spacer(Modifier.width(16.dp))
            Column(Modifier.weight(1f)) {
                Text(titulo, style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.SemiBold)
                Text(apoio, style = MaterialTheme.typography.bodyLarge, color = if (destaque) texto.copy(alpha = 0.9f) else if (alerta) MaterialTheme.colorScheme.error else MaterialTheme.colorScheme.onSurfaceVariant)
            }
            Icon(Icons.AutoMirrored.Rounded.KeyboardArrowRight, contentDescription = null, modifier = Modifier.size(28.dp), tint = texto.copy(alpha = 0.6f))
        }
    }
}

@Composable
private fun EsqueletoDoInicio() {
    TelaEsqueleto(Modifier.widthIn(max = 640.dp)) {
        Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
            Bloco(0.6f, 30.dp)
            Bloco(0.45f, 18.dp)
        }
        CartaoEsqueleto()
        CartaoEsqueleto(comBotao = false)
        CartaoEsqueleto(comBotao = false)
    }
}
