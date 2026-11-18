package br.com.vixforge.bulai.ui.avisos

import android.app.Application
import androidx.compose.animation.animateColorAsState
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.selection.toggleable
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Alarm
import androidx.compose.material.icons.rounded.BatteryAlert
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.Delete
import androidx.compose.material.icons.rounded.DoneAll
import androidx.compose.material.icons.rounded.NotificationsActive
import androidx.compose.material.icons.rounded.NotificationsNone
import androidx.compose.material.icons.rounded.PhoneAndroid
import androidx.compose.material.icons.rounded.WarningAmber
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.ExposedDropdownMenuAnchorType
import androidx.compose.material3.ExposedDropdownMenuBox
import androidx.compose.material3.ExposedDropdownMenuDefaults
import androidx.compose.material3.FilterChip
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.SwipeToDismissBox
import androidx.compose.material3.SwipeToDismissBoxValue
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.pulltorefresh.PullToRefreshBox
import androidx.compose.material3.rememberSwipeToDismissBoxState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.semantics.CustomAccessibilityAction
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.customActions
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.LifecycleResumeEffect
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import br.com.vixforge.bulai.alarmes.AjustesDoCelular
import br.com.vixforge.bulai.alarmes.SituacaoDosAlarmes
import br.com.vixforge.bulai.dados.api.Aviso
import br.com.vixforge.bulai.dados.api.CentralDeAvisos
import br.com.vixforge.bulai.dados.api.Destino
import br.com.vixforge.bulai.ui.base.telaViewModel
import br.com.vixforge.bulai.ui.componentes.Bloco
import br.com.vixforge.bulai.ui.componentes.BotaoSecundario
import br.com.vixforge.bulai.ui.componentes.Cartao
import br.com.vixforge.bulai.ui.componentes.CartaoEsqueleto
import br.com.vixforge.bulai.ui.componentes.EstadoVazio
import br.com.vixforge.bulai.ui.componentes.FaixaDeErro
import br.com.vixforge.bulai.ui.componentes.TelaEsqueleto
import br.com.vixforge.bulai.ui.sistema.Permissao
import br.com.vixforge.bulai.ui.sistema.rememberPedirPermissao
import br.com.vixforge.bulai.ui.tema.TemaBulai

