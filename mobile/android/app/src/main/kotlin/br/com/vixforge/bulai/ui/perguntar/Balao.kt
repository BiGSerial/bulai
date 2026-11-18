package br.com.vixforge.bulai.ui.perguntar

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
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
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
import androidx.compose.material.icons.automirrored.rounded.VolumeUp
import androidx.compose.material.icons.rounded.Call
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.ExpandLess
import androidx.compose.material.icons.rounded.ExpandMore
import androidx.compose.material.icons.rounded.MenuBook
import androidx.compose.material.icons.rounded.StopCircle
import androidx.compose.material.icons.rounded.WarningAmber
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
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
import androidx.compose.ui.draw.scale
import androidx.compose.ui.graphics.Color
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
import br.com.vixforge.bulai.dados.api.NoTratamento
import br.com.vixforge.bulai.dados.api.Resposta
import br.com.vixforge.bulai.dados.api.Trecho
import br.com.vixforge.bulai.ui.sistema.Leitor
import br.com.vixforge.bulai.ui.tema.Marca
import br.com.vixforge.bulai.ui.tema.TemaBulai

private val FormaDoBulai = RoundedCornerShape(topStart = 22.dp, topEnd = 22.dp, bottomEnd = 22.dp, bottomStart = 6.dp)
private val FormaDaPessoa = RoundedCornerShape(topStart = 22.dp, topEnd = 22.dp, bottomEnd = 6.dp, bottomStart = 22.dp)

/** O que a pessoa perguntou, à direita. */
@Composable
fun BalaoDaPessoa(texto: String, modifier: Modifier = Modifier) {
    Row(modifier.fillMaxWidth().padding(start = 48.dp), horizontalArrangement = Arrangement.End) {
        Surface(shape = FormaDaPessoa, color = MaterialTheme.colorScheme.primary, contentColor = MaterialTheme.colorScheme.onPrimary) {
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
        Image(painterResource(R.drawable.ic_launcher_frente), contentDescription = null, modifier = Modifier.requiredSize(62.dp))
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
    Row(modifier.fillMaxWidth().padding(end = 12.dp), verticalAlignment = Alignment.Bottom) {
        AvatarDoBulai()
        Spacer(Modifier.width(8.dp))
        Surface(
            shape = FormaDoBulai,
            color = fundo,
            border = borda?.let { BorderStroke(2.dp, it) } ?: BorderStroke(1.dp, MaterialTheme.colorScheme.outlineVariant.copy(alpha = 0.6f)),
            modifier = Modifier.weight(1f, fill = false),
        ) {
            Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp), content = conteudo)
        }
    }
}

