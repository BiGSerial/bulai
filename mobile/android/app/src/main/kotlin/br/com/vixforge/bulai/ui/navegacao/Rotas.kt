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
    const val CONTA = "conta"

    const val PERGUNTAS = "perguntas"
    const val PERGUNTA = "pergunta/{uuid}"
    const val RECEITA = "receita"
    const val REVISAR_RECEITA = "receita/{id}"
    const val BULA = "bula/{id}"
    const val PERGUNTAR_SOBRE = "perguntar/{remedio}"
    const val CUIDADORES = "cuidadores"
    const val CUIDAR = "cuidar"
    const val CUIDANDO = "cuidando/{vinculo}?nome={nome}"
    const val CUIDANDO_REMEDIOS = "cuidando/{vinculo}/remedios"
    const val PERFIL = "perfil?aba={aba}"
    const val BULAS = "bulas"
    const val INTERACOES = "interacoes?com={com}"
    const val RESULTADO = "interacoes/{uuid}"

    fun perfil(aba: Int = 0) = "perfil?aba=$aba"

    fun interacoes(com: Long? = null) = "interacoes" + (com?.let { "?com=$it" } ?: "")

    fun resultado(uuid: String) = "interacoes/$uuid"

    fun remediosDeQuemCuido(vinculo: Long) = "cuidando/$vinculo/remedios"

    fun cuidando(vinculo: Long, nome: String? = null) = "cuidando/$vinculo" + (nome?.let { "?nome=" + java.net.URLEncoder.encode(it, "UTF-8").replace("+", "%20") } ?: "")

    fun bula(remedio: Long) = "bula/$remedio"

    fun perguntarSobre(remedio: Long) = "perguntar/$remedio"

    private val RECEITA_PARA_REVISAR = Regex("""receitas/(\d+)/revisar""")
    private val BULA_DO_REMEDIO = Regex("""bula/(\d+)""")
    private val CUIDANDO_DE = Regex("""cuidando/(\d+)(/hoje)?""")
    private val REMEDIOS_DE_QUEM_CUIDO = Regex("""cuidando/(\d+)/remedios""")
    private val RESULTADO_DA_CONSULTA = Regex("""consulta/([0-9a-fA-F-]{36})""")

    fun pergunta(uuid: String) = "pergunta/$uuid"

    fun revisarReceita(id: Long) = "receita/$id"

    /** Título das telas empilhadas (as abas mostram a marca). */
    fun titulo(rota: String?): String? = when (rota) {
        AVISOS -> "Avisos"
        PERGUNTAS -> "Minhas perguntas"
        PERGUNTA -> "Pergunta anterior"
        RECEITA -> "Fotografar receita"
        REVISAR_RECEITA -> "Conferir a receita"
        BULA -> "Bula simples"
        PERGUNTAR_SOBRE -> "Perguntar"
        CUIDADORES -> "Quem cuida de mim"
        CUIDAR -> "Cuidar de alguém"
        CUIDANDO -> "Remédios de quem você cuida"
        CUIDANDO_REMEDIOS -> "Remédios e horários"
        PERFIL -> "Meus remédios"
        BULAS -> "Bula simples"
        INTERACOES, RESULTADO -> "Interações"
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
            "consulta.cuidadores" -> CUIDADORES
            "consulta.cuidar" -> CUIDAR
            "consulta.cuidando.hoje" -> parametro("vinculo")?.toLongOrNull()?.let { cuidando(it) } ?: CUIDAR
            "consulta.cuidando.remedios" -> parametro("vinculo")?.toLongOrNull()?.let(::remediosDeQuemCuido) ?: CUIDAR
            "consulta.perfil" -> perfil()
            "consulta.bulas" -> BULAS
            "consulta.pesquisa" -> interacoes()
            "consulta.resultado" -> parametro("consultation")?.let(::resultado) ?: interacoes()
            "consulta.conta" -> CONTA
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
        "conta", "account" -> CONTA
        "inicio" -> INICIO
        "cuidadores" -> CUIDADORES
        "cuidar" -> CUIDAR
        "perfil" -> perfil()
        "bulas" -> BULAS
        "consulta", "interacoes" -> interacoes()
        else -> RECEITA_PARA_REVISAR.matchEntire(limpo)?.let { revisarReceita(it.groupValues[1].toLong()) }
            ?: BULA_DO_REMEDIO.matchEntire(limpo)?.let { bula(it.groupValues[1].toLong()) }
            ?: REMEDIOS_DE_QUEM_CUIDO.matchEntire(limpo)?.let { remediosDeQuemCuido(it.groupValues[1].toLong()) }
            ?: CUIDANDO_DE.matchEntire(limpo)?.let { cuidando(it.groupValues[1].toLong()) }
            ?: RESULTADO_DA_CONSULTA.matchEntire(limpo)?.let { resultado(it.groupValues[1]) }
            ?: INICIO
    }
}
