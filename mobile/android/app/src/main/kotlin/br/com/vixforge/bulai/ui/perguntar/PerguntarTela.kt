package br.com.vixforge.bulai.ui.perguntar

import androidx.compose.animation.AnimatedVisibility
import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.text.BasicTextField
import androidx.compose.runtime.Stable
import androidx.compose.runtime.mutableLongStateOf
import androidx.compose.runtime.mutableStateMapOf
import androidx.compose.runtime.snapshotFlow
import androidx.compose.ui.focus.onFocusChanged
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.geometry.Rect
import androidx.compose.ui.graphics.SolidColor
import androidx.compose.ui.layout.boundsInRoot
import androidx.compose.ui.layout.onGloballyPositioned
import androidx.compose.ui.layout.positionInRoot
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.semantics.clearAndSetSemantics
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.unit.IntOffset
import androidx.compose.foundation.layout.offset
import androidx.compose.ui.util.lerp
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.withTimeoutOrNull
import kotlin.math.roundToInt
import androidx.compose.animation.core.Animatable
import androidx.compose.animation.core.FastOutLinearInEasing
import androidx.compose.animation.core.FastOutSlowInEasing
import androidx.compose.animation.core.LinearOutSlowInEasing
import androidx.compose.animation.core.tween
import androidx.compose.animation.fadeOut
import androidx.compose.animation.scaleIn
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.rounded.Check
import androidx.compose.ui.graphics.TransformOrigin
import androidx.compose.ui.graphics.graphicsLayer
import kotlin.math.abs
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
import androidx.compose.runtime.mutableIntStateOf
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
fun PerguntarTela(preenchimento: PaddingValues, remedioInicial: Long? = null, aoAbrirBula: (Long) -> Unit = {}) {
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
    // Voo da pergunta: sai da caixa e pousa no balão dela (que fica escondido até o pouso).
    val voo = remember { VooDaPergunta() }
    var ultimaVista by remember { mutableLongStateOf(ultima?.id ?: -1L) }
    LaunchedEffect(ultima?.id) {
        val nova = ultima?.takeIf { it.esperando && it.id > ultimaVista } ?: return@LaunchedEffect
        ultimaVista = nova.id
        voo.preparar(nova.id, nova.pergunta)
        // Leva a conversa ao balão novo de uma vez, para o voo já ter onde pousar.
        lista.scrollToItem(lista.layoutInfo.totalItemsCount.coerceAtLeast(1) - 1)
        voo.voar(nova.id, nova.pergunta)
    }
    val respostaAtual = ultima?.resposta
    val aviso = respostaAtual?.alert?.warning?.takeIf { respostaAtual.uuid != alergiaAvisada }

    val acoes = AcoesDaResposta(
        sugerir = vm::sugerir,
        corrigir = vm::corrigir,
        remover = vm::remover,
        trocarRemedio = { corrigindo = true },
        abrirBula = aoAbrirBula,
    )

    Box(Modifier.fillMaxSize().onGloballyPositioned { voo.raiz = it.positionInRoot() }) {
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
                    PerguntaEnviada(troca, voo)
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
            modifier = Modifier.onGloballyPositioned { voo.origem = it.boundsInRoot() },
        )
    }
    BalaoEmVoo(voo)
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
            onDismissRequest = { alergiaAvisada = respostaAtual.uuid },
            icon = { Icon(Icons.Rounded.WarningAmber, contentDescription = null, tint = MaterialTheme.colorScheme.error) },
            title = { Text("Atenção à sua alergia") },
            text = { Text(aviso, style = MaterialTheme.typography.bodyLarge) },
            confirmButton = { TextButton(onClick = { alergiaAvisada = respostaAtual.uuid }) { Text("Entendi") } },
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

/**
 * Voo da pergunta enviada: um balão "fantasma" sai do lugar e do tamanho da caixa de texto ([origem]) e pousa no balão
 * da pergunta na conversa ([alvos]), que fica escondido até o pouso. O alvo é lido a cada quadro porque a conversa rola
 * enquanto isso. Igual ao PWA (resources/js/animacoes.js).
 */
@Stable
class VooDaPergunta {
    var raiz by mutableStateOf(Offset.Zero)
    var origem by mutableStateOf<Rect?>(null)
    val alvos = mutableStateMapOf<Long, Rect>()
    var trocaId by mutableStateOf<Long?>(null)
        private set
    var texto by mutableStateOf("")
        private set
    val progresso = Animatable(0f)

    /** Esconde o balão novo (e o "Enviada") desde já, antes de a conversa rolar até ele. */
    fun preparar(id: Long, pergunta: String) {
        if (origem != null) {
            texto = pergunta
            trocaId = id
        }
    }

    suspend fun voar(id: Long, pergunta: String) {
        if (origem == null) {
            return
        }
        texto = pergunta
        trocaId = id
        progresso.snapTo(0f)
        // O balão pode ainda não estar na tela (a conversa rola até ele); espera um pouco por ele.
        withTimeoutOrNull(800) { snapshotFlow { alvos[id] }.first { it != null } }
        if (alvos[id] != null) {
            progresso.animateTo(1f, tween(520, easing = FastOutSlowInEasing))
        }
        trocaId = null
    }

    fun escondido(id: Long) = trocaId == id
}

