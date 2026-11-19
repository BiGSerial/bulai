import Foundation
import Testing
@testable import BulaiCore

/// Entrar, senha provisória, expiração/revogação do token e saída (estado da conta no aparelho).
@Suite("Sessão e token do aparelho")
@MainActor
struct SessaoTests {
    @Test("Entrar envia a instalação e a plataforma iOS, guarda o token no cofre e liga os lembretes")
    func entrar() async throws {
        let app = AppDeTeste()
        app.transporte.responder("POST entrar", 200, contrato("login"))
        app.transporte.responder("GET agenda", 200, contrato("agenda"))
        app.transporte.responder("GET avisos/novos", 200, contrato("notices.fresh"))

        try await app.sessao.entrar(api: app.api, email: "  maria@exemplo.com ", senha: "segredo", aparelho: "iPhone 16", versao: "1.0.0")

        let corpo = try #require(app.transporte.corpo("POST entrar"))
        #expect(corpo["email"] as? String == "maria@exemplo.com")
        #expect(corpo["platform"] as? String == "ios")
        #expect(corpo["installation_id"] as? String == app.preferencias.instalacao)
        #expect(UUID(uuidString: app.preferencias.instalacao) != nil)
        #expect(corpo["device_name"] as? String == "iPhone 16")
        #expect(app.cofre.ler() == "bulai_app_TOKEN_DE_TESTE")
        #expect(app.sessao.conectado)
        #expect(app.preferencias.conectado)
        #expect(app.transporte.feitos.contains("GET agenda"), "entrar liga a sincronização dos lembretes")
    }

    @Test("A mesma instalação mantém o identificador entre entradas")
    func instalacaoEstavel() {
        let app = AppDeTeste()
        #expect(app.preferencias.instalacao == app.preferencias.instalacao)
        app.preferencias.limparConta()
        #expect(!app.preferencias.instalacao.isEmpty)
    }

