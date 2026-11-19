package br.com.vixforge.bulai.ui.perguntar

import br.com.vixforge.bulai.ui.componentes.nomeAmigavel
import android.content.Intent
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.core.RepeatMode
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.rememberInfiniteTransition
import androidx.compose.animation.core.tween
import androidx.compose.animation.expandVertically
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.shrinkVertically
import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.verticalScroll
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.requiredSize
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.KeyboardArrowRight
import androidx.compose.material.icons.automirrored.rounded.VolumeUp
import androidx.compose.material.icons.rounded.Call
import androidx.compose.material.icons.rounded.Favorite
import androidx.compose.material.icons.rounded.Groups
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.ExpandLess
import androidx.compose.material.icons.rounded.ExpandMore
import androidx.compose.material.icons.automirrored.rounded.MenuBook
import androidx.compose.material.icons.rounded.StopCircle
import androidx.compose.material.icons.rounded.WarningAmber
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.LocalContentColor
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.drawBehind
import androidx.compose.ui.geometry.Size
import androidx.compose.ui.draw.scale
import androidx.compose.ui.graphics.Color
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.offset
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.ui.semantics.clearAndSetSemantics
import androidx.compose.ui.graphics.luminance
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.painterResource
import androidx.compose.ui.semantics.LiveRegionMode
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.liveRegion
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.AnnotatedString
import androidx.compose.ui.text.SpanStyle
import androidx.compose.ui.text.buildAnnotatedString
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.withStyle
import androidx.compose.ui.unit.dp
import androidx.core.net.toUri
import br.com.vixforge.bulai.R
import br.com.vixforge.bulai.dados.api.Fonte
import br.com.vixforge.bulai.dados.api.NoTratamento
import br.com.vixforge.bulai.dados.api.Resposta
import br.com.vixforge.bulai.dados.api.GrupoDeAjuda
import br.com.vixforge.bulai.dados.api.Trecho
import br.com.vixforge.bulai.ui.sistema.Leitor
import br.com.vixforge.bulai.ui.tema.Marca
import br.com.vixforge.bulai.ui.tema.TemaBulai

private val FormaDoBulai = RoundedCornerShape(topStart = 6.dp, topEnd = 22.dp, bottomEnd = 22.dp, bottomStart = 22.dp)
internal val FormaDaPessoa = RoundedCornerShape(topStart = 22.dp, topEnd = 22.dp, bottomEnd = 6.dp, bottomStart = 22.dp)

/** O que a pessoa perguntou, à direita. */
@Composable
fun BalaoDaPessoa(texto: String, modifier: Modifier = Modifier, modificadorDoBalao: Modifier = Modifier) {
    Row(modifier.fillMaxWidth().padding(start = 40.dp), horizontalArrangement = Arrangement.End) {
        Surface(shape = FormaDaPessoa, color = MaterialTheme.colorScheme.primary, contentColor = MaterialTheme.colorScheme.onPrimary, modifier = modificadorDoBalao) {
            Text(texto, style = MaterialTheme.typography.bodyLarge, modifier = Modifier.padding(horizontal = 18.dp, vertical = 12.dp).semantics { contentDescription = "Você perguntou: $texto" })
        }
    }
}

/** Símbolo do BULA-IA ao lado de cada fala dele. */
@Composable
fun AvatarDoBulai(modifier: Modifier = Modifier) {
    Box(
        modifier.size(38.dp).clip(CircleShape).background(Color.White).border(1.dp, MaterialTheme.colorScheme.outlineVariant, CircleShape),
        contentAlignment = Alignment.Center,
    ) {
        Image(painterResource(R.drawable.ic_launcher_frente), contentDescription = null, modifier = Modifier.fillMaxSize().scale(1.6f))
    }
}

