package br.com.vixforge.bulai.dados

import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyProperties
import android.util.Base64
import java.security.KeyStore
import javax.crypto.Cipher
import javax.crypto.KeyGenerator
import javax.crypto.SecretKey
import javax.crypto.spec.GCMParameterSpec

/**
 * Cifra o token do aparelho com uma chave AES-GCM do Android Keystore (a chave não sai do hardware/TEE). Se a chave for
 * perdida (restauração, troca de bloqueio em alguns aparelhos), [abrir] devolve null e a pessoa entra de novo.
 */
object Cofre {
    private const val PROVEDOR = "AndroidKeyStore"
    private const val APELIDO = "bulai-token"
    private const val TRANSFORMACAO = "AES/GCM/NoPadding"
    private const val TAG_BITS = 128

    fun fechar(texto: String): String {
        val cifra = Cipher.getInstance(TRANSFORMACAO).apply { init(Cipher.ENCRYPT_MODE, chave()) }
        val dados = cifra.doFinal(texto.toByteArray(Charsets.UTF_8))

        return Base64.encodeToString(cifra.iv, Base64.NO_WRAP) + ":" + Base64.encodeToString(dados, Base64.NO_WRAP)
    }

    fun abrir(guardado: String): String? = try {
        val (iv, dados) = guardado.split(":", limit = 2).map { Base64.decode(it, Base64.NO_WRAP) }
        val cifra = Cipher.getInstance(TRANSFORMACAO).apply { init(Cipher.DECRYPT_MODE, chave(), GCMParameterSpec(TAG_BITS, iv)) }
        String(cifra.doFinal(dados), Charsets.UTF_8)
    } catch (e: Exception) {
        null
    }

    private fun chave(): SecretKey {
        val loja = KeyStore.getInstance(PROVEDOR).apply { load(null) }
        (loja.getEntry(APELIDO, null) as? KeyStore.SecretKeyEntry)?.let { return it.secretKey }

        return KeyGenerator.getInstance(KeyProperties.KEY_ALGORITHM_AES, PROVEDOR).apply {
            init(
                KeyGenParameterSpec.Builder(APELIDO, KeyProperties.PURPOSE_ENCRYPT or KeyProperties.PURPOSE_DECRYPT)
                    .setBlockModes(KeyProperties.BLOCK_MODE_GCM)
                    .setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE)
                    .setKeySize(256)
                    .build(),
            )
        }.generateKey()
    }
}
