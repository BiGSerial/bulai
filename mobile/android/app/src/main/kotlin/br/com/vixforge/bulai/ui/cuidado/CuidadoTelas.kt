package br.com.vixforge.bulai.ui.cuidado

import android.content.Intent
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.aspectRatio
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.KeyboardArrowRight
import androidx.compose.material.icons.rounded.Favorite
import androidx.compose.material.icons.rounded.Group
import androidx.compose.material.icons.rounded.HourglassTop
import androidx.compose.material.icons.rounded.QrCode2
import androidx.compose.material.icons.rounded.QrCodeScanner
import androidx.compose.material.icons.rounded.Share
import androidx.compose.material.icons.rounded.VisibilityOff
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Checkbox
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.pulltorefresh.PullToRefreshBox
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateMapOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.geometry.Size
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.LifecycleResumeEffect
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import br.com.vixforge.bulai.dados.api.Convite
import br.com.vixforge.bulai.dados.api.Cuidador
import br.com.vixforge.bulai.dados.api.MeusCuidadores
import br.com.vixforge.bulai.dados.api.PessoaAcompanhada
import br.com.vixforge.bulai.ui.base.telaViewModel
import br.com.vixforge.bulai.ui.componentes.Bloco
import br.com.vixforge.bulai.ui.componentes.BotaoPrincipal
import br.com.vixforge.bulai.ui.componentes.BotaoSecundario
import br.com.vixforge.bulai.ui.componentes.Campo
import br.com.vixforge.bulai.ui.componentes.Cartao
import br.com.vixforge.bulai.ui.componentes.EstadoVazio
import br.com.vixforge.bulai.ui.componentes.FaixaDeErro
import br.com.vixforge.bulai.ui.componentes.LinhaEsqueleto
import br.com.vixforge.bulai.ui.componentes.TelaEsqueleto
import com.google.mlkit.vision.barcode.common.Barcode
import com.google.mlkit.vision.codescanner.GmsBarcodeScannerOptions
import com.google.mlkit.vision.codescanner.GmsBarcodeScanning
import com.google.zxing.BarcodeFormat
import com.google.zxing.EncodeHintType
import com.google.zxing.common.BitMatrix
import com.google.zxing.qrcode.QRCodeWriter
import com.google.zxing.qrcode.decoder.ErrorCorrectionLevel
import java.time.OffsetDateTime
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import java.util.Locale

private val LARGURA = Modifier.widthIn(max = 640.dp)

// ---------------------------------------------------------------------------------------------------------------------
// Quem cuida de mim (paciente)
// ---------------------------------------------------------------------------------------------------------------------

/**
 * O paciente mostra o QR Code do convite para quem vai cuidar dele (ou envia o link), aprova o pedido escolhendo o que a
 * pessoa pode fazer, muda as permissões depois e tira o acesso quando quiser.
 */
