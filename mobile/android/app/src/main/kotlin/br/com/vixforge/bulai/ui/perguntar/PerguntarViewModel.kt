package br.com.vixforge.bulai.ui.perguntar

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import br.com.vixforge.bulai.dados.PerguntasRepositorio
import br.com.vixforge.bulai.dados.api.InicioDaConversa
import br.com.vixforge.bulai.dados.api.PedidoDePergunta
import br.com.vixforge.bulai.dados.api.RemedioDoCatalogo
import br.com.vixforge.bulai.dados.api.Resposta
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

/** Uma troca da conversa: a pergunta aparece na hora; a resposta chega depois (ou a falha, com "Tentar de novo"). */
data class Troca(val id: Long, val pergunta: String, val resposta: Resposta? = null, val erro: String? = null) {
    val esperando: Boolean get() = resposta == null && erro == null
}

data class EstadoDaConversa(
    val inicio: InicioDaConversa? = null,
    val trocas: List<Troca> = emptyList(),
    val texto: String = "",
    /** Aviso acima da caixa ("Escreva sua pergunta citando o nome do remédio."). */
    val aviso: String? = null,
    /** Remédios escolhidos à mão (correção ou atalho de outro lugar) que valem para a próxima resposta. */
    val fixos: List<Long> = emptyList(),
    val removidos: List<Long> = emptyList(),
    val buscando: Boolean = false,
    val resultados: List<RemedioDoCatalogo>? = null,
) {
    val enviando: Boolean get() = trocas.lastOrNull()?.esperando == true
    val minimo: Int get() = inicio?.minimo ?: 5
    val maximo: Int get() = inicio?.maximo ?: 500
}

/**
 * Conversa do Perguntar, como no site: a pergunta seguinte sem nome de remédio ("e com café?") usa as anteriores como
 * contexto. O aparelho manda só os `uuid` das últimas trocas; o servidor relê o histórico da própria pessoa.
 */
class PerguntarViewModel(private val repositorio: PerguntasRepositorio, private val remedioInicial: Long?) : ViewModel() {
    private val _estado = MutableStateFlow(EstadoDaConversa(fixos = listOfNotNull(remedioInicial)))
    val estado: StateFlow<EstadoDaConversa> = _estado.asStateFlow()

    private var proximoId = 0L
    private var envio: Job? = null
    private var busca: Job? = null

    init {
        viewModelScope.launch {
            if (remedioInicial == null) {
                repositorio.inicioGuardado()?.let { guardado -> _estado.update { if (it.inicio == null) it.copy(inicio = guardado) else it } }
            }
            repositorio.inicio(remedioInicial).onSuccess { inicio ->
                _estado.update { atual ->
                    val texto = if (atual.texto.isEmpty() && inicio.preview != null) "Sobre ${inicio.preview.medicine}: " else atual.texto
                    atual.copy(inicio = inicio, texto = texto)
                }
            }
        }
    }

    /** Enter de teclado físico (ou colado com quebra de linha) envia, como no site. */
    fun escrever(texto: String) {
        if (texto.contains('\n')) {
            _estado.update { it.copy(texto = texto.replace('\n', ' ').trim().take(it.maximo), aviso = null) }
            enviar()
            return
        }
        _estado.update { it.copy(texto = texto.take(it.maximo), aviso = null) }
    }

    /** Toque numa sugestão: completa a caixa (a pessoa revisa e envia). */
    fun sugerir(texto: String) {
        _estado.update { it.copy(texto = "${it.texto.trim()} $texto".trim().take(it.maximo), aviso = null) }
    }

    /** Resposta rápida ("Não estou me sentindo bem"): vira a próxima mensagem e já é enviada. */
    fun responder(texto: String) {
        _estado.update { it.copy(texto = texto.take(it.maximo), aviso = null) }
        enviar()
    }

    fun enviar() {
        val atual = _estado.value
        val pergunta = atual.texto.trim()
        if (atual.enviando) {
            return
        }
        if (pergunta.length < atual.minimo) {
            _estado.update { it.copy(aviso = "Escreva sua pergunta citando o nome do remédio.") }
            return
        }
        val troca = Troca(proximoId++, pergunta)
        _estado.update { it.copy(trocas = it.trocas + troca, texto = "", aviso = null, removidos = emptyList()) }
        responder(troca)
    }

    /** A pergunta que falhou (sem conexão, limite) volta a ser enviada. */
    fun tentarDeNovo() {
        val ultima = _estado.value.trocas.lastOrNull()?.takeIf { it.erro != null } ?: return
        val troca = ultima.copy(id = proximoId++, erro = null)
        _estado.update { it.copy(trocas = it.trocas.dropLast(1) + troca) }
        responder(troca)
    }

    /** "Não é esse?": troca o remédio entendido pelo escolhido e refaz a mesma pergunta. */
    fun corrigir(remedioId: Long) {
        _estado.update { it.copy(fixos = listOf(remedioId), removidos = emptyList(), resultados = null) }
        refazer()
    }

    /** "Não era esse remédio": tira o remédio entendido por engano e refaz a mesma pergunta sem ele. */
    fun remover(remedioId: Long) {
        _estado.update { it.copy(removidos = (it.removidos + remedioId).distinct(), fixos = it.fixos - remedioId) }
        refazer()
    }

    fun novaConversa() {
        envio?.cancel()
        _estado.update { EstadoDaConversa(inicio = it.inicio) }
    }

    /** Busca no catálogo para a correção, com uma pequena espera enquanto a pessoa digita. */
    fun buscar(termo: String) {
        busca?.cancel()
        if (termo.trim().length < 3) {
            _estado.update { it.copy(resultados = null, buscando = false) }
            return
        }
        busca = viewModelScope.launch {
            delay(300)
            _estado.update { it.copy(buscando = true) }
            repositorio.buscarRemedios(termo)
                .onSuccess { encontrados -> _estado.update { it.copy(resultados = encontrados.items, buscando = false) } }
                .onFailure { _estado.update { it.copy(buscando = false) } }
        }
    }

    fun fecharBusca() {
        busca?.cancel()
        _estado.update { it.copy(resultados = null, buscando = false) }
    }

    private fun refazer() {
        val ultima = _estado.value.trocas.lastOrNull() ?: return
        if (ultima.esperando) {
            return
        }
        val troca = Troca(proximoId++, ultima.pergunta)
        _estado.update { it.copy(trocas = it.trocas.dropLast(1) + troca) }
        responder(troca)
    }

    private fun responder(troca: Troca) {
        val atual = _estado.value
        val anteriores = atual.trocas.filter { it.id != troca.id }.mapNotNull { it.resposta?.uuid }.takeLast(MAX_TROCAS)
        envio = viewModelScope.launch {
            repositorio.perguntar(PedidoDePergunta(troca.pergunta, atual.fixos, atual.removidos, anteriores))
                .onSuccess { resposta ->
                    _estado.update { estado ->
                        estado.copy(
                            trocas = estado.trocas.map { if (it.id == troca.id) it.copy(resposta = resposta) else it }.takeLast(MAX_TROCAS + 1),
                            fixos = if (resposta.liberaFixos) emptyList() else estado.fixos,
                        )
                    }
                }
                .onFailure { falha ->
                    _estado.update { estado -> estado.copy(trocas = estado.trocas.map { if (it.id == troca.id) it.copy(erro = falha.message ?: "Não deu para perguntar agora.") else it }) }
                }
        }
    }

    private companion object {
        /** Como no site: as trocas mais antigas saem da conversa. */
        const val MAX_TROCAS = 6
    }
}
