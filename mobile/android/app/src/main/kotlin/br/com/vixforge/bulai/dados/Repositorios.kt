package br.com.vixforge.bulai.dados

import android.content.Context
import br.com.vixforge.bulai.dados.api.AlteracaoDePreferencias
import br.com.vixforge.bulai.dados.api.BulaiApi
import br.com.vixforge.bulai.dados.api.CentralDeAvisos
import br.com.vixforge.bulai.dados.api.Destino
import br.com.vixforge.bulai.dados.api.Dia
import br.com.vixforge.bulai.dados.api.Dose
import br.com.vixforge.bulai.dados.api.Inicio
import br.com.vixforge.bulai.dados.api.Mensagem
import br.com.vixforge.bulai.dados.api.PedidoDeCadastro
import br.com.vixforge.bulai.dados.api.PedidoDeEmail
import br.com.vixforge.bulai.dados.api.PedidoDeMarcacao
import br.com.vixforge.bulai.dados.api.chamar
import br.com.vixforge.bulai.sync.Sincronizacao

/** Cadastro e "esqueci minha senha" (sem conta conectada). */
class ContaRepositorio(private val api: BulaiApi) {
    suspend fun cadastrar(nome: String, email: String, senha: String, confirmacao: String, aceitou: Boolean): Result<Mensagem> =
        chamar { api.cadastrar(PedidoDeCadastro(nome.trim(), email.trim(), senha, confirmacao, aceitou)) }

    suspend fun esqueciSenha(email: String): Result<Mensagem> = chamar { api.esqueciSenha(PedidoDeEmail(email.trim())) }
}

/**
 * Início e Remédios do dia. Cada resposta boa fica no [CacheEmDisco] para a próxima abertura; cada marcação pede uma
 * sincronização para os alarmes do aparelho seguirem a tela (uma dose marcada não toca mais).
 */
class TratamentoRepositorio(context: Context, private val api: BulaiApi, private val cache: CacheEmDisco) {
    private val app = context.applicationContext

    suspend fun inicioGuardado(): Inicio? = cache.ler(INICIO, Inicio.serializer())

    suspend fun inicio(): Result<Inicio> = chamar { api.inicio() }.onSuccess { cache.gravar(INICIO, Inicio.serializer(), it) }

    suspend fun diaGuardado(deslocamento: Int): Dia? = cache.ler(chaveDoDia(deslocamento), Dia.serializer())

    suspend fun dia(deslocamento: Int): Result<Dia> = chamar { api.dia(deslocamento) }.onSuccess { guardar(it) }

    suspend fun marcar(dose: Dose, situacao: String, deslocamento: Int): Result<Dia> =
        chamar { api.marcar(PedidoDeMarcacao(dose.remedioId, dose.agendadaPara, situacao, deslocamento)) }.onSuccess { mudou(it) }

    suspend fun desmarcar(dose: Dose, deslocamento: Int): Result<Dia> =
        chamar { api.desmarcar(PedidoDeMarcacao(dose.remedioId, dose.agendadaPara, null, deslocamento)) }.onSuccess { mudou(it) }

    private suspend fun mudou(dia: Dia) {
        guardar(dia)
        Sincronizacao.agora(app)
    }

    private suspend fun guardar(dia: Dia) = cache.gravar(chaveDoDia(dia.offset), Dia.serializer(), dia)

    private fun chaveDoDia(deslocamento: Int) = "dia$deslocamento"

    private companion object {
        const val INICIO = "inicio"
    }
}

class AvisosRepositorio(context: Context, private val api: BulaiApi, private val cache: CacheEmDisco) {
    private val app = context.applicationContext

    suspend fun guardados(): CentralDeAvisos? = cache.ler(CHAVE, CentralDeAvisos.serializer())

    suspend fun central(): Result<CentralDeAvisos> = guardar { api.avisos() }

    suspend fun abrir(id: String): Result<Destino> = chamar { api.abrirAviso(id).destination }

    suspend fun marcarTodosLidos(): Result<CentralDeAvisos> = guardar { api.marcarAvisosLidos() }

    suspend fun apagar(id: String): Result<CentralDeAvisos> = guardar { api.apagarAviso(id) }

    suspend fun apagarTodos(): Result<CentralDeAvisos> = guardar { api.apagarAvisos() }

    /** Lembretes e fuso mudam a agenda dos alarmes: sincroniza em seguida. */
    suspend fun preferencias(alteracao: AlteracaoDePreferencias): Result<CentralDeAvisos> =
        guardar { api.preferencias(alteracao) }.onSuccess { Sincronizacao.agora(app) }

    private suspend fun guardar(bloco: suspend () -> CentralDeAvisos): Result<CentralDeAvisos> =
        chamar { bloco() }.onSuccess { cache.gravar(CHAVE, CentralDeAvisos.serializer(), it) }

    private companion object {
        const val CHAVE = "avisos"
    }
}
