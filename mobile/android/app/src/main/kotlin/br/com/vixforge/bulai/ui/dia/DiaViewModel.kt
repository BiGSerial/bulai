package br.com.vixforge.bulai.ui.dia

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import br.com.vixforge.bulai.dados.FonteDoDia
import br.com.vixforge.bulai.dados.api.Dia
import br.com.vixforge.bulai.dados.api.Dose
import br.com.vixforge.bulai.ui.base.Carga
import kotlinx.coroutines.Job
import kotlinx.coroutines.flow.MutableSharedFlow
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.SharedFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asSharedFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

/**
 * Ontem (-1), hoje (0) e amanhã (1), cada um com a sua carga; marcações aparecem na hora e se confirmam depois. A [fonte]
 * é o tratamento da própria pessoa ou o de quem ela cuida (modo cuidador).
 */
class DiaViewModel(private val tratamento: FonteDoDia) : ViewModel() {
    private val _dias = MutableStateFlow(DESLOCAMENTOS.associateWith { Carga<Dia>() })
    val dias: StateFlow<Map<Int, Carga<Dia>>> = _dias.asStateFlow()

    /** Doses com marcação a caminho do servidor (botões dela ficam ocupados). */
    private val _enviando = MutableStateFlow<Set<String>>(emptySet())
    val enviando: StateFlow<Set<String>> = _enviando.asStateFlow()

    private val _recados = MutableSharedFlow<Recado>(extraBufferCapacity = 4)
    val recados: SharedFlow<Recado> = _recados.asSharedFlow()

    private val cargas = mutableMapOf<Int, Job>()

    /** Recado do snackbar; com [desfazer], o snackbar oferece "Desfazer". */
    data class Recado(val texto: String, val desfazer: (() -> Unit)? = null)

    init {
        viewModelScope.launch {
            DESLOCAMENTOS.forEach { deslocamento ->
                tratamento.guardado(deslocamento)?.let { guardado -> mudar(deslocamento) { if (it.dados == null) it.copy(dados = guardado) else it } }
            }
            atualizar(0)
        }
    }

    fun atualizar(deslocamento: Int) {
        if (cargas[deslocamento]?.isActive == true) {
            return
        }
        cargas[deslocamento] = viewModelScope.launch {
            mudar(deslocamento) { it.copy(atualizando = true) }
            tratamento.dia(deslocamento)
                .onSuccess { novo -> mudar(deslocamento) { Carga(novo) } }
                .onFailure { falha -> mudar(deslocamento) { it.copy(atualizando = false, erro = falha.message) } }
        }
    }

    fun tomei(deslocamento: Int, dose: Dose) = marcar(deslocamento, dose, Dose.TOMADA)

    fun pular(deslocamento: Int, dose: Dose) = marcar(deslocamento, dose, Dose.PULADA)

    fun desfazer(deslocamento: Int, dose: Dose) {
        enviar(deslocamento, dose, Dose.PENDENTE, { tratamento.desmarcar(dose, deslocamento) }) {
            Recado("Marcação de ${dose.name} desfeita.")
        }
    }

    private fun marcar(deslocamento: Int, dose: Dose, situacao: String) {
        enviar(deslocamento, dose, situacao, { tratamento.marcar(dose, situacao, deslocamento) }) {
            val quem = tratamento.deQuem?.let { "$it tomou" } ?: "você tomou"
            val texto = if (situacao == Dose.TOMADA) "Anotado: $quem ${dose.name}." else "${dose.name} pulado neste horário."
            Recado(texto) { desfazer(deslocamento, dose.copy(status = situacao)) }
        }
    }

    private fun enviar(deslocamento: Int, dose: Dose, situacaoNaTela: String, chamada: suspend () -> Result<Dia>, recado: () -> Recado) {
        if (dose.key in _enviando.value) {
            return
        }
        val antes = _dias.value[deslocamento]?.dados
        _enviando.update { it + dose.key }
        mudar(deslocamento) { carga -> carga.copy(dados = carga.dados?.comSituacao(dose.key, situacaoNaTela)) }
        viewModelScope.launch {
            cargas[deslocamento]?.cancel()
            chamada()
                .onSuccess { novo ->
                    mudar(deslocamento) { Carga(novo) }
                    _recados.tryEmit(recado())
                }
                .onFailure { falha ->
                    mudar(deslocamento) { it.copy(dados = antes) }
                    _recados.tryEmit(Recado(falha.message ?: "Não deu para anotar agora. Tente de novo."))
                }
            _enviando.update { it - dose.key }
        }
    }

    private fun mudar(deslocamento: Int, transformar: (Carga<Dia>) -> Carga<Dia>) =
        _dias.update { atual -> atual + (deslocamento to transformar(atual.getValue(deslocamento))) }

    companion object {
        val DESLOCAMENTOS = listOf(-1, 0, 1)
    }
}

/**
 * Retorno imediato do toque (só experiência): a dose e o resumo mudam na hora; a resposta do servidor, que é quem decide,
 * substitui este cálculo logo em seguida (ou o desfaz, se recusar).
 */
internal fun Dia.comSituacao(chave: String, situacao: String): Dia {
    val novas = doses.map { if (it.key == chave) it.copy(status = situacao) else it }
    return copy(
        doses = novas,
        summary = summary.copy(
            done = novas.count { it.feita },
            taken = novas.count { it.status == Dose.TOMADA },
            late = novas.count { it.status == Dose.ATRASADA },
        ),
    )
}
