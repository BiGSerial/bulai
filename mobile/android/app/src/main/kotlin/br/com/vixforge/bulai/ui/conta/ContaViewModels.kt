package br.com.vixforge.bulai.ui.conta

import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import br.com.vixforge.bulai.dados.ContaRepositorio
import br.com.vixforge.bulai.dados.Sessao
import br.com.vixforge.bulai.dados.api.BulaiApi
import br.com.vixforge.bulai.dados.api.FalhaDaApi
import kotlinx.coroutines.launch

/** Estado comum dos formulários de conta: enviando, erro geral e erros por campo (do 422 do servidor). */
abstract class FormularioViewModel : ViewModel() {
    var enviando by mutableStateOf(false)
        protected set
    var erro by mutableStateOf<String?>(null)
        protected set
    var errosDosCampos by mutableStateOf<Map<String, String>>(emptyMap())
        protected set

    protected fun enviar(bloco: suspend () -> Result<*>) {
        if (enviando) {
            return
        }
        enviando = true
        erro = null
        errosDosCampos = emptyMap()
        viewModelScope.launch {
            bloco().onFailure { falha ->
                if (falha is FalhaDaApi.Validacao && falha.campos.isNotEmpty()) {
                    errosDosCampos = falha.campos
                } else {
                    erro = falha.message
                }
            }
            enviando = false
        }
    }

    fun limparErro(campo: String) {
        if (campo in errosDosCampos) {
            errosDosCampos = errosDosCampos - campo
        }
        erro = null
    }
}

class EntrarViewModel(private val sessao: Sessao, private val api: BulaiApi) : FormularioViewModel() {
    var email by mutableStateOf("")
    var senha by mutableStateOf("")

    fun entrar() {
        if (email.isBlank() || senha.isBlank()) {
            errosDosCampos = buildMap {
                if (email.isBlank()) put("email", "Escreva o seu e-mail.")
                if (senha.isBlank()) put("password", "Escreva a sua senha.")
            }
            return
        }
        enviar { sessao.entrar(api, email, senha) }
    }
}

class CadastroViewModel(private val conta: ContaRepositorio) : FormularioViewModel() {
    var nome by mutableStateOf("")
    var email by mutableStateOf("")
    var senha by mutableStateOf("")
    var confirmacao by mutableStateOf("")
    var aceitou by mutableStateOf(false)
    var concluido by mutableStateOf<String?>(null)
        private set

    fun cadastrar() = enviar { conta.cadastrar(nome, email, senha, confirmacao, aceitou).onSuccess { concluido = it.message } }
}

class EsqueciSenhaViewModel(private val conta: ContaRepositorio) : FormularioViewModel() {
    var email by mutableStateOf("")
    var enviado by mutableStateOf<String?>(null)
        private set

    fun pedir() = enviar { conta.esqueciSenha(email).onSuccess { enviado = it.message } }
}

class PrimeiroAcessoViewModel(private val sessao: Sessao, private val api: BulaiApi) : FormularioViewModel() {
    var senha by mutableStateOf("")
    var confirmacao by mutableStateOf("")

    fun salvar() = enviar { sessao.trocarSenhaProvisoria(api, senha, confirmacao) }

    fun sair() = sessao.sair()
}
