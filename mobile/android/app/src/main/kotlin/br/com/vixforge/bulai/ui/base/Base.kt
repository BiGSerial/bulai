package br.com.vixforge.bulai.ui.base

import androidx.compose.runtime.Composable
import androidx.compose.ui.platform.LocalContext
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewmodel.compose.viewModel
import br.com.vixforge.bulai.Container
import br.com.vixforge.bulai.container

/** ViewModel da tela criada com as dependências do [Container] (sem framework de injeção). */
@Composable
inline fun <reified VM : ViewModel> telaViewModel(crossinline criar: (Container) -> VM): VM {
    val container = LocalContext.current.container
    return viewModel { criar(container) }
}

/**
 * Estado de uma tela que carrega da rede: [dados] pode vir do cache enquanto [atualizando] busca a versão nova;
 * [erro] aparece por cima dos dados que já existem (sem apagar a tela).
 */
data class Carga<T>(val dados: T? = null, val atualizando: Boolean = false, val erro: String? = null) {
    /** Primeira carga, sem nada para mostrar: a tela mostra o esqueleto. */
    val esqueleto: Boolean get() = dados == null && erro == null
}
