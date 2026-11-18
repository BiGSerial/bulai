package br.com.vixforge.bulai.dados

import android.content.Context
import br.com.vixforge.bulai.dados.api.BulaiApi
import br.com.vixforge.bulai.dados.api.Dia
import br.com.vixforge.bulai.dados.api.Dose
import br.com.vixforge.bulai.dados.api.MeusCuidadores
import br.com.vixforge.bulai.dados.api.PedidoDeCodigo
import br.com.vixforge.bulai.dados.api.PedidoDeMarcacao
import br.com.vixforge.bulai.dados.api.PedidoDePermissoes
import br.com.vixforge.bulai.dados.api.QuemEuCuido
import br.com.vixforge.bulai.dados.api.chamar
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import java.io.File

/** De onde vem o dia mostrado na tela Hoje: o da própria pessoa ou o de quem ela cuida. */
interface FonteDoDia {
    /** Primeiro nome de quem toma os remédios, quando não é a própria pessoa (muda as frases da tela). */
    val deQuem: String? get() = null

    suspend fun guardado(deslocamento: Int): Dia?

    suspend fun dia(deslocamento: Int): Result<Dia>

    suspend fun marcar(dose: Dose, situacao: String, deslocamento: Int): Result<Dia>

    suspend fun desmarcar(dose: Dose, deslocamento: Int): Result<Dia>
}

/**
 * Modo cuidador no aplicativo: o paciente gera o convite (QR Code), aprova e muda permissões; quem cuida lê o QR, escolhe
 * de quem ver o dia e marca as doses quando tem permissão. As regras ficam no servidor (CaregiverLinkService).
 */
class CuidadoRepositorio(context: Context, private val api: BulaiApi, private val cache: CacheEmDisco) {
    private val app = context.applicationContext

    suspend fun meusCuidadores(): Result<MeusCuidadores> = chamar { api.cuidadores() }

    suspend fun gerarConvite(): Result<MeusCuidadores> = chamar { api.gerarConvite() }

    suspend fun cancelarConvite(): Result<MeusCuidadores> = chamar { api.cancelarConvite() }

    suspend fun aprovar(vinculo: Long, permissoes: Map<String, Boolean>): Result<MeusCuidadores> = chamar { api.aprovarCuidador(vinculo, PedidoDePermissoes(permissoes)) }

    suspend fun recusar(vinculo: Long): Result<MeusCuidadores> = chamar { api.recusarCuidador(vinculo) }

    suspend fun mudarPermissoes(vinculo: Long, permissoes: Map<String, Boolean>): Result<MeusCuidadores> = chamar { api.mudarPermissoes(vinculo, PedidoDePermissoes(permissoes)) }

    suspend fun tirarAcesso(vinculo: Long): Result<MeusCuidadores> = chamar { api.tirarAcesso(vinculo) }

    suspend fun quemEuCuidoGuardado(): QuemEuCuido? = cache.ler(QUEM_EU_CUIDO, QuemEuCuido.serializer())

    suspend fun quemEuCuido(): Result<QuemEuCuido> = chamar { api.quemEuCuido() }.onSuccess { cache.gravar(QUEM_EU_CUIDO, QuemEuCuido.serializer(), it) }

    /** Código lido do QR Code (o link inteiro) ou digitado. */
    suspend fun usarConvite(codigo: String): Result<QuemEuCuido> = chamar { api.usarConvite(PedidoDeCodigo(codigo)) }.onSuccess { cache.gravar(QUEM_EU_CUIDO, QuemEuCuido.serializer(), it) }

    suspend fun deixarDeAcompanhar(vinculo: Long): Result<QuemEuCuido> = chamar { api.deixarDeAcompanhar(vinculo) }.onSuccess { cache.gravar(QUEM_EU_CUIDO, QuemEuCuido.serializer(), it) }

    /** O dia de uma pessoa acompanhada, com cache próprio (abre na hora da segunda vez). */
    fun fonte(vinculo: Long, primeiroNome: String?): FonteDoDia = object : FonteDoDia {
        override val deQuem: String? = primeiroNome

        override suspend fun guardado(deslocamento: Int): Dia? = cache.ler(chave(deslocamento), Dia.serializer())

        override suspend fun dia(deslocamento: Int): Result<Dia> = chamar { api.diaDeQuemCuido(vinculo, deslocamento) }.onSuccess { guardar(it) }

        override suspend fun marcar(dose: Dose, situacao: String, deslocamento: Int): Result<Dia> =
            chamar { api.marcarParaQuemCuido(vinculo, PedidoDeMarcacao(dose.remedioId, dose.agendadaPara, situacao, deslocamento)) }.onSuccess { guardar(it) }

        override suspend fun desmarcar(dose: Dose, deslocamento: Int): Result<Dia> =
            chamar { api.desmarcarParaQuemCuido(vinculo, PedidoDeMarcacao(dose.remedioId, dose.agendadaPara, null, deslocamento)) }.onSuccess { guardar(it) }

        private suspend fun guardar(dia: Dia) = cache.gravar(chave(dia.offset), Dia.serializer(), dia)

        private fun chave(deslocamento: Int) = "cuidando-$vinculo-dia$deslocamento"
    }

    /** Baixa o "Resumo para o médico" (PDF) para a pasta temporária do app, pronto para abrir ou compartilhar. */
    suspend fun baixarResumo(): Result<File> = chamar {
        val corpo = api.resumoParaOMedico()
        withContext(Dispatchers.IO) {
            val pasta = File(app.cacheDir, "resumos").apply { mkdirs() }
            pasta.listFiles()?.forEach { it.delete() }
            File(pasta, "resumo-para-o-medico.pdf").also { arquivo -> corpo.use { entrada -> arquivo.outputStream().use { entrada.byteStream().copyTo(it) } } }
        }
    }

    private companion object {
        const val QUEM_EU_CUIDO = "quem-eu-cuido"
    }
}
