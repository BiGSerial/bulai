package br.com.vixforge.bulai.ui.perfil

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
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.selection.selectable
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Add
import androidx.compose.material.icons.rounded.AllInclusive
import androidx.compose.material.icons.rounded.Check
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.Event
import androidx.compose.material.icons.rounded.ExpandLess
import androidx.compose.material.icons.rounded.ExpandMore
import androidx.compose.material.icons.rounded.NightsStay
import androidx.compose.material.icons.rounded.NotificationsActive
import androidx.compose.material.icons.rounded.Schedule
import androidx.compose.material.icons.rounded.WbSunny
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.DatePicker
import androidx.compose.material3.DatePickerDialog
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.ExposedDropdownMenuBox
import androidx.compose.material3.ExposedDropdownMenuDefaults
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.MenuAnchorType
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.RadioButton
import androidx.compose.material3.Surface
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
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import br.com.vixforge.bulai.dados.api.AtalhosDePosologia
import br.com.vixforge.bulai.dados.api.DoseEHorarios
import br.com.vixforge.bulai.dados.api.MODO_FIXO
import br.com.vixforge.bulai.dados.api.MODO_INTERVALO
import br.com.vixforge.bulai.dados.api.ReceitaDoPerfil
import br.com.vixforge.bulai.ui.componentes.BotaoPrincipal
import br.com.vixforge.bulai.ui.componentes.Campo
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import java.time.Instant
import java.time.LocalDate
import java.time.ZoneOffset
import java.time.format.DateTimeFormatter

/** Período do dia de um horário "HH:MM", com o ícone (a mesma divisão do site). */
fun periodoDoDia(horario: String): Pair<String, ImageVector> {
    val hora = horario.take(2).toIntOrNull() ?: return "Escolha a hora" to Icons.Rounded.Schedule
    return when (hora) {
        in 5..11 -> "Manhã" to Icons.Rounded.WbSunny
        in 12..17 -> "Tarde" to Icons.Rounded.WbSunny
        in 18..23 -> "Noite" to Icons.Rounded.NightsStay
        else -> "Madrugada" to Icons.Rounded.NightsStay
    }
}

private fun mesmoTexto(a: String, b: String) = a.trim().equals(b.trim(), ignoreCase = true)

/**
 * "Dose e horários" de um remédio, igual ao formulário do site e pensado para o celular, em três passos:
 * 1. Quanto tomar (atalhos de dose), 2. Quando tomar (atalhos grandes, como "2 vezes ao dia", ou o texto da receita) e
 * 3. Horários do dia (cada um com Manhã/Tarde/Noite, tocando para mudar). Datas, receita e observações ficam recolhidas.
 * [aoSugerir] pergunta ao servidor o que muda com o "quando tomar" (com `atalho`, os horários são refeitos do zero).
 */
