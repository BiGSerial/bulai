package br.com.vixforge.bulai.dados

import android.content.Context
import android.os.Build
import br.com.vixforge.bulai.BuildConfig
import br.com.vixforge.bulai.dados.api.BulaiApi
import br.com.vixforge.bulai.dados.api.PedidoDeEntrada
import br.com.vixforge.bulai.dados.api.PedidoDeNovaSenha
import br.com.vixforge.bulai.dados.api.Rede
import br.com.vixforge.bulai.dados.api.Usuario
import br.com.vixforge.bulai.dados.api.chamar
import br.com.vixforge.bulai.sync.Sincronizacao
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow

/** Em que ponto da conta o app está; a navegação inteira deriva disto. */
sealed interface EstadoDaSessao {
    data object Visitante : EstadoDaSessao

    data class TrocarSenha(val usuario: Usuario) : EstadoDaSessao

    data class Conectado(val usuario: Usuario) : EstadoDaSessao
}

/**
 * Conta conectada neste aparelho. O token fica cifrado (Preferencias + Cofre) e em memória enquanto o app roda; entrar
 * liga os alarmes (Sincronizacao) e sair, ou o servidor recusar o token, apaga tudo o que é do paciente.
 */
class Sessao(context: Context, private val cache: CacheEmDisco) {
    private val app = context.applicationContext
    private val preferencias = Preferencias(app)

    @Volatile
    private var tokenEmMemoria: String? = preferencias.token

    private val _estado = MutableStateFlow(estadoInicial())
    val estado: StateFlow<EstadoDaSessao> = _estado.asStateFlow()

    val token: String? get() = tokenEmMemoria

    private fun estadoInicial(): EstadoDaSessao {
        val usuario = preferencias.contaJson?.let { runCatching { Rede.json.decodeFromString(Usuario.serializer(), it) }.getOrNull() }
        return when {
            tokenEmMemoria == null || usuario == null -> EstadoDaSessao.Visitante
            usuario.precisaTrocarSenha -> EstadoDaSessao.TrocarSenha(usuario)
            else -> EstadoDaSessao.Conectado(usuario)
        }
    }

    suspend fun entrar(api: BulaiApi, email: String, senha: String): Result<Usuario> = chamar {
        api.entrar(PedidoDeEntrada(email.trim(), senha, preferencias.instalacao, nomeDoAparelho(), BuildConfig.VERSION_NAME))
    }.map { resposta ->
        if (preferencias.usuario.isNotEmpty() && preferencias.usuario != resposta.user.id.toString()) {
            // Outra conta neste celular: nada da anterior pode continuar (alarmes, cache das telas).
            Sincronizacao.desconectarLocalmente(app)
            cache.limpar()
        }
        tokenEmMemoria = resposta.token
        preferencias.token = resposta.token
        preferencias.usuario = resposta.user.id.toString()
        atualizar(resposta.user)
        resposta.user
    }

    suspend fun trocarSenhaProvisoria(api: BulaiApi, senha: String, confirmacao: String): Result<Usuario> =
        chamar { api.primeiroAcesso(PedidoDeNovaSenha(senha, confirmacao)).user }.onSuccess { atualizar(it) }

    /** Dados novos da conta (GET /eu, troca de senha): ajusta o estado e liga os alarmes quando a conta está liberada. */
    fun atualizar(usuario: Usuario) {
        preferencias.contaJson = Rede.json.encodeToString(Usuario.serializer(), usuario)
        val anterior = _estado.value
        _estado.value = if (usuario.precisaTrocarSenha) EstadoDaSessao.TrocarSenha(usuario) else EstadoDaSessao.Conectado(usuario)
        if (!usuario.precisaTrocarSenha && anterior !is EstadoDaSessao.Conectado) {
            tokenEmMemoria?.let { Sincronizacao.conectar(app, it, usuario.id.toString()) }
        }
    }

    /** "Sair" pela tela: avisa o servidor (melhor esforço) e limpa o aparelho. */
    fun sair() {
        Sincronizacao.desconectar(app)
        encerrarLocalmente()
    }

    /** O servidor recusou o token (conta desativada, sessões encerradas, senha trocada em outro lugar). */
    fun perdeuAcesso() {
        if (_estado.value == EstadoDaSessao.Visitante) {
            return
        }
        Sincronizacao.desconectarLocalmente(app)
        encerrarLocalmente()
    }

    /** O trabalho em segundo plano pode ter desconectado o aparelho enquanto o app estava fechado. */
    fun conferir() {
        if (_estado.value != EstadoDaSessao.Visitante && preferencias.token == null) {
            encerrarLocalmente()
        }
    }

    private fun encerrarLocalmente() {
        tokenEmMemoria = null
        cache.limpar()
        preferencias.limparConta()
        _estado.value = EstadoDaSessao.Visitante
    }

    private fun nomeDoAparelho(): String {
        val fabricante = Build.MANUFACTURER.replaceFirstChar { it.uppercase() }
        return if (Build.MODEL.startsWith(Build.MANUFACTURER, ignoreCase = true)) Build.MODEL else "$fabricante ${Build.MODEL}"
    }
}
