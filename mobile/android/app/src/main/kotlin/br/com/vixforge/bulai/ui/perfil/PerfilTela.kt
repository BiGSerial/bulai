package br.com.vixforge.bulai.ui.perfil

import androidx.compose.animation.AnimatedVisibility
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.material3.FilledTonalButton
import androidx.compose.material.icons.rounded.Medication
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
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
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.MenuBook
import androidx.compose.material.icons.rounded.Add
import androidx.compose.material.icons.rounded.DeleteOutline
import androidx.compose.material.icons.rounded.HealthAndSafety
import androidx.compose.material.icons.rounded.Schedule
import androidx.compose.material.icons.rounded.WarningAmber
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.PrimaryTabRow
import androidx.compose.material3.Surface
import androidx.compose.material3.Tab
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.pulltorefresh.PullToRefreshBox
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import br.com.vixforge.bulai.dados.api.PedidoDeReceitaDoPerfil
import br.com.vixforge.bulai.dados.api.Perfil
import br.com.vixforge.bulai.dados.api.RemedioDoPerfil
import br.com.vixforge.bulai.ui.base.telaViewModel
import br.com.vixforge.bulai.ui.componentes.Bloco
import br.com.vixforge.bulai.ui.componentes.BotaoPrincipal
import br.com.vixforge.bulai.ui.componentes.BotaoSecundario
import br.com.vixforge.bulai.ui.componentes.Campo
import br.com.vixforge.bulai.ui.componentes.Cartao
import br.com.vixforge.bulai.ui.componentes.FaixaDeErro
import br.com.vixforge.bulai.ui.componentes.LinhaEsqueleto
import br.com.vixforge.bulai.ui.componentes.TelaEsqueleto
import br.com.vixforge.bulai.ui.perguntar.BuscaDeRemedio
import br.com.vixforge.bulai.ui.tema.TemaBulai

private val LARGURA = Modifier.widthIn(max = 640.dp)
private val ABAS = listOf("Remédios", "Receitas", "Alergias")

/**
 * "Meus remédios": autorizar os dados de saúde; remédios em uso (adicionar pelo catálogo ou pelo nome, "Dose e horários",
 * bula, tirar da lista) e encerrados; receitas; alergias; apagar os dados de saúde. [abaInicial]: 0, 1 ou 2.
 */
