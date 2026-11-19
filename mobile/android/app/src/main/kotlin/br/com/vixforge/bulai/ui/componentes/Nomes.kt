package br.com.vixforge.bulai.ui.componentes

/**
 * "LOSARTANA POTÁSSICA" → "Losartana potássica": o nome do catálogo da ANVISA vem todo em maiúsculas, que é mais difícil
 * de ler e ocupa mais espaço (em telas de 360 dp, "SINVASTATINA" quebrava no meio da palavra). Nomes já escritos pela
 * pessoa, com minúsculas, ficam como estão.
 */
fun nomeAmigavel(nome: String): String =
    if (nome.any { it.isLetter() } && nome.none { it.isLowerCase() }) nome.lowercase().replaceFirstChar { it.titlecase() } else nome
