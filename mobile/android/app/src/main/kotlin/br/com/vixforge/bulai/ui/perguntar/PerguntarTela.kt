package br.com.vixforge.bulai.ui.perguntar

import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.core.MutableTransitionState
import androidx.compose.animation.animateContentSize
import androidx.compose.animation.fadeIn
import androidx.compose.animation.slideInVertically
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.consumeWindowInsets
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.Send
import androidx.compose.material.icons.rounded.Mic
import androidx.compose.material.icons.rounded.Search
import androidx.compose.material.icons.rounded.WarningAmber
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.FilledIconButton
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.ListItem
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.rememberModalBottomSheetState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.focus.FocusRequester
import androidx.compose.ui.focus.focusRequester
import androidx.compose.ui.hapticfeedback.HapticFeedbackType
import androidx.compose.ui.platform.LocalHapticFeedback
import androidx.compose.ui.platform.LocalSoftwareKeyboardController
import androidx.compose.ui.semantics.LiveRegionMode
import androidx.compose.ui.semantics.liveRegion
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardCapitalization
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import br.com.vixforge.bulai.dados.api.Resposta
import br.com.vixforge.bulai.ui.base.telaViewModel
import br.com.vixforge.bulai.ui.componentes.BotaoSecundario
import br.com.vixforge.bulai.ui.sistema.rememberDitado
import br.com.vixforge.bulai.ui.sistema.rememberLeitor
import br.com.vixforge.bulai.ui.tema.TemaBulai

/**
 * Perguntar: conversa com o BULA-IA. A pessoa escreve ou dita a dúvida citando o remédio; a pergunta aparece na hora,
 * o "digitando" fica enquanto a resposta não chega, e a caixa de texto acompanha o teclado.
 */