@Composable
fun PerfilTela(preenchimento: PaddingValues, recado: (String) -> Unit, aoAbrirBula: (Long) -> Unit, abaInicial: Int = 0) {
    val vm = telaViewModel { PerfilViewModel(it.perfil, it.perguntas) }
    val carga by vm.carga.collectAsStateWithLifecycle()
    val estado by vm.estado.collectAsStateWithLifecycle()
    var aba by rememberSaveable { mutableIntStateOf(abaInicial.coerceIn(0, 2)) }
    var buscar by rememberSaveable { mutableStateOf(false) }
    var tirar by remember { mutableStateOf<RemedioDoPerfil?>(null) }
    var apagarTudo by rememberSaveable { mutableStateOf(false) }
    LaunchedEffect(Unit) { vm.recados.collect(recado) }

    Column(Modifier.fillMaxSize().padding(top = preenchimento.calculateTopPadding())) {
        val dados = carga.dados
        if (dados?.consentimento == true) {
            PrimaryTabRow(selectedTabIndex = aba, containerColor = MaterialTheme.colorScheme.background) {
                ABAS.forEachIndexed { indice, titulo ->
                    val quantos = when (indice) {
                        0 -> dados.remedios.size
                        1 -> dados.receitas.size
                        else -> dados.alergias.size
                    }
                    Tab(selected = aba == indice, onClick = { aba = indice }, text = { Text(if (quantos > 0) "$titulo ($quantos)" else titulo, style = MaterialTheme.typography.titleSmall) }, modifier = Modifier.heightIn(min = 56.dp))
                }
            }
        }
        PullToRefreshBox(carga.atualizando && dados != null, vm::atualizar, Modifier.fillMaxSize()) {
            LazyColumn(
                Modifier.fillMaxSize(),
                contentPadding = PaddingValues(start = 20.dp, end = 20.dp, top = 16.dp, bottom = preenchimento.calculateBottomPadding() + 24.dp),
                verticalArrangement = Arrangement.spacedBy(12.dp),
                horizontalAlignment = Alignment.CenterHorizontally,
            ) {
                item(key = "erro") { FaixaDeErro(carga.erro, LARGURA, vm::atualizar) }
                when {
                    dados == null -> if (carga.esqueleto) {
                        item(key = "esqueleto") {
                            TelaEsqueleto(LARGURA) {
                                Bloco(0.5f, 22.dp)
                                repeat(4) { LinhaEsqueleto() }
                            }
                        }
                    }
                    !dados.consentimento -> item(key = "consentimento") { Consentimento(estado.ocupado, vm::consentir) }
                    aba == 0 -> remedios(dados, estado.ocupado, { buscar = true }, { vm.editar(it) }, aoAbrirBula) { tirar = it }
                    aba == 1 -> item(key = "receitas") { Receitas(dados, estado.ocupado, vm) }
                    else -> item(key = "alergias") { Alergias(dados, estado.ocupado, vm) }
                }
                if (dados?.consentimento == true) {
                    item(key = "apagar") {
                        Column(LARGURA.fillMaxWidth().padding(top = 16.dp), horizontalAlignment = Alignment.CenterHorizontally) {
                            Text("Seus dados de saúde ficam só na sua conta.", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                            TextButton(onClick = { apagarTudo = true }, modifier = Modifier.heightIn(min = 48.dp)) {
                                Text("Apagar meus dados de saúde", style = MaterialTheme.typography.labelLarge, color = MaterialTheme.colorScheme.error)
                            }
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
            aoEscolher = { buscar = false; vm.adicionar(it, null) },
            aoFechar = { buscar = false; vm.fecharBusca() },
            titulo = "Adicionar remédio",
            aoUsarNome = { nome -> buscar = false; vm.adicionar(null, nome) },
        )
    }
    estado.editando?.let { remedio ->
        EditorDePosologia(
            nome = remedio.name,
            inicial = remedio.posologia,
            receitas = carga.dados?.receitas.orEmpty(),
            salvando = estado.ocupado,
            erro = estado.erro,
            aoSugerir = vm::sugerir,
            aoSalvar = vm::salvar,
            aoFechar = { vm.editar(null) },
        )
    }
    tirar?.let { remedio ->
        AlertDialog(
            onDismissRequest = { tirar = null },
            title = { Text("Tirar ${remedio.name} da lista?") },
            text = { Text("Os horários e os alarmes deste remédio deixam de aparecer. As doses já anotadas saem junto.") },
            confirmButton = { TextButton(onClick = { vm.tirarRemedio(remedio); tirar = null }) { Text("Tirar", color = MaterialTheme.colorScheme.error) } },
            dismissButton = { TextButton(onClick = { tirar = null }) { Text("Cancelar") } },
        )
    }
    if (apagarTudo) {
        AlertDialog(
            onDismissRequest = { apagarTudo = false },
            icon = { Icon(Icons.Rounded.WarningAmber, contentDescription = null, tint = MaterialTheme.colorScheme.error) },
            title = { Text("Apagar seus dados de saúde?") },
            text = { Text("Remédios, horários, doses anotadas, receitas, alergias e perguntas serão apagados, e a autorização será revogada. Sua conta continua. Não dá para desfazer.") },
            confirmButton = { TextButton(onClick = { vm.apagarDadosDeSaude(); apagarTudo = false }) { Text("Apagar", color = MaterialTheme.colorScheme.error) } },
            dismissButton = { TextButton(onClick = { apagarTudo = false }) { Text("Cancelar") } },
        )
    }
}

@Composable
private fun Consentimento(ocupado: Boolean, aoAutorizar: () -> Unit) {
    Cartao(LARGURA) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Icon(Icons.Rounded.HealthAndSafety, contentDescription = null, tint = MaterialTheme.colorScheme.primary, modifier = Modifier.size(32.dp))
            Spacer(Modifier.width(12.dp))
            Text("Guardar seus dados de saúde", style = MaterialTheme.typography.titleLarge, color = MaterialTheme.colorScheme.primary, modifier = Modifier.semantics { heading() })
        }
        Text("Para lembrar os horários, avisar sobre alergias e interações e montar o resumo para o médico, o BULA-IA precisa guardar seus remédios, receitas e alergias.", style = MaterialTheme.typography.bodyLarge)
        Text("Esses dados ficam só na sua conta, não são vendidos nem compartilhados, e você pode apagá-los quando quiser (LGPD).", style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
        BotaoPrincipal("Autorizar e continuar", aoAutorizar, carregando = ocupado, icone = Icons.Rounded.HealthAndSafety)
    }
}

private fun androidx.compose.foundation.lazy.LazyListScope.remedios(
    dados: Perfil,
    ocupado: Boolean,
    aoAdicionar: () -> Unit,
    aoEditar: (RemedioDoPerfil) -> Unit,
    aoAbrirBula: (Long) -> Unit,
    aoTirar: (RemedioDoPerfil) -> Unit,
) {
    item(key = "adicionar") { BotaoPrincipal("Adicionar remédio", aoAdicionar, LARGURA, carregando = ocupado, icone = Icons.Rounded.Add) }
    if (dados.remedios.isEmpty()) {
        item(key = "vazio") {
            Text("Nenhum remédio em uso. Adicione pelo nome ou fotografe a receita no Início.", style = MaterialTheme.typography.bodyLarge, color = MaterialTheme.colorScheme.onSurfaceVariant, modifier = LARGURA.fillMaxWidth())
        }
    }
    items(dados.remedios, key = { "remedio-${it.id}" }) { remedio -> CartaoDoRemedio(remedio, { aoEditar(remedio) }, remedio.remedioDoCatalogo?.let { id -> { aoAbrirBula(id) } }, { aoTirar(remedio) }, LARGURA) }
    if (dados.encerrados.isNotEmpty()) {
        item(key = "encerrados") {
            Cartao(LARGURA) {
                Text("Tratamentos encerrados", style = MaterialTheme.typography.titleMedium)
                dados.encerrados.forEach { remedio ->
                    Text("${remedio.name}${remedio.fim?.let { " · terminou em $it" }.orEmpty()}", style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
                }
            }
        }
    }
}

/** Um remédio em uso, para o próprio paciente ou para quem cuida ([aoTirar] nulo esconde "Tirar da lista"). */
@OptIn(ExperimentalLayoutApi::class)
@Composable
fun CartaoDoRemedio(remedio: RemedioDoPerfil, aoEditar: (() -> Unit)?, aoAbrirBula: (() -> Unit)?, aoTirar: (() -> Unit)?, modifier: Modifier = Modifier) {
    val posologia = remedio.posologia
    Cartao(modifier) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Surface(shape = CircleShape, color = MaterialTheme.colorScheme.primaryContainer, modifier = Modifier.size(44.dp)) {
                Box(contentAlignment = Alignment.Center) { Icon(Icons.Rounded.Medication, contentDescription = null, tint = MaterialTheme.colorScheme.onPrimaryContainer) }
            }
            Spacer(Modifier.width(12.dp))
            Column(Modifier.weight(1f)) {
                Text(remedio.name, style = MaterialTheme.typography.titleMedium)
                val apoio = listOfNotNull(
                    remedio.dosage?.takeIf { it.isNotBlank() },
                    remedio.principioAtivo?.takeIf { it.isNotBlank() && !it.equals(remedio.name, ignoreCase = true) }?.lowercase(),
                )
                if (apoio.isNotEmpty()) {
                    Text(apoio.joinToString(" · "), style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
                }
            }
            if (aoTirar != null) {
                IconButton(onClick = aoTirar) { Icon(Icons.Rounded.DeleteOutline, contentDescription = "Tirar ${remedio.name} da lista", tint = MaterialTheme.colorScheme.onSurfaceVariant) }
            }
        }
        if (!remedio.noCatalogo) {
            Surface(color = MaterialTheme.colorScheme.secondaryContainer, shape = CircleShape) {
                Text("Fora do catálogo · nossa equipe vai conferir", style = MaterialTheme.typography.labelMedium, color = MaterialTheme.colorScheme.onSecondaryContainer, modifier = Modifier.padding(horizontal = 12.dp, vertical = 6.dp))
            }
        }
        // Quando tomar: os horários (ou o intervalo) e a nota, como o servidor resumiu.
        FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp), itemVerticalAlignment = Alignment.CenterVertically) {
            remedio.horarios.chips.forEach { Pilula(it, destaque = !posologia.seNecessario) }
            remedio.horarios.note?.let { Text(it, style = MaterialTheme.typography.bodyMedium, color = if (remedio.horarios.missing) TemaBulai.extras.alerta else MaterialTheme.colorScheme.onSurfaceVariant) }
        }
        val detalhes = listOfNotNull(
            remedio.fim?.let { "até $it" },
            remedio.prescritor?.let { "receita de $it" },
            remedio.notes?.takeIf { it.isNotBlank() },
            if (!remedio.lembretes) "sem lembretes" else null,
        )
        if (detalhes.isNotEmpty()) {
            Text(detalhes.joinToString(" · "), style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
        }
        if (aoEditar != null || aoAbrirBula != null) {
            Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                if (aoEditar != null) {
                    FilledTonalButton(
                        onClick = aoEditar,
                        modifier = Modifier.weight(1f).heightIn(min = 52.dp).semantics { contentDescription = "Dose e horários de ${remedio.name}" },
                        shape = MaterialTheme.shapes.medium,
                        contentPadding = PaddingValues(horizontal = 10.dp),
                        colors = androidx.compose.material3.ButtonDefaults.filledTonalButtonColors(containerColor = MaterialTheme.colorScheme.primaryContainer, contentColor = MaterialTheme.colorScheme.onPrimaryContainer),
                    ) {
                        Icon(Icons.Rounded.Schedule, contentDescription = null, modifier = Modifier.size(20.dp))
                        Spacer(Modifier.width(6.dp))
                        Text("Horários", maxLines = 1, style = MaterialTheme.typography.labelLarge)
                    }
                }
                if (aoAbrirBula != null) {
                    OutlinedButton(onClick = aoAbrirBula, modifier = Modifier.weight(1f).heightIn(min = 52.dp), shape = MaterialTheme.shapes.medium, contentPadding = PaddingValues(horizontal = 10.dp)) {
                        Icon(Icons.AutoMirrored.Rounded.MenuBook, contentDescription = null, modifier = Modifier.size(20.dp))
                        Spacer(Modifier.width(6.dp))
                        Text("Bula", maxLines = 1, style = MaterialTheme.typography.labelLarge)
                    }
                }
            }
        }
    }
}

@Composable
private fun Pilula(texto: String, destaque: Boolean = false) {
    Surface(
        shape = CircleShape,
        color = if (destaque) TemaBulai.extras.sucessoFundo else MaterialTheme.colorScheme.surfaceVariant,
        contentColor = if (destaque) TemaBulai.extras.sucesso else MaterialTheme.colorScheme.onSurfaceVariant,
    ) {
        Text(texto, style = MaterialTheme.typography.titleSmall, modifier = Modifier.padding(horizontal = 14.dp, vertical = 6.dp))
    }
}

@Composable
private fun Receitas(dados: Perfil, ocupado: Boolean, vm: PerfilViewModel) {
    var nova by rememberSaveable { mutableStateOf(false) }
    var prescritor by rememberSaveable { mutableStateOf("") }
    var registro by rememberSaveable { mutableStateOf("") }
    var data by rememberSaveable { mutableStateOf("") }
    var observacoes by rememberSaveable { mutableStateOf("") }
    Column(LARGURA.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(12.dp)) {
        Text("Receitas fotografadas entram aqui sozinhas. Você também pode registrar uma receita à mão.", style = MaterialTheme.typography.bodyLarge, color = MaterialTheme.colorScheme.onSurfaceVariant)
        if (!nova) {
            BotaoSecundario("Registrar receita", { nova = true }, Modifier.fillMaxWidth(), icone = Icons.Rounded.Add)
        }
        AnimatedVisibility(nova) {
            Cartao {
                Text("Nova receita", style = MaterialTheme.typography.titleMedium)
                Campo(prescritor, { prescritor = it.take(120) }, "Quem receitou", ajuda = "Ex.: Dra. Ana Souza")
                Campo(registro, { registro = it.take(40) }, "Registro (CRM, opcional)")
                Campo(data, { data = it.filter { c -> c.isDigit() || c == '/' }.take(10) }, "Data da receita (opcional)", ajuda = "dd/mm/aaaa", tipo = androidx.compose.ui.text.input.KeyboardType.Number)
                Campo(observacoes, { observacoes = it.take(500) }, "Observações (opcional)")
                BotaoPrincipal("Guardar receita", {
                    vm.adicionarReceita(PedidoDeReceitaDoPerfil(prescritor, registro.ifBlank { null }, data.ifBlank { null }, observacoes.ifBlank { null })) {
                        nova = false; prescritor = ""; registro = ""; data = ""; observacoes = ""
                    }
                }, carregando = ocupado, habilitado = prescritor.trim().length >= 3)
                TextButton(onClick = { nova = false }, modifier = Modifier.align(Alignment.CenterHorizontally)) { Text("Cancelar") }
            }
        }
        if (dados.receitas.isEmpty()) {
            Text("Nenhuma receita registrada.", style = MaterialTheme.typography.bodyLarge, color = MaterialTheme.colorScheme.onSurfaceVariant)
        }
        dados.receitas.forEach { receita ->
            Cartao {
                Row(verticalAlignment = Alignment.Top) {
                    Column(Modifier.weight(1f)) {
                        Text(receita.prescriber, style = MaterialTheme.typography.titleMedium)
                        Text(listOfNotNull(receita.registration, receita.data).joinToString(" · ").ifBlank { "Sem data" }, style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
                    }
                    IconButton(onClick = { vm.tirarReceita(receita.id) }) { Icon(Icons.Rounded.DeleteOutline, contentDescription = "Apagar a receita de ${receita.prescriber}", tint = MaterialTheme.colorScheme.onSurfaceVariant) }
                }
                if (receita.remedios.isNotEmpty()) {
                    Text(receita.remedios.joinToString(", "), style = MaterialTheme.typography.bodyLarge)
                }
                receita.notes?.let { Text(it, style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant) }
            }
        }
    }
}

@Composable
private fun Alergias(dados: Perfil, ocupado: Boolean, vm: PerfilViewModel) {
    var substancia by rememberSaveable { mutableStateOf("") }
    var reacao by rememberSaveable { mutableStateOf("") }
    Column(LARGURA.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(12.dp)) {
        Cartao {
            Text("Registrar alergia", style = MaterialTheme.typography.titleMedium)
            Text("O BULA-IA avisa quando um remédio que você perguntar, fotografar ou verificar tiver essa substância ou classe.", style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
            Campo(substancia, { substancia = it.take(120) }, "Substância ou classe", ajuda = "Ex.: dipirona, penicilinas")
            Campo(reacao, { reacao = it.take(255) }, "O que acontece (opcional)", ajuda = "Ex.: manchas na pele, falta de ar")
            BotaoPrincipal("Registrar alergia", { vm.adicionarAlergia(substancia, reacao) { substancia = ""; reacao = "" } }, carregando = ocupado, habilitado = substancia.trim().length >= 3)
        }
        if (dados.alergias.isEmpty()) {
            Text("Nenhuma alergia registrada.", style = MaterialTheme.typography.bodyLarge, color = MaterialTheme.colorScheme.onSurfaceVariant)
        }
        dados.alergias.forEach { alergia ->
            Cartao(cor = MaterialTheme.colorScheme.errorContainer.copy(alpha = 0.35f)) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Icon(Icons.Rounded.WarningAmber, contentDescription = null, tint = MaterialTheme.colorScheme.error)
                    Spacer(Modifier.width(12.dp))
                    Column(Modifier.weight(1f)) {
                        Text(alergia.substance.replaceFirstChar { it.uppercase() }, style = MaterialTheme.typography.titleMedium)
                        alergia.reaction?.let { Text(it, style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant) }
                    }
                    IconButton(onClick = { vm.tirarAlergia(alergia.id) }) { Icon(Icons.Rounded.DeleteOutline, contentDescription = "Apagar a alergia a ${alergia.substance}", tint = MaterialTheme.colorScheme.onSurfaceVariant) }
                }
            }
        }
    }
}
