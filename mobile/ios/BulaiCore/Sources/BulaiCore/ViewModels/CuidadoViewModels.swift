import Foundation
import Observation

/// "Quem cuida de mim": convite em QR Code, aprovar/recusar pedidos com as permissões, mudar e tirar acesso.
/// As regras ficam no servidor (CaregiverLinkService); o app só mostra o que ele devolve.
@MainActor
@Observable
public final class QuemCuidaDeMimViewModel {
    public private(set) var carga = Carga<MeusCuidadores>()
    /// Vínculo (ou "convite") com ação a caminho do servidor.
    public private(set) var ocupado: String?
    public var recado: Recado?
    @ObservationIgnored private let api: ClienteDaApi

    public init(api: ClienteDaApi) {
        self.api = api
    }

    public func carregar() async {
        carga.atualizando = true
        switch await tentar({ try await api.cuidadores() }) {
        case let .success(dados):
            carga = Carga(dados: dados)
        case let .failure(falha):
            carga.atualizando = false
            carga.erro = falha.mensagem
        }
    }

    public func gerarConvite() async {
        await agir("convite", nil) { try await self.api.gerarConvite() }
    }

    public func cancelarConvite() async {
        await agir("convite", "Convite cancelado. O QR Code não vale mais.") { try await self.api.cancelarConvite() }
    }

    public func aprovar(_ pessoa: Cuidador, permissoes: [String: Bool]) async {
        await agir("\(pessoa.id)", "\(pessoa.primeiroNome) agora acompanha você, com as permissões que você marcou.") { try await self.api.aprovarCuidador(pessoa.id, permissoes: permissoes) }
    }

    public func recusar(_ pessoa: Cuidador) async {
        await agir("\(pessoa.id)", "Pedido recusado. Nada foi compartilhado.") { try await self.api.recusarCuidador(pessoa.id) }
    }

    public func mudarPermissoes(_ pessoa: Cuidador, permissoes: [String: Bool]) async {
        await agir("\(pessoa.id)", "Permissões de \(pessoa.primeiroNome) atualizadas.") { try await self.api.mudarPermissoes(pessoa.id, permissoes: permissoes) }
    }

    public func tirarAcesso(_ pessoa: Cuidador) async {
        await agir("\(pessoa.id)", "\(pessoa.primeiroNome) não tem mais acesso aos seus remédios.") { try await self.api.tirarAcesso(pessoa.id) }
    }

    private func agir(_ chave: String, _ sucesso: String?, _ chamada: () async throws -> MeusCuidadores) async {
        guard ocupado == nil else {
            return
        }
        ocupado = chave
        defer { ocupado = nil }
        switch await tentar(chamada) {
        case let .success(novo):
            carga = Carga(dados: novo)
            if let sucesso {
                recado = Recado(sucesso)
            }
        case let .failure(falha):
            recado = Recado(falha.mensagem)
        }
    }

    /// Texto para "Enviar o link do convite".
    public static func textoDoConvite(_ convite: Convite) -> String {
        "Quero que você acompanhe meus remédios no BULA-IA. Abra este link (vale até \(Datas.curta(convite.venceEm))): \(convite.url)\nOu use o código \(convite.code) em Cuidar de alguém."
    }
}

/// "Cuidar de alguém": as pessoas que a pessoa acompanha (para escolher de quem ver o dia), ler o QR e sair do vínculo.
@MainActor
@Observable
public final class CuidarDeAlguemViewModel {
    public private(set) var carga = Carga<QuemEuCuido>()
    public private(set) var enviando = false
    /// Erro do código lido ou digitado (aparece embaixo do campo).
    public var erroDoCodigo: String?
    public var recado: Recado?
    @ObservationIgnored private let dependencias: Dependencias

    public init(_ dependencias: Dependencias) {
        self.dependencias = dependencias
    }

    public func carregar() async {
        if carga.dados == nil, let guardado = await dependencias.cache.ler("quem-eu-cuido", como: QuemEuCuido.self) {
            carga.dados = guardado
        }
        carga.atualizando = true
        switch await tentar({ try await dependencias.api.quemEuCuido() }) {
        case let .success(dados):
            await mostrar(dados)
        case let .failure(falha):
            carga.atualizando = false
            carga.erro = falha.mensagem
        }
    }

    /// `codigo` é o link lido no QR Code ou os 8 números digitados (o servidor aceita os dois).
    @discardableResult
    public func usarConvite(_ codigo: String) async -> Bool {
        let limpo = codigo.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !enviando, !limpo.isEmpty else {
            return false
        }
        enviando = true
        erroDoCodigo = nil
        defer { enviando = false }
        switch await tentar({ try await dependencias.api.usarConvite(limpo) }) {
        case let .success(dados):
            await mostrar(dados)
            if let mensagem = dados.message {
                recado = Recado(mensagem)
            }
            return true
        case let .failure(falha):
            erroDoCodigo = falha.campos["code"] ?? falha.mensagem
            return false
        }
    }

    public func deixarDeAcompanhar(_ pessoa: PessoaAcompanhada) async {
        switch await tentar({ try await dependencias.api.deixarDeAcompanhar(pessoa.id) }) {
        case let .success(dados):
            await mostrar(dados)
            recado = Recado("Você deixou de acompanhar \(pessoa.primeiroNome).")
        case let .failure(falha):
            recado = Recado(falha.mensagem)
        }
    }

    private func mostrar(_ dados: QuemEuCuido) async {
        carga = Carga(dados: dados)
        await dependencias.cache.gravar("quem-eu-cuido", dados)
    }
}

/// "Resumo para o médico": baixa o PDF (o mesmo do site) para a pasta temporária, pronto para abrir ou compartilhar.
@MainActor
@Observable
public final class ResumoViewModel {
    public private(set) var baixando = false
    public var erro: String?
    public var arquivo: URL?
    @ObservationIgnored private let api: ClienteDaApi

    public init(api: ClienteDaApi) {
        self.api = api
    }

    public func baixar() async {
        guard !baixando else {
            return
        }
        baixando = true
        erro = nil
        defer { baixando = false }
        switch await tentar({ try await api.resumoParaOMedico() }) {
        case let .success(dados):
            let pasta = FileManager.default.temporaryDirectory.appendingPathComponent("resumos", isDirectory: true)
            try? FileManager.default.removeItem(at: pasta)
            try? FileManager.default.createDirectory(at: pasta, withIntermediateDirectories: true)
            let destino = pasta.appendingPathComponent("resumo-para-o-medico.pdf")
            do {
                try dados.write(to: destino, options: [.atomic])
                arquivo = destino
            } catch {
                erro = "Não deu para gerar o resumo agora. Tente de novo."
            }
        case let .failure(falha):
            erro = falha.mensagem
        }
    }
}
