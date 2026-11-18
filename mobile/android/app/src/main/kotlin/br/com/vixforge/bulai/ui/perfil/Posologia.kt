package br.com.vixforge.bulai.ui.perfil

import androidx.compose.animation.AnimatedVisibility
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.selection.selectable
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Add
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.Event
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.DatePicker
import androidx.compose.material3.DatePickerDialog
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.ExposedDropdownMenuBox
import androidx.compose.material3.ExposedDropdownMenuDefaults
import androidx.compose.material3.Icon
import androidx.compose.material3.InputChip
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.MenuAnchorType
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.RadioButton
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TimePicker
import androidx.compose.material3.rememberDatePickerState
import androidx.compose.material3.rememberModalBottomSheetState
import androidx.compose.material3.rememberTimePickerState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.unit.dp
import br.com.vixforge.bulai.dados.api.MODO_FIXO
import br.com.vixforge.bulai.dados.api.MODO_INTERVALO
import br.com.vixforge.bulai.dados.api.DoseEHorarios
import br.com.vixforge.bulai.dados.api.ReceitaDoPerfil
import br.com.vixforge.bulai.ui.componentes.BotaoPrincipal
import br.com.vixforge.bulai.ui.componentes.Campo
import kotlinx.coroutines.delay
import java.time.Instant
import java.time.LocalDate
import java.time.ZoneOffset
import java.time.format.DateTimeFormatter

/**
 * "Dose e horários" de um remédio, igual ao formulário do site: dose, "quando tomar" (que sugere horários, como "de 8 em 8
 * horas"), só quando precisar, horários (relógio do sistema), contar a partir de quando tomar ou horários fixos, início e
 * fim, receita, observações e lembretes. [aoSugerir] pergunta ao servidor o que muda com o "quando tomar" digitado.
 */