@OptIn(ExperimentalMaterial3Api::class, ExperimentalLayoutApi::class)
@Composable
fun EditorDePosologia(
    nome: String,
    inicial: DoseEHorarios,
    receitas: List<ReceitaDoPerfil>,
    atalhos: AtalhosDePosologia,
    salvando: Boolean,
    erro: String?,
    aoSugerir: suspend (String, DoseEHorarios, Boolean) -> DoseEHorarios?,
    aoSalvar: (DoseEHorarios) -> Unit,
    aoFechar: () -> Unit,
) {
    var posologia by remember(inicial) { mutableStateOf(inicial) }
    var frequenciaDigitada by remember(inicial) { mutableStateOf<String?>(null) }
    var horarioEditado by remember { mutableStateOf<Int?>(null) }
    var novoHorario by remember { mutableStateOf(false) }
    var data by remember { mutableStateOf<String?>(null) }
    val atalhoEscolhido = atalhos.frequencias.firstOrNull { mesmoTexto(it, posologia.frequency) }
    var outroJeito by remember(inicial) { mutableStateOf(atalhoEscolhido == null && inicial.frequency.isNotBlank()) }
    var detalhes by remember { mutableStateOf(false) }
    val escopo = rememberCoroutineScope()

    // "Quando tomar" escrito: espera a pessoa parar de digitar e aplica a sugestão (horários, intervalo, só quando precisar).
    LaunchedEffect(frequenciaDigitada) {
        val texto = frequenciaDigitada ?: return@LaunchedEffect
        delay(600)
        aoSugerir(texto, posologia, false)?.let { posologia = it }
    }
    val escolherAtalho = { texto: String ->
        outroJeito = false
        posologia = posologia.copy(frequency = texto)
        escopo.launch { aoSugerir(texto, posologia, true)?.let { posologia = it } }
        Unit
    }

    ModalBottomSheet(onDismissRequest = { if (!salvando) aoFechar() }, sheetState = rememberModalBottomSheetState(skipPartiallyExpanded = true)) {
        Column(
            Modifier.fillMaxWidth().verticalScroll(rememberScrollState()).padding(horizontal = 20.dp).padding(bottom = 28.dp).imePadding().animateContentSize(),
            verticalArrangement = Arrangement.spacedBy(20.dp),
        ) {
            Column {
                Text("Dose e horários", style = MaterialTheme.typography.titleLarge, color = MaterialTheme.colorScheme.primary)
                Text(nome, style = MaterialTheme.typography.bodyLarge, color = MaterialTheme.colorScheme.onSurfaceVariant)
            }

            // 1. Quanto tomar
            Passo(1, "Quanto tomar") {
                if (atalhos.doses.isNotEmpty()) {
                    FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                        atalhos.doses.forEach { dose ->
                            ChipDeEscolha(dose, mesmoTexto(dose, posologia.dosage)) { posologia = posologia.copy(dosage = dose) }
                        }
                    }
                }
                Campo(posologia.dosage, { posologia = posologia.copy(dosage = it.take(120)) }, "Ou escreva (ex.: 1 comprimido de 500 mg)")
            }

            // 2. Quando tomar
            Passo(2, "Quando tomar") {
                atalhos.frequencias.chunked(2).forEach { linha ->
                    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        linha.forEach { atalho -> CartaoDeAtalho(atalho, atalho == atalhoEscolhido, Modifier.weight(1f)) { escolherAtalho(atalho) } }
                        if (linha.size == 1) Spacer(Modifier.weight(1f))
                    }
                }
                if (!outroJeito) {
                    TextButton(onClick = { outroJeito = true }, modifier = Modifier.heightIn(min = 48.dp)) {
                        Text("Outro jeito? Escreva como está na receita", style = MaterialTheme.typography.labelLarge)
                    }
                }
                AnimatedVisibility(outroJeito, enter = expandVertically() + fadeIn(), exit = shrinkVertically() + fadeOut()) {
                    Campo(
                        posologia.frequency,
                        {
                            posologia = posologia.copy(frequency = it.take(120))
                            frequenciaDigitada = it
                        },
                        "Como está na receita",
                        ajuda = "Ex.: pela manhã e à noite. Os horários são sugeridos a partir disto.",
                    )
                }
            }

            // 3. Horários do dia
            Passo(3, "Horários do dia") {
                if (posologia.seNecessario) {
                    Surface(shape = MaterialTheme.shapes.large, color = MaterialTheme.colorScheme.surfaceVariant.copy(alpha = 0.5f)) {
                        Text(
                            "Sem horário fixo: toma só quando precisar. Para ter horários, escolha outra opção em “Quando tomar”.",
                            style = MaterialTheme.typography.bodyLarge,
                            modifier = Modifier.padding(14.dp),
                        )
                    }
                } else {
                    val dinamico = posologia.intervaloEmHoras != null && posologia.modo == MODO_INTERVALO
                    posologia.intervaloEmHoras?.let { horas ->
                        OpcaoDeModo("Contar a partir de quando eu tomar (recomendado)", "A cada $horas h depois da hora em que você marcar “Tomei”.", posologia.modo == MODO_INTERVALO) {
                            posologia = posologia.copy(modo = MODO_INTERVALO)
                        }
                        OpcaoDeModo("Horários fixos todos os dias", "Sempre nos mesmos horários, mesmo se atrasar uma dose.", posologia.modo == MODO_FIXO) {
                            posologia = posologia.copy(modo = MODO_FIXO)
                        }
                    }
                    if (dinamico) {
                        LinhaDeHorario(posologia.times.firstOrNull().orEmpty(), "Primeira dose do dia", { horarioEditado = 0; novoHorario = posologia.times.isEmpty() }, null)
                        Text("As próximas doses vêm a cada ${posologia.intervaloEmHoras} h a partir da última que você marcar.", style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
                    } else {
                        posologia.times.forEachIndexed { indice, horario ->
                            LinhaDeHorario(horario, null, { horarioEditado = indice }) {
                                posologia = posologia.copy(times = posologia.times.filterIndexed { i, _ -> i != indice })
                            }
                        }
                        if (posologia.times.isEmpty()) {
                            Text("Nenhum horário ainda. Escolha em “Quando tomar” ou adicione.", style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
                        }
                        if (posologia.times.size < 12) {
                            OutlinedButton(
                                onClick = { novoHorario = true },
                                modifier = Modifier.fillMaxWidth().heightIn(min = 56.dp),
                                shape = MaterialTheme.shapes.large,
                                border = BorderStroke(2.dp, MaterialTheme.colorScheme.primary.copy(alpha = 0.45f)),
                            ) {
                                Icon(Icons.Rounded.Add, contentDescription = null)
                                Spacer(Modifier.width(8.dp))
                                Text("Adicionar horário", style = MaterialTheme.typography.labelLarge)
                            }
                        }
                    }
                    LinhaComChave(Icons.Rounded.NotificationsActive, "Lembrar nos horários", "Alarme no celular na hora da dose", posologia.lembretes) { posologia = posologia.copy(lembretes = it) }
                }
            }

            // 4. Até quando: todo remédio tem prazo, uma data ou "Uso contínuo" (o servidor confere).
            Passo(4, "Até quando?") {
                LinhaComChave(Icons.Rounded.AllInclusive, "Uso contínuo", "Sem data para parar (pressão, diabetes, colesterol…)", posologia.continuo) {
                    posologia = posologia.copy(continuo = it, fim = if (it) null else posologia.fim)
                }
                AnimatedVisibility(!posologia.continuo, enter = expandVertically() + fadeIn(), exit = shrinkVertically() + fadeOut()) {
                    Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
                        val inicio = posologia.inicio?.let { runCatching { LocalDate.parse(it) }.getOrNull() } ?: LocalDate.now()
                        FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                            atalhos.duracoes.forEach { dias ->
                                val ultimo = inicio.plusDays(dias - 1L).toString()
                                ChipDeEscolha("$dias dias", posologia.fim == ultimo) { posologia = posologia.copy(fim = ultimo, continuo = false) }
                            }
                        }
                        CampoDeData("Ou escolha o último dia", posologia.fim, { data = "fim" }, { posologia = posologia.copy(fim = null) }, Modifier.fillMaxWidth())
                    }
                }
            }

            // Mais detalhes (recolhido)
            Surface(shape = MaterialTheme.shapes.large, border = BorderStroke(1.dp, MaterialTheme.colorScheme.outlineVariant)) {
                Column {
                    Row(
                        Modifier.fillMaxWidth().clickable(onClickLabel = if (detalhes) "Recolher" else "Abrir") { detalhes = !detalhes }.heightIn(min = 64.dp).padding(horizontal = 16.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Icon(Icons.Rounded.Event, contentDescription = null, tint = MaterialTheme.colorScheme.primary)
                        Spacer(Modifier.width(12.dp))
                        Column(Modifier.weight(1f)) {
                            Text("Mais detalhes", style = MaterialTheme.typography.titleMedium)
                            Text(if (receitas.isNotEmpty()) "Início, receita e observações" else "Início e observações", style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
                        }
                        Icon(if (detalhes) Icons.Rounded.ExpandLess else Icons.Rounded.ExpandMore, contentDescription = null)
                    }
                    AnimatedVisibility(detalhes) {
                        Column(Modifier.padding(start = 16.dp, end = 16.dp, bottom = 16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                            CampoDeData("Começou em", posologia.inicio, { data = "inicio" }, { posologia = posologia.copy(inicio = null) }, Modifier.fillMaxWidth())
                            if (receitas.isNotEmpty()) {
                                EscolhaDeReceita(receitas, posologia.receita) { posologia = posologia.copy(receita = it) }
                            }
                            Campo(posologia.notes, { posologia = posologia.copy(notes = it.take(255)) }, "Observações (ex.: em jejum)")
                        }
                    }
                }
            }

            erro?.let { Text(it, color = MaterialTheme.colorScheme.error, style = MaterialTheme.typography.bodyLarge) }
            Column {
                BotaoPrincipal("Salvar", { aoSalvar(posologia) }, carregando = salvando)
                TextButton(onClick = aoFechar, enabled = !salvando, modifier = Modifier.align(Alignment.CenterHorizontally).heightIn(min = 48.dp)) { Text("Cancelar") }
            }
        }
    }

    if (novoHorario || horarioEditado != null) {
        // Horário novo: sugere 4 h depois do último (ou 08:00), para a pessoa só ajustar.
        val ultimo = posologia.times.lastOrNull()?.take(2)?.toIntOrNull()
        val atual = horarioEditado?.let { posologia.times.getOrNull(it) }?.takeIf { it.length == 5 }
            ?: ultimo?.let { "%02d:00".format((it + 4) % 24) } ?: "08:00"
        val relogio = rememberTimePickerState(atual.take(2).toInt(), atual.takeLast(2).toInt(), is24Hour = true)
        AlertDialog(
            onDismissRequest = { novoHorario = false; horarioEditado = null },
            title = { Text(if (horarioEditado == null || novoHorario) "Novo horário" else "Mudar horário") },
            text = { TimePicker(relogio) },
            confirmButton = {
                TextButton(onClick = {
                    val texto = "%02d:%02d".format(relogio.hour, relogio.minute)
                    val lista = posologia.times.toMutableList()
                    val indice = horarioEditado
                    if (indice != null && indice < lista.size) lista[indice] = texto else lista.add(texto)
                    // Em ordem do dia, para ficar fácil de conferir (no modo "a partir de quando tomar" só vale o primeiro).
                    posologia = posologia.copy(times = if (posologia.modo == MODO_INTERVALO && posologia.intervaloEmHoras != null) lista else lista.distinct().sorted())
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
                    posologia = if (qual == "inicio") posologia.copy(inicio = escolhida) else posologia.copy(fim = escolhida, continuo = false)
                    data = null
                }) { Text("OK") }
            },
            dismissButton = { TextButton(onClick = { data = null }) { Text("Cancelar") } },
        ) { DatePicker(calendario) }
    }
}

/** Um passo numerado do formulário ("1 Quanto tomar"). */
@Composable
private fun Passo(numero: Int, titulo: String, conteudo: @Composable () -> Unit) {
    Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically, modifier = Modifier.semantics(mergeDescendants = true) { heading() }) {
            Surface(shape = CircleShape, color = MaterialTheme.colorScheme.primary, contentColor = MaterialTheme.colorScheme.onPrimary, modifier = Modifier.size(28.dp)) {
                Box(contentAlignment = Alignment.Center) { Text("$numero", style = MaterialTheme.typography.labelLarge, fontWeight = FontWeight.Bold) }
            }
            Spacer(Modifier.width(10.dp))
            Text(titulo, style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.SemiBold)
        }
        conteudo()
    }
}

