package br.com.vixforge.bulai.ui.conta

import android.app.Application
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.animateContentSize
import androidx.compose.animation.expandVertically
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.shrinkVertically
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.foundation.selection.selectable
import androidx.compose.foundation.selection.selectableGroup
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.KeyboardArrowRight
import androidx.compose.material.icons.automirrored.rounded.Logout
import androidx.compose.material.icons.rounded.DarkMode
import androidx.compose.material.icons.rounded.DeleteForever
import androidx.compose.material.icons.rounded.ExpandLess
import androidx.compose.material.icons.rounded.ExpandMore
import androidx.compose.material.icons.rounded.History
import androidx.compose.material.icons.rounded.LightMode
import androidx.compose.material.icons.rounded.Lock
import androidx.compose.material.icons.rounded.Palette
import androidx.compose.material.icons.rounded.PhoneAndroid
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.RadioButton
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.autofill.ContentType
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalFocusManager
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.unit.dp
import androidx.lifecycle.ViewModel
import androidx.lifecycle.compose.LifecycleResumeEffect
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.lifecycle.viewModelScope
import br.com.vixforge.bulai.BuildConfig
import br.com.vixforge.bulai.container
import br.com.vixforge.bulai.dados.ContaRepositorio
import br.com.vixforge.bulai.dados.EstadoDaSessao
import br.com.vixforge.bulai.dados.Preferencias
import br.com.vixforge.bulai.dados.api.FalhaDaApi
import br.com.vixforge.bulai.ui.avisos.AlarmesDoCelular
import br.com.vixforge.bulai.ui.avisos.AvisosViewModel
import br.com.vixforge.bulai.ui.avisos.Lembretes
import br.com.vixforge.bulai.ui.base.telaViewModel
import br.com.vixforge.bulai.ui.componentes.BotaoPrincipal
import br.com.vixforge.bulai.ui.componentes.Campo
import br.com.vixforge.bulai.ui.componentes.Cartao
import br.com.vixforge.bulai.ui.sistema.rememberPedirPermissao
import kotlinx.coroutines.flow.MutableSharedFlow
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.asSharedFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

data class EstadoDaConta(
    val salvandoNome: Boolean = false,
    val erroDoNome: String? = null,
    val trocandoSenha: Boolean = false,
    val errosDaSenha: Map<String, String> = emptyMap(),
    val senhaTrocada: Int = 0,
    val apagando: Boolean = false,
    val erroAoApagar: String? = null,
)

/** Nome de chamada e troca de senha; a sessão é atualizada com o que o servidor devolve. */
class ContaViewModel(private val conta: ContaRepositorio) : ViewModel() {
    private val _estado = MutableStateFlow(EstadoDaConta())
    val estado = _estado.asStateFlow()

    private val _recados = MutableSharedFlow<String>(extraBufferCapacity = 4)
    val recados = _recados.asSharedFlow()

    init {
        viewModelScope.launch { conta.atualizar() }
    }

    fun salvarNome(nome: String) {
        if (_estado.value.salvandoNome) {
            return
        }
        _estado.update { it.copy(salvandoNome = true, erroDoNome = null) }
        viewModelScope.launch {
            conta.mudarNomeDeChamada(nome)
                .onSuccess { usuario ->
                    _estado.update { it.copy(salvandoNome = false) }
                    _recados.tryEmit(if (usuario.nomeDeChamada == null) "Pronto: vamos chamar você de ${usuario.primeiroNome}." else "Pronto: agora chamamos você de ${usuario.primeiroNome}.")
                }
                .onFailure { falha -> _estado.update { it.copy(salvandoNome = false, erroDoNome = (falha as? FalhaDaApi.Validacao)?.campos?.get("preferred_name") ?: falha.message) } }
        }
    }

    fun apagarConta(senha: String, aoApagar: (String) -> Unit) {
        if (_estado.value.apagando) {
            return
        }
        _estado.update { it.copy(apagando = true, erroAoApagar = null) }
        viewModelScope.launch {
            conta.apagarConta(senha)
                .onSuccess { aoApagar(it.message) }
                .onFailure { falha ->
                    _estado.update { it.copy(apagando = false, erroAoApagar = (falha as? FalhaDaApi.Validacao)?.campos?.get("password") ?: falha.message) }
                }
        }
    }