@OptIn(ExperimentalMaterial3Api::class, ExperimentalLayoutApi::class)
@Composable
fun EditorDePosologia(
    nome: String,
    inicial: DoseEHorarios,
    receitas: List<ReceitaDoPerfil>,
    salvando: Boolean,
    erro: String?,
    aoSugerir: suspend (String, DoseEHorarios) -> DoseEHorarios?,
    aoSalvar: (DoseEHorarios) -> Unit,
    aoFechar: () -> Unit,
) {
    var posologia by remember(inicial) { mutableStateOf(inicial) }
    var frequenciaDigitada by remember(inicial) { mutableStateOf<String?>(null) }
    var horarioEditado by remember { mutableStateOf<Int?>(null) }
    var novoHorario by remember { mutableStateOf(false) }
    var data by remember { mutableStateOf<String?>(null) }

    // "Quando tomar" mudou: espera a pessoa parar de digitar e aplica a sugestão (horários, intervalo, só quando precisar).
    LaunchedEffect(frequenciaDigitada) {
        val texto = frequenciaDigitada ?: return@LaunchedEffect
        delay(600)
        aoSugerir(texto, posologia)?.let { posologia = it }
    }

    ModalBottomSheet(onDismissRequest = { if (!salvando) aoFechar() }, sheetState = rememberModalBottomSheetState(skipPartiallyExpanded = true)) {
        Column(
            Modifier.fillMaxWidth().verticalScroll(rememberScrollState()).padding(horizontal = 20.dp).padding(bottom = 28.dp).imePadding(),
            verticalArrangement = Arrangement.spacedBy(14.dp),
        ) {
            Text("Dose e horários", style = MaterialTheme.typography.titleLarge, color = MaterialTheme.colorScheme.primary)
            Text(nome, style = MaterialTheme.typography.titleMedium)
            Campo(posologia.dosage, { posologia = posologia.copy(dosage = it.take(120)) }, "Dose (ex.: 1 comprimido)")
            Campo(
                posologia.frequency,
                {
                    posologia = posologia.copy(frequency = it.take(120))
                    frequenciaDigitada = it
                },
                "Quando tomar (ex.: de 8 em 8 horas)",
                ajuda = "Os horários são sugeridos a partir do que você escrever.",
            )
            LinhaComChave("Só quando precisar", "Sem horário fixo (ex.: se tiver dor)", posologia.seNecessario) {
                posologia = posologia.copy(seNecessario = it)
            }
            AnimatedVisibility(!posologia.seNecessario) {
                Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
                    Text("Horários", style = MaterialTheme.typography.titleMedium)
                    FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                        posologia.times.forEachIndexed { indice, horario ->
                            InputChip(
                                selected = false,
                                onClick = { horarioEditado = indice },
                                label = { Text(horario.ifBlank { "--:--" }, style = MaterialTheme.typography.titleMedium) },
                                trailingIcon = {
                                    Icon(
                                        Icons.Rounded.Close,
                                        contentDescription = "Tirar o horário $horario",
                                        modifier = Modifier.clickable { posologia = posologia.copy(times = posologia.times.filterIndexed { i, _ -> i != indice }) },
                                    )
                                },
                                modifier = Modifier.heightIn(min = 48.dp),
                            )
                        }
                        OutlinedButton(onClick = { novoHorario = true }, modifier = Modifier.heightIn(min = 48.dp)) {
                            Icon(Icons.Rounded.Add, contentDescription = null)
                            Spacer(Modifier.width(6.dp))
                            Text("Horário")
                        }
                    }
                    posologia.intervaloEmHoras?.let { horas ->
                        Column(Modifier.fillMaxWidth()) {
                            OpcaoDeModo("Contar a partir de quando tomar", "A próxima dose é $horas h depois da hora em que você marcar \"Tomei\".", posologia.modo == MODO_INTERVALO) {
                                posologia = posologia.copy(modo = MODO_INTERVALO)
                            }
                            OpcaoDeModo("Horários fixos todos os dias", "Os horários acima, sempre iguais.", posologia.modo == MODO_FIXO) {
                                posologia = posologia.copy(modo = MODO_FIXO)
                            }
                        }
                    }
                }
            }
            Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                CampoDeData("Início", posologia.inicio, { data = "inicio" }, { posologia = posologia.copy(inicio = null) }, Modifier.weight(1f))
                CampoDeData("Fim", posologia.fim, { data = "fim" }, { posologia = posologia.copy(fim = null) }, Modifier.weight(1f))
            }
            if (receitas.isNotEmpty()) {
                EscolhaDeReceita(receitas, posologia.receita) { posologia = posologia.copy(receita = it) }
            }
            Campo(posologia.notes, { posologia = posologia.copy(notes = it.take(255)) }, "Observações (ex.: em jejum)")
            LinhaComChave("Lembrar nos horários", "Alarme no celular e aviso", posologia.lembretes) { posologia = posologia.copy(lembretes = it) }
            erro?.let { Text(it, color = MaterialTheme.colorScheme.error, style = MaterialTheme.typography.bodyLarge) }
            BotaoPrincipal("Salvar", { aoSalvar(posologia) }, carregando = salvando)
            TextButton(onClick = aoFechar, enabled = !salvando, modifier = Modifier.align(Alignment.CenterHorizontally).heightIn(min = 48.dp)) { Text("Cancelar") }
        }
    }

    if (novoHorario || horarioEditado != null) {
        val atual = horarioEditado?.let { posologia.times.getOrNull(it) }?.takeIf { it.length == 5 } ?: "08:00"
        val relogio = rememberTimePickerState(atual.take(2).toInt(), atual.takeLast(2).toInt(), is24Hour = true)
        AlertDialog(
            onDismissRequest = { novoHorario = false; horarioEditado = null },
            title = { Text(if (novoHorario) "Novo horário" else "Mudar horário") },
            text = { TimePicker(relogio) },
            confirmButton = {
                TextButton(onClick = {
                    val texto = "%02d:%02d".format(relogio.hour, relogio.minute)
                    val lista = posologia.times.toMutableList()
                    horarioEditado?.let { lista[it] = texto } ?: lista.add(texto)
                    posologia = posologia.copy(times = lista)
                    novoHorario = false
                    horarioEditado = null
                }) { Text("OK") }
            },
            dismissButton = { TextButton(onClick = { novoHorario = false; horarioEditado = null }) { Text("Cancelar") } },
        )
    }

    data?.let { qual ->
        val atual = if (qual == "inicio") posologia.inicio else posologia.fim
        val calendario = rememberDatePickerState(initialSelectedDateMillis = atual?.let { runCatching { LocalDate.parse(it).atStartOfDay().toInstant(ZoneOffset.UTC).toEpochMilli() }.getOrNull() })
        DatePickerDialog(
            onDismissRequest = { data = null },
            confirmButton = {
                TextButton(onClick = {
                    val escolhida = calendario.selectedDateMillis?.let { Instant.ofEpochMilli(it).atZone(ZoneOffset.UTC).toLocalDate().toString() }
                    posologia = if (qual == "inicio") posologia.copy(inicio = escolhida) else posologia.copy(fim = escolhida)
                    data = null
                }) { Text("OK") }
            },
            dismissButton = { TextButton(onClick = { data = null }) { Text("Cancelar") } },
        ) { DatePicker(calendario) }
    }
}

