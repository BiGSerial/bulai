package br.com.vixforge.bulai.ui.inicio

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
    preenchimento: PaddingValues,
    aoContarAvisos: (Int) -> Unit = {},
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
                item(key = "saudacao") { Saudacao(dados) }
                item(key = "cartao") { CartaoDoDia(dados, marcando, vm::tomei, aoAbrirDia, Modifier.widthIn(max = 640.dp)) }
                item(key = "atalhos") {
                    Column(Modifier.widthIn(max = 640.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                        Atalho(Icons.Rounded.EventAvailable, "Remédios de hoje", "Marque o que já tomou", aoAbrirDia)
                        Atalho(Icons.AutoMirrored.Rounded.Chat, "Perguntar sobre um remédio", "Escreva ou fale sua dúvida", aoAbrirPerguntar)
                        Atalho(Icons.Rounded.PhotoCamera, "Fotografar receita", "Os remédios entram no seu tratamento", aoFotografarReceita)
                        Atalho(Icons.Rounded.NotificationsActive, "Avisos", when (dados.avisosNaoLidos) { 0 -> "Nenhum aviso novo"; 1 -> "1 aviso novo"; else -> "${dados.avisosNaoLidos} avisos novos" }, aoAbrirAvisos, dados.avisosNaoLidos)
                    }
                }
            }
        }
    }
}

@Composable
private fun Saudacao(dados: Inicio) {
    val hora = LocalTime.now().hour
    val periodo = when (hora) {
        in 5..11 -> "Bom dia"
        in 12..17 -> "Boa tarde"
        else -> "Boa noite"
    }
    Column(Modifier.fillMaxWidth().widthIn(max = 640.dp)) {
        Text(if (dados.primeiroNome.isBlank()) "$periodo!" else "$periodo, ${dados.primeiroNome}!", style = MaterialTheme.typography.headlineMedium, color = MaterialTheme.colorScheme.primary, modifier = Modifier.semantics { heading() })
        Text(dados.data, style = MaterialTheme.typography.bodyLarge, color = MaterialTheme.colorScheme.onSurfaceVariant)
    }
}

@Composable
private fun CartaoDoDia(dados: Inicio, marcando: Boolean, aoTomar: (Dose) -> Unit, aoAbrirDia: () -> Unit, modifier: Modifier = Modifier) {
    val extras = TemaBulai.extras
    Cartao(modifier.animateContentSize()) {
        when {
            !dados.consentimento -> {
                Text("Seus remédios", style = MaterialTheme.typography.titleLarge, color = MaterialTheme.colorScheme.primary)
                Text("Para guardar seus remédios e receber lembretes, autorize o uso dos seus dados de saúde no seu perfil.", style = MaterialTheme.typography.bodyLarge)
            }
            !dados.temRemedios -> {
                Text("Nenhum remédio cadastrado", style = MaterialTheme.typography.titleLarge, color = MaterialTheme.colorScheme.primary)
                Text("Cadastre seus remédios no perfil para ver os horários aqui e receber os alarmes.", style = MaterialTheme.typography.bodyLarge)
            }
            else -> {
                val progresso = dados.progress
                val fracao by animateFloatAsState(if (progresso.total == 0) 0f else progresso.done / progresso.total.toFloat(), label = "progresso")
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text("Hoje", style = MaterialTheme.typography.titleLarge, color = MaterialTheme.colorScheme.primary, modifier = Modifier.weight(1f))
                    Text("${progresso.done} de ${progresso.total}", style = MaterialTheme.typography.titleMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
                }
                LinearProgressIndicator(
                    progress = { fracao },
                    modifier = Modifier.fillMaxWidth().height(10.dp).clip(CircleShape).semantics { stateDescription = "${progresso.done} de ${progresso.total} doses anotadas" },
                    color = extras.sucesso,
                    trackColor = MaterialTheme.colorScheme.surfaceVariant,
                    drawStopIndicator = {},
                )
                AnimatedContent(dados.proxima, transitionSpec = { fadeIn() togetherWith fadeOut() }, label = "proxima") { proxima ->
                    if (proxima == null) {
                        Row(verticalAlignment = Alignment.CenterVertically, modifier = Modifier.padding(vertical = 6.dp)) {
                            Icon(Icons.Rounded.CheckCircle, contentDescription = null, tint = extras.sucesso, modifier = Modifier.size(32.dp))
                            Spacer(Modifier.width(12.dp))
                            Text(if (progresso.total > 0 && progresso.done == progresso.total) "Tudo anotado por hoje. Muito bem!" else "Nenhuma dose pendente agora.", style = MaterialTheme.typography.bodyLarge)
                        }
                    } else {
                        ProximaDose(proxima, marcando, aoTomar)
                    }
                }
                BotaoSecundario("Ver todos os horários", aoAbrirDia)
            }
        }
    }
}