    fun limparErroAoApagar() = _estado.update { it.copy(erroAoApagar = null) }

    fun trocarSenha(atual: String, nova: String, confirmacao: String) {
        if (_estado.value.trocandoSenha) {
            return
        }
        _estado.update { it.copy(trocandoSenha = true, errosDaSenha = emptyMap()) }
        viewModelScope.launch {
            conta.trocarSenha(atual, nova, confirmacao)
                .onSuccess { resposta ->
                    _estado.update { it.copy(trocandoSenha = false, senhaTrocada = it.senhaTrocada + 1) }
                    _recados.tryEmit(resposta.message)
                }
                .onFailure { falha ->
                    val campos = (falha as? FalhaDaApi.Validacao)?.campos.orEmpty()
                    _estado.update { it.copy(trocandoSenha = false, errosDaSenha = campos.ifEmpty { mapOf("geral" to (falha.message ?: "Não deu para trocar a senha agora.")) }) }
                }
        }
    }
}

/**
 * Conta: quem está conectado, como quer ser chamado, aparência (tema), lembretes, alarmes deste celular, senha, Minhas
 * perguntas e sair. Os avisos em si ficam no sininho da barra superior.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun ContaAreaTela(preenchimento: PaddingValues, recado: (String) -> Unit, aoAbrirPerguntas: () -> Unit) {
    val context = LocalContext.current
    val container = context.container
    val vm = telaViewModel { ContaViewModel(it.conta) }
    val app = context.applicationContext as Application
    val avisos = telaViewModel { AvisosViewModel(app, it.avisos) }
    val estado by vm.estado.collectAsStateWithLifecycle()
    val central by avisos.estado.collectAsStateWithLifecycle()
    val alarmes by avisos.alarmes.collectAsStateWithLifecycle()
    val sessao by container.sessao.estado.collectAsStateWithLifecycle()
    val tema by container.tema.collectAsStateWithLifecycle()
    val usuario = (sessao as? EstadoDaSessao.Conectado)?.usuario
    val pedirPermissao = rememberPedirPermissao(avisos::lerAlarmes)
    var confirmarSaida by rememberSaveable { mutableStateOf(false) }
    var apagarConta by rememberSaveable { mutableStateOf(false) }
    LaunchedEffect(Unit) { vm.recados.collect(recado) }
    LaunchedEffect(Unit) { avisos.recados.collect(recado) }
    LifecycleResumeEffect(Unit) {
        avisos.atualizar()
        onPauseOrDispose { }
    }

    LazyColumn(
        Modifier.fillMaxSize(),
        contentPadding = PaddingValues(start = 20.dp, end = 20.dp, top = preenchimento.calculateTopPadding() + 8.dp, bottom = preenchimento.calculateBottomPadding() + 24.dp),
        verticalArrangement = Arrangement.spacedBy(14.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        val largura = Modifier.widthIn(max = 640.dp)
        item(key = "quem") { Identificacao(usuario?.primeiroNome.orEmpty(), usuario?.name.orEmpty(), usuario?.email.orEmpty(), largura) }
        item(key = "nome") {
            NomeDeChamada(usuario?.nomeDeChamada.orEmpty(), usuario?.name.orEmpty(), estado.salvandoNome, estado.erroDoNome, vm::salvarNome, largura)
        }
        item(key = "tema") { Aparencia(tema, container::escolherTema, largura) }
        central.dados?.let { dados ->
            item(key = "lembretes") {
                if (dados.consentimento) {
                    Lembretes(dados, avisos::lembretes, avisos::email, avisos::fuso, largura)
                } else {
                    Cartao(largura) {
                        Text("Lembretes", style = MaterialTheme.typography.titleLarge, color = MaterialTheme.colorScheme.primary)
                        Text("Para receber lembretes dos remédios, autorize guardar seus dados de saúde no perfil.", style = MaterialTheme.typography.bodyLarge)
                    }
                }
            }
        }
        alarmes?.let { situacao ->
            item(key = "alarmes") { AlarmesDoCelular(situacao, pedirPermissao, avisos::modoDespertador, avisos::adiamento, avisos::testarAlarme, largura) }
        }
        item(key = "senha") { TrocaDeSenha(estado, vm::trocarSenha, largura) }
        item(key = "atalhos") {
            Cartao(largura) {
                LinhaDeAtalho(Icons.Rounded.History, "Minhas perguntas", "O que você perguntou ao BULA-IA", aoAbrirPerguntas)
                LinhaDeAtalho(Icons.AutoMirrored.Rounded.Logout, "Sair da conta", "Os alarmes deixam de tocar neste celular", { confirmarSaida = true })
                LinhaDeAtalho(Icons.Rounded.DeleteForever, "Apagar minha conta", "Remove seus dados pessoais e de saúde", { apagarConta = true }, perigo = true)
            }
        }
        item(key = "versao") {
            Text("BULA-IA ${BuildConfig.VERSION_NAME} · não substitui seu médico ou farmacêutico.", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant, modifier = largura)
        }
    }

    if (apagarConta) {
        ApagarConta(
            apagando = estado.apagando,
            erro = estado.erroAoApagar,
            aoConfirmar = { senha ->
                vm.apagarConta(senha) { mensagem -> android.widget.Toast.makeText(context, mensagem, android.widget.Toast.LENGTH_LONG).show() }
            },
            aoFechar = { apagarConta = false; vm.limparErroAoApagar() },
        )
    }

    if (confirmarSaida) {
        AlertDialog(
            onDismissRequest = { confirmarSaida = false },
            title = { Text("Sair da conta?") },
            text = { Text("Os alarmes dos remédios deixam de tocar neste celular até você entrar de novo.") },
            confirmButton = { TextButton(onClick = { confirmarSaida = false; container.sessao.sair() }) { Text("Sair", color = MaterialTheme.colorScheme.error) } },
            dismissButton = { TextButton(onClick = { confirmarSaida = false }) { Text("Cancelar") } },
        )
    }
}

@Composable
private fun Identificacao(chamada: String, nome: String, email: String, modifier: Modifier) {
    Row(modifier.fillMaxWidth().padding(vertical = 4.dp), verticalAlignment = Alignment.CenterVertically) {
        Box(Modifier.size(64.dp).background(MaterialTheme.colorScheme.primaryContainer, CircleShape), contentAlignment = Alignment.Center) {
            Text(chamada.take(1).uppercase().ifEmpty { "?" }, style = MaterialTheme.typography.headlineSmall, fontWeight = FontWeight.Bold, color = MaterialTheme.colorScheme.onPrimaryContainer)
        }
        Spacer(Modifier.width(16.dp))
        Column(Modifier.weight(1f)) {
            Text(if (chamada.isNotEmpty()) "Olá, $chamada!" else "Sua conta", style = MaterialTheme.typography.headlineSmall, fontWeight = FontWeight.SemiBold, modifier = Modifier.semantics { heading() })
            if (nome.isNotEmpty()) Text(nome, style = MaterialTheme.typography.bodyLarge, color = MaterialTheme.colorScheme.onSurfaceVariant)
            if (email.isNotEmpty()) Text(email, style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
        }
    }
}

@Composable
private fun NomeDeChamada(atual: String, nomeCompleto: String, salvando: Boolean, erro: String?, aoSalvar: (String) -> Unit, modifier: Modifier) {
    var texto by rememberSaveable(atual) { mutableStateOf(atual) }
    val foco = LocalFocusManager.current
    val primeiro = nomeCompleto.trim().substringBefore(' ')
    Cartao(modifier) {
        Text("Como quer ser chamado(a)?", style = MaterialTheme.typography.titleLarge, color = MaterialTheme.colorScheme.primary, modifier = Modifier.semantics { heading() })
        Text("É assim que o BULA-IA cumprimenta você no Início e nos avisos.", style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
        Campo(
            texto,
            { texto = it.take(40) },
            "Nome de chamada",
            erro = erro,
            acao = ImeAction.Done,
            aoConcluir = { foco.clearFocus(); aoSalvar(texto) },
            autoPreenchimento = listOf(ContentType.PersonFirstName),
            ajuda = if (primeiro.isNotEmpty()) "Deixe vazio para usar “$primeiro”." else null,
        )
        BotaoPrincipal("Salvar nome", { foco.clearFocus(); aoSalvar(texto) }, carregando = salvando, habilitado = texto.trim() != atual.trim())
    }
}

/** Tema: segue o celular, sempre claro ou sempre escuro. A tela troca na hora. */
@Composable
private fun Aparencia(tema: String, aoEscolher: (String) -> Unit, modifier: Modifier) {
    val opcoes = listOf(
        Triple(Preferencias.TEMA_SISTEMA, "Igual ao celular", Icons.Rounded.PhoneAndroid),
        Triple(Preferencias.TEMA_CLARO, "Sempre claro", Icons.Rounded.LightMode),
        Triple(Preferencias.TEMA_ESCURO, "Sempre escuro", Icons.Rounded.DarkMode),
    )
    Cartao(modifier) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Icon(Icons.Rounded.Palette, contentDescription = null, tint = MaterialTheme.colorScheme.primary)
            Spacer(Modifier.width(10.dp))
            Text("Aparência", style = MaterialTheme.typography.titleLarge, color = MaterialTheme.colorScheme.primary, modifier = Modifier.semantics { heading() })
        }
        Column(Modifier.selectableGroup(), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            opcoes.forEach { (valor, rotulo, icone) ->
                val escolhida = tema == valor
                Row(
                    Modifier.fillMaxWidth().clip(MaterialTheme.shapes.medium)
                        .background(if (escolhida) MaterialTheme.colorScheme.primaryContainer else MaterialTheme.colorScheme.surfaceVariant.copy(alpha = 0.4f))
                        .selectable(escolhida, role = Role.RadioButton) { aoEscolher(valor) }
                        .heightIn(min = 56.dp).padding(horizontal = 14.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Icon(icone, contentDescription = null, tint = MaterialTheme.colorScheme.primary)
                    Spacer(Modifier.width(14.dp))
                    Text(rotulo, style = MaterialTheme.typography.titleMedium, modifier = Modifier.weight(1f))
                    RadioButton(selected = escolhida, onClick = null)
                }
            }
        }
        Text(if (tema == Preferencias.TEMA_SISTEMA) "Segue o modo claro ou escuro do celular." else "Fica assim mesmo que o celular mude.", style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
    }
}