@Composable
private fun LinhaComChave(titulo: String, apoio: String, ligado: Boolean, aoMudar: (Boolean) -> Unit) {
    Row(
        Modifier.fillMaxWidth().selectable(ligado, role = Role.Switch) { aoMudar(!ligado) }.heightIn(min = 56.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Column(Modifier.weight(1f)) {
            Text(titulo, style = MaterialTheme.typography.titleMedium)
            Text(apoio, style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
        }
        Switch(ligado, onCheckedChange = null)
    }
}

@Composable
private fun OpcaoDeModo(titulo: String, apoio: String, escolhido: Boolean, aoEscolher: () -> Unit) {
    Row(Modifier.fillMaxWidth().selectable(escolhido, role = Role.RadioButton, onClick = aoEscolher).padding(vertical = 6.dp), verticalAlignment = Alignment.CenterVertically) {
        RadioButton(escolhido, onClick = null)
        Spacer(Modifier.width(10.dp))
        Column {
            Text(titulo, style = MaterialTheme.typography.bodyLarge)
            Text(apoio, style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
        }
    }
}

private val DIA_MES_ANO: DateTimeFormatter = DateTimeFormatter.ofPattern("dd/MM/yyyy")

@Composable
private fun CampoDeData(rotulo: String, iso: String?, aoAbrir: () -> Unit, aoLimpar: () -> Unit, modifier: Modifier = Modifier) {
    OutlinedTextField(
        value = iso?.let { runCatching { LocalDate.parse(it).format(DIA_MES_ANO) }.getOrDefault(it) } ?: "",
        onValueChange = {},
        readOnly = true,
        label = { Text(rotulo) },
        placeholder = { Text("—") },
        trailingIcon = {
            if (iso != null) {
                Icon(Icons.Rounded.Close, contentDescription = "Limpar $rotulo", modifier = Modifier.clickable(onClick = aoLimpar))
            } else {
                Icon(Icons.Rounded.Event, contentDescription = null)
            }
        },
        modifier = modifier.clickable(onClick = aoAbrir),
        enabled = false,
        colors = androidx.compose.material3.OutlinedTextFieldDefaults.colors(
            disabledTextColor = MaterialTheme.colorScheme.onSurface,
            disabledLabelColor = MaterialTheme.colorScheme.onSurfaceVariant,
            disabledBorderColor = MaterialTheme.colorScheme.outline,
            disabledTrailingIconColor = MaterialTheme.colorScheme.onSurfaceVariant,
            disabledPlaceholderColor = MaterialTheme.colorScheme.onSurfaceVariant,
        ),
    )
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun EscolhaDeReceita(receitas: List<ReceitaDoPerfil>, escolhida: Long?, aoEscolher: (Long?) -> Unit) {
    var aberto by remember { mutableStateOf(false) }
    val nome = { receita: ReceitaDoPerfil -> listOfNotNull(receita.prescriber, receita.data).joinToString(" · ") }
    ExposedDropdownMenuBox(aberto, { aberto = it }) {
        OutlinedTextField(
            value = receitas.firstOrNull { it.id == escolhida }?.let(nome) ?: "Nenhuma",
            onValueChange = {},
            readOnly = true,
            label = { Text("Receita") },
            trailingIcon = { ExposedDropdownMenuDefaults.TrailingIcon(aberto) },
            modifier = Modifier.fillMaxWidth().menuAnchor(MenuAnchorType.PrimaryNotEditable),
        )
        ExposedDropdownMenu(aberto, { aberto = false }) {
            DropdownMenuItem(text = { Text("Nenhuma") }, onClick = { aoEscolher(null); aberto = false })
            receitas.forEach { receita -> DropdownMenuItem(text = { Text(nome(receita)) }, onClick = { aoEscolher(receita.id); aberto = false }) }
        }
    }
}