@Composable
private fun BalaoEmVoo(voo: VooDaPergunta) {
    val id = voo.trocaId ?: return
    val de = voo.origem ?: return
    val para = voo.alvos[id] ?: return
    val p = voo.progresso.value
    val densidade = LocalDensity.current
    val largura = with(densidade) { lerp(de.width, para.width, p).toDp() }
    Surface(
        shape = FormaDaPessoa,
        color = MaterialTheme.colorScheme.primary,
        contentColor = MaterialTheme.colorScheme.onPrimary,
        shadowElevation = 6.dp,
        modifier = Modifier
            .offset { IntOffset((lerp(de.left, para.left, p) - voo.raiz.x).roundToInt(), (lerp(de.top, para.top, p) - voo.raiz.y).roundToInt()) }
            .width(largura)
            .graphicsLayer { alpha = (0.35f + p * 1.6f).coerceAtMost(1f) }
            .clearAndSetSemantics {},
    ) {
        Text(voo.texto, style = MaterialTheme.typography.bodyLarge, modifier = Modifier.padding(horizontal = 18.dp, vertical = 12.dp))
    }
}

/** A pergunta na conversa; a recém-enviada fica escondida até o voo pousar e depois mostra "Enviada". */
@Composable
private fun PerguntaEnviada(troca: Troca, voo: VooDaPergunta) {
    Column(Modifier.fillMaxWidth(), horizontalAlignment = Alignment.End) {
        BalaoDaPessoa(
            troca.pergunta,
            modificadorDoBalao = Modifier
                .onGloballyPositioned { voo.alvos[troca.id] = it.boundsInRoot() }
                .graphicsLayer { alpha = if (voo.escondido(troca.id)) 0f else 1f },
        )
        AnimatedVisibility(troca.esperando && !voo.escondido(troca.id), enter = fadeIn(tween(250)), exit = fadeOut()) {
            Row(Modifier.padding(top = 4.dp, end = 4.dp), verticalAlignment = Alignment.CenterVertically) {
                Icon(Icons.Rounded.Check, contentDescription = null, modifier = Modifier.size(16.dp), tint = MaterialTheme.colorScheme.onSurfaceVariant)
                Spacer(Modifier.width(4.dp))
                Text("Enviada", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
            }
        }
    }
}

/** Caixa da pergunta: uma caixa só, com o texto, o microfone e o botão de enviar juntos. */
@Composable
private fun CaixaDaPergunta(
    texto: String,
    aviso: String?,
    maximo: Int,
    enviando: Boolean,
    aoEscrever: (String) -> Unit,
    aoEnviar: () -> Unit,
    aoDitar: (String) -> Unit,
    modifier: Modifier = Modifier,
) {
    val ditar = rememberDitado(aoDitar)
    var focada by remember { mutableStateOf(false) }
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
            if (texto.length > maximo * 0.8) {
                Text("${texto.length}/$maximo", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant, modifier = Modifier.padding(horizontal = 8.dp))
            }
            Surface(
                shape = RoundedCornerShape(28.dp),
                color = MaterialTheme.colorScheme.surface,
                border = BorderStroke(if (focada) 2.dp else 1.dp, if (focada) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.outline),
                modifier = modifier.fillMaxWidth(),
            ) {
                Row(Modifier.padding(start = 18.dp, end = 6.dp, top = 6.dp, bottom = 6.dp), verticalAlignment = Alignment.Bottom) {
                    BasicTextField(
                        value = texto,
                        onValueChange = aoEscrever,
                        modifier = Modifier.weight(1f).padding(vertical = 10.dp).onFocusChanged { focada = it.isFocused }.semantics { contentDescription = "Sua dúvida sobre um remédio" },
                        textStyle = MaterialTheme.typography.bodyLarge.copy(color = MaterialTheme.colorScheme.onSurface),
                        cursorBrush = SolidColor(MaterialTheme.colorScheme.primary),
                        maxLines = 5,
                        keyboardOptions = KeyboardOptions(capitalization = KeyboardCapitalization.Sentences, imeAction = ImeAction.Send),
                        keyboardActions = KeyboardActions(onSend = { aoEnviar() }),
                        decorationBox = { campo ->
                            Box {
                                if (texto.isEmpty()) {
                                    Text("Sua dúvida sobre um remédio", style = MaterialTheme.typography.bodyLarge, color = MaterialTheme.colorScheme.onSurfaceVariant)
                                }
                                campo()
                            }
                        },
                    )
                    if (ditar != null) {
                        IconButton(onClick = ditar, enabled = !enviando, modifier = Modifier.size(44.dp)) {
                            Icon(Icons.Rounded.Mic, contentDescription = "Falar a pergunta", tint = MaterialTheme.colorScheme.primary)
                        }
                    }
                    Spacer(Modifier.width(4.dp))
                    FilledIconButton(onClick = aoEnviar, enabled = !enviando && texto.isNotBlank(), modifier = Modifier.size(44.dp)) {
                        Icon(Icons.AutoMirrored.Rounded.Send, contentDescription = "Perguntar", modifier = Modifier.size(22.dp))
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
    aoUsarNome: ((String) -> Unit)? = null,
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
            // Fora do catálogo: o remédio entra pelo nome digitado (a equipe confere depois).
            if (aoUsarNome != null && termo.trim().length >= 3 && !buscando && resultados != null) {
                androidx.compose.material3.OutlinedButton(onClick = { aoUsarNome(termo.trim()) }, modifier = Modifier.fillMaxWidth().heightIn(min = 52.dp)) {
                    Text("Não achei: usar “${termo.trim()}” pelo nome", style = MaterialTheme.typography.labelLarge)
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
