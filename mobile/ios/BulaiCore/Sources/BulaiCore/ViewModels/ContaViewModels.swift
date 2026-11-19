import Foundation
import Observation

@MainActor
@Observable
public final class EntrarViewModel {
    public var email = ""
    public var senha = ""
    public var formulario = EstadoDoFormulario()
    @ObservationIgnored private let dependencias: Dependencias

    public init(_ dependencias: Dependencias) {
        self.dependencias = dependencias
    }

    public func entrar() async {
        guard !formulario.enviando else {
            return
        }
        var faltando: [String: String] = [:]
        if email.trimmingCharacters(in: .whitespaces).isEmpty {
            faltando["email"] = "Escreva o seu e-mail."
        }
        if senha.isEmpty {
            faltando["password"] = "Escreva a sua senha."
        }
        guard faltando.isEmpty else {
            formulario.campos = faltando
            return
        }
        formulario.comecar()
        let resultado = await tentar { try await dependencias.sessao.entrar(api: dependencias.api, email: email, senha: senha, aparelho: dependencias.aparelho, versao: dependencias.versao) }
        switch resultado {
        case .success:
            formulario = EstadoDoFormulario()
            senha = ""
        case let .failure(falha):
            formulario.falhou(falha)
        }
    }
}

@MainActor
@Observable
public final class CadastroViewModel {
    public var nome = ""
    public var email = ""
    public var senha = ""
    public var confirmacao = ""
    public var aceitou = false
    public var formulario = EstadoDoFormulario()
    public private(set) var concluido: String?
    @ObservationIgnored private let api: ClienteDaApi

    public init(api: ClienteDaApi) {
        self.api = api
    }

    public func cadastrar() async {
        guard !formulario.enviando else {
            return
        }
        formulario.comecar()
        let pedido = PedidoDeCadastro(name: nome.trimmingCharacters(in: .whitespaces), email: email.trimmingCharacters(in: .whitespaces), password: senha, confirmacao: confirmacao, terms: aceitou)
        switch await tentar({ try await api.cadastrar(pedido) }) {
        case let .success(mensagem):
            formulario.enviando = false
            concluido = mensagem.message
        case let .failure(falha):
            formulario.falhou(falha)
        }
    }
}

@MainActor
@Observable
public final class EsqueciSenhaViewModel {
    public var email = ""
    public var formulario = EstadoDoFormulario()
    public private(set) var enviado: String?
    @ObservationIgnored private let api: ClienteDaApi

    public init(api: ClienteDaApi) {
        self.api = api
    }

    public func pedir() async {
        guard !formulario.enviando else {
            return
        }
        formulario.comecar()
        let endereco = email.trimmingCharacters(in: .whitespaces)
        switch await tentar({ try await api.esqueciSenha(endereco) }) {
        case let .success(mensagem):
            formulario.enviando = false
            enviado = mensagem.message
        case let .failure(falha):
            formulario.falhou(falha)
        }
    }
}

/// Senha provisória: só esta tela (e sair) funciona até a troca.
@MainActor
@Observable
public final class PrimeiroAcessoViewModel {
    public var senha = ""
    public var confirmacao = ""
    public var formulario = EstadoDoFormulario()
    @ObservationIgnored private let dependencias: Dependencias

    public init(_ dependencias: Dependencias) {
        self.dependencias = dependencias
    }

    public func salvar() async {
        guard !formulario.enviando else {
            return
        }
        formulario.comecar()
        switch await tentar({ try await dependencias.sessao.trocarSenhaProvisoria(api: dependencias.api, senha: senha, confirmacao: confirmacao) }) {
        case .success:
            formulario = EstadoDoFormulario()
        case let .failure(falha):
            formulario.falhou(falha)
        }
    }
}

/// Aba Conta: nome de chamada, lembretes (preferências da central de avisos), senha, sair e apagar a conta.
@MainActor
@Observable
public final class ContaViewModel {
    public var nomeDeChamada = ""
    public var formularioDoNome = EstadoDoFormulario()
    public var senhaAtual = ""
    public var novaSenha = ""
    public var confirmacao = ""
    public var formularioDaSenha = EstadoDoFormulario()
    public var senhaParaApagar = ""
    public var formularioDeApagar = EstadoDoFormulario()
    public private(set) var central = Carga<CentralDeAvisos>()
    public var recado: Recado?
    public private(set) var proximoLembrete: String?
    public var tema: Tema {
        didSet { dependencias.preferencias.tema = tema }
    }

    public var insistir: Bool {
        didSet {
            dependencias.preferencias.insistir = insistir
            Task { await dependencias.sincronizador.reagendar() }
        }
    }

    public var adiarMinutos: Int {
        didSet {
            dependencias.preferencias.adiarMinutos = adiarMinutos
            Task { await dependencias.sincronizador.reagendar() }
        }
    }

    public static let opcoesDeAdiamento = [5, 10, 15, 30]

    @ObservationIgnored private let dependencias: Dependencias

