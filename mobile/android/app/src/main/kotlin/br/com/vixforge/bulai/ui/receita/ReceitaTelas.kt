package br.com.vixforge.bulai.ui.receita

import android.content.ActivityNotFoundException
import android.graphics.Bitmap
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.PickVisualMediaRequest
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.animateContentSize
import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
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
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.itemsIndexed
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.selection.selectable
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.AddAlarm
import androidx.compose.material.icons.rounded.CameraAlt
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.EditCalendar
import androidx.compose.material.icons.rounded.ErrorOutline
import androidx.compose.material.icons.automirrored.rounded.HelpOutline
import androidx.compose.material.icons.rounded.PhotoLibrary
import androidx.compose.material.icons.rounded.Shield
import androidx.compose.material.icons.rounded.WarningAmber
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.DatePicker
import androidx.compose.material3.DatePickerDialog
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.InputChip
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.RadioButton
import androidx.compose.material3.SelectableDates
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TimePicker
import androidx.compose.material3.rememberDatePickerState
import androidx.compose.material3.rememberTimePickerState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.produceState
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardCapitalization
import androidx.compose.ui.unit.dp
import androidx.core.net.toUri
import androidx.core.content.FileProvider
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import br.com.vixforge.bulai.dados.Fotos
import br.com.vixforge.bulai.dados.api.ItemDaReceita
import br.com.vixforge.bulai.dados.api.Receita
import br.com.vixforge.bulai.ui.base.telaViewModel
import br.com.vixforge.bulai.ui.componentes.BotaoPrincipal
import br.com.vixforge.bulai.ui.componentes.BotaoSecundario
import br.com.vixforge.bulai.ui.componentes.Campo
import br.com.vixforge.bulai.ui.componentes.Cartao
import br.com.vixforge.bulai.ui.componentes.CartaoEsqueleto
import br.com.vixforge.bulai.ui.componentes.FaixaDeErro
import br.com.vixforge.bulai.ui.perguntar.BuscaDeRemedio
import br.com.vixforge.bulai.ui.perguntar.Pilula
import br.com.vixforge.bulai.ui.tema.TemaBulai
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import java.io.File
import java.time.Instant
import java.time.LocalDate
import java.time.ZoneOffset
import java.time.format.DateTimeFormatter

/** 1 Foto → 2 Conferir → 3 Pronto, como no site. */
@Composable
private fun Etapas(atual: Int, modifier: Modifier = Modifier) {
    Row(modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
        listOf("Foto", "Conferir", "Pronto").forEachIndexed { indice, rotulo ->
            val n = indice + 1
            val cor = when {
                n < atual -> TemaBulai.extras.sucesso
                n == atual -> MaterialTheme.colorScheme.secondary
                else -> MaterialTheme.colorScheme.surfaceVariant
            }
            Box(Modifier.size(32.dp).clip(CircleShape).background(cor), contentAlignment = Alignment.Center) {
                if (n < atual) {
                    Icon(Icons.Rounded.CheckCircle, contentDescription = null, tint = MaterialTheme.colorScheme.surface, modifier = Modifier.size(20.dp))
                } else {
                    Text("$n", fontWeight = FontWeight.Bold, color = if (n == atual) MaterialTheme.colorScheme.onSecondary else MaterialTheme.colorScheme.onSurfaceVariant)
                }
            }
            Spacer(Modifier.width(6.dp))
            Text(rotulo, style = MaterialTheme.typography.labelLarge, color = if (n == atual) MaterialTheme.colorScheme.onSurface else MaterialTheme.colorScheme.onSurfaceVariant)
            if (n < 3) {
                Box(Modifier.weight(1f).padding(horizontal = 8.dp).height(2.dp).background(if (n < atual) TemaBulai.extras.sucesso else MaterialTheme.colorScheme.surfaceVariant))
            }
        }
    }
}