/** Fala do BULA-IA, à esquerda, com o tom da situação (aviso em amarelo, alergia em vermelho). */
@Composable
fun BalaoDoBulai(
    modifier: Modifier = Modifier,
    fundo: Color = MaterialTheme.colorScheme.surface,
    borda: Color? = null,
    conteudo: @Composable ColumnScope.() -> Unit,
) {
    // O símbolo e o nome ficam numa linha acima da fala, não ao lado: em telas estreitas (360 dp, como o Galaxy S22) a
    // coluna do avatar tirava quase 50 dp do texto e dos botões de ligar.
    Column(modifier.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(6.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically, modifier = Modifier.clearAndSetSemantics { }) {
            AvatarDoBulai(Modifier.size(28.dp))
            Spacer(Modifier.width(8.dp))
            Text("BULA-IA", style = MaterialTheme.typography.labelMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
        }
        Surface(
            shape = FormaDoBulai,
            color = fundo,
            border = borda?.let { BorderStroke(2.dp, it) } ?: BorderStroke(1.dp, MaterialTheme.colorScheme.outlineVariant.copy(alpha = 0.6f)),
            modifier = Modifier.fillMaxWidth(),
        ) {
            Column(Modifier.padding(horizontal = 14.dp, vertical = 14.dp), verticalArrangement = Arrangement.spacedBy(12.dp), content = conteudo)
        }
    }
}

/** "Digitando…" enquanto o BULA-IA procura na bula. */
@Composable
fun Digitando(modifier: Modifier = Modifier) {
    val transicao = rememberInfiniteTransition(label = "digitando")
    Column(modifier.fillMaxWidth().semantics { contentDescription = "O BULA-IA está procurando na bula"; liveRegion = LiveRegionMode.Polite }, verticalArrangement = Arrangement.spacedBy(6.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            AvatarDoBulai(Modifier.size(28.dp))
            Spacer(Modifier.width(8.dp))
            Text("BULA-IA", style = MaterialTheme.typography.labelMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
        }
        Surface(shape = FormaDoBulai, color = MaterialTheme.colorScheme.surface, border = BorderStroke(1.dp, MaterialTheme.colorScheme.outlineVariant.copy(alpha = 0.6f))) {
            Row(Modifier.padding(horizontal = 18.dp, vertical = 18.dp), horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                repeat(3) { indice ->
                    val escala by transicao.animateFloat(0.6f, 1f, infiniteRepeatable(tween(450, delayMillis = indice * 150), RepeatMode.Reverse), label = "ponto$indice")
                    Box(Modifier.size(10.dp).scale(escala).clip(CircleShape).background(MaterialTheme.colorScheme.primary.copy(alpha = 0.55f)))
                }
            }
        }
    }
}

/**
 * Blocos de ajuda da resposta (os mesmos do site), cada um identificável pela situação: título, texto curto e os telefones
 * como botões de ligar grandes (abrem o discador). Tons: acolhimento (azul), urgência (vermelho), cuidado (neutro).
 */
@Composable
fun BlocosDeAjuda(grupos: List<GrupoDeAjuda>, modifier: Modifier = Modifier) {
    if (grupos.isEmpty()) {
        return
    }
    Column(modifier.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(10.dp)) {
        grupos.forEach { grupo -> BlocoDeAjuda(grupo) }
    }
}

/** Cores de um bloco de ajuda: fundo e borda do bloco, cor do tom (título, selo, botão cheio) e texto sobre ela. */
private data class CoresDaAjuda(val fundo: Color, val borda: Color, val tom: Color, val titulo: Color, val sobreOTom: Color, val botaoClaro: Color)

/**
 * As mesmas cores do site (x-consulta.ajuda): vermelho é perigo agora, verde-água é acolhimento e azul da marca é
 * acompanhamento. No tema escuro, fundos escuros tingidos e o tom mais claro.
 */
@Composable
private fun coresDaAjuda(tom: String?): CoresDaAjuda {
    val escuro = MaterialTheme.colorScheme.surface.luminance() < 0.5f
    return when (tom) {
        "acolhimento" -> if (escuro) {
            CoresDaAjuda(Color(0xFF0F2A2A), Color(0xFF1F5B57), Color(0xFF5EEAD4), Color(0xFF99F6E4), Color(0xFF042F2E), Color(0xFF132F31))
        } else {
            CoresDaAjuda(Color(0xFFF0FDFA), Color(0xFF99F6E4), Color(0xFF0F766E), Color(0xFF134E4A), Color.White, Color.White)
        }
        "cuidado" -> if (escuro) {
            CoresDaAjuda(Color(0xFF16283A), Color(0xFF2E4A63), Color(0xFF9DBCD4), Color(0xFFDDE8F1), Color(0xFF0E2A3F), Color(0xFF1C2B38))
        } else {
            CoresDaAjuda(Color(0xFFEEF3F7), Color(0xFFB3C7D7), Color(0xFF12344D), Color(0xFF0E2A3F), Color.White, Color.White)
        }
        else -> if (escuro) {
            CoresDaAjuda(Color(0xFF3A1714), Color(0xFF7A2A22), Color(0xFFFCA5A5), Color(0xFFFECACA), Color(0xFF450A0A), Color(0xFF2A1513))
        } else {
            CoresDaAjuda(Color(0xFFFEF2F2), Color(0xFFFECACA), Color(0xFFB91C1C), Color(0xFF991B1B), Color.White, Color.White)
        }
    }
}

@Composable
private fun BlocoDeAjuda(grupo: GrupoDeAjuda) {
    val context = LocalContext.current
    val cores = coresDaAjuda(grupo.tone)
    val icone = when (grupo.tone) {
        "acolhimento" -> Icons.Rounded.Favorite
        "cuidado" -> Icons.Rounded.Groups
        else -> Icons.Rounded.Call
    }
    Surface(Modifier.fillMaxWidth(), shape = MaterialTheme.shapes.large, color = cores.fundo, border = BorderStroke(1.dp, cores.borda)) {
        Column(Modifier.padding(12.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            Row(verticalAlignment = Alignment.Top, modifier = Modifier.semantics { heading() }) {
                Box(Modifier.size(32.dp).clip(CircleShape).background(cores.tom), contentAlignment = Alignment.Center) {
                    Icon(icone, contentDescription = null, tint = cores.sobreOTom, modifier = Modifier.size(18.dp))
                }
                Spacer(Modifier.width(10.dp))
                Text(grupo.title ?: "Precisa de ajuda agora? Toque para ligar.", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold, color = cores.titulo, modifier = Modifier.padding(top = 2.dp))
            }
            grupo.text?.let { Text(it, style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurface) }
            grupo.telefones.forEachIndexed { indice, telefone ->
                val primeiro = indice == 0
                Surface(
                    onClick = { context.startActivity(Intent(Intent.ACTION_DIAL, "tel:${telefone.tel}".toUri())) },
                    shape = MaterialTheme.shapes.medium,
                    color = if (primeiro) cores.tom else cores.botaoClaro,
                    contentColor = if (primeiro) cores.sobreOTom else cores.titulo,
                    border = if (primeiro) null else BorderStroke(1.dp, cores.borda),
                    shadowElevation = 1.dp,
                    modifier = Modifier.fillMaxWidth().heightIn(min = 64.dp).semantics(mergeDescendants = true) { contentDescription = "Ligar para ${telefone.name}, ${telefone.number}. ${telefone.detail}" },
                ) {
                    // Número em destaque numa linha só, e embaixo quem atende e quando ligar: dentro do balão o espaço é
                    // estreito, e "Ligar 136 · Disque Saúde" numa linha só quebrava no meio do nome.
                    Row(Modifier.padding(horizontal = 12.dp, vertical = 10.dp), verticalAlignment = Alignment.CenterVertically) {
                        Box(
                            Modifier.size(38.dp).clip(CircleShape).background(if (primeiro) Color.White.copy(alpha = 0.2f) else cores.tom),
                            contentAlignment = Alignment.Center,
                        ) { Icon(Icons.Rounded.Call, contentDescription = null, tint = cores.sobreOTom, modifier = Modifier.size(20.dp)) }
                        Spacer(Modifier.width(10.dp))
                        Column(Modifier.weight(1f)) {
                            Text("Ligar ${telefone.number}", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold, maxLines = 1)
                            Text(telefone.name, style = MaterialTheme.typography.labelLarge, fontWeight = FontWeight.SemiBold)
                            if (telefone.detail.isNotBlank()) {
                                Text(telefone.detail, style = MaterialTheme.typography.bodySmall, color = LocalContentColor.current.copy(alpha = 0.85f))
                            }
                        }
                    }
                }
            }
        }
    }
}

/** Botão em forma de pílula, alto o bastante para o dedo (sugestões, remédios, palpites). */
@Composable
fun Pilula(texto: String, aoClicar: () -> Unit, modifier: Modifier = Modifier, destaque: Boolean = false) {
    Surface(
        onClick = aoClicar,
        shape = CircleShape,
        color = if (destaque) MaterialTheme.colorScheme.primaryContainer else MaterialTheme.colorScheme.surface,
        contentColor = if (destaque) MaterialTheme.colorScheme.onPrimaryContainer else MaterialTheme.colorScheme.primary,
        border = BorderStroke(1.dp, MaterialTheme.colorScheme.primary.copy(alpha = 0.3f)),
        modifier = modifier.heightIn(min = 48.dp),
    ) {
        Box(Modifier.padding(horizontal = 16.dp, vertical = 12.dp), contentAlignment = Alignment.CenterStart) {
            Text(texto, style = MaterialTheme.typography.bodyLarge, fontWeight = FontWeight.Medium)
        }
    }
}

fun textoComDestaques(paragrafo: List<Trecho>): AnnotatedString = buildAnnotatedString {
    paragrafo.forEach { trecho ->
        if (trecho.bold) {
            withStyle(SpanStyle(fontWeight = FontWeight.Bold)) { append(trecho.text) }
        } else {
            append(trecho.text)
        }
    }
}

/** Cor da gravidade (DDInter): sempre junto com o texto, nunca só a cor. */
@Composable
private fun corDaGravidade(gravidade: String?): Color = when (gravidade) {
    "major" -> MaterialTheme.colorScheme.error
    "moderate", "duplicate" -> Marca.Laranja500
    "minor" -> Color(0xFFE3B008)
    else -> MaterialTheme.colorScheme.outline
}

@Composable
private fun SeloDeGravidade(gravidade: String?, texto: String?) {
    texto ?: return
    Row(verticalAlignment = Alignment.CenterVertically) {
        Box(Modifier.size(12.dp).clip(RoundedCornerShape(3.dp)).background(corDaGravidade(gravidade)))
        Spacer(Modifier.width(6.dp))
        Text(texto, style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.SemiBold)
    }
}

/** Ações da resposta mais recente (as antigas mostram só o texto). */
class AcoesDaResposta(
    val sugerir: (String) -> Unit,
    /** Resposta rápida: já envia o texto como a próxima mensagem. */
    val responder: (String) -> Unit,
    val corrigir: (Long) -> Unit,
    val remover: (Long) -> Unit,
    val trocarRemedio: () -> Unit,
    val abrirBula: (Long) -> Unit,
)

/**
 * Resposta completa do BULA-IA: a fala em parágrafos (com destaques), o alerta do motor estruturado, de onde veio, "Ouvir",
 * sugestões do próximo passo, correção do remédio entendido e, recolhidos, as fontes e o cruzamento com o tratamento.
 */
@Composable
fun BalaoDaResposta(resposta: Resposta, leitor: Leitor?, acoes: AcoesDaResposta?, modifier: Modifier = Modifier, aoAbrirBula: ((Long) -> Unit)? = null) {
    val extras = TemaBulai.extras
    val alerta = resposta.alert
    val (fundo, borda) = when {
        alerta?.alergia == true -> MaterialTheme.colorScheme.errorContainer to MaterialTheme.colorScheme.error
        alerta != null -> MaterialTheme.colorScheme.secondaryContainer to MaterialTheme.colorScheme.secondary
        resposta.tone == "danger" -> MaterialTheme.colorScheme.errorContainer to null
        resposta.tone == "warning" -> MaterialTheme.colorScheme.secondaryContainer to null
        else -> MaterialTheme.colorScheme.surface to null
    }
    BalaoDoBulai(modifier, fundo = fundo, borda = borda) {
        resposta.urgency?.let { urgencia ->
            Surface(shape = MaterialTheme.shapes.medium, color = MaterialTheme.colorScheme.errorContainer, border = BorderStroke(2.dp, MaterialTheme.colorScheme.error)) {
                Text(urgencia, style = MaterialTheme.typography.bodyLarge, fontWeight = FontWeight.SemiBold, color = MaterialTheme.colorScheme.onErrorContainer, modifier = Modifier.padding(12.dp).semantics { liveRegion = LiveRegionMode.Assertive })
            }
            // Com urgência, os telefones vêm logo abaixo do aviso, antes do resto da resposta.
            BlocosDeAjuda(resposta.ajuda)
        }
        // Recusa em negrito; na crise só a primeira frase (a recusa), o acolhimento e os contatos em texto normal.
        resposta.paragraphs.forEachIndexed { indice, paragrafo ->
            val negrito = resposta.status == Resposta.BLOQUEADA && (!resposta.crisis || indice == 0)
            Text(textoComDestaques(paragrafo), style = MaterialTheme.typography.bodyLarge, fontWeight = if (negrito) FontWeight.SemiBold else null)
        }

        alerta?.let {
            Column(
                Modifier.fillMaxWidth().clip(MaterialTheme.shapes.medium).background(MaterialTheme.colorScheme.surface.copy(alpha = 0.7f)).padding(12.dp),
                verticalArrangement = Arrangement.spacedBy(8.dp),
            ) {
                it.items.forEach { item ->
                    Column(verticalArrangement = Arrangement.spacedBy(2.dp)) {
                        Text(item.text, style = MaterialTheme.typography.bodyLarge, fontWeight = FontWeight.SemiBold)
                        if (item.severity != null && item.severity != "duplicate") {
                            SeloDeGravidade(item.severity, item.gravidade)
                        }
                    }
                }
            }
        }

        if (resposta.urgency == null) {
            BlocosDeAjuda(resposta.ajuda)
        }

        // "Baseado na bula…" fica dentro de "De onde veio esta resposta?"; no balão só a orientação de quando não houve resposta.
        resposta.source?.takeUnless { resposta.respondida }?.let { fonte ->
            Row(verticalAlignment = Alignment.Top) {
                Icon(Icons.AutoMirrored.Rounded.MenuBook, contentDescription = null, modifier = Modifier.size(18.dp).padding(top = 2.dp), tint = MaterialTheme.colorScheme.onSurfaceVariant)
                Spacer(Modifier.width(6.dp))
                Text(fonte, style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
            }
        }

        if (acoes != null) {
            AcoesDoBalao(resposta, acoes)
        } else if (resposta.status != Resposta.BLOQUEADA && resposta.status != Resposta.SEM_REMEDIO) {
            RemediosDaResposta(resposta, aoAbrirBula, null)
        }

        // Ações discretas numa linha só (ouvir e corrigir o remédio), para a resposta não virar uma pilha de botões.
        val podeOuvir = resposta.podeOuvir && leitor != null
        val podeCorrigir = acoes != null && resposta.medicines.isNotEmpty()
        if (podeOuvir || podeCorrigir) {
            FlowRow(horizontalArrangement = Arrangement.spacedBy(4.dp), modifier = Modifier.offset(x = (-8).dp)) {
                if (podeOuvir) {
                    val falando by leitor!!.falando
                    TextButton(onClick = { leitor.alternar(resposta.textoCorrido) }, modifier = Modifier.heightIn(min = 48.dp), contentPadding = PaddingValues(horizontal = 8.dp)) {
                        Icon(if (falando) Icons.Rounded.StopCircle else Icons.AutoMirrored.Rounded.VolumeUp, contentDescription = null)
                        Spacer(Modifier.width(6.dp))
                        Text(if (falando) "Parar" else "Ouvir", style = MaterialTheme.typography.labelLarge)
                    }
                }
                if (podeCorrigir) {
                    TextButton(onClick = acoes!!.trocarRemedio, modifier = Modifier.heightIn(min = 48.dp), contentPadding = PaddingValues(horizontal = 8.dp)) {
                        Text("Não é esse remédio?", style = MaterialTheme.typography.labelLarge)
                    }
                }
            }
        }

        if (resposta.sources.isNotEmpty()) {
            Fontes(resposta)
        }
    }
}

@Composable
private fun ColumnScope.AcoesDoBalao(resposta: Resposta, acoes: AcoesDaResposta) {
    if (resposta.respostasRapidas.isNotEmpty()) {
        Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
            resposta.respostasRapidas.forEach { opcao ->
                Surface(
                    onClick = { acoes.responder(opcao) },
                    shape = MaterialTheme.shapes.large,
                    color = MaterialTheme.colorScheme.surface,
                    contentColor = MaterialTheme.colorScheme.primary,
                    border = BorderStroke(1.dp, MaterialTheme.colorScheme.primary.copy(alpha = 0.35f)),
                    modifier = Modifier.fillMaxWidth().heightIn(min = 56.dp),
                ) {
                    Row(Modifier.padding(horizontal = 16.dp, vertical = 12.dp), verticalAlignment = Alignment.CenterVertically) {
                        Text(opcao, style = MaterialTheme.typography.bodyLarge, fontWeight = FontWeight.SemiBold, modifier = Modifier.weight(1f))
                        Icon(Icons.AutoMirrored.Rounded.KeyboardArrowRight, contentDescription = null)
                    }
                }
            }
        }
    }
    if (resposta.guesses.isNotEmpty()) {
        FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            resposta.guesses.forEach { Pilula(nomeAmigavel(it.name), { acoes.corrigir(it.id) }, destaque = true) }
        }
    }
    if (resposta.meusRemedios.isNotEmpty()) {
        Text("Ou toque em um dos seus remédios:", style = MaterialTheme.typography.bodyMedium)
        FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            resposta.meusRemedios.forEach { Pilula(nomeAmigavel(it.name), { acoes.corrigir(it.id) }) }
        }
    }
    if (resposta.outrosRemedios.isNotEmpty()) {
        FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            resposta.outrosRemedios.forEach { nome -> Pilula(nomeAmigavel(nome), { acoes.sugerir("Sobre $nome:") }) }
        }
    }
    if (resposta.suggestions.isNotEmpty()) {
        FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            resposta.suggestions.forEach { Pilula(it, { acoes.sugerir(it) }, destaque = true) }
        }
    }
    resposta.corrections.forEach { correcao ->
        Column(
            Modifier.fillMaxWidth().clip(MaterialTheme.shapes.medium).background(MaterialTheme.colorScheme.primaryContainer.copy(alpha = 0.6f)).padding(12.dp),
            verticalArrangement = Arrangement.spacedBy(8.dp),
        ) {
            Text("Entendemos “${correcao.escrito}” como ${correcao.name}. Não é isso?", style = MaterialTheme.typography.bodyLarge, fontWeight = FontWeight.SemiBold)
            FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Pilula("Escolher o certo", acoes.trocarRemedio)
                Pilula("Não era esse", { acoes.remover(correcao.id) })
            }
        }
    }
    RemediosDaResposta(resposta, acoes.abrirBula, acoes)
}

