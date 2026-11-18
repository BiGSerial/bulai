package br.com.vixforge.bulai.ui.componentes

import androidx.compose.animation.core.LinearEasing
import androidx.compose.animation.core.RepeatMode
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.rememberInfiniteTransition
import androidx.compose.animation.core.tween
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.MaterialTheme
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.composed
import androidx.compose.ui.draw.clip
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Shape
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import br.com.vixforge.bulai.ui.tema.TemaBulai

/** Brilho que atravessa o esqueleto enquanto a tela carrega (mesma fase para todos os blocos da tela). */
fun Modifier.brilho(): Modifier = composed {
    val extras = TemaBulai.extras
    val transicao = rememberInfiniteTransition(label = "brilho")
    val x by transicao.animateFloat(
        initialValue = -600f,
        targetValue = 1400f,
        animationSpec = infiniteRepeatable(tween(1300, easing = LinearEasing), RepeatMode.Restart),
        label = "x",
    )
    background(Brush.linearGradient(listOf(extras.esqueleto, extras.esqueletoBrilho, extras.esqueleto), start = Offset(x, 0f), end = Offset(x + 500f, 200f)))
}

@Composable
fun Bloco(largura: Float, altura: Dp, modifier: Modifier = Modifier, forma: Shape = RoundedCornerShape(8.dp)) {
    Box(modifier.fillMaxWidth(largura).height(altura).clip(forma).brilho())
}

/** Cartão-esqueleto com as proporções de um cartão real (título, duas linhas e um botão). */
@Composable
fun CartaoEsqueleto(modifier: Modifier = Modifier, comBotao: Boolean = true) {
    Column(
        modifier
            .fillMaxWidth()
            .clip(MaterialTheme.shapes.large)
            .background(MaterialTheme.colorScheme.surface)
            .padding(20.dp),
        verticalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        Bloco(0.45f, 22.dp)
        Bloco(0.9f, 18.dp)
        Bloco(0.7f, 18.dp)
        if (comBotao) {
            Spacer(Modifier.height(4.dp))
            Bloco(1f, 56.dp, forma = RoundedCornerShape(16.dp))
        }
    }
}

/** Linha de dose (horário, nome, situação) em esqueleto. */
@Composable
fun LinhaEsqueleto(modifier: Modifier = Modifier) {
    Row(
        modifier
            .fillMaxWidth()
            .clip(MaterialTheme.shapes.large)
            .background(MaterialTheme.colorScheme.surface)
            .padding(16.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(Modifier.size(52.dp).clip(CircleShape).brilho())
        Spacer(Modifier.width(16.dp))
        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            Bloco(0.6f, 20.dp)
            Bloco(0.4f, 16.dp)
        }
    }
}

/** Tela inteira em esqueleto; leitores de tela ouvem "Carregando". */
@Composable
fun TelaEsqueleto(modifier: Modifier = Modifier, conteudo: @Composable () -> Unit) {
    Column(
        modifier.semantics { contentDescription = "Carregando" },
        verticalArrangement = Arrangement.spacedBy(14.dp),
    ) { conteudo() }
}