@Composable
private fun ChipDeEscolha(texto: String, escolhido: Boolean, aoEscolher: () -> Unit) {
    Surface(
        onClick = aoEscolher,
        shape = CircleShape,
        color = if (escolhido) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.surface,
        contentColor = if (escolhido) MaterialTheme.colorScheme.onPrimary else MaterialTheme.colorScheme.primary,
        border = if (escolhido) null else BorderStroke(1.dp, MaterialTheme.colorScheme.primary.copy(alpha = 0.35f)),
        modifier = Modifier.heightIn(min = 48.dp).selectable(escolhido, role = Role.RadioButton, onClick = aoEscolher),
    ) {
        Box(Modifier.padding(horizontal = 16.dp, vertical = 12.dp), contentAlignment = Alignment.Center) {
            Text(texto, style = MaterialTheme.typography.bodyLarge, fontWeight = FontWeight.SemiBold)
        }
    }
}

/** Atalho grande de "quando tomar" (2 por linha). */
@Composable
private fun CartaoDeAtalho(texto: String, escolhido: Boolean, modifier: Modifier = Modifier, aoEscolher: () -> Unit) {
    Surface(
        onClick = aoEscolher,
        shape = MaterialTheme.shapes.large,
        color = if (escolhido) MaterialTheme.colorScheme.primaryContainer else MaterialTheme.colorScheme.surface,
        contentColor = if (escolhido) MaterialTheme.colorScheme.onPrimaryContainer else MaterialTheme.colorScheme.onSurface,
        border = BorderStroke(2.dp, if (escolhido) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.outlineVariant),
        modifier = modifier.heightIn(min = 60.dp).selectable(escolhido, role = Role.RadioButton, onClick = aoEscolher),
    ) {
        Row(Modifier.padding(horizontal = 10.dp, vertical = 10.dp), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.Center) {
            if (escolhido) {
                Icon(Icons.Rounded.Check, contentDescription = null, modifier = Modifier.size(20.dp))
                Spacer(Modifier.width(4.dp))
            }
            Text(texto, style = MaterialTheme.typography.bodyLarge, fontWeight = FontWeight.SemiBold, textAlign = TextAlign.Center)
        }
    }
}