/**
 * "Falando de:": cada remédio leva à bula humanizada (com o ícone do livro quando há bula). Na resposta mais recente, também
 * dá para tirar um remédio entendido por engano e corrigir ("Não é esse remédio?").
 */
@Composable
private fun RemediosDaResposta(resposta: Resposta, aoAbrirBula: ((Long) -> Unit)?, acoes: AcoesDaResposta?) {
    if (resposta.medicines.isEmpty()) {
        return
    }
    FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
        resposta.medicines.forEach { remedio ->
            val podeTirar = acoes != null && resposta.medicines.size > 1
            Surface(shape = CircleShape, color = MaterialTheme.colorScheme.surface, border = BorderStroke(1.dp, MaterialTheme.colorScheme.primary.copy(alpha = 0.3f))) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Row(
                        Modifier.clip(CircleShape)
                            .clickable(enabled = aoAbrirBula != null, onClickLabel = if (remedio.temBula) "Ver a bula simples" else "Ver o remédio") { aoAbrirBula?.invoke(remedio.id) }
                            .heightIn(min = 48.dp).padding(start = 14.dp, end = if (podeTirar) 4.dp else 16.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        if (remedio.temBula) {
                            Icon(Icons.AutoMirrored.Rounded.MenuBook, contentDescription = null, modifier = Modifier.size(20.dp), tint = MaterialTheme.colorScheme.primary)
                            Spacer(Modifier.width(8.dp))
                        }
                        Text(nomeAmigavel(remedio.name), style = MaterialTheme.typography.bodyLarge, fontWeight = FontWeight.SemiBold, color = MaterialTheme.colorScheme.primary)
                    }
                    if (podeTirar) {
                        IconButton(onClick = { acoes.remover(remedio.id) }) { Icon(Icons.Rounded.Close, contentDescription = "Tirar ${remedio.name} da pergunta") }
                    }
                }
            }
        }
    }
}

