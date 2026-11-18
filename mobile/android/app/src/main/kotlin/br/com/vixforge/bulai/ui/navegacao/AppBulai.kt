package br.com.vixforge.bulai.ui.navegacao

import androidx.compose.animation.AnimatedContent
import androidx.compose.animation.AnimatedContentTransitionScope
import androidx.compose.animation.EnterTransition
import androidx.compose.animation.ExitTransition
import androidx.compose.animation.core.tween
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.slideInHorizontally
import androidx.compose.animation.slideOutHorizontally
import androidx.compose.animation.togetherWith
import androidx.compose.foundation.Image
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.height
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.outlined.Chat
import androidx.compose.material.icons.automirrored.rounded.ArrowBack
import androidx.compose.material.icons.automirrored.rounded.Chat
import androidx.compose.material.icons.automirrored.rounded.Logout
import androidx.compose.material.icons.rounded.History
import androidx.compose.material.icons.outlined.AccountCircle
import androidx.compose.material.icons.rounded.AccountCircle
import androidx.compose.material.icons.rounded.EventAvailable
import androidx.compose.material.icons.rounded.NotificationsActive
import androidx.compose.material.icons.rounded.Home
import androidx.compose.material.icons.rounded.MoreVert
import androidx.compose.material.icons.rounded.Notifications
import androidx.compose.material.icons.outlined.EventAvailable
import androidx.compose.material.icons.outlined.Home
import androidx.compose.material.icons.outlined.Notifications
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Badge
import androidx.compose.material3.BadgedBox
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.NavigationBar
import androidx.compose.material3.NavigationBarItem
import androidx.compose.material3.Scaffold
import androidx.compose.material3.SnackbarDuration
import androidx.compose.material3.SnackbarHost
import androidx.compose.material3.SnackbarHostState
import androidx.compose.material3.SnackbarResult
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TopAppBar
import androidx.compose.material3.TopAppBarDefaults
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.input.nestedscroll.nestedScroll
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.painterResource
import androidx.compose.ui.unit.dp
import androidx.core.net.toUri
import androidx.navigation.NavType
import androidx.navigation.navArgument
import br.com.vixforge.bulai.BuildConfig
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.navigation.NavGraph.Companion.findStartDestination
import androidx.navigation.NavBackStackEntry
import androidx.navigation.NavHostController
import androidx.navigation.compose.NavHost
import androidx.navigation.compose.composable
import androidx.navigation.compose.currentBackStackEntryAsState
import androidx.navigation.compose.rememberNavController
import br.com.vixforge.bulai.R
import br.com.vixforge.bulai.ui.tema.TemaBulai
import br.com.vixforge.bulai.container
import br.com.vixforge.bulai.dados.EstadoDaSessao
import br.com.vixforge.bulai.dados.Preferencias
import br.com.vixforge.bulai.ui.avisos.AvisosTela
import br.com.vixforge.bulai.ui.bula.BulaTela
import br.com.vixforge.bulai.ui.conta.CadastroTela
import br.com.vixforge.bulai.ui.conta.ContaAreaTela
import br.com.vixforge.bulai.ui.conta.EntrarTela
import br.com.vixforge.bulai.ui.conta.EsqueciSenhaTela
import br.com.vixforge.bulai.ui.conta.PrimeiroAcessoTela
import br.com.vixforge.bulai.ui.dia.DiaTela
import br.com.vixforge.bulai.ui.inicio.InicioTela
import br.com.vixforge.bulai.ui.perguntar.HistoricoTela
import br.com.vixforge.bulai.ui.perguntar.PerguntaRegistradaTela
import br.com.vixforge.bulai.ui.perguntar.PerguntarTela
import br.com.vixforge.bulai.ui.receita.NovaReceitaTela
import br.com.vixforge.bulai.ui.receita.RevisarReceitaTela
import br.com.vixforge.bulai.ui.sistema.rememberPedidoInicialDeNotificacoes
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.launch

private data class Aba(val rota: String, val titulo: String, val icone: ImageVector, val iconeSelecionado: ImageVector)

