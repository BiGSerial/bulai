package br.com.vixforge.bulai.ui.componentes

import androidx.compose.animation.AnimatedContent
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.expandVertically
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.shrinkVertically
import androidx.compose.animation.togetherWith
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.CloudOff
import androidx.compose.material.icons.rounded.ErrorOutline
import androidx.compose.material.icons.rounded.Visibility
import androidx.compose.material.icons.rounded.VisibilityOff
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.semantics.LiveRegionMode
import androidx.compose.ui.semantics.contentType
import androidx.compose.ui.semantics.error
import androidx.compose.ui.semantics.liveRegion
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.input.VisualTransformation
import androidx.compose.ui.unit.dp
import br.com.vixforge.bulai.ui.tema.TemaBulai

/** Ação principal da tela: 60 dp de altura, texto grande; mostra o progresso no lugar do texto. */
@Composable
fun BotaoPrincipal(
    texto: String,
    aoClicar: () -> Unit,
    modifier: Modifier = Modifier,
    carregando: Boolean = false,
    habilitado: Boolean = true,
    destaque: Boolean = false,
    icone: ImageVector? = null,
) {
    Button(
        onClick = aoClicar,
        enabled = habilitado && !carregando,
        modifier = modifier.fillMaxWidth().heightIn(min = 60.dp),
        shape = MaterialTheme.shapes.medium,
        colors = if (destaque) ButtonDefaults.buttonColors(containerColor = MaterialTheme.colorScheme.secondary, contentColor = MaterialTheme.colorScheme.onSecondary) else ButtonDefaults.buttonColors(),
        contentPadding = PaddingValues(horizontal = 20.dp, vertical = 14.dp),
    ) {
        AnimatedContent(carregando, transitionSpec = { fadeIn() togetherWith fadeOut() }, label = "botao") { ocupado ->
            if (ocupado) {
                CircularProgressIndicator(Modifier.size(26.dp), color = localContentColorOr(MaterialTheme.colorScheme.onPrimary), strokeWidth = 3.dp)
            } else {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    icone?.let {
                        Icon(it, contentDescription = null, Modifier.size(24.dp))
                        Spacer(Modifier.width(10.dp))
                    }
                    Text(texto, style = MaterialTheme.typography.labelLarge)
                }
            }
        }
    }
}

@Composable
private fun localContentColorOr(cor: Color): Color = androidx.compose.material3.LocalContentColor.current.takeOrElse(cor)

private fun Color.takeOrElse(outra: Color): Color = if (this == Color.Unspecified) outra else this

@Composable
fun BotaoSecundario(texto: String, aoClicar: () -> Unit, modifier: Modifier = Modifier, icone: ImageVector? = null, habilitado: Boolean = true) {
    OutlinedButton(
        onClick = aoClicar,
        enabled = habilitado,
        modifier = modifier.fillMaxWidth().heightIn(min = 56.dp),
        shape = MaterialTheme.shapes.medium,
        contentPadding = PaddingValues(horizontal = 20.dp, vertical = 12.dp),
    ) {
        icone?.let {
            Icon(it, contentDescription = null, Modifier.size(22.dp))
            Spacer(Modifier.width(10.dp))
        }
        Text(texto, style = MaterialTheme.typography.labelLarge)
    }
}

@Composable
fun BotaoTexto(texto: String, aoClicar: () -> Unit, modifier: Modifier = Modifier) {
    TextButton(onClick = aoClicar, modifier = modifier.heightIn(min = 48.dp)) {
        Text(texto, style = MaterialTheme.typography.labelLarge)
    }
}

