package br.com.vixforge.bulai

import android.app.Application
import android.content.Context
import br.com.vixforge.bulai.alarmes.Notificacoes
import br.com.vixforge.bulai.dados.AvisosRepositorio
import br.com.vixforge.bulai.dados.CacheEmDisco
import br.com.vixforge.bulai.dados.ContaRepositorio
import br.com.vixforge.bulai.dados.PerguntasRepositorio
import br.com.vixforge.bulai.dados.Preferencias
import br.com.vixforge.bulai.dados.ReceitasRepositorio
import br.com.vixforge.bulai.dados.Sessao
import br.com.vixforge.bulai.dados.TratamentoRepositorio
import br.com.vixforge.bulai.dados.api.BulaiApi
import br.com.vixforge.bulai.dados.api.Rede
import br.com.vixforge.bulai.sync.Sincronizacao

class BulaiApp : Application() {
    lateinit var container: Container
        private set

    override fun onCreate() {
        super.onCreate()
        container = Container(this)
        Notificacoes.criarCanais(this)
        if (Preferencias(this).conectado) {
            Sincronizacao.agendarPeriodica(this)
        }
    }
}

/** Dependências do app, criadas uma vez (sem framework de injeção: o app é pequeno o bastante). */
class Container(context: Context) {
    val cache = CacheEmDisco(context)
    val sessao = Sessao(context, cache)
    val api: BulaiApi = Rede.criar(token = { sessao.token }, aoPerderAcesso = { sessao.perdeuAcesso() })
    val conta = ContaRepositorio(api)
    val tratamento = TratamentoRepositorio(context, api, cache)
    val avisos = AvisosRepositorio(context, api, cache)
    val perguntas = PerguntasRepositorio(api, cache)
    val receitas = ReceitasRepositorio(context, api)
}

val Context.container: Container get() = (applicationContext as BulaiApp).container