    @Test("Senha errada mostra a frase do servidor embaixo do e-mail e não guarda nada")
    func senhaErrada() async {
        let app = AppDeTeste()
        app.transporte.responder("POST entrar", 422, json(#"{"message":"Credenciais inválidas","errors":{"email":["E-mail ou senha incorretos."]}}"#))
        let tela = EntrarViewModel(app.dependencias)
        tela.email = "maria@exemplo.com"
        tela.senha = "errada"
        await tela.entrar()
        #expect(tela.formulario.campos["email"] == "E-mail ou senha incorretos.")
        #expect(app.cofre.ler() == nil)
        #expect(app.sessao.estado == .visitante)
    }

    @Test("Entrar sem preencher avisa sem chamar o servidor")
    func camposVazios() async {
        let app = AppDeTeste()
        let tela = EntrarViewModel(app.dependencias)
        await tela.entrar()
        #expect(tela.formulario.campos["email"] == "Escreva o seu e-mail.")
        #expect(tela.formulario.campos["password"] == "Escreva a sua senha.")
        #expect(app.transporte.feitos.isEmpty)
    }

    @Test("Senha provisória: só a tela de troca; depois da troca a conta fica liberada")
    func senhaProvisoria() async throws {
        let app = AppDeTeste()
        var usuario = AppDeTeste.maria
        usuario.precisaTrocarSenha = true
        app.transporte.responder("POST entrar", 200, codificar(["token": "bulai_app_novo"]).mesclando(["user": usuario]))
        app.transporte.responder("GET agenda", 200, contrato("agenda"))
        try await app.sessao.entrar(api: app.api, email: "maria@exemplo.com", senha: "provisoria", aparelho: "iPhone", versao: "1")
        #expect(app.sessao.estado == .trocarSenha(usuario))
        #expect(!app.transporte.feitos.contains("GET agenda"), "sem lembretes antes da troca")

        usuario.precisaTrocarSenha = false
        app.transporte.responder("POST primeiro-acesso", 200, codificar(["user": usuario]))
        let tela = PrimeiroAcessoViewModel(app.dependencias)
        tela.senha = "nova-senha-123"
        tela.confirmacao = "nova-senha-123"
        await tela.salvar()
        #expect(app.sessao.estado == .conectado(usuario))
        #expect(app.transporte.feitos.contains("GET agenda"))
    }

    @Test("Reabrir o app com a conta guardada entra direto; token sem conta (reinstalação) é descartado")
    func retomar() {
        let conectado = AppDeTeste.conectado()
        #expect(conectado.sessao.conectado)
        #expect(conectado.api.caixa.token == "bulai_app_guardado")

        let preferencias = PreferenciasLocais(UserDefaults(suiteName: "bulai-widget-\(UUID().uuidString)")!)
        let cofreDoWidget = CofreEmMemoria("bulai_app_do_app")
        _ = Sessao(cofre: cofreDoWidget, preferencias: preferencias, cache: CacheEmDisco(pasta: FileManager.default.temporaryDirectory), processoPrincipal: false)
        #expect(cofreDoWidget.ler() == "bulai_app_do_app", "o widget nunca apaga o token do app")

        let reinstalado = AppDeTeste(token: "bulai_app_de_outra_instalacao", conta: nil)
        #expect(reinstalado.sessao.estado == .visitante)
        #expect(reinstalado.cofre.ler() == nil)
        #expect(reinstalado.api.caixa.token == nil)
    }

    @Test("Token revogado em qualquer tela apaga dados locais e volta para Entrar")
    func revogado() async {
        let app = AppDeTeste.conectado()
        await app.cache.gravar("inicio", try! JSONDecoder().decode(Inicio.self, from: contrato("home")))
        app.preferencias.agenda = try! JSONDecoder().decode(AgendaDoAparelho.self, from: contrato("agenda"))
        app.transporte.responder("GET perfil", 401, json(#"{"message":"Aparelho desconectado."}"#))
        let perfil = PerfilViewModel(app.dependencias)
        await perfil.atualizar()
        #expect(app.sessao.estado == .visitante)
        #expect(await app.cache.ler("inicio", como: Inicio.self) == nil)
        #expect(app.preferencias.agenda == nil)
        #expect(app.notificacoes.lembretes.isEmpty)
    }

    @Test("Sair limpa na hora e avisa o servidor com o token antigo")
    func sair() async {
        let app = AppDeTeste.conectado()
        app.transporte.responder("DELETE aparelho", 200, json("{}"))
        let conta = ContaViewModel(app.dependencias)
        await conta.sair()
        #expect(app.sessao.estado == .visitante)
        #expect(app.cofre.ler() == nil)
        let pedido = app.transporte.ultimo("DELETE aparelho")
        #expect(pedido?.value(forHTTPHeaderField: "Authorization") == "Bearer bulai_app_guardado")
    }

    @Test("Outra conta no mesmo celular não herda cache nem lembretes da anterior")
    func outraConta() async throws {
        let app = AppDeTeste.conectado()
        await app.cache.gravar("inicio", try JSONDecoder().decode(Inicio.self, from: contrato("home")))
        app.preferencias.marcadasLocalmente = ["1|x"]
        app.transporte.responder("POST entrar", 200, contrato("login"))
        app.transporte.responder("GET agenda", 200, contrato("agenda"))
        try await app.sessao.entrar(api: app.api, email: "outra@exemplo.com", senha: "x", aparelho: "iPhone", versao: "1")
        #expect(await app.cache.ler("inicio", como: Inicio.self) == nil)
        #expect(app.preferencias.marcadasLocalmente.isEmpty)
        #expect(app.preferencias.usuario == "2")
    }

    @Test("Apagar a conta pede a senha e, aceito, limpa o aparelho")
    func apagarConta() async {
        let app = AppDeTeste.conectado()
        let conta = ContaViewModel(app.dependencias)
        app.transporte.responder("DELETE conta", 422, json(#"{"message":"x","errors":{"password":["A senha está incorreta."]}}"#))
        conta.senhaParaApagar = "errada"
        await conta.apagarConta()
        #expect(conta.formularioDeApagar.campos["password"] == "A senha está incorreta.")
        #expect(app.sessao.conectado)

        app.transporte.limpar("DELETE conta")
        app.transporte.responder("DELETE conta", 200, contrato("account.destroy"))
        conta.senhaParaApagar = "certa"
        await conta.apagarConta()
        #expect(app.sessao.estado == .visitante)
    }
}

extension Data {
    /// Junta dois objetos JSON (montar respostas nos testes).
    func mesclando(_ outro: [String: Usuario]) -> Data {
        var base = (try? JSONSerialization.jsonObject(with: self) as? [String: Any]) ?? [:]
        for (chave, valor) in outro {
            base[chave] = try? JSONSerialization.jsonObject(with: codificar(valor))
        }
        return try! JSONSerialization.data(withJSONObject: base)
    }
}
