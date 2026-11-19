import Foundation

/// Endereço do servidor, grupos compartilhados com o widget e versão, lidos do `Info.plist` (definidos pelos
/// `Config/*.xcconfig`, sem segredo nenhum no código).
public struct Configuracao: Sendable {
    public var servidor: URL
    public var grupoDoApp: String?
    public var grupoDoKeychain: String?
    public var servicoDoKeychain: String
    public var versao: String
    public var build: String

    public init(servidor: URL, grupoDoApp: String? = nil, grupoDoKeychain: String? = nil, servicoDoKeychain: String = "br.com.vixforge.bulai", versao: String = "1.0.0", build: String = "1") {
        self.servidor = servidor
        self.grupoDoApp = grupoDoApp
        self.grupoDoKeychain = grupoDoKeychain
        self.servicoDoKeychain = servicoDoKeychain
        self.versao = versao
        self.build = build
    }

    public static let producao = URL(string: "https://bulai.vixforge.com.br")!

    /// Lê `BulaiAPIURL`, `BulaiAppGroup`, `BulaiKeychainGroup` e as versões do bundle.
    public static func doBundle(_ bundle: Bundle = .main) -> Configuracao {
        let texto = { (chave: String) -> String? in
            guard let valor = bundle.object(forInfoDictionaryKey: chave) as? String else {
                return nil
            }
            let limpo = valor.trimmingCharacters(in: .whitespacesAndNewlines)
            // Variável de build não resolvida ("$(…)") ou vazia: trata como ausente.
            return limpo.isEmpty || limpo.contains("$(") ? nil : limpo
        }
        let servidor = texto("BulaiAPIURL").flatMap(URL.init(string:)) ?? producao
        return Configuracao(
            servidor: servidor,
            grupoDoApp: texto("BulaiAppGroup"),
            grupoDoKeychain: texto("BulaiKeychainGroup"),
            servicoDoKeychain: texto("BulaiKeychainService") ?? "br.com.vixforge.bulai",
            versao: texto("CFBundleShortVersionString") ?? "1.0.0",
            build: texto("CFBundleVersion") ?? "1"
        )
    }
}

public extension Dependencias {
    /// Monta sessão, cliente, sincronização e cache ligados entre si (o mesmo desenho no app, no widget e nos testes):
    /// um 401 encerra a sessão; entrar liga os lembretes; sair os desliga.
    static func montar(configuracao: Configuracao, cofre: Cofre, notificacoes: ServicoDeNotificacoes, cache: CacheEmDisco = .padrao(), transporte: Transporte = TransporteURLSession(), aparelho: String, processoPrincipal: Bool = true, aoMudarAgenda: @escaping @MainActor () -> Void = {}) -> Dependencias {
        let preferencias = PreferenciasLocais(grupo: configuracao.grupoDoApp)
        let caixa = CaixaDoToken()
        let sessao = Sessao(cofre: cofre, preferencias: preferencias, cache: cache, caixa: caixa, processoPrincipal: processoPrincipal)
        let api = ClienteDaApi(base: configuracao.servidor, caixa: caixa, transporte: transporte, versao: configuracao.versao) {
            await sessao.perdeuAcesso()
        }
        let sincronizador = Sincronizador(api: api, preferencias: preferencias, notificacoes: notificacoes, aoMudarAgenda: aoMudarAgenda)
        sessao.aoConectar = { await sincronizador.conectar() }
        sessao.aoDesconectar = { await sincronizador.desconectarLocalmente() }
        return Dependencias(api: api, cache: cache, sessao: sessao, preferencias: preferencias, sincronizador: sincronizador, aparelho: aparelho, versao: configuracao.versao)
    }
}