/**
 * Receita por foto, passo 1: câmera (pelo app de câmera do aparelho, sem pedir permissão) ou galeria (seletor de fotos do
 * Android); a pessoa confere a prévia e envia.
 */
@Composable
fun NovaReceitaTela(preenchimento: PaddingValues, aoEnviar: (Long) -> Unit, aoAbrirPerfil: () -> Unit) {
    val context = LocalContext.current
    val vm = telaViewModel { NovaReceitaViewModel(it.receitas) }
    val estado by vm.estado.collectAsStateWithLifecycle()
    var foto by rememberSaveable { mutableStateOf<String?>(null) }
    var destinoDaCamera by rememberSaveable { mutableStateOf<String?>(null) }

    val camera = rememberLauncherForActivityResult(ActivityResultContracts.TakePicture()) { tirou ->
        if (tirou) {
            foto = destinoDaCamera
            vm.limparErro()
        }
    }
    val galeria = rememberLauncherForActivityResult(ActivityResultContracts.PickVisualMedia()) { escolhida ->
        if (escolhida != null) {
            foto = escolhida.toString()
            vm.limparErro()
        }
    }
    fun abrirCamera() {
        val pasta = File(context.cacheDir, "fotos").apply { mkdirs() }
        pasta.listFiles()?.forEach { it.delete() }
        val arquivo = File(pasta, "receita-${System.currentTimeMillis()}.jpg")
        val uri = FileProvider.getUriForFile(context, "${context.packageName}.arquivos", arquivo)
        destinoDaCamera = uri.toString()
        try {
            camera.launch(uri)
        } catch (_: ActivityNotFoundException) {
            vm.avisar("Não encontramos um app de câmera neste celular. Escolha a foto da galeria.")
        }
    }

    val previa by produceState<Bitmap?>(null, foto) {
        value = foto?.let { caminho -> withContext(Dispatchers.IO) { runCatching { Fotos.carregar(context, caminho.toUri(), 1200) }.getOrNull() } }
    }

    Column(
        Modifier.fillMaxSize().padding(preenchimento).verticalScroll(rememberScrollState()).padding(16.dp),
        verticalArrangement = Arrangement.spacedBy(16.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Column(Modifier.widthIn(max = 640.dp), verticalArrangement = Arrangement.spacedBy(16.dp)) {
            Etapas(1)
            if (estado.semConsentimento) {
                Cartao(cor = MaterialTheme.colorScheme.secondaryContainer) {
                    Text("Antes, autorize guardar seus dados de saúde", style = MaterialTheme.typography.titleLarge)
                    Text("A receita vira remédios em uso no seu perfil, e isso é dado de saúde. Aceite o consentimento no seu perfil e volte aqui.", style = MaterialTheme.typography.bodyLarge)
                    BotaoSecundario("Abrir no site", aoAbrirPerfil)
                }
            } else {
                FaixaDeErro(estado.erro)
            }

            Cartao {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Icon(Icons.Rounded.CameraAlt, contentDescription = null, tint = TemaBulai.extras.sucesso, modifier = Modifier.size(36.dp))
                    Spacer(Modifier.width(12.dp))
                    Text("Tire uma foto da receita inteira, com o texto nítido.", style = MaterialTheme.typography.titleMedium, modifier = Modifier.semantics { heading() })
                }
                BotaoPrincipal("Tirar foto da receita", ::abrirCamera, destaque = foto == null, icone = Icons.Rounded.CameraAlt, habilitado = !estado.enviando)
                BotaoSecundario("Escolher da galeria", { galeria.launch(PickVisualMediaRequest(ActivityResultContracts.PickVisualMedia.ImageOnly)) }, icone = Icons.Rounded.PhotoLibrary, habilitado = !estado.enviando)

                AnimatedVisibility(foto != null) {
                    Column(verticalArrangement = Arrangement.spacedBy(12.dp)) {
                        Box(Modifier.fillMaxWidth().heightIn(min = 160.dp).clip(MaterialTheme.shapes.medium).background(MaterialTheme.colorScheme.surfaceVariant), contentAlignment = Alignment.Center) {
                            previa?.let {
                                Image(it.asImageBitmap(), contentDescription = "Prévia da receita", contentScale = ContentScale.Fit, modifier = Modifier.fillMaxWidth().heightIn(max = 380.dp))
                            } ?: CircularProgressIndicator()
                        }
                        Text("Dá para ler o texto? Se sim, toque em “Ler esta receita”.", style = MaterialTheme.typography.bodyLarge)
                        BotaoPrincipal("Ler esta receita", { foto?.let { vm.enviar(it.toUri(), aoEnviar) } }, carregando = estado.enviando, destaque = true)
                    }
                }
            }

            Row(verticalAlignment = Alignment.Top) {
                Icon(Icons.Rounded.Shield, contentDescription = null, tint = MaterialTheme.colorScheme.primary, modifier = Modifier.size(20.dp))
                Spacer(Modifier.width(8.dp))
                Text("A foto é lida e apagada em seguida. Você confere cada remédio antes de salvar.", style = MaterialTheme.typography.bodyMedium)
            }
            Row(verticalAlignment = Alignment.Top) {
                Icon(Icons.AutoMirrored.Rounded.HelpOutline, contentDescription = null, tint = MaterialTheme.colorScheme.primary, modifier = Modifier.size(20.dp))
                Spacer(Modifier.width(8.dp))
                Text("O BULA-IA não muda a receita do seu médico.", style = MaterialTheme.typography.bodyMedium)
            }
        }
    }
}

