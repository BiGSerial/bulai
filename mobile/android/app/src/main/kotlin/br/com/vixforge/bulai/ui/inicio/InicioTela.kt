package br.com.vixforge.bulai.ui.inicio

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
                        Atalho(Icons.Rounded.NotificationsActive, "Avisos e alarmes", when (dados.avisosNaoLidos) { 0 -> "Lembretes e alarmes do celular"; 1 -> "1 aviso novo"; else -> "${dados.avisosNaoLidos} avisos novos" }, aoAbrirAvisos, dados.avisosNaoLidos)
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

@Composable
private fun ProximaDose(dose: Dose, marcando: Boolean, aoTomar: (Dose) -> Unit) {
    val extras = TemaBulai.extras
    val atrasada = dose.status == Dose.ATRASADA
    val podeMarcar = dose.status == Dose.AGORA || atrasada
    Column(verticalArrangement = Arrangement.spacedBy(12.dp)) {
        Surface(
            color = if (atrasada) extras.alertaFundo else MaterialTheme.colorScheme.primaryContainer,
            shape = MaterialTheme.shapes.medium,
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
