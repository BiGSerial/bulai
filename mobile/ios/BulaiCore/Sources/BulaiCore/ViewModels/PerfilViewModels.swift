import Foundation
import Observation

/// "Meus remédios": consentimento, remédios com dose e horários, receitas e alergias, com as regras do site.
/// As regras e as frases vêm do servidor: o app mostra a `message` da resposta (ou o erro dela).
@MainActor
@Observable
public final class PerfilViewModel {
    public private(set) var carga = Carga<Perfil>()
    public private(set) var ocupado = false
    public var erro: String?
    /// Remédio com "Dose e horários" aberto.
    public var editando: RemedioDoPerfil?
    public var recado: Recado?
    public let busca: BuscaNoCatalogo
    @ObservationIgnored private let dependencias: Dependencias

    public init(_ dependencias: Dependencias) {
        self.dependencias = dependencias
        busca = BuscaNoCatalogo(api: dependencias.api)
    }

    public func carregar() async {
        if carga.dados == nil, let guardado = await dependencias.cache.ler("perfil", como: Perfil.self) {
            carga.dados = guardado
        }
        await atualizar()
    }

    public func atualizar() async {
        carga.atualizando = true
        switch await tentar({ try await dependencias.api.perfil() }) {
        case let .success(perfil):
            carga = Carga(dados: perfil)
            await dependencias.cache.gravar("perfil", perfil)
        case let .failure(falha):
            carga.atualizando = false
            carga.erro = falha.mensagem
        }
    }

    @discardableResult
    public func consentir() async -> Bool {
        await agir { try await self.dependencias.api.consentir() }
    }

    public func apagarDadosDeSaude() async {
        await agir { try await self.dependencias.api.apagarDadosDeSaude() }
    }

    /// Remédio do catálogo (ou pelo nome): entra na lista e já abre "Dose e horários".
    public func adicionar(catalogo: Int64?, nome: String?) async {
        busca.fechar()
        let limpo = nome?.trimmingCharacters(in: .whitespaces)
        await agir({ try await self.dependencias.api.adicionarRemedio(catalogo: catalogo, nome: catalogo == nil ? limpo : nil) }) { novo in
            self.editando = novo.remedios.first { $0.id == novo.remedioNovo }
        }
    }

    public func sugerir(frequencia: String, posologia: DoseEHorarios, atalho: Bool) async -> DoseEHorarios? {
        try? await dependencias.api.sugerirHorarios(frequencia: frequencia, posologia: posologia, atalho: atalho)
    }

    public func salvar(_ posologia: DoseEHorarios) async {
        guard let remedio = editando else {
            return
        }
        await agir({ try await self.dependencias.api.salvarPosologia(remedio.id, posologia) }) { _ in
            self.editando = nil
        }
    }

    public func tirarRemedio(_ remedio: RemedioDoPerfil) async {
        await agir { try await self.dependencias.api.tirarRemedio(remedio.id) }
    }

    @discardableResult
    public func adicionarReceita(_ pedido: PedidoDeReceitaDoPerfil) async -> Bool {
        await agir { try await self.dependencias.api.adicionarReceitaDoPerfil(pedido) }
    }

    public func tirarReceita(_ id: Int64) async {
        await agir { try await self.dependencias.api.tirarReceita(id) }
    }

    @discardableResult
    public func adicionarAlergia(substancia: String, reacao: String?) async -> Bool {
        let limpa = reacao?.trimmingCharacters(in: .whitespaces)
        return await agir { try await self.dependencias.api.adicionarAlergia(substancia: substancia.trimmingCharacters(in: .whitespaces), reacao: (limpa?.isEmpty ?? true) ? nil : limpa) }
    }

    public func tirarAlergia(_ id: Int64) async {
        await agir { try await self.dependencias.api.tirarAlergia(id) }
    }

    @discardableResult
    private func agir(_ chamada: () async throws -> Perfil, aoConseguir: (Perfil) -> Void = { _ in }) async -> Bool {
        guard !ocupado else {
            return false
        }
        ocupado = true
        erro = nil
        defer { ocupado = false }
        switch await tentar(chamada) {
        case let .success(novo):
            carga = Carga(dados: novo)
            await dependencias.cache.gravar("perfil", novo)
            dependencias.pedirSincronizacao()
            aoConseguir(novo)
            if let mensagem = novo.message {
                recado = Recado(mensagem)
            }
            return true
        case let .failure(falha):
            erro = falha.mensagem
            if editando == nil {
                recado = Recado(falha.mensagem)
            }
            return false
        }
    }
}

/// Formulário "Dose e horários" (também para quem cuida), em três passos como no Android e no PWA:
/// 1. Quanto tomar, 2. Quando tomar (atalhos do servidor ou o texto da receita), 3. Horários do dia.
/// Mudar "quando tomar" pede ao servidor o que muda (horários, intervalo, "só quando precisar"); nada é salvo até "Salvar".
@MainActor
@Observable
public final class EditorDePosologia {
    public var posologia: DoseEHorarios
    public private(set) var outroJeito: Bool
    public let atalhos: AtalhosDePosologia
    @ObservationIgnored private let sugerir: (String, DoseEHorarios, Bool) async -> DoseEHorarios?
    @ObservationIgnored private var digitacao: Task<Void, Never>?

