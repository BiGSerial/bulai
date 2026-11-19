import Foundation
import Observation

/// Montar a lista (os remédios em uso já entram), buscar mais e verificar; e o histórico das consultas.
@MainActor
@Observable
public final class PesquisaViewModel {
    public private(set) var carregando = true
    public private(set) var escolhidos: [RemedioDaConsulta] = []
    public private(set) var maximo = 5
    public private(set) var historico: [ConsultaAnterior] = []
    public private(set) var verificando = false
    public var mensagem: String?
    public let busca: BuscaNoCatalogo
    @ObservationIgnored private let api: ClienteDaApi
    @ObservationIgnored private let comRemedio: Int64?
    @ObservationIgnored private var carregou = false

    public init(api: ClienteDaApi, comRemedio: Int64? = nil) {
        self.api = api
        self.comRemedio = comRemedio
        busca = BuscaNoCatalogo(api: api)
        busca.excluir = { [weak self] in self?.escolhidos.map(\.id) ?? [] }
    }

    public func carregar() async {
        guard !carregou else {
            await atualizarHistorico()
            return
        }
        carregou = true
        switch await tentar({ try await api.interacoes() }) {
        case let .success(inicio):
            escolhidos = inicio.meus
            maximo = inicio.max
            historico = inicio.historico
        case let .failure(falha):
            mensagem = falha.mensagem
        }
        carregando = false
        if let comRemedio, !escolhidos.contains(where: { $0.id == comRemedio }) {
            await adicionar(comRemedio)
        }
    }

    public func atualizarHistorico() async {
        if case let .success(inicio) = await tentar({ try await api.interacoes() }) {
            historico = inicio.historico
        }
    }

    /// O servidor decide se o remédio pode entrar (repetido, limite) e devolve o alerta de alergia.
    public func adicionar(_ remedio: Int64) async {
        busca.fechar()
        let atuais = escolhidos.map(\.id)
        switch await tentar({ try await api.remedioDaConsulta(remedio, escolhidos: atuais) }) {
        case let .success(item):
            escolhidos.append(item)
            mensagem = nil
        case let .failure(falha):
            mensagem = falha.mensagem
        }
    }

    public func tirar(_ remedio: Int64) {
        escolhidos.removeAll { $0.id == remedio }
        mensagem = nil
    }

    /// Devolve o uuid da consulta criada (a tela abre o resultado).
    public func verificar() async -> String? {
        guard !verificando else {
            return nil
        }
        verificando = true
        mensagem = nil
        defer { verificando = false }
        let ids = escolhidos.map(\.id)
        switch await tentar({ try await api.verificarInteracoes(ids) }) {
        case let .success(consulta):
            return consulta.uuid
        case let .failure(falha):
            mensagem = falha.mensagem
            return nil
        }
    }

    public func apagar(_ uuid: String) async {
        if case .success = await tentar({ try await api.apagarConsulta(uuid) }) {
            historico.removeAll { $0.uuid == uuid }
        }
    }
}

/// Resultado de uma consulta: acompanha a análise a cada 2 s até terminar.
@MainActor
@Observable
public final class ResultadoViewModel {
    public private(set) var carga = Carga<ConsultaDeInteracoes>()
    @ObservationIgnored private let api: ClienteDaApi
    @ObservationIgnored public let uuid: String
    @ObservationIgnored public var intervaloDeConsulta: UInt64 = 2000

    public init(api: ClienteDaApi, uuid: String) {
        self.api = api
        self.uuid = uuid
    }

    public func acompanhar() async {
        while !Task.isCancelled {
            switch await tentar({ try await api.consulta(uuid) }) {
            case let .success(consulta):
                carga = Carga(dados: consulta)
            case let .failure(falha):
                carga.erro = falha.mensagem
            }
            if carga.dados?.running == false || (carga.dados == nil && carga.erro != nil) {
                return
            }
            try? await Task.sleep(nanoseconds: intervaloDeConsulta * 1_000_000)
        }
    }

    /// uuid da nova consulta (a tela abre o resultado dela).
    public func repetir() async -> String? {
        switch await tentar({ try await api.repetirConsulta(uuid) }) {
        case let .success(consulta):
            return consulta.uuid
        case let .failure(falha):
            carga.erro = falha.mensagem
            return nil
        }
    }
}
