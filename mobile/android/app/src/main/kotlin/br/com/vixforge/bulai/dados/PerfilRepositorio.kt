package br.com.vixforge.bulai.dados

import android.content.Context
import br.com.vixforge.bulai.dados.api.BulaiApi
import br.com.vixforge.bulai.dados.api.ConsultaDeInteracoes
import br.com.vixforge.bulai.dados.api.InicioDasInteracoes
import br.com.vixforge.bulai.dados.api.ListaDeBulas
import br.com.vixforge.bulai.dados.api.Mensagem
import br.com.vixforge.bulai.dados.api.PedidoDeAlergia
import br.com.vixforge.bulai.dados.api.PedidoDeConsulta
import br.com.vixforge.bulai.dados.api.PedidoDeDoseEHorarios
import br.com.vixforge.bulai.dados.api.PedidoDeReceitaDoPerfil
import br.com.vixforge.bulai.dados.api.PedidoDeRemedio
import br.com.vixforge.bulai.dados.api.PedidoDeSugestao
import br.com.vixforge.bulai.dados.api.Perfil
import br.com.vixforge.bulai.dados.api.DoseEHorarios
import br.com.vixforge.bulai.dados.api.RemedioDaConsulta
import br.com.vixforge.bulai.dados.api.RemediosDeQuemCuido
import br.com.vixforge.bulai.dados.api.chamar
import br.com.vixforge.bulai.sync.Sincronizacao

/**
 * "Meus remédios" (perfil de saúde), dose e horários (também de quem a pessoa cuida), interações e lista de bulas.
 * Mudanças nos remédios ressincronizam os alarmes do aparelho.
 */
class PerfilRepositorio(context: Context, private val api: BulaiApi, private val cache: CacheEmDisco) {
    private val app = context.applicationContext

    suspend fun guardado(): Perfil? = cache.ler(PERFIL, Perfil.serializer())

    suspend fun perfil(): Result<Perfil> = chamar { api.perfil() }.onSuccess { guardar(it) }

    suspend fun consentir(): Result<Perfil> = mudou { api.consentir() }

    suspend fun apagarDadosDeSaude(): Result<Perfil> = mudou { api.apagarDadosDeSaude() }

    suspend fun adicionarDoCatalogo(remedio: Long): Result<Perfil> = mudou { api.adicionarRemedio(PedidoDeRemedio(remedio = remedio)) }

    suspend fun adicionarPeloNome(nome: String): Result<Perfil> = mudou { api.adicionarRemedio(PedidoDeRemedio(name = nome.trim())) }

    suspend fun salvarPosologia(remedio: Long, posologia: DoseEHorarios): Result<Perfil> = mudou { api.salvarPosologia(remedio, PedidoDeDoseEHorarios(posologia)) }

    suspend fun tirarRemedio(remedio: Long): Result<Perfil> = mudou { api.tirarRemedio(remedio) }

    suspend fun adicionarReceita(pedido: PedidoDeReceitaDoPerfil): Result<Perfil> = mudou { api.adicionarReceitaDoPerfil(pedido) }

    suspend fun tirarReceita(receita: Long): Result<Perfil> = mudou { api.tirarReceita(receita) }

    suspend fun adicionarAlergia(substancia: String, reacao: String?): Result<Perfil> = mudou { api.adicionarAlergia(PedidoDeAlergia(substancia.trim(), reacao?.trim()?.ifBlank { null })) }

    suspend fun tirarAlergia(alergia: Long): Result<Perfil> = mudou { api.tirarAlergia(alergia) }

    /** O que muda no formulário quando a pessoa escreve "quando tomar" ou toca num atalho ([atalho]); nada é salvo. */
    suspend fun sugerirHorarios(frequencia: String, posologia: DoseEHorarios, atalho: Boolean = false): Result<DoseEHorarios> =
        chamar { api.sugerirHorarios(PedidoDeSugestao(frequencia, posologia, atalho)).posology }

    suspend fun remediosDeQuemCuido(vinculo: Long): Result<RemediosDeQuemCuido> = chamar { api.remediosDeQuemCuido(vinculo) }

    suspend fun salvarPosologiaDeQuemCuido(vinculo: Long, remedio: Long, posologia: DoseEHorarios): Result<RemediosDeQuemCuido> =
        chamar { api.salvarPosologiaDeQuemCuido(vinculo, remedio, PedidoDeDoseEHorarios(posologia)) }

    suspend fun interacoes(): Result<InicioDasInteracoes> = chamar { api.interacoes() }

    /** O servidor recusa o repetido e o que passa do máximo (a frase vem na falha). */
    suspend fun remedioDaConsulta(remedio: Long, escolhidos: List<Long>): Result<RemedioDaConsulta> = chamar { api.remedioDaConsulta(remedio, escolhidos.joinToString(",")) }

    suspend fun verificar(remedios: List<Long>): Result<ConsultaDeInteracoes> = chamar { api.verificarInteracoes(PedidoDeConsulta(remedios)) }

    suspend fun consulta(uuid: String): Result<ConsultaDeInteracoes> = chamar { api.consulta(uuid) }

    suspend fun repetir(uuid: String): Result<ConsultaDeInteracoes> = chamar { api.repetirConsulta(uuid) }

    suspend fun apagarConsulta(uuid: String): Result<Mensagem> = chamar { api.apagarConsulta(uuid) }

    suspend fun bulas(termo: String? = null): Result<ListaDeBulas> = chamar { api.bulas(termo?.trim()?.ifBlank { null }) }

    private suspend fun mudou(bloco: suspend () -> Perfil): Result<Perfil> = chamar { bloco() }.onSuccess {
        guardar(it)
        Sincronizacao.agora(app)
    }

    private suspend fun guardar(perfil: Perfil) = cache.gravar(PERFIL, Perfil.serializer(), perfil)

    private companion object {
        const val PERFIL = "perfil"
    }
}
