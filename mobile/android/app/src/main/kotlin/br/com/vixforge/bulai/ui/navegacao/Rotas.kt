package br.com.vixforge.bulai.ui.navegacao

import br.com.vixforge.bulai.dados.api.Destino
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.jsonPrimitive

/** Telas do app. Os nomes de rota do site (`consulta.hoje`…) e os caminhos (`/hoje`) chegam por avisos e notificações. */
object Rotas {
    const val ENTRAR = "entrar"
    const val CADASTRO = "cadastro"
    const val ESQUECI = "esqueci-senha"

    const val INICIO = "inicio"
    const val PERGUNTAR = "perguntar"
    const val DIA = "dia"
    const val AVISOS = "avisos"

    const val PERGUNTAS = "perguntas"
    const val PERGUNTA = "pergunta/{uuid}"
    const val RECEITA = "receita"
    const val REVISAR_RECEITA = "receita/{id}"
    const val BULA = "bula/{id}"
    const val PERGUNTAR_SOBRE = "perguntar/{remedio}"

    fun bula(remedio: Long) = "bula/$remedio"

    fun perguntarSobre(remedio: Long) = "perguntar/$remedio"

    private val RECEITA_PARA_REVISAR = Regex("""receitas/(\d+)/revisar""")
    private val BULA_DO_REMEDIO = Regex("""bula/(\d+)""")

    fun pergunta(uuid: String) = "pergunta/$uuid"

    fun revisarReceita(id: Long) = "receita/$id"

    /** Título das telas empilhadas (as abas mostram a marca). */
    fun titulo(rota: String?): String? = when (rota) {
        PERGUNTAS -> "Minhas perguntas"
        PERGUNTA -> "Pergunta anterior"
        RECEITA -> "Fotografar receita"
        REVISAR_RECEITA -> "Conferir a receita"
        BULA -> "Bula simples"
        PERGUNTAR_SOBRE -> "Perguntar"
        else -> null
    }

    /** Aviso do servidor → tela do app (o que ainda não existe no app abre o Início). */
    fun doDestino(destino: Destino): String {
        val parametro = { nome: String -> (destino.params as? JsonObject)?.get(nome)?.jsonPrimitive?.content }
        return when (destino.route) {
            "consulta.hoje" -> DIA
            "consulta.avisos" -> AVISOS
            "consulta.perguntar" -> PERGUNTAR
            "consulta.historico" -> PERGUNTAS
            "consulta.pergunta" -> parametro("question")?.let(::pergunta) ?: PERGUNTAS
            "consulta.bula" -> parametro("medicine")?.toLongOrNull()?.let(::bula) ?: INICIO
            "consulta.receita.nova" -> RECEITA
            "consulta.receita.revisar" -> parametro("prescription")?.toLongOrNull()?.let(::revisarReceita) ?: RECEITA
            else -> INICIO
        }
    }

    /** Caminho de bulai://abrir/{caminho} (notificação, widget, atalho) → tela do app. */
    fun doCaminho(caminho: String?): String? = when (val limpo = caminho?.trim('/')?.substringBefore('?')) {
        null, "" -> null
        "hoje" -> DIA
        "avisos" -> AVISOS
        "perguntar" -> PERGUNTAR
        "historico" -> PERGUNTAS
        "receitas/nova", "receita" -> RECEITA
        else -> RECEITA_PARA_REVISAR.matchEntire(limpo)?.let { revisarReceita(it.groupValues[1].toLong()) }
            ?: BULA_DO_REMEDIO.matchEntire(limpo)?.let { bula(it.groupValues[1].toLong()) }
            ?: INICIO
    }
}