@Composable
fun QuemCuidaDeMimTela(preenchimento: PaddingValues, recado: (String) -> Unit) {
    val vm = telaViewModel { QuemCuidaDeMimViewModel(it.cuidado) }
    val carga by vm.estado.collectAsStateWithLifecycle()
    val ocupado by vm.ocupado.collectAsStateWithLifecycle()
    var tirar by remember { mutableStateOf<Cuidador?>(null) }
    LaunchedEffect(Unit) { vm.recados.collect(recado) }

    PullToRefreshBox(carga.atualizando && carga.dados != null, vm::atualizar, Modifier.fillMaxSize()) {
        LazyColumn(
            Modifier.fillMaxSize(),
            contentPadding = PaddingValues(start = 20.dp, end = 20.dp, top = preenchimento.calculateTopPadding() + 8.dp, bottom = preenchimento.calculateBottomPadding() + 24.dp),
            verticalArrangement = Arrangement.spacedBy(14.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            item(key = "erro") { FaixaDeErro(carga.erro, LARGURA, vm::atualizar) }
            val dados = carga.dados
            when {
                dados == null -> if (carga.esqueleto) item(key = "esqueleto") { Esqueleto() }
                !dados.consentimento -> item(key = "consentimento") {
                    EstadoVazio(Icons.Rounded.Group, "Autorize no seu perfil", "Para alguém acompanhar seus remédios, primeiro autorize guardar seus dados de saúde no perfil.", LARGURA)
                }
                else -> {
                    item(key = "explica") {
                        Text(
                            "Quem cuida de você pode ver seus remédios, receber aviso quando uma dose atrasar e anotar as doses. Você escolhe o que cada pessoa pode fazer.",
                            style = MaterialTheme.typography.bodyLarge,
                            modifier = LARGURA.fillMaxWidth(),
                        )
                    }
                    item(key = "convite") { CartaoDoConvite(dados, ocupado == "convite", vm::gerarConvite, vm::cancelarConvite) }
                    if (dados.pedidos.isNotEmpty()) {
                        item(key = "pedidos-titulo") { Titulo("Pedindo para acompanhar você") }
                        items(dados.pedidos, key = { "pedido-${it.id}" }) { pessoa ->
                            CartaoDoCuidador(
                                pessoa,
                                dados,
                                pendente = true,
                                ocupado = ocupado == "${pessoa.id}",
                                aoSalvar = { vm.aprovar(pessoa.id, pessoa.primeiroNome, it) },
                                aoRecusarOuTirar = { vm.recusar(pessoa.id) },
                            )
                        }
                    }
                    item(key = "ativos-titulo") { Titulo("Acompanham você") }
                    if (dados.ativos.isEmpty()) {
                        item(key = "ativos-vazio") {
                            Text("Ninguém acompanha você ainda.", style = MaterialTheme.typography.bodyLarge, color = MaterialTheme.colorScheme.onSurfaceVariant, modifier = LARGURA.fillMaxWidth())
                        }
                    }
                    items(dados.ativos, key = { "ativo-${it.id}" }) { pessoa ->
                        CartaoDoCuidador(
                            pessoa,
                            dados,
                            pendente = false,
                            ocupado = ocupado == "${pessoa.id}",
                            aoSalvar = { vm.mudarPermissoes(pessoa.id, pessoa.primeiroNome, it) },
                            aoRecusarOuTirar = { tirar = pessoa },
                        )
                    }
                    if (dados.encerrados.isNotEmpty()) {
                        item(key = "encerrados") {
                            Cartao(LARGURA) {
                                Text("Já acompanharam você", style = MaterialTheme.typography.titleMedium)
                                dados.encerrados.forEach { antigo ->
                                    Text(
                                        "${antigo.name} · ${if (antigo.status == "LEFT") "deixou de acompanhar" else "acesso retirado"}${antigo.terminouEm?.let { " em ${dataCurta(it)}" }.orEmpty()}",
                                        style = MaterialTheme.typography.bodyMedium,
                                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                                    )
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    tirar?.let { pessoa ->
        AlertDialog(
            onDismissRequest = { tirar = null },
            title = { Text("Tirar o acesso de ${pessoa.primeiroNome}?") },
            text = { Text("${pessoa.primeiroNome} deixa de ver seus remédios e de receber avisos na hora. Para voltar, será preciso um convite novo.") },
            confirmButton = { TextButton(onClick = { vm.tirarAcesso(pessoa.id, pessoa.primeiroNome); tirar = null }) { Text("Tirar acesso", color = MaterialTheme.colorScheme.error) } },
            dismissButton = { TextButton(onClick = { tirar = null }) { Text("Cancelar") } },
        )
    }
}

/** QR Code do convite (o link `/cuidar?codigo=`), o código para digitar, a validade e "Enviar o link". */
@Composable
private fun CartaoDoConvite(dados: MeusCuidadores, ocupado: Boolean, aoGerar: () -> Unit, aoCancelar: () -> Unit) {
    val context = LocalContext.current
    val convite = dados.convite
    Cartao(LARGURA) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Icon(Icons.Rounded.QrCode2, contentDescription = null, tint = MaterialTheme.colorScheme.primary)
            Spacer(Modifier.width(10.dp))
            Text("Convidar quem cuida de você", style = MaterialTheme.typography.titleLarge, color = MaterialTheme.colorScheme.primary, modifier = Modifier.semantics { heading() })
        }
        if (convite == null) {
            Text(
                if (dados.conviteVencido) "O último convite venceu. Gere um novo QR Code." else "Gere um QR Code e mostre para a pessoa. Ela abre o BULA-IA no celular dela, em Conta › Cuidar de alguém, e lê o código.",
                style = MaterialTheme.typography.bodyLarge,
            )
            BotaoPrincipal("Gerar QR Code", aoGerar, carregando = ocupado, icone = Icons.Rounded.QrCode2)
        } else {
            Text("Peça para a pessoa abrir o BULA-IA em Conta › Cuidar de alguém e tocar em Ler QR Code.", style = MaterialTheme.typography.bodyLarge)
            QrCode(convite)
            Text(
                "Ou digite o código ${convite.code}",
                style = MaterialTheme.typography.titleMedium,
                fontWeight = FontWeight.Bold,
                modifier = Modifier.fillMaxWidth().semantics { contentDescription = "Código do convite: ${convite.code.toList().joinToString(" ")}" },
                textAlign = androidx.compose.ui.text.style.TextAlign.Center,
            )
            Text("Vale uma vez, até ${dataCurta(convite.venceEm)}.", style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant, modifier = Modifier.fillMaxWidth(), textAlign = androidx.compose.ui.text.style.TextAlign.Center)
            BotaoSecundario(
                "Enviar o link do convite",
                {
                    val envio = Intent(Intent.ACTION_SEND).setType("text/plain").putExtra(
                        Intent.EXTRA_TEXT,
                        "Quero que você acompanhe meus remédios no BULA-IA. Abra este link (vale até ${dataCurta(convite.venceEm)}): ${convite.url}\nOu use o código ${convite.code} em Cuidar de alguém.",
                    )
                    context.startActivity(Intent.createChooser(envio, "Enviar convite"))
                },
                Modifier.fillMaxWidth(),
                icone = Icons.Rounded.Share,
            )
            TextButton(onClick = aoCancelar, enabled = !ocupado, modifier = Modifier.align(Alignment.CenterHorizontally).heightIn(min = 48.dp)) {
                Text("Cancelar convite", color = MaterialTheme.colorScheme.error)
            }
        }
    }
}

/** QR Code desenhado módulo a módulo: sempre escuro sobre branco (o leitor precisa do contraste, mesmo no tema escuro). */
@Composable
private fun QrCode(convite: Convite) {
    val matriz = remember(convite.url) { qrDe(convite.url) }
    Box(Modifier.fillMaxWidth(), contentAlignment = Alignment.Center) {
        Box(
            Modifier.widthIn(max = 280.dp).fillMaxWidth(0.8f).aspectRatio(1f).clip(MaterialTheme.shapes.large).background(Color.White).padding(14.dp)
                .semantics { contentDescription = "QR Code do convite" },
        ) {
            Canvas(Modifier.fillMaxSize()) {
                val modulo = size.minDimension / matriz.width
                for (y in 0 until matriz.height) {
                    for (x in 0 until matriz.width) {
                        if (matriz[x, y]) {
                            drawRect(Color(0xFF0F2537), Offset(x * modulo, y * modulo), Size(modulo + 0.5f, modulo + 0.5f))
                        }
                    }
                }
            }
        }
    }
}

internal fun qrDe(texto: String): BitMatrix =
    QRCodeWriter().encode(texto, BarcodeFormat.QR_CODE, 0, 0, mapOf(EncodeHintType.MARGIN to 0, EncodeHintType.ERROR_CORRECTION to ErrorCorrectionLevel.M))

/** Pedido (aprovar/recusar) ou pessoa que já acompanha (mudar permissões/tirar acesso), com as caixas de permissão. */
@Composable
private fun CartaoDoCuidador(
    pessoa: Cuidador,
    dados: MeusCuidadores,
    pendente: Boolean,
    ocupado: Boolean,
    aoSalvar: (Map<String, Boolean>) -> Unit,
    aoRecusarOuTirar: () -> Unit,
) {
    val inicial = remember(pessoa.id, pessoa.permissoes) { dados.rotulos.associate { it.key to (if (pendente) true else pessoa.permissoes[it.key] == true) } }
    val marcadas = remember(pessoa.id, pessoa.permissoes) { mutableStateMapOf<String, Boolean>().apply { putAll(inicial) } }
    val mudou = marcadas.toMap() != inicial
    Cartao(LARGURA, cor = if (pendente) MaterialTheme.colorScheme.secondaryContainer.copy(alpha = 0.5f) else MaterialTheme.colorScheme.surface) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Avatar(pessoa.primeiroNome)
            Spacer(Modifier.width(12.dp))
            Column(Modifier.weight(1f)) {
                Text(pessoa.name, style = MaterialTheme.typography.titleMedium)
                pessoa.email?.let { Text(it, style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant) }
            }
        }
        Text(if (pendente) "Marque o que ${pessoa.primeiroNome} pode fazer:" else "${pessoa.primeiroNome} pode:", style = MaterialTheme.typography.bodyLarge)
        dados.rotulos.forEach { rotulo ->
            Row(
                Modifier.fillMaxWidth().clip(MaterialTheme.shapes.small).clickable(enabled = !ocupado) { marcadas[rotulo.key] = !(marcadas[rotulo.key] ?: false) }.heightIn(min = 48.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Checkbox(checked = marcadas[rotulo.key] == true, onCheckedChange = null, modifier = Modifier.padding(horizontal = 8.dp))
                Text(rotulo.label, style = MaterialTheme.typography.bodyLarge)
            }
        }
        if (pendente) {
            BotaoPrincipal("Aprovar", { aoSalvar(marcadas.toMap()) }, carregando = ocupado)
            TextButton(onClick = aoRecusarOuTirar, enabled = !ocupado, modifier = Modifier.align(Alignment.CenterHorizontally).heightIn(min = 48.dp)) {
                Text("Recusar", color = MaterialTheme.colorScheme.error)
            }
        } else {
            AnimatedVisibility(mudou) { BotaoPrincipal("Salvar permissões", { aoSalvar(marcadas.toMap()) }, carregando = ocupado) }
            TextButton(onClick = aoRecusarOuTirar, enabled = !ocupado, modifier = Modifier.align(Alignment.CenterHorizontally).heightIn(min = 48.dp)) {
                Text("Tirar acesso", color = MaterialTheme.colorScheme.error)
            }
        }
    }
}

// ---------------------------------------------------------------------------------------------------------------------
// Cuidar de alguém (quem cuida)
// ---------------------------------------------------------------------------------------------------------------------

/**
 * Quem cuida escolhe aqui de quem ver os remédios (uma linha por pessoa), lê o QR Code de um convite novo (ou digita o
 * código) e pode deixar de acompanhar alguém.
 */
@Composable
fun CuidarDeAlguemTela(preenchimento: PaddingValues, recado: (String) -> Unit, aoAbrirPessoa: (PessoaAcompanhada) -> Unit) {
    val context = LocalContext.current
    val vm = telaViewModel { CuidarDeAlguemViewModel(it.cuidado) }
    val carga by vm.estado.collectAsStateWithLifecycle()
    val enviando by vm.enviando.collectAsStateWithLifecycle()
    val erroDoCodigo by vm.erroDoCodigo.collectAsStateWithLifecycle()
    var digitar by rememberSaveable { mutableStateOf(false) }
    var codigo by rememberSaveable { mutableStateOf("") }
    var sair by remember { mutableStateOf<PessoaAcompanhada?>(null) }
    LaunchedEffect(Unit) { vm.recados.collect(recado) }

    val lerQr = {
        val opcoes = GmsBarcodeScannerOptions.Builder().setBarcodeFormats(Barcode.FORMAT_QR_CODE).enableAutoZoom().build()
        GmsBarcodeScanning.getClient(context, opcoes).startScan()
            .addOnSuccessListener { lido -> lido.rawValue?.let { vm.usarConvite(it) { codigo = ""; digitar = false } } }
            .addOnFailureListener { recado("Não deu para abrir o leitor de QR Code. Digite o código do convite.") ; digitar = true }
        Unit
    }

    PullToRefreshBox(carga.atualizando && carga.dados != null, vm::atualizar, Modifier.fillMaxSize()) {
        LazyColumn(
            Modifier.fillMaxSize(),
            contentPadding = PaddingValues(start = 20.dp, end = 20.dp, top = preenchimento.calculateTopPadding() + 8.dp, bottom = preenchimento.calculateBottomPadding() + 24.dp),
            verticalArrangement = Arrangement.spacedBy(14.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            item(key = "erro") { FaixaDeErro(carga.erro, LARGURA, vm::atualizar) }
            val dados = carga.dados
            if (dados == null) {
                if (carga.esqueleto) item(key = "esqueleto") { Esqueleto() }
            } else {
                item(key = "pessoas-titulo") { Titulo("Pessoas que você acompanha") }
                if (dados.pessoas.isEmpty()) {
                    item(key = "vazio") {
                        Text(
                            "Você ainda não acompanha ninguém. Peça para a pessoa gerar o QR Code em Conta › Quem cuida de mim, no BULA-IA dela.",
                            style = MaterialTheme.typography.bodyLarge,
                            color = MaterialTheme.colorScheme.onSurfaceVariant,
                            modifier = LARGURA.fillMaxWidth(),
                        )
                    }
                }
                items(dados.pessoas, key = { "pessoa-${it.id}" }) { pessoa ->
                    LinhaDaPessoa(pessoa, { aoAbrirPessoa(pessoa) }, { sair = pessoa }, LARGURA)
                }
                if (dados.aguardando.isNotEmpty()) {
                    item(key = "aguardando") {
                        Cartao(LARGURA) {
                            Row(verticalAlignment = Alignment.CenterVertically) {
                                Icon(Icons.Rounded.HourglassTop, contentDescription = null, tint = MaterialTheme.colorScheme.secondary)
                                Spacer(Modifier.width(10.dp))
                                Text("Esperando aprovação", style = MaterialTheme.typography.titleMedium)
                            }
                            dados.aguardando.forEach { pessoa ->
                                Row(verticalAlignment = Alignment.CenterVertically) {
                                    Text(pessoa.name, style = MaterialTheme.typography.bodyLarge, modifier = Modifier.weight(1f))
                                    TextButton(onClick = { sair = pessoa }, modifier = Modifier.heightIn(min = 48.dp)) { Text("Desistir") }
                                }
                            }
                            Text("Assim que a pessoa aprovar, os remédios dela aparecem aqui.", style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
                        }
                    }
                }
            }
            item(key = "convite") {
                Cartao(LARGURA) {
                    Text("Acompanhar mais alguém", style = MaterialTheme.typography.titleLarge, color = MaterialTheme.colorScheme.primary, modifier = Modifier.semantics { heading() })
                    Text("A pessoa gera o QR Code no BULA-IA dela, em Conta › Quem cuida de mim. Depois de ler, ela aprova o que você pode ver e fazer.", style = MaterialTheme.typography.bodyLarge)
                    BotaoPrincipal("Ler QR Code", lerQr, carregando = enviando && !digitar, icone = Icons.Rounded.QrCodeScanner)
                    if (!digitar) {
                        TextButton(onClick = { digitar = true }, modifier = Modifier.align(Alignment.CenterHorizontally).heightIn(min = 48.dp)) { Text("Digitar o código") }
                    }
                    AnimatedVisibility(digitar) {
                        Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
                            Campo(
                                valor = codigo,
                                aoMudar = { novo -> codigo = novo.filter { it.isDigit() || it == ' ' }.take(9) },
                                rotulo = "Código do convite (8 números)",
                                erro = erroDoCodigo,
                                tipo = KeyboardType.Number,
                                acao = ImeAction.Done,
                                aoConcluir = { vm.usarConvite(codigo) { codigo = ""; digitar = false } },
                            )
                            BotaoSecundario("Usar código", { vm.usarConvite(codigo) { codigo = ""; digitar = false } }, Modifier.fillMaxWidth(), habilitado = !enviando && codigo.count(Char::isDigit) == 8)
                        }
                    }
                    if (!digitar && erroDoCodigo != null) {
                        Text(erroDoCodigo.orEmpty(), style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.error)
                    }
                }
            }
        }
    }

    sair?.let { pessoa ->
        AlertDialog(
            onDismissRequest = { sair = null },
            title = { Text("Deixar de acompanhar ${pessoa.primeiroNome}?") },
            text = { Text("Você deixa de ver os remédios e de receber os avisos de ${pessoa.primeiroNome}. Para voltar, será preciso um convite novo.") },
            confirmButton = { TextButton(onClick = { vm.deixarDeAcompanhar(pessoa.id, pessoa.primeiroNome); sair = null }) { Text("Deixar de acompanhar", color = MaterialTheme.colorScheme.error) } },
            dismissButton = { TextButton(onClick = { sair = null }) { Text("Cancelar") } },
        )
    }
}

/** Uma pessoa acompanhada: toque para ver o dia dela (se tiver permissão de ver). */
@Composable
internal fun LinhaDaPessoa(pessoa: PessoaAcompanhada, aoAbrir: () -> Unit, aoSair: (() -> Unit)?, modifier: Modifier = Modifier) {
    val podeAbrir = pessoa.podeVer
    Cartao(modifier, aoClicar = if (podeAbrir) aoAbrir else null) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Avatar(pessoa.primeiroNome)
            Spacer(Modifier.width(12.dp))
            Column(Modifier.weight(1f)) {
                Text(pessoa.name, style = MaterialTheme.typography.titleMedium)
                Text(
                    when {
                        !pessoa.consentimento -> "Ainda não autorizou guardar os dados de saúde"
                        !podeAbrir -> "Você recebe só os avisos de atraso"
                        pessoa.podeMarcar -> "Ver e anotar as doses"
                        else -> "Ver os remédios e horários"
                    },
                    style = MaterialTheme.typography.bodyMedium,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            }
            Icon(if (podeAbrir) Icons.AutoMirrored.Rounded.KeyboardArrowRight else Icons.Rounded.VisibilityOff, contentDescription = null, tint = MaterialTheme.colorScheme.onSurfaceVariant)
        }
        if (aoSair != null) {
            HorizontalDivider(color = MaterialTheme.colorScheme.outlineVariant)
            pessoa.frases.forEach { Text("• $it", style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant) }
            TextButton(onClick = aoSair, modifier = Modifier.heightIn(min = 48.dp)) { Text("Deixar de acompanhar", color = MaterialTheme.colorScheme.error) }
        }
    }
}

/** No Início de quem cuida: as pessoas acompanhadas, para escolher de quem ver o dia. Não aparece para quem não cuida de ninguém. */
@Composable
fun PessoasQueVoceCuida(aoAbrirPessoa: (PessoaAcompanhada) -> Unit, aoVerTodas: () -> Unit, modifier: Modifier = Modifier) {
    val vm = telaViewModel { CuidarDeAlguemViewModel(it.cuidado) }
    val carga by vm.estado.collectAsStateWithLifecycle()
    LifecycleResumeEffect(Unit) {
        vm.atualizar()
        onPauseOrDispose { }
    }
    val pessoas = carga.dados?.pessoas.orEmpty()
    if (pessoas.isEmpty()) {
        return
    }
    Column(modifier.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(10.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Icon(Icons.Rounded.Favorite, contentDescription = null, tint = MaterialTheme.colorScheme.secondary, modifier = Modifier.size(20.dp))
            Spacer(Modifier.width(8.dp))
            Text("Quem você acompanha", style = MaterialTheme.typography.titleMedium, modifier = Modifier.weight(1f).semantics { heading() })
            TextButton(onClick = aoVerTodas, modifier = Modifier.heightIn(min = 48.dp)) { Text("Ver todas") }
        }
        pessoas.forEach { LinhaDaPessoa(it, { aoAbrirPessoa(it) }, aoSair = null) }
    }
}

// ---------------------------------------------------------------------------------------------------------------------

@Composable
private fun Avatar(nome: String) {
    Surface(shape = CircleShape, color = MaterialTheme.colorScheme.primaryContainer, modifier = Modifier.size(44.dp)) {
        Box(contentAlignment = Alignment.Center) {
            Text(nome.take(1).uppercase(), style = MaterialTheme.typography.titleMedium, color = MaterialTheme.colorScheme.onPrimaryContainer)
        }
    }
}

@Composable
private fun Titulo(texto: String) {
    Text(texto, style = MaterialTheme.typography.titleMedium, color = MaterialTheme.colorScheme.onSurfaceVariant, modifier = LARGURA.fillMaxWidth().padding(top = 6.dp).semantics { heading() })
}

@Composable
private fun Esqueleto() {
    TelaEsqueleto(LARGURA) {
        Bloco(0.6f, 22.dp)
        repeat(3) { LinhaEsqueleto() }
    }
}

private val DATA_CURTA: DateTimeFormatter = DateTimeFormatter.ofPattern("dd/MM 'às' HH:mm", Locale.forLanguageTag("pt-BR"))

internal fun dataCurta(iso: String): String = runCatching { OffsetDateTime.parse(iso).atZoneSameInstant(ZoneId.systemDefault()).format(DATA_CURTA) }.getOrDefault(iso)
