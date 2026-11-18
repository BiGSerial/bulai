package br.com.vixforge.bulai.ui.receita

import android.net.Uri
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import br.com.vixforge.bulai.dados.PerguntasRepositorio
import br.com.vixforge.bulai.dados.ReceitasRepositorio
import br.com.vixforge.bulai.dados.api.CabecalhoDaReceita
import br.com.vixforge.bulai.dados.api.FalhaDaApi
import br.com.vixforge.bulai.dados.api.ItemDaReceita
import br.com.vixforge.bulai.dados.api.PedidoDeConfirmacao
import br.com.vixforge.bulai.dados.api.Receita
import br.com.vixforge.bulai.dados.api.RemedioDoCatalogo
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

data class EstadoDoEnvio(val enviando: Boolean = false, val erro: String? = null, val semConsentimento: Boolean = false)

/** Passo 1: a foto escolhida sobe para a leitura; a tela segue para a revisão quando o servidor aceita. */
class NovaReceitaViewModel(private val receitas: ReceitasRepositorio) : ViewModel() {
    private val _estado = MutableStateFlow(EstadoDoEnvio())
    val estado: StateFlow<EstadoDoEnvio> = _estado.asStateFlow()

    fun enviar(foto: Uri, aoEnviar: (Long) -> Unit) {
        if (_estado.value.enviando) {
            return
        }
        _estado.value = EstadoDoEnvio(enviando = true)
        viewModelScope.launch {
            receitas.enviar(foto)
                .onSuccess { receita ->
                    _estado.value = EstadoDoEnvio()
                    aoEnviar(receita.id)
                }
                .onFailure { falha ->
                    val consentimento = falha is FalhaDaApi.Conflito && falha.codigo == "health_consent_required"
                    _estado.value = EstadoDoEnvio(erro = falha.message, semConsentimento = consentimento)
                }
        }
    }

    fun limparErro() = _estado.update { it.copy(erro = null) }

    fun avisar(mensagem: String) = _estado.update { it.copy(erro = mensagem) }
}

data class EstadoDaRevisao(
    val receita: Receita? = null,
    val erroDeCarga: String? = null,
    val cabecalho: CabecalhoDaReceita = CabecalhoDaReceita(),
    val itens: List<ItemDaReceita> = emptyList(),
    val erroDoPrescritor: String? = null,
    val aviso: String? = null,
    val salvando: Boolean = false,
    val buscando: Boolean = false,
    val resultados: List<RemedioDoCatalogo>? = null,
) {
    val lendo: Boolean get() = receita == null || receita.reading
}

/**
 * Passo 2: acompanha a leitura (a cada 2 s) e, pronta, a pessoa confere e corrige cada item antes de salvar. Mudar
 * "quando tomar" refaz os horários sugeridos com o mesmo intérprete do site. Nada entra no tratamento sem "Confirmar".
 */
class RevisarReceitaViewModel(private val receitas: ReceitasRepositorio, private val perguntas: PerguntasRepositorio, private val id: Long) : ViewModel() {
    private val _estado = MutableStateFlow(EstadoDaRevisao())
    val estado: StateFlow<EstadoDaRevisao> = _estado.asStateFlow()

    private val horarios = mutableMapOf<Int, Job>()
    private var busca: Job? = null

    init {
        acompanhar()
    }

    fun acompanhar() {
        viewModelScope.launch {
            _estado.update { it.copy(erroDeCarga = null) }
            while (true) {
                val resultado = receitas.receita(id)
                resultado.onFailure { falha ->
                    _estado.update { it.copy(erroDeCarga = falha.message) }
                    return@launch
                }
                val receita = resultado.getOrThrow()
                _estado.update { atual ->
                    val primeiraVez = atual.receita?.status != Receita.EM_REVISAO && receita.status == Receita.EM_REVISAO
                    atual.copy(
                        receita = receita,
                        cabecalho = if (primeiraVez) receita.header ?: CabecalhoDaReceita() else atual.cabecalho,
                        itens = if (primeiraVez) receita.items else atual.itens,
                    )
                }
                if (!receita.reading) {
                    return@launch
                }
                delay(2_000)
            }
        }
    }

