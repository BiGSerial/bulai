import Foundation
import Observation

/// Conta conectada neste aparelho. O token fica no ``Cofre`` (Keychain) e em memória enquanto o app roda; entrar liga
/// os lembretes (``Sincronizador``) e sair, ou o servidor recusar o token, apaga tudo o que é do paciente.
@MainActor
@Observable
public final class Sessao {
    /// Em que ponto da conta o app está; a navegação inteira deriva disto.
    public enum Estado: Equatable, Sendable {
        case visitante
        case trocarSenha(Usuario)
        case conectado(Usuario)
    }

    public private(set) var estado: Estado

    @ObservationIgnored public let caixa: CaixaDoToken
    @ObservationIgnored private let cofre: Cofre
    @ObservationIgnored private let preferencias: PreferenciasLocais
    @ObservationIgnored private let cache: CacheEmDisco
    /// Chamado quando a conta fica liberada (liga a sincronização dos lembretes).
    @ObservationIgnored public var aoConectar: (@MainActor () async -> Void)?
    /// Chamado ao sair ou perder o acesso (cancela as notificações, recarrega o widget).
    @ObservationIgnored public var aoDesconectar: (@MainActor () async -> Void)?

    /// - Parameter processoPrincipal: `false` no widget e nas extensões: elas nunca apagam o token (só o app decide).
    public init(cofre: Cofre, preferencias: PreferenciasLocais, cache: CacheEmDisco, caixa: CaixaDoToken = CaixaDoToken(), processoPrincipal: Bool = true) {
        self.cofre = cofre
        self.preferencias = preferencias
        self.cache = cache
        self.caixa = caixa
        let token = cofre.ler()
        let usuario = preferencias.conta
        if let token, !token.isEmpty, let usuario {
            caixa.token = token
            estado = usuario.precisaTrocarSenha ? .trocarSenha(usuario) : .conectado(usuario)
        } else {
            // O Keychain sobrevive à reinstalação do app, os UserDefaults não: um token sem conta guardada é de uma
            // instalação anterior e não deve valer (o servidor o derruba quando a pessoa entrar de novo).
            if token != nil, processoPrincipal {
                cofre.apagar()
            }
            caixa.token = nil
            estado = .visitante
        }
    }

    public var usuario: Usuario? {
        switch estado {
        case .visitante: nil
        case let .trocarSenha(usuario), let .conectado(usuario): usuario
        }
    }

    public var conectado: Bool {
        if case .conectado = estado {
            return true
        }
        return false
    }

    /// `POST /entrar` com o identificador desta instalação e `platform = ios`.
    @discardableResult
    public func entrar(api: ClienteDaApi, email: String, senha: String, aparelho: String, versao: String) async throws -> Usuario {
        let pedido = PedidoDeEntrada(email: email.trimmingCharacters(in: .whitespacesAndNewlines), password: senha, instalacao: preferencias.instalacao, plataforma: "ios", aparelho: aparelho, versao: versao)
        let resposta = try await api.entrar(pedido)
        if !preferencias.usuario.isEmpty, preferencias.usuario != String(resposta.user.id) {
            // Outra conta neste celular: nada da anterior pode continuar (lembretes, cache das telas).
            await aoDesconectar?()
            preferencias.limparConta()
            await cache.limpar()
        }
        caixa.token = resposta.token
        cofre.gravar(resposta.token)
        preferencias.usuario = String(resposta.user.id)
        await atualizar(resposta.user)
        return resposta.user
    }

    @discardableResult
    public func trocarSenhaProvisoria(api: ClienteDaApi, senha: String, confirmacao: String) async throws -> Usuario {
        let usuario = try await api.primeiroAcesso(senha: senha, confirmacao: confirmacao)
        await atualizar(usuario)
        return usuario
    }

    /// Dados novos da conta (`GET /eu`, troca de senha): ajusta o estado e liga os lembretes quando a conta está liberada.
    public func atualizar(_ usuario: Usuario) async {
        guard caixa.token != nil else {
            return
        }
        preferencias.conta = usuario
        let antes = estado
        estado = usuario.precisaTrocarSenha ? .trocarSenha(usuario) : .conectado(usuario)
        preferencias.conectado = !usuario.precisaTrocarSenha
        if !usuario.precisaTrocarSenha, !Sessao.estavaConectado(antes) {
            await aoConectar?()
        }
    }

    /// Abriu o app com a conta guardada: liga a sincronização sem esperar a rede.
    public func retomar() async {
        if conectado {
            preferencias.conectado = true
            await aoConectar?()
        }
    }

    /// "Sair" pela tela: limpa o aparelho na hora e avisa o servidor em seguida (melhor esforço, com o token antigo).
    public func sair(avisarServidor: @escaping @Sendable (String) async -> Void) async {
        let token = caixa.token
        await encerrarLocalmente()
        if let token {
            await avisarServidor(token)
        }
    }

    /// O servidor recusou o token (conta desativada, sessões encerradas, senha trocada em outro lugar, conta apagada).
    public func perdeuAcesso() async {
        guard estado != .visitante else {
            return
        }
        await encerrarLocalmente()
    }

    private func encerrarLocalmente() async {
        caixa.token = nil
        cofre.apagar()
        preferencias.limparConta()
        estado = .visitante
        await cache.limpar()
        await aoDesconectar?()
    }

    private static func estavaConectado(_ estado: Estado) -> Bool {
        if case .conectado = estado {
            return true
        }
        return false
    }
}