/**
 * "De onde veio esta resposta?", recolhido: a bula, a parte e a página, em frases simples. O trecho original da bula fica
 * mais um toque abaixo, em letra legível e sem rolagem dentro da rolagem (o servidor já junta palavras quebradas do PDF).
 */
@Composable
private fun Fontes(resposta: Resposta) {
    var aberto by rememberSaveable(resposta.uuid) { mutableStateOf(false) }
    Column(Modifier.fillMaxWidth().clip(MaterialTheme.shapes.medium).background(MaterialTheme.colorScheme.surfaceVariant.copy(alpha = 0.5f))) {
        Row(
            Modifier.fillMaxWidth().clickable(onClickLabel = if (aberto) "Recolher" else "Abrir") { aberto = !aberto }.heightIn(min = 52.dp).padding(horizontal = 12.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Icon(Icons.AutoMirrored.Rounded.MenuBook, contentDescription = null, modifier = Modifier.size(20.dp), tint = MaterialTheme.colorScheme.primary)
            Spacer(Modifier.width(10.dp))
            Text("De onde veio esta resposta?", style = MaterialTheme.typography.labelLarge, color = MaterialTheme.colorScheme.primary, modifier = Modifier.weight(1f))
            Icon(if (aberto) Icons.Rounded.ExpandLess else Icons.Rounded.ExpandMore, contentDescription = null, tint = MaterialTheme.colorScheme.primary)
        }
        AnimatedVisibility(aberto, enter = expandVertically() + fadeIn(), exit = shrinkVertically() + fadeOut()) {
            Column(Modifier.padding(start = 12.dp, end = 12.dp, bottom = 12.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                resposta.sources.forEachIndexed { indice, fonte -> ItemDaFonte(fonte, resposta.uuid, indice) }
            }
        }
    }
}

/** Uma fonte em linguagem simples ("Da bula do Cozaar, parte Reações adversas, página 8") e o trecho ao tocar. */
@Composable
private fun ItemDaFonte(fonte: Fonte, chave: String?, indice: Int) {
    var aberto by rememberSaveable(chave, indice) { mutableStateOf(false) }
    val onde = listOfNotNull(fonte.section?.let { "Parte: $it" }, fonte.pagina?.let { "página $it" }).joinToString(", ")
    Surface(shape = MaterialTheme.shapes.medium, color = MaterialTheme.colorScheme.surface, modifier = Modifier.fillMaxWidth()) {
        Column(Modifier.padding(12.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
            Text(fonte.titulo, style = MaterialTheme.typography.titleSmall)
            if (onde.isNotEmpty()) {
                Text(onde, style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
            }
            fonte.processed?.let { Text("Bula de $it", style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant) }
            if (fonte.excerpt.isNotBlank()) {
                TextButton(onClick = { aberto = !aberto }, modifier = Modifier.heightIn(min = 48.dp).offset(x = (-8).dp), contentPadding = PaddingValues(horizontal = 8.dp)) {
                    Text(if (aberto) "Esconder o texto da bula" else "Ler o texto da bula", style = MaterialTheme.typography.labelLarge)
                    Icon(if (aberto) Icons.Rounded.ExpandLess else Icons.Rounded.ExpandMore, contentDescription = null)
                }
                AnimatedVisibility(aberto, enter = expandVertically() + fadeIn(), exit = shrinkVertically() + fadeOut()) {
                    val barra = MaterialTheme.colorScheme.primary.copy(alpha = 0.35f)
                    Text(
                        "“${fonte.excerpt}”",
                        style = MaterialTheme.typography.bodyLarge,
                        color = MaterialTheme.colorScheme.onSurface,
                        modifier = Modifier.drawBehind { drawRect(barra, size = Size(3.dp.toPx(), size.height)) }.padding(start = 14.dp),
                    )
                }
            }
        }
    }
}

/** "No seu tratamento": remédio em uso, alergias, interações com o que a pessoa já usa e o que não deu para verificar. */
@Composable
fun PainelDoTratamento(tratamento: NoTratamento, modifier: Modifier = Modifier, somenteRelevante: Boolean = false) {
    val relevante = !tratamento.consented || tratamento.emUso || tratamento.alergias.isNotEmpty() || tratamento.interactions.isNotEmpty() || tratamento.unresolved.isNotEmpty()
    if (somenteRelevante && !relevante) {
        return
    }
    val extras = TemaBulai.extras
    val (fundo, borda) = when {
        tratamento.alergias.isNotEmpty() || tratamento.maiorGravidade == "major" -> MaterialTheme.colorScheme.errorContainer to MaterialTheme.colorScheme.error
        tratamento.maiorGravidade == "moderate" || tratamento.interactions.any { it.severity == "duplicate" } -> MaterialTheme.colorScheme.secondaryContainer to MaterialTheme.colorScheme.secondary
        else -> MaterialTheme.colorScheme.surface to MaterialTheme.colorScheme.outlineVariant
    }
    Surface(modifier.fillMaxWidth(), shape = MaterialTheme.shapes.large, color = fundo, border = BorderStroke(1.dp, borda)) {
        Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                if (borda != MaterialTheme.colorScheme.outlineVariant) {
                    Icon(Icons.Rounded.WarningAmber, contentDescription = null, tint = borda)
                    Spacer(Modifier.width(8.dp))
                }
                Text("No seu tratamento", style = MaterialTheme.typography.titleMedium, modifier = Modifier.semantics { heading() })
            }
            if (!tratamento.consented) {
                Text("Para cruzar com o que você já usa e com suas alergias, registre seu perfil de saúde.", style = MaterialTheme.typography.bodyLarge)
                return@Column
            }
            when {
                tratamento.emUso -> {
                    val posologia = listOfNotNull(tratamento.posology?.dosage, tratamento.posology?.frequency).joinToString(", ")
                    val receita = tratamento.posology?.prescriber?.let { " · receita de $it" }.orEmpty()
                    Text("✓ ${tratamento.medicine} está entre os remédios que você usa" + (if (posologia.isNotEmpty()) " ($posologia)" else "") + receita + ".", style = MaterialTheme.typography.bodyLarge, fontWeight = FontWeight.Medium)
                }
                tratamento.totalDeRemedios == 0 -> Text("Você ainda não registrou remédios em uso; não há o que cruzar.", style = MaterialTheme.typography.bodyLarge)
                tratamento.interactions.isEmpty() && tratamento.alergias.isEmpty() -> Text(
                    buildAnnotatedString {
                        append("${tratamento.medicine} não está na sua lista. Nenhuma interação registrada com o que você usa. ")
                        withStyle(SpanStyle(fontWeight = FontWeight.Bold)) { append("Isso não garante que seja seguro.") }
                    },
                    style = MaterialTheme.typography.bodyLarge,
                )
            }
            tratamento.alergias.forEach { alergia ->
                val relacao = if (alergia.via == "classe") "pertence à mesma classe de" else "contém"
                Text("⚠ Você registrou alergia a ${alergia.allergy}: este remédio $relacao ${alergia.ingredient}.", style = MaterialTheme.typography.bodyLarge, fontWeight = FontWeight.SemiBold, color = MaterialTheme.colorScheme.onErrorContainer)
            }
            tratamento.interactions.forEach { interacao ->
                Surface(shape = MaterialTheme.shapes.medium, color = MaterialTheme.colorScheme.surface.copy(alpha = 0.8f)) {
                    Column(Modifier.fillMaxWidth().padding(12.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                        Text("Com ${interacao.medicine}", style = MaterialTheme.typography.bodyLarge, fontWeight = FontWeight.SemiBold)
                        SeloDeGravidade(interacao.severity, interacao.gravidade)
                        if (interacao.divergent) {
                            Text("A base registra gravidades diferentes; mostramos a mais alta.", style = MaterialTheme.typography.bodySmall)
                        }
                    }
                }
            }
            if (tratamento.interactions.isNotEmpty()) {
                Text("Gravidade conforme a base DDInter; não é uma avaliação do seu caso.", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
            } else if (tratamento.emUso && tratamento.totalDeRemedios > 1) {
                Text(
                    buildAnnotatedString {
                        append("Nenhuma interação registrada com os seus outros remédios. ")
                        withStyle(SpanStyle(fontWeight = FontWeight.Bold)) { append("Isso não garante que seja seguro.") }
                    },
                    style = MaterialTheme.typography.bodyLarge,
                )
            }
            if (tratamento.unresolved.isNotEmpty()) {
                Text("Não foi possível verificar: ${tratamento.unresolved.joinToString(", ")}. Remédio sem bula cadastrada ou substância não identificada: não há o que cruzar.", style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
            }
        }
    }
}

/** Largura máxima confortável de leitura em telas largas (tablet, celular deitado). */
val LarguraDaConversa = Modifier.widthIn(max = 720.dp)