    public init(_ dependencias: Dependencias) {
        self.dependencias = dependencias
        nomeDeChamada = dependencias.sessao.usuario?.nomeDeChamada ?? ""
        tema = dependencias.preferencias.tema
        insistir = dependencias.preferencias.insistir
        adiarMinutos = dependencias.preferencias.adiarMinutos
    }

    public var usuario: Usuario? { dependencias.sessao.usuario }

    public func carregar() async {
        lerProximoLembrete()
        if central.dados == nil, let guardada = await dependencias.cache.ler("avisos", como: CentralDeAvisos.self) {
            central.dados = guardada
        }
        central.atualizando = true
        switch await tentar({ try await dependencias.api.avisos() }) {
        case let .success(nova):
            central = Carga(dados: nova)
            await dependencias.cache.gravar("avisos", nova)
        case let .failure(falha):
            central.atualizando = false
            central.erro = falha.mensagem
        }
        if case let .success(usuario) = await tentar({ try await dependencias.api.conta() }) {
            await dependencias.sessao.atualizar(usuario)
        }
    }

    public func lerProximoLembrete() {
        proximoLembrete = dependencias.sincronizador.proximoLembrete().map { lembrete in
            let dia = Calendar.current.isDateInToday(lembrete.quando) ? "Hoje" : "Amanhã"
            return "\(dia) às \(Datas.formatar(lembrete.quando, "HH:mm")) · \(lembrete.dose.name)"
        }
    }

    /// Vazio volta a usar o primeiro nome. A saudação do Início muda junto.
    public func salvarNome() async {
        guard !formularioDoNome.enviando else {
            return
        }
        formularioDoNome.comecar()
        let limpo = nomeDeChamada.trimmingCharacters(in: .whitespaces)
        switch await tentar({ try await dependencias.api.mudarNomeDeChamada(limpo.isEmpty ? nil : limpo) }) {
        case let .success(usuario):
            formularioDoNome.enviando = false
            await dependencias.sessao.atualizar(usuario)
            nomeDeChamada = usuario.nomeDeChamada ?? ""
            recado = Recado(limpo.isEmpty ? "Pronto: vamos chamar você de \(usuario.primeiroNome)." : "Pronto: agora chamamos você de \(usuario.primeiroNome).")
        case let .failure(falha):
            formularioDoNome.falhou(falha)
        }
    }

    public func trocarSenha() async -> Bool {
        guard !formularioDaSenha.enviando else {
            return false
        }
        formularioDaSenha.comecar()
        switch await tentar({ try await dependencias.api.trocarSenha(atual: senhaAtual, nova: novaSenha, confirmacao: confirmacao) }) {
        case let .success(mensagem):
            formularioDaSenha = EstadoDoFormulario()
            senhaAtual = ""
            novaSenha = ""
            confirmacao = ""
            recado = Recado(mensagem.message)
            return true
        case let .failure(falha):
            formularioDaSenha.falhou(falha)
            return false
        }
    }

    /// Apaga a conta no servidor; depois, limpa o aparelho (lembretes, dados guardados) e volta para Entrar.
    public func apagarConta() async {
        guard !formularioDeApagar.enviando else {
            return
        }
        formularioDeApagar.comecar()
        switch await tentar({ try await dependencias.api.apagarConta(senha: senhaParaApagar) }) {
        case .success:
            formularioDeApagar = EstadoDoFormulario()
            await dependencias.sessao.perdeuAcesso()
        case let .failure(falha):
            formularioDeApagar.falhou(falha)
        }
    }

    public func sair() async {
        let api = dependencias.api
        await dependencias.sessao.sair { token in
            try? await api.comToken(token).sair()
        }
    }

    // MARK: Lembretes (preferências da conta, iguais às do site)

    public func lembretes(_ ligados: Bool) async {
        await preferencia(AlteracaoDePreferencias(reminders: ligados), ligados ? "Lembretes ligados." : "Lembretes desligados. O celular não vai avisar nos horários.") { $0.reminders = ligados }
    }

    public func email(_ ligado: Bool) async {
        await preferencia(AlteracaoDePreferencias(email: ligado), ligado ? "Você também receberá os lembretes por e-mail." : "Lembretes só no aplicativo.") { $0.email = ligado }
    }

    public func fuso(_ id: String) async {
        await preferencia(AlteracaoDePreferencias(timezone: id), "Horário ajustado.") { $0.timezone = id }
    }

    public func testarLembrete() async {
        await dependencias.sincronizador.testar()
        recado = Recado("O lembrete de teste vai aparecer em alguns segundos. Bloqueie a tela para ver como fica.")
    }

    private func preferencia(_ alteracao: AlteracaoDePreferencias, _ mensagem: String, naTela: (inout PreferenciasDeAviso) -> Void) async {
        let antes = central.dados
        if var dados = central.dados {
            naTela(&dados.preferences)
            central.dados = dados
        }
        switch await tentar({ try await dependencias.api.preferencias(alteracao) }) {
        case let .success(nova):
            central = Carga(dados: nova)
            await dependencias.cache.gravar("avisos", nova)
            recado = Recado(mensagem)
            dependencias.pedirSincronizacao()
        case let .failure(falha):
            central.dados = antes
            recado = Recado(falha.mensagem)
        }
    }
}
