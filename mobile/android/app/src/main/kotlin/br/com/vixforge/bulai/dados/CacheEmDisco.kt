package br.com.vixforge.bulai.dados

import android.content.Context
import br.com.vixforge.bulai.dados.api.Rede
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import kotlinx.serialization.KSerializer
import java.io.File

/**
 * Última resposta de cada tela, em arquivos privados (fora de backup): o app abre mostrando o que já sabia e atualiza
 * por cima, em vez de mostrar tela em branco. Apagado ao sair da conta.
 */
class CacheEmDisco(context: Context) {
    private val pasta = File(context.applicationContext.noBackupFilesDir, "telas").apply { mkdirs() }

    suspend fun <T> ler(chave: String, serializador: KSerializer<T>): T? = withContext(Dispatchers.IO) {
        val arquivo = File(pasta, "$chave.json")
        if (!arquivo.exists()) {
            return@withContext null
        }
        runCatching { Rede.json.decodeFromString(serializador, arquivo.readText()) }.getOrNull()
    }

    suspend fun <T> gravar(chave: String, serializador: KSerializer<T>, valor: T) = withContext(Dispatchers.IO) {
        runCatching {
            val temporario = File(pasta, "$chave.json.tmp")
            temporario.writeText(Rede.json.encodeToString(serializador, valor))
            temporario.renameTo(File(pasta, "$chave.json"))
        }
    }

    fun limpar() {
        pasta.listFiles()?.forEach { it.delete() }
    }
}