@Composable
fun PerguntarTela(preenchimento: PaddingValues, remedioInicial: Long? = null) {
    val vm = telaViewModel { PerguntarViewModel(it.perguntas, remedioInicial) }
    val estado by vm.estado.collectAsStateWithLifecycle()
    val leitor = rememberLeitor()
    val lista = rememberLazyListState()
    var corrigindo by rememberSaveable { mutableStateOf(false) }
    var alergiaAvisada by rememberSaveable { mutableStateOf<String?>(null) }
    val vibrar = LocalHapticFeedback.current
    val teclado = LocalSoftwareKeyboardController.current

    val ultima = estado.trocas.lastOrNull()
    // A cada pergunta enviada ou resposta nova, a conversa rola até o começo do último balão.
    LaunchedEffect(estado.trocas.size, ultima?.resposta?.uuid, ultima?.erro) {
        if (estado.trocas.isNotEmpty()) {
            lista.animateScrollToItem(lista.layoutInfo.totalItemsCount.coerceAtLeast(1) - 1)
        }
        if (ultima?.resposta != null) {
            vibrar.performHapticFeedback(HapticFeedbackType.Confirm)
        }
    }
    val respostaAtual = ultima?.resposta
    val aviso = respostaAtual?.alert?.warning?.takeIf { respostaAtual.uuid != alergiaAvisada }

    val acoes = AcoesDaResposta(
        sugerir = vm::sugerir,
        corrigir = vm::corrigir,
        remover = vm::remover,
        trocarRemedio = { corrigindo = true },
    )

    Column(Modifier.fillMaxSize().padding(preenchimento).consumeWindowInsets(preenchimento).imePadding()) {
        LazyColumn(
            state = lista,
            modifier = Modifier.weight(1f).fillMaxWidth(),
            contentPadding = PaddingValues(start = 16.dp, end = 16.dp, top = 8.dp, bottom = 16.dp),
            verticalArrangement = Arrangement.spacedBy(14.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            if (estado.trocas.isEmpty()) {
                item(key = "boas-vindas") { BoasVindas(estado, vm::sugerir, LarguraDaConversa) }
                estado.inicio?.preview?.let { previa -> item(key = "previa") { PainelDoTratamento(previa, LarguraDaConversa) } }
            }
            items(estado.trocas, key = { it.id }) { troca ->
                Column(LarguraDaConversa.animateContentSize(), verticalArrangement = Arrangement.spacedBy(14.dp)) {
                    BalaoDaPessoa(troca.pergunta)
                    when {
                        troca.esperando -> Digitando()
                        troca.erro != null -> BalaoDoBulai(fundo = TemaBulai.extras.alertaFundo) {
                            Text(troca.erro, style = MaterialTheme.typography.bodyLarge, modifier = Modifier.semantics { liveRegion = LiveRegionMode.Polite })
                            if (troca === ultima) {
                                BotaoSecundario("Tentar de novo", vm::tentarDeNovo)
                            }
                        }
                        troca.resposta != null -> {
                            val aparecer = remember(troca.id) { MutableTransitionState(false).apply { targetState = true } }
                            AnimatedVisibility(aparecer, enter = fadeIn() + slideInVertically { it / 8 }) {
                                BalaoDaResposta(troca.resposta, leitor, if (troca === ultima) acoes else null)
                            }
                        }
                    }
                }
            }
            if (estado.trocas.isNotEmpty() && !estado.enviando) {
                item(key = "nova") {
                    TextButton(onClick = vm::novaConversa, modifier = LarguraDaConversa.fillMaxWidth().heightIn(min = 48.dp)) {
                        Text("Começar outra conversa", style = MaterialTheme.typography.labelLarge)
                    }
                }
            }
        }

        CaixaDaPergunta(
            texto = estado.texto,
            aviso = estado.aviso,
            maximo = estado.maximo,
            enviando = estado.enviando,
            aoEscrever = vm::escrever,
            aoEnviar = { teclado?.hide(); vm.enviar() },
            aoDitar = { falado -> vm.sugerir(falado) },
        )
    }

    if (corrigindo) {
        BuscaDeRemedio(
            buscando = estado.buscando,
            resultados = estado.resultados,
            aoBuscar = vm::buscar,
            aoEscolher = { id -> corrigindo = false; vm.corrigir(id) },
            aoFechar = { corrigindo = false; vm.fecharBusca() },
        )
    }

    if (aviso != null) {
        AlertDialog(
            onDismissRequest = { alergiaAvisada = respostaAtual?.uuid },
            icon = { Icon(Icons.Rounded.WarningAmber, contentDescription = null, tint = MaterialTheme.colorScheme.error) },
            title = { Text("Atenção à sua alergia") },
            text = { Text(aviso, style = MaterialTheme.typography.bodyLarge) },
            confirmButton = { TextButton(onClick = { alergiaAvisada = respostaAtual?.uuid }) { Text("Entendi") } },
        )
    }
}

@Composable
private fun BoasVindas(estado: EstadoDaConversa, aoSugerir: (String) -> Unit, modifier: Modifier = Modifier) {
    BalaoDoBulai(modifier) {
        Text("Oi! Sou o BULA-IA. Qual é a sua dúvida sobre um remédio?", style = MaterialTheme.typography.bodyLarge)
        Text("Escreva ou fale, citando o nome do remédio. Depois você pode continuar: “e com café?”, “e à noite?”.", style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
        val inicio = estado.inicio
        if (inicio != null && (inicio.emUso.isNotEmpty() || inicio.examples.isNotEmpty())) {
            FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                inicio.emUso.forEach { nome -> Pilula(nome, { aoSugerir(nome) }, destaque = true) }
                if (estado.texto.isBlank()) {
                    inicio.examples.forEach { exemplo -> Pilula(exemplo, { aoSugerir(exemplo) }) }
                }
            }
        }
    }
}

@Composable
private fun CaixaDaPergunta(
    texto: String,
    aviso: String?,
    maximo: Int,
    enviando: Boolean,
    aoEscrever: (String) -> Unit,
    aoEnviar: () -> Unit,
    aoDitar: (String) -> Unit,
) {
    val ditar = rememberDitado(aoDitar)
    Surface(color = MaterialTheme.colorScheme.surface, tonalElevation = 3.dp, shadowElevation = 6.dp) {
        Column(Modifier.fillMaxWidth().padding(horizontal = 12.dp, vertical = 10.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
            AnimatedVisibility(aviso != null) {
                Text(
                    aviso.orEmpty(),
                    style = MaterialTheme.typography.bodyMedium,
                    color = TemaBulai.extras.alerta,
                    modifier = Modifier.padding(horizontal = 4.dp).semantics { liveRegion = LiveRegionMode.Polite },
                )
            }
            Row(verticalAlignment = Alignment.Bottom) {
                OutlinedTextField(
                    value = texto,
                    onValueChange = aoEscrever,
                    modifier = Modifier.weight(1f),
                    placeholder = { Text("Sua dúvida sobre um remédio") },
                    textStyle = MaterialTheme.typography.bodyLarge,
                    maxLines = 5,
                    shape = MaterialTheme.shapes.large,
                    supportingText = if (texto.length > maximo * 0.8) {
                        { Text("${texto.length}/$maximo") }
                    } else {
                        null
                    },
                    keyboardOptions = KeyboardOptions(capitalization = KeyboardCapitalization.Sentences, imeAction = ImeAction.Send),
                    keyboardActions = KeyboardActions(onSend = { aoEnviar() }),
                )
                if (ditar != null) {
                    IconButton(onClick = ditar, modifier = Modifier.padding(start = 4.dp).size(56.dp), enabled = !enviando) {
                        Icon(Icons.Rounded.Mic, contentDescription = "Falar a pergunta", modifier = Modifier.size(30.dp), tint = MaterialTheme.colorScheme.primary)
                    }
                } else {
                    Spacer(Modifier.width(6.dp))
                }
                FilledIconButton(onClick = aoEnviar, enabled = !enviando && texto.isNotBlank(), modifier = Modifier.size(56.dp)) {
                    if (enviando) {
                        CircularProgressIndicator(Modifier.size(24.dp), strokeWidth = 3.dp, color = MaterialTheme.colorScheme.onPrimary)
                    } else {
                        Icon(Icons.AutoMirrored.Rounded.Send, contentDescription = "Perguntar")
                    }
                }
            }
        }
    }
}

/** "Qual remédio você quis dizer?": busca no catálogo para corrigir o remédio entendido. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun BuscaDeRemedio(
    buscando: Boolean,
    resultados: List<br.com.vixforge.bulai.dados.api.RemedioDoCatalogo>?,
    aoBuscar: (String) -> Unit,
    aoEscolher: (Long) -> Unit,
    aoFechar: () -> Unit,
    titulo: String = "Qual remédio você quis dizer?",
    extra: (@Composable () -> Unit)? = null,
) {
    var termo by rememberSaveable { mutableStateOf("") }
    val foco = remember { FocusRequester() }
    ModalBottomSheet(onDismissRequest = aoFechar, sheetState = rememberModalBottomSheetState(skipPartiallyExpanded = true)) {
        Column(Modifier.fillMaxWidth().padding(horizontal = 20.dp).padding(bottom = 24.dp).imePadding(), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            Text(titulo, style = MaterialTheme.typography.titleLarge)
            extra?.invoke()
            OutlinedTextField(
                value = termo,
                onValueChange = { termo = it; aoBuscar(it) },
                modifier = Modifier.fillMaxWidth().focusRequester(foco),
                placeholder = { Text("Digite o nome (pelo menos 3 letras)") },
                leadingIcon = { Icon(Icons.Rounded.Search, contentDescription = null) },
                trailingIcon = if (buscando) {
                    { CircularProgressIndicator(Modifier.size(22.dp), strokeWidth = 2.dp) }
                } else {
                    null
                },
                singleLine = true,
                textStyle = MaterialTheme.typography.bodyLarge,
                shape = MaterialTheme.shapes.medium,
            )
            LaunchedEffect(Unit) { foco.requestFocus() }
            when {
                resultados == null -> Unit
                resultados.isEmpty() -> Text("Nenhum remédio encontrado para “$termo”.", style = MaterialTheme.typography.bodyLarge)
                else -> Column {
                    resultados.forEach { remedio ->
                        Surface(onClick = { aoEscolher(remedio.id) }, shape = MaterialTheme.shapes.medium) {
                            ListItem(
                                headlineContent = { Text(remedio.name, style = MaterialTheme.typography.bodyLarge) },
                                supportingContent = { Text(listOfNotNull(remedio.ingredients.takeIf { it.isNotBlank() }, remedio.holder).joinToString(" · "), style = MaterialTheme.typography.bodyMedium) },
                            )
                        }
                    }
                }
            }
            Box(Modifier.heightIn(min = 8.dp))
        }
    }
}

/** Status de uma pergunta do histórico em palavras. */
fun rotuloDoStatus(status: String): String = when (status) {
    Resposta.RESPONDIDA -> "Respondida"
    Resposta.PRECISA_DE_INFORMACAO -> "Pediu mais informações"
    Resposta.BLOQUEADA -> "Não pude ajudar"
    Resposta.SEM_REMEDIO -> "Remédio não reconhecido"
    "no_leaflet", "not_identified" -> "Sem bula cadastrada"
    "no_evidence" -> "Sem orientação clara na bula"
    "guidance" -> "Orientação de uso do sistema"
    "unavailable" -> "Não consegui responder"
    else -> "Respondida"
}