    fun mudarCabecalho(mudanca: (CabecalhoDaReceita) -> CabecalhoDaReceita) = _estado.update { it.copy(cabecalho = mudanca(it.cabecalho), erroDoPrescritor = null, aviso = null) }

    fun mudarItem(indice: Int, mudanca: (ItemDaReceita) -> ItemDaReceita) = _estado.update { estado ->
        estado.copy(itens = estado.itens.mapIndexed { i, item -> if (i == indice) mudanca(item) else item }, aviso = null)
    }

    /** Novo "quando tomar": os horários sugeridos são refeitos depois que a pessoa para de digitar. */
    fun mudarQuandoTomar(indice: Int, texto: String) {
        mudarItem(indice) { it.copy(frequency = texto.take(120)) }
        horarios[indice]?.cancel()
        horarios[indice] = viewModelScope.launch {
            delay(600)
            receitas.horarios(texto).onSuccess { sugeridos -> mudarItem(indice) { it.comHorarios(sugeridos) } }
        }
    }

    fun escolher(indice: Int, remedio: Long, nome: String) {
        mudarItem(indice) { it.copy(remedioId = remedio, remedioNome = nome, skip = false) }
        fecharBusca()
    }

    fun manterPeloNome(indice: Int) {
        mudarItem(indice) { it.copy(remedioId = null, remedioNome = null, skip = false) }
        fecharBusca()
    }

    fun pular(indice: Int) = mudarItem(indice) { it.copy(skip = !it.skip) }

    fun adicionarHorario(indice: Int, hora: String) = mudarItem(indice) { item ->
        if (item.times.size >= 12) item else item.copy(times = (item.times + hora).distinct().sorted(), seNecessario = false)
    }

    fun trocarHorario(indice: Int, posicao: Int, hora: String) = mudarItem(indice) { item ->
        item.copy(times = item.times.mapIndexed { i, atual -> if (i == posicao) hora else atual }.distinct().sorted())
    }

    fun tirarHorario(indice: Int, posicao: Int) = mudarItem(indice) { item -> item.copy(times = item.times.filterIndexed { i, _ -> i != posicao }) }

    fun buscar(termo: String) {
        busca?.cancel()
        if (termo.trim().length < 3) {
            _estado.update { it.copy(resultados = null, buscando = false) }
            return
        }
        busca = viewModelScope.launch {
            delay(300)
            _estado.update { it.copy(buscando = true) }
            perguntas.buscarRemedios(termo)
                .onSuccess { encontrados -> _estado.update { it.copy(resultados = encontrados.items, buscando = false) } }
                .onFailure { _estado.update { it.copy(buscando = false) } }
        }
    }

    fun fecharBusca() {
        busca?.cancel()
        _estado.update { it.copy(resultados = null, buscando = false) }
    }

    fun confirmar(aoConcluir: (String) -> Unit) {
        val atual = _estado.value
        if (atual.salvando) {
            return
        }
        if (atual.cabecalho.prescriber.trim().length < 3) {
            _estado.update { it.copy(erroDoPrescritor = if (it.cabecalho.prescriber.isBlank()) "Informe quem prescreveu a receita." else "O nome de quem prescreveu precisa ter pelo menos 3 letras.") }
            return
        }
        _estado.update { it.copy(salvando = true, aviso = null) }
        viewModelScope.launch {
            receitas.confirmar(id, PedidoDeConfirmacao(atual.cabecalho, atual.itens))
                .onSuccess { resultado ->
                    _estado.update { it.copy(salvando = false) }
                    aoConcluir(resultado.message)
                }
                .onFailure { falha ->
                    val prescritor = (falha as? FalhaDaApi.Validacao)?.campos?.get("prescriber")
                    _estado.update { it.copy(salvando = false, erroDoPrescritor = prescritor, aviso = if (prescritor == null) falha.message else null) }
                }
        }
    }

    fun descartar(aoConcluir: (String) -> Unit) {
        viewModelScope.launch {
            receitas.descartar(id)
                .onSuccess { aoConcluir("Receita descartada. Nada foi salvo.") }
                .onFailure { falha -> _estado.update { it.copy(aviso = falha.message) } }
        }
    }
}
