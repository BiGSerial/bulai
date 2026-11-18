package br.com.vixforge.bulai.ui.cuidado

import android.content.ActivityNotFoundException
import android.content.Intent
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.platform.LocalContext
import androidx.core.content.FileProvider
import br.com.vixforge.bulai.container
import kotlinx.coroutines.launch

/** Estado do botão "Resumo para o médico": [baixando] enquanto o PDF vem do servidor; [abrir] baixa e abre. */
class ResumoParaOMedico(val baixando: Boolean, val abrir: () -> Unit)

/**
 * Baixa o mesmo PDF do site e abre no leitor de PDF do celular (sem leitor, oferece compartilhar: WhatsApp, e-mail…).
 * Sem o consentimento de saúde, o servidor responde com a frase para a pessoa.
 */
@Composable
fun rememberResumoParaOMedico(recado: (String) -> Unit): ResumoParaOMedico {
    val context = LocalContext.current
    val escopo = rememberCoroutineScope()
    var baixando by remember { mutableStateOf(false) }
    return ResumoParaOMedico(baixando) {
        if (!baixando) {
            baixando = true
            escopo.launch {
                context.container.cuidado.baixarResumo()
                    .onSuccess { arquivo ->
                        val uri = FileProvider.getUriForFile(context, "${context.packageName}.arquivos", arquivo)
                        val ver = Intent(Intent.ACTION_VIEW).setDataAndType(uri, "application/pdf").addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
                        try {
                            context.startActivity(ver)
                        } catch (_: ActivityNotFoundException) {
                            val enviar = Intent(Intent.ACTION_SEND).setType("application/pdf").putExtra(Intent.EXTRA_STREAM, uri).addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
                            context.startActivity(Intent.createChooser(enviar, "Resumo para o médico"))
                        }
                    }
                    .onFailure { falha -> recado(falha.message ?: "Não deu para gerar o resumo agora. Tente de novo.") }
                baixando = false
            }
        }
    }
}