/** Passo 2: leitura em andamento, falha, já confirmada ou a revisão de cada item. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun RevisarReceitaTela(id: Long, preenchimento: PaddingValues, aoConcluir: (String) -> Unit, aoTentarOutraFoto: () -> Unit, aoVerHoje: () -> Unit) {
    val vm = telaViewModel { RevisarReceitaViewModel(it.receitas, it.perguntas, id) }
    val estado by vm.estado.collectAsStateWithLifecycle()
    var buscandoPara by rememberSaveable { mutableStateOf<Int?>(null) }
    var confirmarDescarte by rememberSaveable { mutableStateOf(false) }
    val receita = estado.receita
    val revisando = receita?.status == Receita.EM_REVISAO

    Column(Modifier.fillMaxSize().padding(preenchimento).consumeWindowInsets(preenchimento).imePadding()) {
        LazyColumn(
            Modifier.weight(1f).fillMaxWidth(),
            contentPadding = PaddingValues(16.dp),
            verticalArrangement = Arrangement.spacedBy(14.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            val largura = Modifier.widthIn(max = 640.dp)
            item(key = "etapas") { Etapas(if (receita?.status == Receita.CONFIRMADA) 3 else 2, largura) }
            item(key = "erro") { Box(largura) { FaixaDeErro(estado.erroDeCarga, aoTentarDeNovo = vm::acompanhar) } }
            when {
                estado.erroDeCarga != null && receita == null -> Unit
                estado.lendo -> {
                    item(key = "lendo") {
                        Cartao(largura, cor = MaterialTheme.colorScheme.primaryContainer) {
                            Row(verticalAlignment = Alignment.CenterVertically) {
                                CircularProgressIndicator(Modifier.size(28.dp), strokeWidth = 3.dp)
                                Spacer(Modifier.width(14.dp))
                                Text("Lendo a receita…", style = MaterialTheme.typography.titleLarge)
                            }
                            Text("Leva alguns segundos. A foto é apagada quando terminar.", style = MaterialTheme.typography.bodyLarge)
                        }
                    }
                    items(2) { CartaoEsqueleto(largura) }
                }
                receita?.status == Receita.FALHOU -> item(key = "falhou") {
                    Cartao(largura, cor = MaterialTheme.colorScheme.errorContainer) {
                        Icon(Icons.Rounded.ErrorOutline, contentDescription = null, tint = MaterialTheme.colorScheme.error, modifier = Modifier.size(36.dp))
                        Text("Não conseguimos ler esta receita.", style = MaterialTheme.typography.titleLarge)
                        Text("${receita.mensagemDoErro.orEmpty()} Nada foi salvo.", style = MaterialTheme.typography.bodyLarge)
                        receita.codigoDoErro?.let { Text("Código técnico: $it.", style = MaterialTheme.typography.bodySmall) }
                        BotaoPrincipal("Tentar outra foto", aoTentarOutraFoto, icone = Icons.Rounded.CameraAlt)
                    }
                }
                receita?.status == Receita.CONFIRMADA -> item(key = "pronta") {
                    Cartao(largura, cor = TemaBulai.extras.sucessoFundo) {
                        Icon(Icons.Rounded.CheckCircle, contentDescription = null, tint = TemaBulai.extras.sucesso, modifier = Modifier.size(36.dp))
                        Text("Pronto! Esta receita já foi confirmada e seus remédios estão na sua lista.", style = MaterialTheme.typography.titleLarge)
                        BotaoPrincipal("Ver remédios de hoje", aoVerHoje, destaque = true)
                    }
                }
                revisando -> {
                    item(key = "orientacao") { Orientacao(receita, largura) }
                    item(key = "cabecalho") { CartaoDoPrescritor(estado, vm, largura) }
                    if (estado.itens.isEmpty()) {
                        item(key = "vazio") {
                            Cartao(largura) {
                                Text("Nenhum remédio foi identificado na foto.", style = MaterialTheme.typography.titleLarge)
                                Text("Tente outra foto mais nítida, com a receita inteira.", style = MaterialTheme.typography.bodyLarge)
                                BotaoSecundario("Tentar outra foto", aoTentarOutraFoto)
                            }
                        }
                    }
                    itemsIndexed(estado.itens, key = { indice, _ -> "item$indice" }) { indice, item ->
                        CartaoDoItem(indice, item, vm, aoBuscar = { buscandoPara = indice }, modifier = largura.animateContentSize())
                    }
                    item(key = "descartar") {
                        OutlinedButton(
                            onClick = { confirmarDescarte = true },
                            modifier = largura.fillMaxWidth().heightIn(min = 56.dp),
                            border = BorderStroke(1.dp, MaterialTheme.colorScheme.error.copy(alpha = 0.5f)),
                        ) { Text("Descartar esta receita", color = MaterialTheme.colorScheme.error, style = MaterialTheme.typography.labelLarge) }
                    }
                }
            }
        }

        if (revisando) {
            Surface(tonalElevation = 3.dp, shadowElevation = 8.dp) {
                Column(Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 12.dp), horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    estado.aviso?.let { Text(it, color = TemaBulai.extras.alerta, style = MaterialTheme.typography.bodyMedium, modifier = Modifier.widthIn(max = 640.dp)) }
                    BotaoPrincipal("Confirmar e salvar", { vm.confirmar(aoConcluir) }, modifier = Modifier.widthIn(max = 640.dp), carregando = estado.salvando, destaque = true, icone = Icons.Rounded.CheckCircle)
                }
            }
        }
    }

    buscandoPara?.let { indice ->
        val item = estado.itens.getOrNull(indice)
        BuscaDeRemedio(
            buscando = estado.buscando,
            resultados = estado.resultados,
            aoBuscar = vm::buscar,
            aoEscolher = { remedio ->
                val nome = estado.resultados?.firstOrNull { it.id == remedio }?.name ?: item?.candidates?.firstOrNull { it.id == remedio }?.name.orEmpty()
                vm.escolher(indice, remedio, nome)
                buscandoPara = null
            },
            aoFechar = { buscandoPara = null; vm.fecharBusca() },
            titulo = "Qual é o remédio “${item?.nomeEscrito.orEmpty()}”?",
            extra = {
                if (item != null) {
                    Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                        if (item.candidates.isNotEmpty()) {
                            Text("Parecidos no catálogo:", style = MaterialTheme.typography.bodyMedium)
                            FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                                item.candidates.forEach { candidato ->
                                    Pilula(candidato.name, { vm.escolher(indice, candidato.id, candidato.name); buscandoPara = null }, destaque = candidato.id == item.remedioId)
                                }
                            }
                        }
                        if (item.remedioId != null) {
                            Pilula("Nenhum destes: manter “${item.nomeEscrito}”", { vm.manterPeloNome(indice); buscandoPara = null })
                        }
                    }
                }
            },
        )
    }

    if (confirmarDescarte) {
        AlertDialog(
            onDismissRequest = { confirmarDescarte = false },
            title = { Text("Descartar esta receita?") },
            text = { Text("Nada desta receita será salvo no seu tratamento.") },
            confirmButton = { TextButton(onClick = { confirmarDescarte = false; vm.descartar(aoConcluir) }) { Text("Descartar", color = MaterialTheme.colorScheme.error) } },
            dismissButton = { TextButton(onClick = { confirmarDescarte = false }) { Text("Cancelar") } },
        )
    }
}

@Composable
private fun Orientacao(receita: Receita, modifier: Modifier) {
    Column(modifier, verticalArrangement = Arrangement.spacedBy(10.dp)) {
        if (receita.textoIgnorado) {
            Cartao(cor = MaterialTheme.colorScheme.secondaryContainer) {
                Text("Parte do que estava escrito na receita foi ignorada porque não parecia ser receita. Confira os remédios e complete o que faltar.", style = MaterialTheme.typography.bodyLarge)
            }
        }
        if (receita.legibility == "poor") {
            Cartao(cor = MaterialTheme.colorScheme.secondaryContainer) {
                Text("A foto ficou pouco legível.", style = MaterialTheme.typography.titleMedium)
                Text("Confira cada item com atenção ou tente outra foto.", style = MaterialTheme.typography.bodyLarge)
            }
        } else {
            Text("Está tudo certo? Confira cada remédio e toque em Confirmar.", style = MaterialTheme.typography.titleMedium)
            Text("Remédio que não achamos no catálogo também entra no seu tratamento; só fica marcado para a nossa equipe conferir.", style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
            if (receita.legibility == "partial") {
                Text("Itens marcados com “confira” ficaram difíceis de ler.", style = MaterialTheme.typography.bodyMedium)
            }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun CartaoDoPrescritor(estado: EstadoDaRevisao, vm: RevisarReceitaViewModel, modifier: Modifier) {
    var escolherData by rememberSaveable { mutableStateOf(false) }
    Cartao(modifier) {
        Text("Quem receitou", style = MaterialTheme.typography.titleMedium, modifier = Modifier.semantics { heading() })
        Campo(estado.cabecalho.prescriber, { texto -> vm.mudarCabecalho { it.copy(prescriber = texto.take(120)) } }, "Médico(a)", erro = estado.erroDoPrescritor)
        Campo(estado.cabecalho.registration, { texto -> vm.mudarCabecalho { it.copy(registration = texto.take(40)) } }, "CRM (opcional)")
        val data = estado.cabecalho.date?.let { runCatching { LocalDate.parse(it).format(DateTimeFormatter.ofPattern("dd/MM/yyyy")) }.getOrNull() }
        BotaoSecundario(data?.let { "Data da receita: $it" } ?: "Informar a data da receita", { escolherData = true }, icone = Icons.Rounded.EditCalendar)
    }
    if (escolherData) {
        val hoje = LocalDate.now()
        val inicial = estado.cabecalho.date?.let { runCatching { LocalDate.parse(it) }.getOrNull() } ?: hoje
        val seletor = rememberDatePickerState(
            initialSelectedDateMillis = inicial.atStartOfDay(ZoneOffset.UTC).toInstant().toEpochMilli(),
            selectableDates = object : SelectableDates {
                override fun isSelectableDate(utcTimeMillis: Long) = !Instant.ofEpochMilli(utcTimeMillis).atZone(ZoneOffset.UTC).toLocalDate().isAfter(hoje)
            },
        )
        DatePickerDialog(
            onDismissRequest = { escolherData = false },
            confirmButton = {
                TextButton(onClick = {
                    seletor.selectedDateMillis?.let { millis -> vm.mudarCabecalho { it.copy(date = Instant.ofEpochMilli(millis).atZone(ZoneOffset.UTC).toLocalDate().toString()) } }
                    escolherData = false
                }) { Text("OK") }
            },
            dismissButton = { TextButton(onClick = { escolherData = false }) { Text("Cancelar") } },
        ) { DatePicker(seletor) }
    }
}

@Composable
private fun CartaoDoItem(indice: Int, item: ItemDaReceita, vm: RevisarReceitaViewModel, aoBuscar: () -> Unit, modifier: Modifier = Modifier) {
    var editar by rememberSaveable(indice) { mutableStateOf(false) }
    var novoHorario by rememberSaveable(indice) { mutableStateOf(false) }
    var trocarHorario by rememberSaveable(indice) { mutableStateOf<Int?>(null) }
    val extras = TemaBulai.extras

    Cartao(modifier, cor = if (item.skip) MaterialTheme.colorScheme.surfaceVariant.copy(alpha = 0.5f) else MaterialTheme.colorScheme.surface) {
        Row(verticalAlignment = Alignment.Top) {
            Box(Modifier.size(36.dp).clip(CircleShape).background(MaterialTheme.colorScheme.primaryContainer), contentAlignment = Alignment.Center) {
                Text("${indice + 1}", fontWeight = FontWeight.Bold, color = MaterialTheme.colorScheme.onPrimaryContainer)
            }
            Spacer(Modifier.width(12.dp))
            Column(Modifier.weight(1f)) {
                Text(item.nomeEscrito, style = MaterialTheme.typography.titleLarge, modifier = Modifier.semantics { heading() })
                if (!item.skip) {
                    Text(listOf(item.dosage, item.frequency, item.duration).filter { it.isNotBlank() }.joinToString(" · ").ifEmpty { "Dose e horário não lidos" }, style = MaterialTheme.typography.bodyLarge)
                }
            }
        }
        if (item.incerto && !item.skip) {
            Surface(shape = CircleShape, color = MaterialTheme.colorScheme.secondaryContainer, contentColor = MaterialTheme.colorScheme.onSecondaryContainer) {
                Row(Modifier.padding(horizontal = 12.dp, vertical = 6.dp), verticalAlignment = Alignment.CenterVertically) {
                    Icon(Icons.Rounded.WarningAmber, contentDescription = null, modifier = Modifier.size(18.dp))
                    Spacer(Modifier.width(6.dp))
                    Text("Leitura incerta: confira", style = MaterialTheme.typography.labelLarge)
                }
            }
        }

        if (item.skip) {
            Text("Este item não vai ser cadastrado.", style = MaterialTheme.typography.bodyLarge)
            BotaoSecundario("Incluir este item de novo", { vm.pular(indice) })
            return@Cartao
        }

        val noCatalogo = item.remedioId != null
        Row(
            Modifier.fillMaxWidth().clip(MaterialTheme.shapes.medium).background(if (noCatalogo) MaterialTheme.colorScheme.primaryContainer.copy(alpha = 0.6f) else MaterialTheme.colorScheme.secondaryContainer).padding(14.dp),
            verticalAlignment = Alignment.Top,
        ) {
            Icon(if (noCatalogo) Icons.Rounded.CheckCircle else Icons.Rounded.WarningAmber, contentDescription = null, tint = if (noCatalogo) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.onSecondaryContainer)
            Spacer(Modifier.width(10.dp))
            Column {
                if (noCatalogo) {
                    Text("Remédio do catálogo", style = MaterialTheme.typography.bodyMedium)
                    Text(item.remedioNome.orEmpty(), style = MaterialTheme.typography.titleMedium)
                } else {
                    Text("Não encontrado no catálogo: vamos conferir", style = MaterialTheme.typography.titleMedium)
                    Text("Ele entra no seu tratamento com o nome escrito na receita.", style = MaterialTheme.typography.bodyMedium)
                }
            }
        }

        Text("Horários", style = MaterialTheme.typography.titleMedium)
        when {
            item.seNecessario -> Text("Só quando precisar: sem horário fixo.", style = MaterialTheme.typography.bodyLarge)
            item.times.isEmpty() -> Text("Sem horário definido. Toque em “Adicionar horário” se quiser lembretes.", style = MaterialTheme.typography.bodyLarge)
        }
        if (!item.seNecessario && item.intervaloHoras != null) {
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                OpcaoDeModo(
                    item.modo == ItemDaReceita.MODO_INTERVALO,
                    "Contar a partir de quando eu tomar (recomendado)",
                    "A cada ${item.intervaloHoras} h depois do horário em que você marcar “Tomei”.",
                ) { vm.mudarItem(indice) { it.copy(modo = ItemDaReceita.MODO_INTERVALO) } }
                OpcaoDeModo(item.modo == ItemDaReceita.MODO_FIXO, "Horários fixos todos os dias", "Sempre nos mesmos horários, mesmo se atrasar uma dose.") {
                    vm.mudarItem(indice) { it.copy(modo = ItemDaReceita.MODO_FIXO) }
                }
            }
        }
        val horarios = if (item.contaDoUltimo) item.times.take(1) else item.times
        if (horarios.isNotEmpty()) {
            if (item.contaDoUltimo) {
                Text("Primeiro horário do dia:", style = MaterialTheme.typography.bodyMedium)
            }
            FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                horarios.forEachIndexed { posicao, hora ->
                    InputChip(
                        selected = false,
                        onClick = { trocarHorario = posicao },
                        label = { Text(hora, style = MaterialTheme.typography.titleMedium) },
                        trailingIcon = if (item.contaDoUltimo) {
                            null
                        } else {
                            { IconButton(onClick = { vm.tirarHorario(indice, posicao) }, modifier = Modifier.size(32.dp)) { Icon(Icons.Rounded.Close, contentDescription = "Tirar o horário $hora") } }
                        },
                        modifier = Modifier.heightIn(min = 48.dp),
                    )
                }
            }
        }
        if (item.contaDoUltimo) {
            Text("Os outros horários são calculados a cada ${item.intervaloHoras} h a partir da última dose que você marcar.", style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
        } else if (item.times.size < 12) {
            TextButton(onClick = { novoHorario = true }, modifier = Modifier.heightIn(min = 48.dp)) {
                Icon(Icons.Rounded.AddAlarm, contentDescription = null)
                Spacer(Modifier.width(8.dp))
                Text("Adicionar horário", style = MaterialTheme.typography.labelLarge)
            }
        }

        Text("Está certo?", style = MaterialTheme.typography.titleMedium)
        BotaoSecundario(if (noCatalogo) "Não, trocar o remédio" else "Buscar o remédio", aoBuscar)
        BotaoSecundario(if (editar) "Fechar" else "Corrigir dose ou horário", { editar = !editar })
        AnimatedVisibility(editar) {
            Column(Modifier.clip(MaterialTheme.shapes.medium).background(MaterialTheme.colorScheme.surfaceVariant.copy(alpha = 0.5f)).padding(12.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                CampoLivre(item.dosage, { texto -> vm.mudarItem(indice) { it.copy(dosage = texto.take(120)) } }, "Dose", "Ex.: 1 comprimido de 500 mg")
                CampoLivre(item.frequency, { vm.mudarQuandoTomar(indice, it) }, "Quando tomar", "Ex.: de 8 em 8 horas")
                CampoLivre(item.duration, { texto -> vm.mudarItem(indice) { it.copy(duration = texto.take(80)) } }, "Por quanto tempo", "Ex.: 7 dias ou uso contínuo")
                CampoLivre(item.notes, { texto -> vm.mudarItem(indice) { it.copy(notes = texto.take(255)) } }, "Observações", "Ex.: após as refeições")
            }
        }
        TextButton(onClick = { vm.pular(indice) }, modifier = Modifier.heightIn(min = 48.dp)) { Text("Não cadastrar este", style = MaterialTheme.typography.labelLarge) }
    }

    if (novoHorario) {
        // Segundo horário em diante: sugere 12 h depois do último (não repete o mesmo).
        val sugestao = item.times.lastOrNull()?.split(":")?.firstOrNull()?.toIntOrNull()?.let { "%02d:00".format((it + 12) % 24) } ?: "08:00"
        EscolherHora(inicial = sugestao, aoEscolher = { vm.adicionarHorario(indice, it); novoHorario = false }, aoFechar = { novoHorario = false })
    }
    trocarHorario?.let { posicao ->
        EscolherHora(inicial = item.times.getOrNull(posicao), aoEscolher = { vm.trocarHorario(indice, posicao, it); trocarHorario = null }, aoFechar = { trocarHorario = null })
    }
}

@Composable
private fun CampoLivre(valor: String, aoMudar: (String) -> Unit, rotulo: String, exemplo: String) {
    OutlinedTextField(
        value = valor,
        onValueChange = aoMudar,
        label = { Text(rotulo) },
        placeholder = { Text(exemplo) },
        modifier = Modifier.fillMaxWidth(),
        textStyle = MaterialTheme.typography.bodyLarge,
        singleLine = true,
        keyboardOptions = KeyboardOptions(capitalization = KeyboardCapitalization.Sentences),
        shape = MaterialTheme.shapes.medium,
    )
}

@Composable
private fun OpcaoDeModo(selecionada: Boolean, titulo: String, texto: String, aoEscolher: () -> Unit) {
    Surface(
        shape = MaterialTheme.shapes.medium,
        border = BorderStroke(2.dp, if (selecionada) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.outlineVariant),
        color = if (selecionada) MaterialTheme.colorScheme.primaryContainer.copy(alpha = 0.5f) else MaterialTheme.colorScheme.surface,
        modifier = Modifier.fillMaxWidth().selectable(selecionada, role = Role.RadioButton, onClick = aoEscolher),
    ) {
        Row(Modifier.padding(12.dp), verticalAlignment = Alignment.Top) {
            RadioButton(selecionada, onClick = null)
            Spacer(Modifier.width(10.dp))
            Column {
                Text(titulo, style = MaterialTheme.typography.bodyLarge, fontWeight = FontWeight.SemiBold)
                Text(texto, style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
            }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun EscolherHora(inicial: String?, aoEscolher: (String) -> Unit, aoFechar: () -> Unit) {
    val (hora, minuto) = inicial?.split(":")?.mapNotNull { it.toIntOrNull() }?.takeIf { it.size == 2 }?.let { it[0] to it[1] } ?: (8 to 0)
    val seletor = rememberTimePickerState(initialHour = hora, initialMinute = minuto, is24Hour = true)
    AlertDialog(
        onDismissRequest = aoFechar,
        title = { Text("Horário do remédio") },
        text = { Box(Modifier.fillMaxWidth(), contentAlignment = Alignment.Center) { TimePicker(seletor) } },
        confirmButton = { TextButton(onClick = { aoEscolher("%02d:%02d".format(seletor.hour, seletor.minute)) }) { Text("OK") } },
        dismissButton = { TextButton(onClick = aoFechar) { Text("Cancelar") } },
    )
}