/** "Digitando…" enquanto o BULA-IA procura na bula. */
@Composable
fun Digitando(modifier: Modifier = Modifier) {
    val transicao = rememberInfiniteTransition(label = "digitando")
    Row(modifier.fillMaxWidth().semantics { contentDescription = "O BULA-IA está procurando na bula"; liveRegion = LiveRegionMode.Polite }, verticalAlignment = Alignment.Bottom) {
        AvatarDoBulai()
        Spacer(Modifier.width(8.dp))
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
    val corrigir: (Long) -> Unit,
    val remover: (Long) -> Unit,
    val trocarRemedio: () -> Unit,
)

/**
 * Resposta completa do BULA-IA: a fala em parágrafos (com destaques), o alerta do motor estruturado, de onde veio, "Ouvir",
 * sugestões do próximo passo, correção do remédio entendido e, recolhidos, as fontes e o cruzamento com o tratamento.
 */
@Composable
fun BalaoDaResposta(resposta: Resposta, leitor: Leitor?, acoes: AcoesDaResposta?, modifier: Modifier = Modifier) {
    val extras = TemaBulai.extras
    val alerta = resposta.alert
    val (fundo, borda) = when {
        alerta?.alergia == true -> MaterialTheme.colorScheme.errorContainer to MaterialTheme.colorScheme.error
        alerta != null -> extras.alertaFundo to extras.alerta
        resposta.tone == "danger" -> MaterialTheme.colorScheme.errorContainer to null
        resposta.tone == "warning" -> extras.alertaFundo to null
        else -> MaterialTheme.colorScheme.surface to null
    }
    val context = LocalContext.current

    BalaoDoBulai(modifier, fundo = fundo, borda = borda) {
        resposta.urgency?.let { urgencia ->
            Surface(shape = MaterialTheme.shapes.medium, color = MaterialTheme.colorScheme.errorContainer, border = BorderStroke(2.dp, MaterialTheme.colorScheme.error)) {
                Text(urgencia, style = MaterialTheme.typography.bodyLarge, fontWeight = FontWeight.SemiBold, color = MaterialTheme.colorScheme.onErrorContainer, modifier = Modifier.padding(12.dp).semantics { liveRegion = LiveRegionMode.Assertive })
            }
        }
        resposta.paragraphs.forEach { paragrafo ->
            Text(textoComDestaques(paragrafo), style = MaterialTheme.typography.bodyLarge, fontWeight = if (resposta.status == Resposta.BLOQUEADA) FontWeight.SemiBold else null)
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

        if (resposta.crisis) {
            Text("Ligue agora para conversar: 188 (CVV, gratuito, 24 h) ou 192 (SAMU) em emergência.", style = MaterialTheme.typography.bodyLarge)
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                listOf("188" to "Ligar 188", "192" to "Ligar 192").forEach { (numero, rotulo) ->
                    OutlinedButton(onClick = { context.startActivity(Intent(Intent.ACTION_DIAL, "tel:$numero".toUri())) }, modifier = Modifier.heightIn(min = 52.dp)) {
                        Icon(Icons.Rounded.Call, contentDescription = null)
                        Spacer(Modifier.width(6.dp))
                        Text(rotulo, style = MaterialTheme.typography.labelLarge)
                    }
                }
            }
        }

        // "Baseado na bula…" fica dentro de "Fontes utilizadas"; no balão só a orientação de quando não houve resposta.
        resposta.source?.takeUnless { resposta.respondida }?.let { fonte ->
            Row(verticalAlignment = Alignment.Top) {
                Icon(Icons.Rounded.MenuBook, contentDescription = null, modifier = Modifier.size(18.dp).padding(top = 2.dp), tint = MaterialTheme.colorScheme.onSurfaceVariant)
                Spacer(Modifier.width(6.dp))
                Text(fonte, style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
            }
        }

        if (resposta.podeOuvir && leitor != null) {
            val falando by leitor.falando
            OutlinedButton(onClick = { leitor.alternar(resposta.textoCorrido) }, modifier = Modifier.heightIn(min = 52.dp)) {
                Icon(if (falando) Icons.Rounded.StopCircle else Icons.AutoMirrored.Rounded.VolumeUp, contentDescription = null)
                Spacer(Modifier.width(8.dp))
                Text(if (falando) "Parar de ouvir" else "Ouvir", style = MaterialTheme.typography.labelLarge)
            }
        }

        if (acoes != null) {
            AcoesDoBalao(resposta, acoes)
        }

        if (resposta.sources.isNotEmpty() || (resposta.respondida && resposta.source != null)) {
            Fontes(resposta)
        }
    }
}

@Composable
private fun ColumnScope.AcoesDoBalao(resposta: Resposta, acoes: AcoesDaResposta) {
    if (resposta.guesses.isNotEmpty()) {
        FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            resposta.guesses.forEach { Pilula(it.name, { acoes.corrigir(it.id) }, destaque = true) }
        }
    }
    if (resposta.meusRemedios.isNotEmpty()) {
        Text("Ou toque em um dos seus remédios:", style = MaterialTheme.typography.bodyMedium)
        FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            resposta.meusRemedios.forEach { Pilula(it.name, { acoes.corrigir(it.id) }) }
        }
    }
    if (resposta.outrosRemedios.isNotEmpty()) {
        FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            resposta.outrosRemedios.forEach { nome -> Pilula(nome, { acoes.sugerir("Sobre $nome:") }) }
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
    if (resposta.medicines.isNotEmpty()) {
        HorizontalDivider(color = MaterialTheme.colorScheme.outlineVariant.copy(alpha = 0.6f))
        Text("Falando de:", style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
        FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            resposta.medicines.forEach { remedio ->
                Surface(shape = CircleShape, color = MaterialTheme.colorScheme.surface, border = BorderStroke(1.dp, MaterialTheme.colorScheme.primary.copy(alpha = 0.3f))) {
                    Row(Modifier.heightIn(min = 44.dp).padding(start = 14.dp, end = if (resposta.medicines.size > 1) 0.dp else 14.dp), verticalAlignment = Alignment.CenterVertically) {
                        Text(remedio.name, style = MaterialTheme.typography.bodyLarge, fontWeight = FontWeight.SemiBold, color = MaterialTheme.colorScheme.primary)
                        if (resposta.medicines.size > 1) {
                            IconButton(onClick = { acoes.remover(remedio.id) }) { Icon(Icons.Rounded.Close, contentDescription = "Tirar ${remedio.name} da pergunta") }
                        }
                    }
                }
            }
        }
        TextButton(onClick = acoes.trocarRemedio, modifier = Modifier.heightIn(min = 48.dp)) { Text("Não é esse remédio?", style = MaterialTheme.typography.labelLarge) }
    }
}

/**
 * "Fontes utilizadas", recolhido: só de onde veio a resposta (a bula, a seção, a página e quando foi processada). Posologia,
 * modo de usar e o resto ficam na bula humanizada ou numa pergunta própria, não aqui.
 */
@Composable
private fun Fontes(resposta: Resposta) {
    var aberto by rememberSaveable(resposta.uuid) { mutableStateOf(false) }
    Column(Modifier.fillMaxWidth().clip(MaterialTheme.shapes.medium).background(MaterialTheme.colorScheme.surfaceVariant.copy(alpha = 0.5f))) {
        TextButton(onClick = { aberto = !aberto }, modifier = Modifier.fillMaxWidth().heightIn(min = 52.dp)) {
            Text("Fontes utilizadas", style = MaterialTheme.typography.labelLarge, modifier = Modifier.weight(1f))
            Icon(if (aberto) Icons.Rounded.ExpandLess else Icons.Rounded.ExpandMore, contentDescription = if (aberto) "Recolher" else "Abrir")
        }
        AnimatedVisibility(aberto, enter = expandVertically() + fadeIn(), exit = shrinkVertically() + fadeOut()) {
            Column(Modifier.padding(start = 14.dp, end = 14.dp, bottom = 14.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                if (resposta.respondida) {
                    resposta.source?.let { Text(it, style = MaterialTheme.typography.bodyMedium) }
                }
                resposta.sources.distinctBy { listOf(it.titulo, it.section, it.pagina) }.forEach { fonte ->
                    Row(verticalAlignment = Alignment.Top) {
                        Icon(Icons.Rounded.MenuBook, contentDescription = null, modifier = Modifier.size(18.dp).padding(top = 2.dp), tint = MaterialTheme.colorScheme.onSurfaceVariant)
                        Spacer(Modifier.width(8.dp))
                        Column(verticalArrangement = Arrangement.spacedBy(2.dp)) {
                            Text(listOfNotNull(fonte.titulo, fonte.section, fonte.pagina?.let { "página $it" }).joinToString(" · "), style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.SemiBold)
                            fonte.processed?.let { Text("Bula processada em $it", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant) }
                        }
                    }
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
        tratamento.maiorGravidade == "moderate" || tratamento.interactions.any { it.severity == "duplicate" } -> extras.alertaFundo to extras.alerta
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