/** Campo de formulário com rótulo fixo, erro embaixo (lido pelo TalkBack) e, em senhas, o botão de mostrar. */
@Composable
fun Campo(
    valor: String,
    aoMudar: (String) -> Unit,
    rotulo: String,
    modifier: Modifier = Modifier,
    erro: String? = null,
    tipo: KeyboardType = KeyboardType.Text,
    senha: Boolean = false,
    acao: ImeAction = ImeAction.Next,
    aoConcluir: () -> Unit = {},
    autoPreenchimento: List<androidx.compose.ui.autofill.ContentType> = emptyList(),
    ajuda: String? = null,
) {
    var mostrarSenha by rememberSaveable { mutableStateOf(false) }
    OutlinedTextField(
        value = valor,
        onValueChange = aoMudar,
        label = { Text(rotulo) },
        modifier = modifier
            .fillMaxWidth()
            .then(if (autoPreenchimento.isNotEmpty()) Modifier.semantics { contentType = autoPreenchimento.reduce { a, b -> a + b } } else Modifier)
            .then(if (erro != null) Modifier.semantics { error(erro) } else Modifier),
        textStyle = MaterialTheme.typography.bodyLarge,
        singleLine = true,
        isError = erro != null,
        supportingText = (erro ?: ajuda)?.let { texto -> { Text(texto, style = MaterialTheme.typography.bodySmall) } },
        visualTransformation = if (senha && !mostrarSenha) PasswordVisualTransformation() else VisualTransformation.None,
        keyboardOptions = KeyboardOptions(keyboardType = if (senha) KeyboardType.Password else tipo, imeAction = acao, autoCorrectEnabled = !senha && tipo == KeyboardType.Text),
        keyboardActions = KeyboardActions(onDone = { aoConcluir() }, onGo = { aoConcluir() }, onSend = { aoConcluir() }),
        trailingIcon = if (senha) {
            {
                IconButton(onClick = { mostrarSenha = !mostrarSenha }) {
                    Icon(if (mostrarSenha) Icons.Rounded.VisibilityOff else Icons.Rounded.Visibility, contentDescription = if (mostrarSenha) "Esconder a senha" else "Mostrar a senha")
                }
            }
        } else {
            null
        },
        shape = MaterialTheme.shapes.medium,
    )
}

@Composable
fun Cartao(modifier: Modifier = Modifier, cor: Color = MaterialTheme.colorScheme.surface, aoClicar: (() -> Unit)? = null, conteudo: @Composable ColumnScope.() -> Unit) {
    val forma = MaterialTheme.shapes.large
    val cores = CardDefaults.cardColors(containerColor = cor)
    val elevacao = CardDefaults.cardElevation(defaultElevation = 0.dp)
    val interior: @Composable ColumnScope.() -> Unit = {
        Column(Modifier.padding(20.dp), verticalArrangement = Arrangement.spacedBy(12.dp), content = conteudo)
    }
    if (aoClicar != null) {
        Card(onClick = aoClicar, modifier = modifier.fillMaxWidth(), shape = forma, colors = cores, elevation = elevacao, content = interior)
    } else {
        Card(modifier = modifier.fillMaxWidth(), shape = forma, colors = cores, elevation = elevacao, content = interior)
    }
}

/** Faixa de erro com "Tentar de novo"; anunciada pelo TalkBack ao aparecer. */
@Composable
fun FaixaDeErro(mensagem: String?, modifier: Modifier = Modifier, aoTentarDeNovo: (() -> Unit)? = null) {
    AnimatedVisibility(mensagem != null, enter = expandVertically() + fadeIn(), exit = shrinkVertically() + fadeOut()) {
        val extras = TemaBulai.extras
        Cartao(modifier.semantics { liveRegion = LiveRegionMode.Polite }, cor = extras.alertaFundo) {
            Row(verticalAlignment = Alignment.Top) {
                Icon(if (mensagem?.contains("conexão") == true) Icons.Rounded.CloudOff else Icons.Rounded.ErrorOutline, contentDescription = null, tint = extras.alerta)
                Spacer(Modifier.width(12.dp))
                Text(mensagem.orEmpty(), style = MaterialTheme.typography.bodyLarge, color = MaterialTheme.colorScheme.onSurface, modifier = Modifier.weight(1f))
            }
            aoTentarDeNovo?.let { BotaoSecundario("Tentar de novo", it) }
        }
    }
}

/** Tela sem conteúdo ainda: ícone, frase curta e, se houver, a ação que resolve. */
@Composable
fun EstadoVazio(icone: ImageVector, titulo: String, texto: String, modifier: Modifier = Modifier, acao: (@Composable () -> Unit)? = null) {
    Cartao(modifier) {
        Icon(icone, contentDescription = null, tint = MaterialTheme.colorScheme.primary, modifier = Modifier.size(40.dp))
        Text(titulo, style = MaterialTheme.typography.titleLarge, color = MaterialTheme.colorScheme.primary)
        Text(texto, style = MaterialTheme.typography.bodyLarge, color = MaterialTheme.colorScheme.onSurfaceVariant)
        acao?.invoke()
    }
}