/** Um horário: ícone e nome do período, a hora grande (toque para mudar) e tirar. */
@Composable
private fun LinhaDeHorario(horario: String, titulo: String?, aoMudar: () -> Unit, aoTirar: (() -> Unit)?) {
    val (periodo, icone) = periodoDoDia(horario)
    Surface(
        onClick = aoMudar,
        shape = MaterialTheme.shapes.large,
        color = MaterialTheme.colorScheme.surface,
        border = BorderStroke(1.dp, MaterialTheme.colorScheme.outlineVariant),
        modifier = Modifier.fillMaxWidth(),
    ) {
        Row(Modifier.heightIn(min = 64.dp).padding(start = 14.dp, end = 4.dp), verticalAlignment = Alignment.CenterVertically) {
            Surface(shape = CircleShape, color = MaterialTheme.colorScheme.primaryContainer, contentColor = MaterialTheme.colorScheme.primary, modifier = Modifier.size(40.dp)) {
                Box(contentAlignment = Alignment.Center) { Icon(icone, contentDescription = null) }
            }
            Spacer(Modifier.width(12.dp))
            Column(Modifier.weight(1f)) {
                Text(titulo ?: periodo, style = MaterialTheme.typography.bodyLarge, fontWeight = FontWeight.SemiBold)
                Text(if (titulo != null) periodo else "Toque para mudar", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
            }
            Text(horario.ifBlank { "--:--" }, style = MaterialTheme.typography.headlineSmall, fontWeight = FontWeight.Bold, color = MaterialTheme.colorScheme.primary)
            if (aoTirar != null) {
                IconButton(onClick = aoTirar, modifier = Modifier.size(48.dp)) {
                    Icon(Icons.Rounded.Close, contentDescription = "Tirar o horário $horario", tint = MaterialTheme.colorScheme.error)
                }
            } else {
                Spacer(Modifier.width(12.dp))
            }
        }
    }
}

@Composable
private fun LinhaComChave(icone: ImageVector, titulo: String, apoio: String, ligado: Boolean, aoMudar: (Boolean) -> Unit) {
    Surface(shape = MaterialTheme.shapes.large, border = BorderStroke(1.dp, MaterialTheme.colorScheme.outlineVariant)) {
        Row(
            Modifier.fillMaxWidth().selectable(ligado, role = Role.Switch) { aoMudar(!ligado) }.heightIn(min = 64.dp).padding(horizontal = 14.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Icon(icone, contentDescription = null, tint = MaterialTheme.colorScheme.primary)
            Spacer(Modifier.width(12.dp))
            Column(Modifier.weight(1f)) {
                Text(titulo, style = MaterialTheme.typography.bodyLarge, fontWeight = FontWeight.SemiBold)
                Text(apoio, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
            }
            Switch(ligado, onCheckedChange = null)
        }
    }
}

@Composable
private fun OpcaoDeModo(titulo: String, apoio: String, escolhido: Boolean, aoEscolher: () -> Unit) {
    Surface(
        shape = MaterialTheme.shapes.large,
        color = if (escolhido) MaterialTheme.colorScheme.primaryContainer.copy(alpha = 0.6f) else MaterialTheme.colorScheme.surface,
        border = BorderStroke(2.dp, if (escolhido) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.outlineVariant),
    ) {
        Row(Modifier.fillMaxWidth().selectable(escolhido, role = Role.RadioButton, onClick = aoEscolher).padding(12.dp), verticalAlignment = Alignment.CenterVertically) {
            RadioButton(escolhido, onClick = null)
            Spacer(Modifier.width(10.dp))
            Column {
                Text(titulo, style = MaterialTheme.typography.bodyLarge, fontWeight = FontWeight.SemiBold)
                Text(apoio, style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
            }
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