/**
 * Próxima dose. O fundo do cartão enche da esquerda para a direita conforme o horário chega (da dose anterior do dia, ou do
 * começo do dia, até o horário dela), com a ponta ondulando como a superfície de um líquido visto de lado. Na hora, cheio.
 */
@Composable
private fun ProximaDose(dose: Dose, marcando: Boolean, aoTomar: (Dose) -> Unit) {
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
    val fundo = if (atrasada) extras.alertaFundo else MaterialTheme.colorScheme.primaryContainer
    val liquido = if (atrasada) extras.alerta.copy(alpha = 0.18f) else MaterialTheme.colorScheme.primary.copy(alpha = 0.2f)
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
                Icon(if (atrasada) Icons.Rounded.WarningAmber else Icons.Rounded.AlarmOn, contentDescription = null, tint = if (atrasada) extras.alerta else MaterialTheme.colorScheme.primary, modifier = Modifier.size(32.dp))
                Spacer(Modifier.width(14.dp))
                Column(Modifier.weight(1f)) {
                    Text(
                        when {
                            atrasada -> "Atrasado · ${dose.time}"
                            dose.ehHoje -> "Próximo · ${dose.time}"
                            else -> "Amanhã · ${dose.time}"
                        },
                        style = MaterialTheme.typography.labelLarge,
                        color = if (atrasada) extras.alerta else MaterialTheme.colorScheme.primary,
                    )
                    Text(dose.name, style = MaterialTheme.typography.titleLarge, color = MaterialTheme.colorScheme.onSurface)
                    dose.dosage?.takeIf { it.isNotBlank() }?.let { Text(it, style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant) }
                    dose.ajustadaDe?.let { Text("Ajustado pela dose das $it", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant) }
                    if (!podeMarcar && faltam != null && faltam > 0) {
                        Text(
                            "Faltam " + when {
                                faltam < 60 -> "$faltam min"
                                faltam % 60 == 0 -> "${faltam / 60} h"
                                else -> "${faltam / 60} h ${faltam % 60} min"
                            },
                            style = MaterialTheme.typography.labelMedium,
                            color = MaterialTheme.colorScheme.primary,
                        )
                    }
                }
            }
        }
        if (podeMarcar) {
            BotaoPrincipal("Tomei", { aoTomar(dose) }, carregando = marcando, destaque = true, icone = Icons.Rounded.Medication)
        }
    }
}

@Composable
private fun Atalho(icone: ImageVector, titulo: String, apoio: String, aoClicar: () -> Unit, contador: Int = 0) {
    Cartao(aoClicar = aoClicar) {
        Row(Modifier.heightIn(min = 48.dp), verticalAlignment = Alignment.CenterVertically) {
            Box {
                Icon(icone, contentDescription = null, tint = MaterialTheme.colorScheme.primary, modifier = Modifier.size(32.dp))
                if (contador > 0) {
                    Badge(Modifier.align(Alignment.TopEnd)) { Text("$contador") }
                }
            }
            Spacer(Modifier.width(16.dp))
            Column(Modifier.weight(1f)) {
                Text(titulo, style = MaterialTheme.typography.titleMedium, color = MaterialTheme.colorScheme.onSurface)
                Text(apoio, style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
            }
            Icon(Icons.AutoMirrored.Rounded.KeyboardArrowRight, contentDescription = null, tint = MaterialTheme.colorScheme.onSurfaceVariant)
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