@Composable
fun AvisosTela(recado: (String) -> Unit, aoAbrirDestino: (Destino) -> Unit, preenchimento: PaddingValues, aoContarAvisos: (Int) -> Unit = {}, aoAbrirAjustes: () -> Unit = {}) {
    val app = LocalContext.current.applicationContext as Application
    val vm = telaViewModel { AvisosViewModel(app, it.avisos) }
    val estado by vm.estado.collectAsStateWithLifecycle()
    var confirmarApagarTodos by rememberSaveable { mutableStateOf(false) }
    LaunchedEffect(Unit) { vm.recados.collect(recado) }
    LaunchedEffect(Unit) { vm.destinos.collect(aoAbrirDestino) }
    LaunchedEffect(estado.dados?.unread) { estado.dados?.let { aoContarAvisos(it.unread) } }
    // Volta das configurações do sistema (permissão concedida) e reabertura da tela: relê tudo.
    LifecycleResumeEffect(Unit) {
        vm.atualizar()
        onPauseOrDispose { }
    }

    PullToRefreshBox(isRefreshing = estado.atualizando && estado.dados != null, onRefresh = vm::atualizar, modifier = Modifier.fillMaxSize()) {
        LazyColumn(
            Modifier.fillMaxSize(),
            contentPadding = PaddingValues(start = 20.dp, end = 20.dp, top = preenchimento.calculateTopPadding() + 8.dp, bottom = preenchimento.calculateBottomPadding() + 24.dp),
            verticalArrangement = Arrangement.spacedBy(12.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            val largura = Modifier.widthIn(max = 640.dp)
            item(key = "erro") { FaixaDeErro(estado.erro, largura, vm::atualizar) }
            val central = estado.dados
            if (central == null) {
                if (estado.esqueleto) {
                    item(key = "esqueleto") {
                        TelaEsqueleto(largura) {
                            Bloco(0.4f, 26.dp)
                            repeat(3) { CartaoEsqueleto(comBotao = false) }
                        }
                    }
                }
            } else {
                item(key = "titulo") { CabecalhoDosAvisos(central, { vm.marcarTodosLidos() }, { confirmarApagarTodos = true }, largura) }
                if (central.notices.isEmpty()) {
                    item(key = "vazio") { EstadoVazio(Icons.Rounded.NotificationsNone, "Nenhum aviso", "Quando houver um lembrete ou uma novidade dos seus remédios, ele aparece aqui.", largura) }
                } else {
                    items(central.notices, key = { it.id }) { aviso ->
                        LinhaDoAviso(aviso, { vm.abrir(aviso) }, { vm.apagar(aviso) }, largura.animateItem())
                    }
                }
                // Lembretes e alarmes do celular ficam em Conta; aqui, só os avisos.
                item(key = "ajustes") {
                    TextButton(onClick = aoAbrirAjustes, modifier = largura.heightIn(min = 48.dp)) {
                        Text("Lembretes e alarmes: ajuste em Conta", style = MaterialTheme.typography.labelLarge)
                    }
                }
            }
        }
    }

    if (confirmarApagarTodos) {
        AlertDialog(
            onDismissRequest = { confirmarApagarTodos = false },
            title = { Text("Apagar todos os avisos?") },
            text = { Text("Os avisos lidos e não lidos serão apagados. Isso não muda seus remédios nem os alarmes.") },
            confirmButton = {
                TextButton(onClick = { confirmarApagarTodos = false; vm.apagarTodos() }) { Text("Apagar todos", color = MaterialTheme.colorScheme.error) }
            },
            dismissButton = { TextButton(onClick = { confirmarApagarTodos = false }) { Text("Cancelar") } },
        )
    }
}

@Composable
private fun CabecalhoDosAvisos(central: CentralDeAvisos, aoMarcarLidos: () -> Unit, aoApagarTodos: () -> Unit, modifier: Modifier) {
    Column(modifier.fillMaxWidth()) {
        // O título "Avisos" já está na barra superior; aqui, só quantos são novos e as ações.
        Text(
            when (central.unread) {
                0 -> "Nenhum aviso novo."
                1 -> "1 aviso novo."
                else -> "${central.unread} avisos novos."
            },
            style = MaterialTheme.typography.bodyLarge,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
        )
        if (central.notices.isNotEmpty()) {
            FlowRow(horizontalArrangement = Arrangement.spacedBy(4.dp)) {
                if (central.unread > 0) {
                    TextButton(onClick = aoMarcarLidos, modifier = Modifier.heightIn(min = 48.dp)) {
                        Icon(Icons.Rounded.DoneAll, contentDescription = null)
                        Spacer(Modifier.width(6.dp))
                        Text("Marcar como lidos")
                    }
                }
                TextButton(onClick = aoApagarTodos, modifier = Modifier.heightIn(min = 48.dp)) {
                    Icon(Icons.Rounded.Delete, contentDescription = null)
                    Spacer(Modifier.width(6.dp))
                    Text("Apagar todos")
                }
            }
        }
    }
}

/** Arrastar para o lado apaga; TalkBack oferece "Apagar aviso" como ação. */
@Composable
private fun LinhaDoAviso(aviso: Aviso, aoAbrir: () -> Unit, aoApagar: () -> Unit, modifier: Modifier) {
    val estado = rememberSwipeToDismissBoxState()
    LaunchedEffect(estado.currentValue) {
        if (estado.currentValue != SwipeToDismissBoxValue.Settled) {
            aoApagar()
        }
    }
    SwipeToDismissBox(
        state = estado,
        modifier = modifier.semantics { customActions = listOf(CustomAccessibilityAction("Apagar aviso") { aoApagar(); true }) },
        backgroundContent = {
            val cor by animateColorAsState(if (estado.targetValue == SwipeToDismissBoxValue.Settled) MaterialTheme.colorScheme.surfaceVariant else TemaBulai.extras.alertaFundo, label = "fundo")
            Box(Modifier.fillMaxSize().clip(MaterialTheme.shapes.large).background(cor).padding(horizontal = 24.dp), contentAlignment = Alignment.CenterEnd) {
                Icon(Icons.Rounded.Delete, contentDescription = null, tint = TemaBulai.extras.alerta)
            }
        },
    ) {
        Cartao(aoClicar = aoAbrir) {
            Row(verticalAlignment = Alignment.Top) {
                Box(Modifier.padding(top = 8.dp).size(10.dp).clip(CircleShape).background(if (aviso.read) MaterialTheme.colorScheme.surface else MaterialTheme.colorScheme.secondary))
                Spacer(Modifier.width(12.dp))
                Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                    Text(aviso.`when`, style = MaterialTheme.typography.labelMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
                    Text(aviso.title, style = MaterialTheme.typography.titleMedium, color = MaterialTheme.colorScheme.onSurface)
                    if (aviso.body.isNotBlank()) {
                        Text(aviso.body, style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
                    }
                    Text(aviso.action, style = MaterialTheme.typography.labelLarge, color = MaterialTheme.colorScheme.primary, modifier = Modifier.padding(top = 4.dp))
                }
            }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
internal fun Lembretes(central: CentralDeAvisos, aoLembretes: (Boolean) -> Unit, aoEmail: (Boolean) -> Unit, aoFuso: (String) -> Unit, modifier: Modifier) {
    val preferencias = central.preferences
    var aberto by remember { mutableStateOf(false) }
    Cartao(modifier) {
        Text("Lembretes", style = MaterialTheme.typography.titleLarge, color = MaterialTheme.colorScheme.primary, modifier = Modifier.semantics { heading() })
        LinhaComChave("Lembrar nos horários dos remédios", "O celular avisa em cada horário.", preferencias.reminders, aoLembretes)
        HorizontalDivider()
        LinhaComChave("Receber também por e-mail", null, preferencias.email, aoEmail, habilitada = preferencias.reminders)
        HorizontalDivider()
        ExposedDropdownMenuBox(expanded = aberto, onExpandedChange = { aberto = it }) {
            OutlinedTextField(
                value = central.timezones.firstOrNull { it.id == preferencias.timezone }?.label ?: preferencias.timezone,
                onValueChange = {},
                readOnly = true,
                label = { Text("Fuso horário") },
                trailingIcon = { ExposedDropdownMenuDefaults.TrailingIcon(expanded = aberto) },
                modifier = Modifier.fillMaxWidth().menuAnchor(ExposedDropdownMenuAnchorType.PrimaryNotEditable),
                textStyle = MaterialTheme.typography.bodyLarge,
                shape = MaterialTheme.shapes.medium,
            )
            ExposedDropdownMenu(expanded = aberto, onDismissRequest = { aberto = false }) {
                central.timezones.forEach { fuso ->
                    DropdownMenuItem(text = { Text(fuso.label) }, onClick = { aberto = false; aoFuso(fuso.id) }, modifier = Modifier.heightIn(min = 52.dp))
                }
            }
        }
    }
}

@Composable
internal fun LinhaComChave(titulo: String, apoio: String?, ligada: Boolean, aoMudar: (Boolean) -> Unit, habilitada: Boolean = true) {
    Row(
        Modifier
            .fillMaxWidth()
            .heightIn(min = 56.dp)
            .toggleable(ligada, enabled = habilitada, role = Role.Switch, onValueChange = aoMudar),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Column(Modifier.weight(1f)) {
            Text(titulo, style = MaterialTheme.typography.bodyLarge, color = if (habilitada) MaterialTheme.colorScheme.onSurface else MaterialTheme.colorScheme.onSurfaceVariant)
            apoio?.let { Text(it, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant) }
        }
        Spacer(Modifier.width(12.dp))
        Switch(checked = ligada, onCheckedChange = null, enabled = habilitada)
    }
}

@Composable
internal fun AlarmesDoCelular(
    situacao: SituacaoDosAlarmes,
    pedir: (Permissao) -> Unit,
    aoDespertador: (Boolean) -> Unit,
    aoAdiar: (Int) -> Unit,
    aoTestar: () -> Unit,
    modifier: Modifier,
) {
    Cartao(modifier) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Icon(Icons.Rounded.PhoneAndroid, contentDescription = null, tint = MaterialTheme.colorScheme.primary)
            Spacer(Modifier.width(10.dp))
            Text("Alarmes neste celular", style = MaterialTheme.typography.titleLarge, color = MaterialTheme.colorScheme.primary, modifier = Modifier.semantics { heading() })
        }
        Text(
            if (situacao.tudoCerto) "Tudo pronto: o celular toca na hora de cada remédio, mesmo parado." else "Libere o que falta para o alarme tocar na hora certa.",
            style = MaterialTheme.typography.bodyMedium,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
        )
        ItemDePermissao(Icons.Rounded.NotificationsActive, "Notificações", situacao.notificacoes) { pedir(Permissao.Notificacoes) }
        ItemDePermissao(Icons.Rounded.Alarm, "Alarmes na hora exata", situacao.alarmesExatos) { pedir(Permissao.AlarmesExatos) }
        ItemDePermissao(Icons.Rounded.BatteryAlert, "Sem economia de bateria", situacao.bateriaLivre) { pedir(Permissao.Bateria) }
        HorizontalDivider()
        LinhaComChave("Tocar como despertador", "Tela cheia e som de alarme, mesmo com o celular bloqueado.", situacao.modoDespertador, aoDespertador)
        if (situacao.modoDespertador && !situacao.telaCheia) {
            ItemDePermissao(Icons.Rounded.PhoneAndroid, "Mostrar em tela cheia", false) { pedir(Permissao.TelaCheia) }
        }
        HorizontalDivider()
        Text("Adiar por", style = MaterialTheme.typography.bodyLarge)
        FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            AjustesDoCelular.OPCOES_DE_ADIAMENTO.forEach { minutos ->
                FilterChip(
                    selected = situacao.adiarMinutos == minutos,
                    onClick = { aoAdiar(minutos) },
                    label = { Text("$minutos min", style = MaterialTheme.typography.labelLarge) },
                    modifier = Modifier.heightIn(min = 48.dp),
                )
            }
        }
        HorizontalDivider()
        Text(situacao.proximoAlarme?.let { "Próximo alarme: $it" } ?: "Nenhum alarme marcado agora.", style = MaterialTheme.typography.bodyLarge)
        BotaoSecundario("Testar o alarme agora", aoTestar, icone = Icons.Rounded.Alarm)
    }
}

@Composable
internal fun ItemDePermissao(icone: ImageVector, titulo: String, liberada: Boolean, aoLiberar: () -> Unit) {
    val extras = TemaBulai.extras
    Row(Modifier.fillMaxWidth().heightIn(min = 56.dp), verticalAlignment = Alignment.CenterVertically) {
        Icon(if (liberada) Icons.Rounded.CheckCircle else Icons.Rounded.WarningAmber, contentDescription = null, tint = if (liberada) extras.sucesso else extras.alerta)
        Spacer(Modifier.width(12.dp))
        Column(Modifier.weight(1f)) {
            Text(titulo, style = MaterialTheme.typography.bodyLarge)
            Text(if (liberada) "Liberado" else "Falta liberar", style = MaterialTheme.typography.bodySmall, color = if (liberada) extras.sucesso else extras.alerta)
        }
        if (!liberada) {
            TextButton(onClick = aoLiberar, modifier = Modifier.heightIn(min = 48.dp)) { Text("Liberar", style = MaterialTheme.typography.labelLarge) }
        }
    }
}