    public static let maximoDeHorarios = 12

    public init(inicial: DoseEHorarios, atalhos: AtalhosDePosologia, sugerir: @escaping (String, DoseEHorarios, Bool) async -> DoseEHorarios?) {
        posologia = inicial
        self.atalhos = atalhos
        self.sugerir = sugerir
        let escolhido = atalhos.frequencias.first { EditorDePosologia.mesmoTexto($0, inicial.frequency) }
        outroJeito = escolhido == nil && !inicial.frequency.trimmingCharacters(in: .whitespaces).isEmpty
    }

    public var atalhoEscolhido: String? {
        atalhos.frequencias.first { EditorDePosologia.mesmoTexto($0, posologia.frequency) }
    }

    public var doseEscolhida: String? {
        atalhos.doses.first { EditorDePosologia.mesmoTexto($0, posologia.dosage) }
    }

    public func escolherDose(_ dose: String) {
        posologia.dosage = dose
    }

    public func escreverDose(_ texto: String) {
        posologia.dosage = String(texto.prefix(120))
    }

    /// Atalho tocado: o servidor refaz os horários do zero.
    public func escolherAtalho(_ texto: String) async {
        outroJeito = false
        digitacao?.cancel()
        posologia.frequency = texto
        if let sugerida = await sugerir(texto, posologia, true) {
            posologia = sugerida
        }
    }

    public func abrirOutroJeito() {
        outroJeito = true
    }

    /// "Quando tomar" escrito: espera a pessoa parar de digitar e aplica a sugestão.
    public func escreverFrequencia(_ texto: String) {
        posologia.frequency = String(texto.prefix(120))
        digitacao?.cancel()
        digitacao = Task {
            guard await esperarDigitacao(600) else {
                return
            }
            if let sugerida = await sugerir(texto, posologia, false), !Task.isCancelled {
                posologia = sugerida
            }
        }
    }

    public func esperarSugestao() async {
        await digitacao?.value
    }

    /// Horário novo sugerido: 4 h depois do último (ou 08:00), para a pessoa só ajustar.
    public var sugestaoDeNovoHorario: String {
        guard let ultimo = posologia.times.last, let hora = Int(ultimo.prefix(2)) else {
            return "08:00"
        }
        return String(format: "%02d:00", (hora + 4) % 24)
    }

    public func adicionarHorario(_ hora: String) {
        guard posologia.times.count < EditorDePosologia.maximoDeHorarios else {
            return
        }
        posologia.times = Array(Set(posologia.times + [hora])).sorted()
        posologia.seNecessario = false
    }

    public func trocarHorario(_ posicao: Int, _ hora: String) {
        guard posologia.times.indices.contains(posicao) else {
            if posicao == 0 {
                posologia.times = [hora]
            }
            return
        }
        posologia.times[posicao] = hora
        posologia.times = Array(Set(posologia.times)).sorted()
    }

    public func tirarHorario(_ posicao: Int) {
        if posologia.times.indices.contains(posicao) {
            posologia.times.remove(at: posicao)
        }
    }

    public func usarModo(_ modo: String) {
        posologia.modo = modo
    }

    public static func mesmoTexto(_ a: String, _ b: String) -> Bool {
        a.trimmingCharacters(in: .whitespaces).caseInsensitiveCompare(b.trimmingCharacters(in: .whitespaces)) == .orderedSame
    }
}

/// Remédios de quem a pessoa cuida: só com a permissão "marcar e editar" cada remédio abre "Dose e horários".
@MainActor
@Observable
public final class RemediosDeQuemCuidoViewModel {
    public private(set) var carga = Carga<RemediosDeQuemCuido>()
    public var editando: RemedioDoPerfil?
    public private(set) var salvando = false
    public var erro: String?
    public var recado: Recado?
    @ObservationIgnored private let api: ClienteDaApi
    @ObservationIgnored public let vinculo: Int64

    public init(api: ClienteDaApi, vinculo: Int64) {
        self.api = api
        self.vinculo = vinculo
    }

    public func carregar() async {
        carga.atualizando = true
        switch await tentar({ try await api.remediosDeQuemCuido(vinculo) }) {
        case let .success(dados):
            carga = Carga(dados: dados)
        case let .failure(falha):
            carga.atualizando = false
            carga.erro = falha.mensagem
        }
    }

    public func sugerir(frequencia: String, posologia: DoseEHorarios, atalho: Bool) async -> DoseEHorarios? {
        try? await api.sugerirHorarios(frequencia: frequencia, posologia: posologia, atalho: atalho)
    }

    public func salvar(_ posologia: DoseEHorarios) async {
        guard let remedio = editando, !salvando else {
            return
        }
        salvando = true
        erro = nil
        defer { salvando = false }
        switch await tentar({ try await api.salvarPosologiaDeQuemCuido(vinculo, remedio: remedio.id, posologia) }) {
        case let .success(dados):
            carga = Carga(dados: dados)
            editando = nil
            recado = dados.message.map { Recado($0) }
        case let .failure(falha):
            erro = falha.mensagem.isEmpty ? "Não deu para salvar agora." : falha.mensagem
        }
    }
}
