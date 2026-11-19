#if canImport(Security)
import Foundation
import Security

/// Token do aparelho no Keychain do iOS: só neste aparelho (não vai para backup nem para outro iPhone) e legível
/// depois do primeiro desbloqueio (as ações "Tomei"/"Pular" da notificação funcionam com a tela bloqueada).
///
/// Com o grupo de Keychain configurado (`BulaiKeychainGroup`), o widget lê o mesmo token para marcar "Tomei". Sem o
/// grupo (conta Apple gratuita sem a capacidade), grava só no app: o widget mostra a próxima dose e abre o app.
public final class KeychainCofre: Cofre, @unchecked Sendable {
    private let servico: String
    private let conta = "token-do-aparelho"
    private let grupo: String?

    public init(servico: String, grupo: String?) {
        self.servico = servico
        self.grupo = (grupo?.isEmpty ?? true) || grupo?.contains("$(") == true ? nil : grupo
    }

    public func ler() -> String? {
        for grupo in grupos {
            var consulta = base(grupo)
            consulta[kSecReturnData as String] = true
            consulta[kSecMatchLimit as String] = kSecMatchLimitOne
            var resultado: AnyObject?
            if SecItemCopyMatching(consulta as CFDictionary, &resultado) == errSecSuccess, let dados = resultado as? Data {
                return String(data: dados, encoding: .utf8)
            }
        }
        return nil
    }

    public func gravar(_ token: String) {
        apagar()
        for grupo in grupos {
            var item = base(grupo)
            item[kSecValueData as String] = Data(token.utf8)
            item[kSecAttrAccessible as String] = kSecAttrAccessibleAfterFirstUnlockThisDeviceOnly
            let situacao = SecItemAdd(item as CFDictionary, nil)
            if situacao == errSecSuccess {
                return
            }
            // errSecMissingEntitlement: o grupo não está na assinatura; tenta só no app.
        }
    }

    public func apagar() {
        for grupo in grupos {
            SecItemDelete(base(grupo) as CFDictionary)
        }
    }

    private var grupos: [String?] {
        grupo == nil ? [nil] : [grupo, nil]
    }

    private func base(_ grupo: String?) -> [String: Any] {
        var consulta: [String: Any] = [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrService as String: servico,
            kSecAttrAccount as String: conta,
        ]
        if let grupo {
            consulta[kSecAttrAccessGroup as String] = grupo
        }
        return consulta
    }
}
#endif
