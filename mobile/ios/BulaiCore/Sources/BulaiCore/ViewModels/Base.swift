import Foundation
import Observation

/// Estado de uma tela que carrega da rede: ``dados`` pode vir do cache enquanto ``atualizando`` busca a versão nova;
/// ``erro`` aparece por cima dos dados que já existem (sem apagar a tela).
public struct Carga<T: Sendable>: Sendable {
    public var dados: T?
    public var atualizando: Bool
    public var erro: String?

    public init(dados: T? = nil, atualizando: Bool = false, erro: String? = nil) {
        self.dados = dados
        self.atualizando = atualizando
        self.erro = erro
    }

    /// Primeira carga, sem nada para mostrar: a tela mostra o esqueleto.
    public var esqueleto: Bool { dados == nil && erro == nil }
}

/// Mensagem curta para o aviso flutuante ("Anotado: você tomou…"); com ``desfazer``, oferece "Desfazer".
public struct Recado: Identifiable, Sendable {
    public let id = UUID()
    public var texto: String
    public var desfazer: (@MainActor @Sendable () -> Void)?

    public init(_ texto: String, desfazer: (@MainActor @Sendable () -> Void)? = nil) {
        self.texto = texto
        self.desfazer = desfazer
    }
}

/// Dependências das telas, criadas uma vez no app (sem framework de injeção).
@MainActor
public final class Dependencias {
    public let api: ClienteDaApi
    public let cache: CacheEmDisco
    public let sessao: Sessao
    public let preferencias: PreferenciasLocais
    public let sincronizador: Sincronizador
    /// Nome do aparelho e versão enviados ao entrar (`device_name`, `app_version`).
    public let aparelho: String
    public let versao: String

    public init(api: ClienteDaApi, cache: CacheEmDisco, sessao: Sessao, preferencias: PreferenciasLocais, sincronizador: Sincronizador, aparelho: String, versao: String) {
        self.api = api
        self.cache = cache
        self.sessao = sessao
        self.preferencias = preferencias
        self.sincronizador = sincronizador
        self.aparelho = aparelho
        self.versao = versao
    }

    /// Mudança no tratamento feita nas telas: os lembretes do aparelho seguem em seguida.
    public func pedirSincronizacao() {
        Task { await sincronizador.sincronizar() }
    }
}

/// Executa a chamada e devolve o resultado com a falha já traduzida.
public func tentar<T: Sendable>(isolation _: isolated (any Actor)? = #isolation, _ bloco: () async throws -> T) async -> Result<T, FalhaDaApi> {
    do {
        return .success(try await bloco())
    } catch {
        return .failure(FalhaDaApi.traduzir(error))
    }
}

/// Espera curta enquanto a pessoa digita; `false` se outra digitação cancelou esta.
func esperarDigitacao(_ milissegundos: UInt64) async -> Bool {
    try? await Task.sleep(nanoseconds: milissegundos * 1_000_000)
    return !Task.isCancelled
}

/// Estado comum dos formulários: enviando, erro geral e erros por campo (do 422 do servidor).
public struct EstadoDoFormulario: Sendable {
    public var enviando = false
    public var erro: String?
    public var campos: [String: String] = [:]

    public init() {}

    public mutating func comecar() {
        enviando = true
        erro = nil
        campos = [:]
    }

    public mutating func falhou(_ falha: FalhaDaApi) {
        enviando = false
        if !falha.campos.isEmpty {
            campos = falha.campos
        } else {
            erro = falha.mensagem
        }
    }

    public mutating func limpar(_ campo: String) {
        campos.removeValue(forKey: campo)
        erro = nil
    }
}

/// Busca no catálogo com espera de digitação (correção do remédio, receita, perfil, interações).
@MainActor
@Observable
public final class BuscaNoCatalogo {
    public private(set) var buscando = false
    public private(set) var resultados: [RemedioDoCatalogo]?
    @ObservationIgnored private let api: ClienteDaApi
    @ObservationIgnored private var tarefa: Task<Void, Never>?
    @ObservationIgnored public var excluir: () -> [Int64] = { [] }

    public init(api: ClienteDaApi) {
        self.api = api
    }

    public func buscar(_ termo: String) {
        tarefa?.cancel()
        guard termo.trimmingCharacters(in: .whitespacesAndNewlines).count >= 3 else {
            resultados = nil
            buscando = false
            return
        }
        tarefa = Task {
            guard await esperarDigitacao(300) else {
                return
            }
            buscando = true
            let excluidos = excluir()
            let resultado = await tentar { try await api.buscarRemedios(termo, excluir: excluidos) }
            guard !Task.isCancelled else {
                return
            }
            if case let .success(achados) = resultado {
                resultados = achados.items
            }
            buscando = false
        }
    }

    public func fechar() {
        tarefa?.cancel()
        resultados = nil
        buscando = false
    }
}
