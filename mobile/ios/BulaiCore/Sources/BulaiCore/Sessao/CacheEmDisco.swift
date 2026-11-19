import Foundation

/// Última resposta de cada tela (Início, dia, avisos, perfil, bulas prontas…), para abrir na hora e atualizar por cima.
/// Fica em Application Support, fora do backup do iCloud e com proteção de arquivo do iOS; é apagada ao sair da conta.
public actor CacheEmDisco {
    private let pasta: URL

    public init(pasta: URL) {
        self.pasta = pasta
    }

    /// Pasta padrão do app: `Application Support/telas`.
    public static func padrao() -> CacheEmDisco {
        let base = FileManager.default.urls(for: .applicationSupportDirectory, in: .userDomainMask).first ?? FileManager.default.temporaryDirectory
        return CacheEmDisco(pasta: base.appendingPathComponent("telas", isDirectory: true))
    }

    public func ler<T: Decodable>(_ chave: String, como tipo: T.Type) -> T? {
        guard let dados = try? Data(contentsOf: arquivo(chave)) else {
            return nil
        }
        return try? JSONDecoder().decode(tipo, from: dados)
    }

    public func gravar<T: Encodable>(_ chave: String, _ valor: T) {
        guard let dados = try? JSONEncoder().encode(valor) else {
            return
        }
        do {
            try prepararPasta()
            #if os(iOS)
            try dados.write(to: arquivo(chave), options: [.atomic, .completeFileProtectionUntilFirstUserAuthentication])
            #else
            try dados.write(to: arquivo(chave), options: [.atomic])
            #endif
        } catch {
            // Sem espaço ou sem permissão: a tela só não abre na hora da próxima vez.
        }
    }

    public func limpar() {
        try? FileManager.default.removeItem(at: pasta)
    }

    private func arquivo(_ chave: String) -> URL {
        let nome = chave.map { $0.isLetter || $0.isNumber || $0 == "-" ? String($0) : "_" }.joined()
        return pasta.appendingPathComponent(nome + ".json")
    }

    private func prepararPasta() throws {
        guard !FileManager.default.fileExists(atPath: pasta.path) else {
            return
        }
        try FileManager.default.createDirectory(at: pasta, withIntermediateDirectories: true)
        #if canImport(Darwin)
        var valores = URLResourceValues()
        valores.isExcludedFromBackup = true
        var url = pasta
        try? url.setResourceValues(valores)
        #endif
    }
}
