package br.com.vixforge.bulai.abertura

import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class VideoDeAberturaTest {
    private val main = "android.intent.action.MAIN"
    private val view = "android.intent.action.VIEW"

    @Test
    fun tocaAoEntrarPeloIcone() {
        assertTrue(VideoDeAbertura.deveTocar(main, temEndereco = false, telaRecriada = false, animacoesDesligadas = false))
    }

    @Test
    fun naoTocaPorNotificacaoAtalhoOuLink() {
        assertFalse(VideoDeAbertura.deveTocar(view, temEndereco = true, telaRecriada = false, animacoesDesligadas = false))
        assertFalse(VideoDeAbertura.deveTocar(main, temEndereco = true, telaRecriada = false, animacoesDesligadas = false))
    }

    @Test
    fun naoTocaQuandoATelaERecriada() {
        assertFalse(VideoDeAbertura.deveTocar(main, temEndereco = false, telaRecriada = true, animacoesDesligadas = false))
    }

    @Test
    fun respeitaAnimacoesDesligadas() {
        assertFalse(VideoDeAbertura.deveTocar(main, temEndereco = false, telaRecriada = false, animacoesDesligadas = true))
    }
}
