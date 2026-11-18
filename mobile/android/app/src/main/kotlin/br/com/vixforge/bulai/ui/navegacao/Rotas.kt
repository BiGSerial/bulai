package br.com.vixforge.bulai.ui.navegacao

import br.com.vixforge.bulai.dados.api.Destino

/** Telas do app. Os nomes de rota do site (`consulta.hoje`…) e os caminhos (`/hoje`) chegam por avisos e notificações. */
object Rotas {
    const val ENTRAR = "entrar"
    const val CADASTRO = "cadastro"
    const val ESQUECI = "esqueci-senha"

    const val INICIO = "inicio"
    const val DIA = "dia"
    const val AVISOS = "avisos"

    /** Aviso do servidor → tela do app (o que ainda não existe no app abre o Início). */
    fun doDestino(destino: Destino): String = when (destino.route) {
        "consulta.hoje" -> DIA
        "consulta.avisos" -> AVISOS
        else -> INICIO
    }

    /** Caminho de bulai://abrir/{caminho} (notificação, widget, atalho) → tela do app. */
    fun doCaminho(caminho: String?): String? = when (caminho?.trim('/')?.substringBefore('?')) {
        null, "" -> null
        "hoje" -> DIA
        "avisos" -> AVISOS
        "inicio" -> INICIO
        else -> INICIO
    }
}
