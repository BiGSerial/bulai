package br.com.vixforge.bulai.ui.tema

import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.ColorScheme
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Shapes
import androidx.compose.material3.Typography
import androidx.compose.material3.darkColorScheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.runtime.Immutable
import androidx.compose.runtime.staticCompositionLocalOf
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp

// Paleta do site (resources/css/app.css): marca (azul), destaque (laranja) e superfícies creme.
object Marca {
    val Azul50 = Color(0xFFEEF3F7)
    val Azul100 = Color(0xFFD8E3EC)
    val Azul300 = Color(0xFF86A5BD)
    val Azul500 = Color(0xFF35617F)
    val Azul600 = Color(0xFF1F4A66)
    val Azul700 = Color(0xFF12344D)
    val Azul900 = Color(0xFF091D2C)
    val Laranja100 = Color(0xFFFBE3D2)
    val Laranja500 = Color(0xFFD9661F)
    val Laranja600 = Color(0xFFB8521A)
    val Laranja700 = Color(0xFF944114)
    val Creme50 = Color(0xFFFBFAF7)
    val Creme100 = Color(0xFFF7F4EE)
    val Creme200 = Color(0xFFECE6DA)
    val Verde = Color(0xFF1D7A46)
    val VerdeClaro = Color(0xFFDDF2E5)
    val Vermelho = Color(0xFFB42318)
    val VermelhoClaro = Color(0xFFFDE7E5)
}

/** Cores de situação (dose tomada, atrasada…) que o Material não tem. */
@Immutable
data class CoresExtras(
    val sucesso: Color,
    val sucessoFundo: Color,
    val alerta: Color,
    val alertaFundo: Color,
    val esqueleto: Color,
    val esqueletoBrilho: Color,
)

val LocalCoresExtras = staticCompositionLocalOf {
    CoresExtras(Marca.Verde, Marca.VerdeClaro, Marca.Vermelho, Marca.VermelhoClaro, Marca.Creme200, Marca.Creme50)
}

private val Claro: ColorScheme = lightColorScheme(
    primary = Marca.Azul700,
    onPrimary = Color.White,
    primaryContainer = Marca.Azul100,
    onPrimaryContainer = Marca.Azul900,
    secondary = Marca.Laranja500,
    onSecondary = Color.White,
    secondaryContainer = Marca.Laranja100,
    onSecondaryContainer = Marca.Laranja700,
    tertiary = Marca.Azul500,
    background = Marca.Creme100,
    onBackground = Color(0xFF0F172A),
    surface = Color.White,
    onSurface = Color(0xFF0F172A),
    surfaceVariant = Marca.Creme200,
    onSurfaceVariant = Color(0xFF334155),
    surfaceContainerLowest = Color.White,
    surfaceContainerLow = Marca.Creme50,
    surfaceContainer = Color.White,
    surfaceContainerHigh = Marca.Creme50,
    outline = Color(0xFF94A3B8),
    outlineVariant = Color(0xFFE2E8F0),
    error = Marca.Vermelho,
    errorContainer = Marca.VermelhoClaro,
)

private val Escuro: ColorScheme = darkColorScheme(
    primary = Color(0xFF9DBCD4),
    onPrimary = Marca.Azul900,
    primaryContainer = Color(0xFF22394F),
    onPrimaryContainer = Color(0xFFDDE8F1),
    secondary = Color(0xFFEF9E6A),
    onSecondary = Color(0xFF4D220A),
    secondaryContainer = Color(0xFF70310F),
    onSecondaryContainer = Marca.Laranja100,
    tertiary = Marca.Azul300,
    background = Color(0xFF0E1821),
    onBackground = Color(0xFFE8EEF4),
    surface = Color(0xFF172431),
    onSurface = Color(0xFFE8EEF4),
    surfaceVariant = Color(0xFF22303C),
    onSurfaceVariant = Color(0xFFC2CCD6),
    surfaceContainerLowest = Color(0xFF0E1821),
    surfaceContainerLow = Color(0xFF13202A),
    surfaceContainer = Color(0xFF172431),
    surfaceContainerHigh = Color(0xFF1C2B38),
    outline = Color(0xFF64748B),
    outlineVariant = Color(0xFF2C3B48),
    error = Color(0xFFFFB4AB),
    errorContainer = Color(0xFF5C1A14),
)

// Texto grande por padrão (público mais velho): corpo 18 sp, títulos fortes. Respeita a escala de fonte do sistema.
private val Tipos = Typography(
    displaySmall = TextStyle(fontSize = 32.sp, lineHeight = 40.sp, fontWeight = FontWeight.SemiBold),
    headlineLarge = TextStyle(fontSize = 30.sp, lineHeight = 38.sp, fontWeight = FontWeight.SemiBold),
    headlineMedium = TextStyle(fontSize = 26.sp, lineHeight = 34.sp, fontWeight = FontWeight.SemiBold),
    headlineSmall = TextStyle(fontSize = 22.sp, lineHeight = 30.sp, fontWeight = FontWeight.SemiBold),
    titleLarge = TextStyle(fontSize = 22.sp, lineHeight = 28.sp, fontWeight = FontWeight.SemiBold),
    titleMedium = TextStyle(fontSize = 19.sp, lineHeight = 26.sp, fontWeight = FontWeight.SemiBold),
    titleSmall = TextStyle(fontSize = 17.sp, lineHeight = 24.sp, fontWeight = FontWeight.SemiBold),
    bodyLarge = TextStyle(fontSize = 18.sp, lineHeight = 27.sp),
    bodyMedium = TextStyle(fontSize = 17.sp, lineHeight = 25.sp),
    bodySmall = TextStyle(fontSize = 15.sp, lineHeight = 22.sp),
    labelLarge = TextStyle(fontSize = 18.sp, lineHeight = 24.sp, fontWeight = FontWeight.SemiBold),
    labelMedium = TextStyle(fontSize = 15.sp, lineHeight = 20.sp, fontWeight = FontWeight.Medium),
    labelSmall = TextStyle(fontSize = 13.sp, lineHeight = 18.sp, fontWeight = FontWeight.Medium),
)

private val Formas = Shapes(
    small = RoundedCornerShape(10.dp),
    medium = RoundedCornerShape(16.dp),
    large = RoundedCornerShape(22.dp),
    extraLarge = RoundedCornerShape(28.dp),
)

@Composable
fun TemaBulai(escuro: Boolean = isSystemInDarkTheme(), conteudo: @Composable () -> Unit) {
    val extras = if (escuro) {
        CoresExtras(Color(0xFF7FD3A0), Color(0xFF173A28), Color(0xFFFFB4AB), Color(0xFF4A1D18), Color(0xFF22303C), Color(0xFF2C3B48))
    } else {
        CoresExtras(Marca.Verde, Marca.VerdeClaro, Marca.Vermelho, Marca.VermelhoClaro, Marca.Creme200, Marca.Creme50)
    }
    androidx.compose.runtime.CompositionLocalProvider(LocalCoresExtras provides extras) {
        MaterialTheme(colorScheme = if (escuro) Escuro else Claro, typography = Tipos, shapes = Formas, content = conteudo)
    }
}

object TemaBulai {
    val extras: CoresExtras
        @Composable get() = LocalCoresExtras.current
}