private val ABAS = listOf(
    Aba(Rotas.INICIO, "Início", Icons.Outlined.Home, Icons.Rounded.Home),
    Aba(Rotas.PERGUNTAR, "Perguntar", Icons.AutoMirrored.Outlined.Chat, Icons.AutoMirrored.Rounded.Chat),
    Aba(Rotas.DIA, "Hoje", Icons.Outlined.EventAvailable, Icons.Rounded.EventAvailable),
    Aba(Rotas.CONTA, "Conta", Icons.Outlined.AccountCircle, Icons.Rounded.AccountCircle),
)

/** Raiz do app: a sessão decide entre as telas de conta, a troca da senha provisória e as telas do paciente. */
@Composable
fun AppBulai(rotaPedida: StateFlow<String?>, aoUsarRota: () -> Unit) {
    val sessao = LocalContext.current.container.sessao
    val estado by sessao.estado.collectAsStateWithLifecycle()
    AnimatedContent(
        targetState = when (estado) {
            EstadoDaSessao.Visitante -> 0
            is EstadoDaSessao.TrocarSenha -> 1
            is EstadoDaSessao.Conectado -> 2
        },
        transitionSpec = { fadeIn(tween(250)) togetherWith fadeOut(tween(200)) },
        label = "sessao",
    ) { etapa ->
        when (etapa) {
            0 -> NavegacaoDeConta()
            1 -> PrimeiroAcessoTela((estado as? EstadoDaSessao.TrocarSenha)?.usuario?.primeiroNome.orEmpty())
            else -> NavegacaoDoPaciente(rotaPedida, aoUsarRota)
        }
    }
}

