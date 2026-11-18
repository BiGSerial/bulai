package br.com.vixforge.bulai.ui.sistema

import android.Manifest
import android.content.ActivityNotFoundException
import android.content.Context
import android.content.Intent
import android.net.Uri
import android.os.Build
import android.provider.Settings
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.runtime.Composable
import androidx.compose.ui.platform.LocalContext
import androidx.core.content.ContextCompat
import android.content.pm.PackageManager

/** Permissões que os alarmes usam. */
enum class Permissao { Notificacoes, AlarmesExatos, TelaCheia, Bateria }

/**
 * Pede uma permissão do jeito que o Android permite: notificações pelo diálogo do sistema (até duas recusas; depois só
 * pelas configurações); hora exata, tela cheia e bateria pelas telas de configuração. [aoVoltar] roda quando o diálogo
 * fecha; ao voltar das configurações, a tela relê a situação no ON_RESUME.
 */
@Composable
fun rememberPedirPermissao(aoVoltar: () -> Unit): (Permissao) -> Unit {
    val context = LocalContext.current
    val dialogo = rememberLauncherForActivityResult(ActivityResultContracts.RequestPermission()) { concedida ->
        if (!concedida) {
            abrirConfiguracoesDeNotificacao(context)
        }
        aoVoltar()
    }
    return { permissao ->
        val pacote = Uri.parse("package:${context.packageName}")
        when (permissao) {
            Permissao.Notificacoes -> if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU && ContextCompat.checkSelfPermission(context, Manifest.permission.POST_NOTIFICATIONS) != PackageManager.PERMISSION_GRANTED) {
                dialogo.launch(Manifest.permission.POST_NOTIFICATIONS)
            } else {
                abrirConfiguracoesDeNotificacao(context)
            }
            Permissao.AlarmesExatos -> if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
                abrir(context, Intent(Settings.ACTION_REQUEST_SCHEDULE_EXACT_ALARM, pacote))
            }
            Permissao.TelaCheia -> if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.UPSIDE_DOWN_CAKE) {
                abrir(context, Intent(Settings.ACTION_MANAGE_APP_USE_FULL_SCREEN_INTENT, pacote))
            }
            Permissao.Bateria -> abrir(context, Intent(Settings.ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS, pacote), Intent(Settings.ACTION_IGNORE_BATTERY_OPTIMIZATION_SETTINGS))
        }
    }
}

/** Primeiro pedido de notificações depois de entrar (uma vez por instalação). */
@Composable
fun rememberPedidoInicialDeNotificacoes(): () -> Unit {
    val dialogo = rememberLauncherForActivityResult(ActivityResultContracts.RequestPermission()) { }
    return { if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) dialogo.launch(Manifest.permission.POST_NOTIFICATIONS) }
}

private fun abrirConfiguracoesDeNotificacao(context: Context) =
    abrir(context, Intent(Settings.ACTION_APP_NOTIFICATION_SETTINGS).putExtra(Settings.EXTRA_APP_PACKAGE, context.packageName))

private fun abrir(context: Context, vararg tentativas: Intent) {
    for (tentativa in tentativas) {
        try {
            context.startActivity(tentativa)
            return
        } catch (e: ActivityNotFoundException) {
            // tenta a próxima
        }
    }
    context.startActivity(Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS, Uri.parse("package:${context.packageName}")))
}