@Composable
private fun TrocaDeSenha(estado: EstadoDaConta, aoTrocar: (String, String, String) -> Unit, modifier: Modifier) {
    var aberto by rememberSaveable { mutableStateOf(false) }
    var atual by rememberSaveable { mutableStateOf("") }
    var nova by rememberSaveable { mutableStateOf("") }
    var confirmacao by rememberSaveable { mutableStateOf("") }
    val foco = LocalFocusManager.current
    // Senha trocada: limpa e recolhe o formulário.
    LaunchedEffect(estado.senhaTrocada) {
        if (estado.senhaTrocada > 0) {
            atual = ""; nova = ""; confirmacao = ""; aberto = false
        }
    }
    Cartao(modifier.animateContentSize()) {
        Row(Modifier.fillMaxWidth().clickable { aberto = !aberto }.heightIn(min = 48.dp), verticalAlignment = Alignment.CenterVertically) {
            Icon(Icons.Rounded.Lock, contentDescription = null, tint = MaterialTheme.colorScheme.primary)
            Spacer(Modifier.width(10.dp))
            Column(Modifier.weight(1f)) {
                Text("Senha", style = MaterialTheme.typography.titleLarge, color = MaterialTheme.colorScheme.primary, modifier = Modifier.semantics { heading() })
                Text("Trocar a senha de acesso", style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
            }
            Icon(if (aberto) Icons.Rounded.ExpandLess else Icons.Rounded.ExpandMore, contentDescription = if (aberto) "Recolher" else "Abrir")
        }
        AnimatedVisibility(aberto, enter = expandVertically() + fadeIn(), exit = shrinkVertically() + fadeOut()) {
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Campo(atual, { atual = it }, "Senha atual", erro = estado.errosDaSenha["current_password"], senha = true, autoPreenchimento = listOf(ContentType.Password))
                Campo(nova, { nova = it }, "Nova senha", erro = estado.errosDaSenha["password"], senha = true, autoPreenchimento = listOf(ContentType.NewPassword), ajuda = "Pelo menos 8 caracteres, diferente da atual.")
                Campo(confirmacao, { confirmacao = it }, "Repita a nova senha", senha = true, acao = ImeAction.Done, aoConcluir = { foco.clearFocus(); aoTrocar(atual, nova, confirmacao) }, autoPreenchimento = listOf(ContentType.NewPassword))
                estado.errosDaSenha["geral"]?.let { Text(it, color = MaterialTheme.colorScheme.error, style = MaterialTheme.typography.bodyMedium) }
                Text("Ao trocar, as outras sessões abertas com a sua conta são encerradas; este celular continua conectado.", style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
                BotaoPrincipal("Trocar senha", { foco.clearFocus(); aoTrocar(atual, nova, confirmacao) }, carregando = estado.trocandoSenha, habilitado = atual.isNotEmpty() && nova.isNotEmpty() && confirmacao.isNotEmpty())
            }
        }
    }
}

@Composable
private fun LinhaDeAtalho(icone: ImageVector, titulo: String, apoio: String, aoClicar: () -> Unit, perigo: Boolean = false) {
    val cor = if (perigo) MaterialTheme.colorScheme.error else MaterialTheme.colorScheme.primary
    Row(Modifier.fillMaxWidth().clickable(onClick = aoClicar).heightIn(min = 56.dp).padding(vertical = 6.dp), verticalAlignment = Alignment.CenterVertically) {
        Icon(icone, contentDescription = null, tint = cor)
        Spacer(Modifier.width(14.dp))
        Column(Modifier.weight(1f)) {
            Text(titulo, style = MaterialTheme.typography.titleMedium, color = if (perigo) cor else MaterialTheme.colorScheme.onSurface)
            Text(apoio, style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
        }
        Icon(Icons.AutoMirrored.Rounded.KeyboardArrowRight, contentDescription = null, tint = MaterialTheme.colorScheme.onSurfaceVariant)
    }
}

/** Aviso claro do que será apagado, senha atual e o botão vermelho. Nada some sem a senha. */
@Composable
private fun ApagarConta(apagando: Boolean, erro: String?, aoConfirmar: (String) -> Unit, aoFechar: () -> Unit) {
    var senha by rememberSaveable { mutableStateOf("") }
    AlertDialog(
        onDismissRequest = { if (!apagando) aoFechar() },
        icon = { Icon(Icons.Rounded.DeleteForever, contentDescription = null, tint = MaterialTheme.colorScheme.error) },
        title = { Text("Apagar minha conta?") },
        text = {
            Column(Modifier.verticalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                Text("Isto não pode ser desfeito. Serão apagados:", style = MaterialTheme.typography.bodyLarge)
                listOf(
                    "seus remédios, horários e doses marcadas",
                    "receitas, alergias e o perfil de saúde",
                    "suas perguntas e consultas de interação",
                    "avisos, cuidadores e aparelhos conectados",
                    "seu nome e e-mail (a conta fica anônima e desativada)",
                ).forEach { Text("• $it", style = MaterialTheme.typography.bodyMedium) }
                Text("Os alarmes deixam de tocar neste celular. Para usar o BULA-IA de novo, faça um novo cadastro.", style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
                Campo(senha, { senha = it }, "Sua senha", erro = erro, senha = true, acao = ImeAction.Done, aoConcluir = { if (senha.isNotEmpty()) aoConfirmar(senha) }, autoPreenchimento = listOf(ContentType.Password))
            }
        },
        confirmButton = {
            androidx.compose.material3.Button(
                onClick = { aoConfirmar(senha) },
                enabled = senha.isNotEmpty() && !apagando,
                colors = androidx.compose.material3.ButtonDefaults.buttonColors(containerColor = MaterialTheme.colorScheme.error, contentColor = MaterialTheme.colorScheme.onError),
                modifier = Modifier.heightIn(min = 48.dp),
            ) {
                if (apagando) {
                    androidx.compose.material3.CircularProgressIndicator(Modifier.size(20.dp), strokeWidth = 2.dp, color = MaterialTheme.colorScheme.onError)
                } else {
                    Text("Apagar minha conta")
                }
            }
        },
        dismissButton = { TextButton(onClick = aoFechar, enabled = !apagando) { Text("Cancelar") } },
    )
}