@Composable
private fun NavegacaoDeConta() {
    val nav = rememberNavController()
    NavHost(
        nav,
        startDestination = Rotas.ENTRAR,
        enterTransition = { slideInHorizontally(tween(260)) { it / 4 } + fadeIn(tween(260)) },
        exitTransition = { fadeOut(tween(180)) },
        popEnterTransition = { fadeIn(tween(220)) },
        popExitTransition = { slideOutHorizontally(tween(220)) { it / 4 } + fadeOut(tween(220)) },
    ) {
        composable(Rotas.ENTRAR) { EntrarTela(aoCadastrar = { nav.navigate(Rotas.CADASTRO) }, aoEsquecerSenha = { nav.navigate(Rotas.ESQUECI) }) }
        composable(Rotas.CADASTRO) { CadastroTela(aoVoltar = { nav.popBackStack() }) }
        composable(Rotas.ESQUECI) { EsqueciSenhaTela(aoVoltar = { nav.popBackStack() }) }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun NavegacaoDoPaciente(rotaPedida: StateFlow<String?>, aoUsarRota: () -> Unit) {
    val context = LocalContext.current
    val nav = rememberNavController()
    val avisosNaoLidos = remember { mutableIntStateOf(0) }
    val snackbar = remember { SnackbarHostState() }
    val escopo = rememberCoroutineScope()
    val rolagem = TopAppBarDefaults.pinnedScrollBehavior()
    val pedirNotificacoes = rememberPedidoInicialDeNotificacoes()
    val pedida by rotaPedida.collectAsStateWithLifecycle()

    // Primeira vez conectado neste aparelho: pede para mostrar notificações (os alarmes dependem disso).
    LaunchedEffect(Unit) {
        val preferencias = Preferencias(context)
        if (!preferencias.pediuNotificacoes) {
            preferencias.pediuNotificacoes = true
            pedirNotificacoes()
        }
    }
    LaunchedEffect(pedida) {
        pedida?.let { rota ->
            irPara(nav, rota)
            aoUsarRota()
        }
    }

    fun mostrar(texto: String, desfazer: (() -> Unit)? = null) {
        escopo.launch {
            snackbar.currentSnackbarData?.dismiss()
            val resultado = snackbar.showSnackbar(texto, actionLabel = desfazer?.let { "Desfazer" }, duration = if (desfazer != null) SnackbarDuration.Long else SnackbarDuration.Short)
            if (resultado == SnackbarResult.ActionPerformed) {
                desfazer?.invoke()
            }
        }
    }

    val atual by nav.currentBackStackEntryAsState()
    Scaffold(
        modifier = Modifier.nestedScroll(rolagem.nestedScrollConnection),
        topBar = {
            val rota = atual?.destination?.route
            val titulo = Rotas.titulo(rota)
            TopAppBar(
                title = {
                    if (titulo != null) {
                        Text(titulo, style = MaterialTheme.typography.titleLarge)
                    } else {
                        Image(painterResource(TemaBulai.logo), contentDescription = "BULA-IA", modifier = Modifier.height(30.dp))
                    }
                },
                navigationIcon = {
                    if (titulo != null) {
                        IconButton(onClick = { nav.popBackStack() }) { Icon(Icons.AutoMirrored.Rounded.ArrowBack, contentDescription = "Voltar") }
                    }
                },
                actions = {
                    if (rota == Rotas.PERGUNTAR) {
                        IconButton(onClick = { nav.navigate(Rotas.PERGUNTAS) }) { Icon(Icons.Rounded.History, contentDescription = "Minhas perguntas") }
                    }
                    // Sininho: os avisos (com o número de não lidos). Fica em todas as telas, menos na própria lista de avisos.
                    if (rota != Rotas.AVISOS) {
                        IconButton(onClick = { nav.navigate(Rotas.AVISOS) { launchSingleTop = true } }) {
                            val naoLidos = avisosNaoLidos.intValue
                            BadgedBox(badge = { if (naoLidos > 0) Badge { Text(if (naoLidos > 99) "99+" else "$naoLidos") } }) {
                                Icon(if (naoLidos > 0) Icons.Rounded.NotificationsActive else Icons.Outlined.Notifications, contentDescription = if (naoLidos == 0) "Avisos" else if (naoLidos == 1) "Avisos, 1 não lido" else "Avisos, $naoLidos não lidos")
                            }
                        }
                    }
                },
                scrollBehavior = rolagem,
                colors = TopAppBarDefaults.topAppBarColors(containerColor = MaterialTheme.colorScheme.background, scrolledContainerColor = MaterialTheme.colorScheme.surface),
            )
        },
        bottomBar = {
            // Telas empilhadas (receita, histórico) ocupam a tela toda: a barra de abas some.
            val emAba = atual == null || ABAS.any { it.rota == atual?.destination?.route }
            if (emAba) NavigationBar(containerColor = MaterialTheme.colorScheme.surface) {
                ABAS.forEach { aba ->
                    val selecionada = atual?.destination?.route == aba.rota
                    NavigationBarItem(
                        selected = selecionada,
                        onClick = { irPara(nav, aba.rota) },
                        icon = {
                            Icon(if (selecionada) aba.iconeSelecionado else aba.icone, contentDescription = null)
                        },
                        label = { Text(aba.titulo, style = MaterialTheme.typography.labelMedium) },
                    )
                }
            }
        },
        snackbarHost = { SnackbarHost(snackbar) },
        containerColor = MaterialTheme.colorScheme.background,
    ) { preenchimento ->
        NavHost(
            nav,
            startDestination = Rotas.INICIO,
            enterTransition = { fadeIn(tween(220)) },
            exitTransition = { fadeOut(tween(150)) },
        ) {
            composable(Rotas.INICIO) {
                InicioTela(
                    recado = { mostrar(it) },
                    aoAbrirDia = { irPara(nav, Rotas.DIA) },
                    aoAbrirAvisos = { irPara(nav, Rotas.AVISOS) },
                    aoAbrirPerguntar = { irPara(nav, Rotas.PERGUNTAR) },
                    aoFotografarReceita = { nav.navigate(Rotas.RECEITA) },
                    aoAbrirPerguntas = { nav.navigate(Rotas.PERGUNTAS) },
                    preenchimento = preenchimento,
                    aoContarAvisos = { avisosNaoLidos.intValue = it },
                )
            }
            composable(Rotas.PERGUNTAR) { PerguntarTela(preenchimento, aoAbrirBula = { nav.navigate(Rotas.bula(it)) }) }
            composable(Rotas.DIA) { DiaTela(recado = { mostrar(it.texto, it.desfazer) }, preenchimento = preenchimento) }
            composable(Rotas.AVISOS, enterTransition = { entrarEmpilhada() }, popExitTransition = { sairEmpilhada() }) {
                AvisosTela(
                    recado = { mostrar(it) },
                    aoAbrirDestino = { irPara(nav, Rotas.doDestino(it)) },
                    preenchimento = preenchimento,
                    aoContarAvisos = { avisosNaoLidos.intValue = it },
                    aoAbrirAjustes = { irPara(nav, Rotas.CONTA) },
                )
            }
            composable(Rotas.CONTA) {
                ContaAreaTela(preenchimento, recado = { mostrar(it) }, aoAbrirPerguntas = { nav.navigate(Rotas.PERGUNTAS) })
            }
            composable(Rotas.PERGUNTAS, enterTransition = { entrarEmpilhada() }, popExitTransition = { sairEmpilhada() }) {
                HistoricoTela(preenchimento, aoAbrir = { nav.navigate(Rotas.pergunta(it)) }, recado = { mostrar(it) })
            }
            composable(Rotas.PERGUNTA, arguments = listOf(navArgument("uuid") { type = NavType.StringType }), enterTransition = { entrarEmpilhada() }, popExitTransition = { sairEmpilhada() }) { entrada ->
                PerguntaRegistradaTela(entrada.arguments?.getString("uuid").orEmpty(), preenchimento, aoAbrirBula = { nav.navigate(Rotas.bula(it)) })
            }
            composable(Rotas.BULA, arguments = listOf(navArgument("id") { type = NavType.LongType }), enterTransition = { entrarEmpilhada() }, popExitTransition = { sairEmpilhada() }) { entrada ->
                BulaTela(entrada.arguments?.getLong("id") ?: 0L, preenchimento, aoPerguntar = { nav.navigate(Rotas.perguntarSobre(it)) })
            }
            composable(Rotas.PERGUNTAR_SOBRE, arguments = listOf(navArgument("remedio") { type = NavType.LongType }), enterTransition = { entrarEmpilhada() }, popExitTransition = { sairEmpilhada() }) { entrada ->
                PerguntarTela(preenchimento, remedioInicial = entrada.arguments?.getLong("remedio"), aoAbrirBula = { nav.navigate(Rotas.bula(it)) })
            }
            composable(Rotas.RECEITA, enterTransition = { entrarEmpilhada() }, popExitTransition = { sairEmpilhada() }) {
                NovaReceitaTela(
                    preenchimento,
                    aoEnviar = { id -> nav.navigate(Rotas.revisarReceita(id)) { popUpTo(Rotas.RECEITA) { inclusive = true } } },
                    aoAbrirPerfil = { abrirNoSite(context, "/perfil") },
                )
            }
            composable(Rotas.REVISAR_RECEITA, arguments = listOf(navArgument("id") { type = NavType.LongType }), enterTransition = { entrarEmpilhada() }, popExitTransition = { sairEmpilhada() }) { entrada ->
                RevisarReceitaTela(
                    entrada.arguments?.getLong("id") ?: 0L,
                    preenchimento,
                    aoConcluir = { mensagem -> mostrar(mensagem); irPara(nav, Rotas.DIA) },
                    aoTentarOutraFoto = { nav.navigate(Rotas.RECEITA) { popUpTo(Rotas.REVISAR_RECEITA) { inclusive = true } } },
                    aoVerHoje = { irPara(nav, Rotas.DIA) },
                )
            }
        }
    }
}

/** Abas do menu: uma cópia de cada tela, guardando a rolagem de cada uma. */
private fun irPara(nav: NavHostController, rota: String) {
    if (nav.currentDestination?.route == rota) {
        return
    }
    nav.navigate(rota) {
        popUpTo(nav.graph.findStartDestination().id) { saveState = true }
        launchSingleTop = true
        restoreState = true
    }
}

private fun AnimatedContentTransitionScope<NavBackStackEntry>.entrarEmpilhada(): EnterTransition =
    slideIntoContainer(AnimatedContentTransitionScope.SlideDirection.Start, tween(280)) { it / 3 } + fadeIn(tween(280))

private fun AnimatedContentTransitionScope<NavBackStackEntry>.sairEmpilhada(): ExitTransition =
    slideOutOfContainer(AnimatedContentTransitionScope.SlideDirection.End, tween(240)) { it / 3 } + fadeOut(tween(240))

/** O que ainda só existe no site abre no navegador (Custom Tab), já na página certa. */
private fun abrirNoSite(context: android.content.Context, caminho: String) {
    androidx.browser.customtabs.CustomTabsIntent.Builder().setShowTitle(true).build().launchUrl(context, (BuildConfig.BASE_URL + caminho).toUri())
}
