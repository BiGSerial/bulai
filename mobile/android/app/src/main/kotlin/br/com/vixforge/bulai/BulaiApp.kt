package br.com.vixforge.bulai

import android.app.Application
import br.com.vixforge.bulai.alarmes.Notificacoes
import br.com.vixforge.bulai.dados.Preferencias
import br.com.vixforge.bulai.sync.Sincronizacao

class BulaiApp : Application() {
    override fun onCreate() {
        super.onCreate()
        Notificacoes.criarCanais(this)
        if (Preferencias(this).conectado) {
            Sincronizacao.agendarPeriodica(this)
        }
    }
}
