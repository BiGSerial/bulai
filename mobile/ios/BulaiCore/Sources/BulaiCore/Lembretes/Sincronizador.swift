import Foundation

/// Quem mostra as notificações locais (UserNotifications no app; um falso nos testes).
public protocol ServicoDeNotificacoes: Sendable {
    /// Troca todos os lembretes de dose pendentes pelos planejados (avisos já mostrados ficam).
    func substituirLembretes(_ lembretes: [LembretePlanejado], adiarMinutos: Int) async
    /// Tira da tela e da fila os lembretes de uma dose (a dose foi marcada).
    func cancelarLembretes(chave: String) async
    func mostrarAviso(id: String, titulo: String, corpo: String, caminho: String) async
    func testar(emSegundos: Int, adiarMinutos: Int) async
    func removerTudo() async
}

/// O que fazer com uma dose a partir da notificação ou do widget.
public enum AcaoDaDose: String, Sendable, CaseIterable {
    case tomei = "TOMEI"
    case pular = "PULAR"
    case adiar = "ADIAR"
}

/// Mantém a agenda local em dia e as notificações dos remédios no lugar (a versão iOS do `Sincronizacao` +
/// `SincronizarWorker` + `MarcarDoseWorker` + `AcaoDaDoseReceiver` do Android).
///
/// - agenda (hoje e amanhã) buscada ao entrar, ao abrir o app, no segundo plano (quando o iOS permitir) e depois de
///   cada mudança feita nas telas;
/// - "Tomei"/"Pular" pela notificação ou widget valem na hora no aparelho e vão para o servidor com a hora real do toque;
///   sem rede, ficam numa fila e seguem na próxima oportunidade;
/// - avisos novos que não são de dose viram notificação local na sincronização (não há push do servidor).
@MainActor
public final class Sincronizador {
    private let api: ClienteDaApi
    private let preferencias: PreferenciasLocais
    private let notificacoes: ServicoDeNotificacoes
    private let aoMudarAgenda: @MainActor () -> Void
    private let relogio: @Sendable () -> Date
    private var emAndamento: Task<Bool, Never>?

    public init(api: ClienteDaApi, preferencias: PreferenciasLocais, notificacoes: ServicoDeNotificacoes, aoMudarAgenda: @escaping @MainActor () -> Void = {}, relogio: @escaping @Sendable () -> Date = { Date() }) {
        self.api = api
        self.preferencias = preferencias
        self.notificacoes = notificacoes
        self.aoMudarAgenda = aoMudarAgenda
        self.relogio = relogio
    }

    public var conectado: Bool { preferencias.conectado && api.caixa.token != nil }

    /// Conta liberada neste aparelho: avisos antigos já estão na central; só o que chegar daqui para frente notifica.
    public func conectar() async {
        preferencias.avisosDesde = Datas.isoAgora(relogio())
        _ = await sincronizar()
    }

    /// Busca a agenda e os avisos novos. Chamadas seguidas aproveitam a que já está em andamento.
    @discardableResult
    public func sincronizar() async -> Bool {
        if let emAndamento {
            return await emAndamento.value
        }
        let tarefa = Task { @MainActor in await self.executarSincronizacao() }
        emAndamento = tarefa
        let resultado = await tarefa.value
        emAndamento = nil
        return resultado
    }

    private func executarSincronizacao() async -> Bool {
        guard conectado else {
            return false
        }
        await enviarPendentes()
        do {
            await salvarAgenda(try await api.agenda())
            await mostrarAvisosNovos()
            return true
        } catch {
            return false
        }
    }

    /// Ação tocada na notificação ou no widget.
    public func executar(_ acao: AcaoDaDose, chave: String, remedioId: Int64, agendadaPara: String) async {
        await notificacoes.cancelarLembretes(chave: chave)
        switch acao {
        case .tomei, .pular:
            preferencias.marcadasLocalmente.insert(chave)
            preferencias.adiados.removeValue(forKey: chave)
            preferencias.filaDeMarcacoes.removeAll { $0.chave == chave }
            preferencias.filaDeMarcacoes.append(MarcacaoPendente(chave: chave, remedioId: remedioId, agendadaPara: agendadaPara, status: acao == .tomei ? Dose.tomada : Dose.pulada, marcadaEm: Datas.isoAgora(relogio())))
            await reagendar()
            aoMudarAgenda()
            await enviarPendentes()
        case .adiar:
            preferencias.adiados[chave] = relogio().addingTimeInterval(TimeInterval(preferencias.adiarMinutos * 60))
            await reagendar()
            aoMudarAgenda()
        }
    }

    /// Envia a fila de marcações. Rede fora: para e tenta depois. Recusada pelo servidor (fora da janela, remédio
    /// apagado): sai da fila e a dose volta a aparecer como pendente.
    public func enviarPendentes() async {
        while let pendente = preferencias.filaDeMarcacoes.first, api.caixa.token != nil {
            do {
                let agenda = try await api.marcarPeloAparelho(PedidoDeMarcacaoDoAparelho(remedioId: pendente.remedioId, agendadaPara: pendente.agendadaPara, status: pendente.status, marcadaEm: pendente.marcadaEm))
                preferencias.filaDeMarcacoes.removeAll { $0 == pendente }
                await salvarAgenda(agenda)
            } catch let falha as FalhaDaApi {
                if falha.transitoria || falha == .naoAutorizado || falha == .trocarSenha {
                    return
                }
                preferencias.filaDeMarcacoes.removeAll { $0 == pendente }
                preferencias.marcadasLocalmente.remove(pendente.chave)
                await reagendar()
                aoMudarAgenda()
            } catch {
                return
            }
        }
    }

    /// Guarda a agenda que veio do servidor e remonta os lembretes. Marcações locais que o servidor já confirmou (ou cuja
    /// dose saiu da agenda) deixam de ser "pendentes"; as que ainda estão na fila continuam valendo.
    public func salvarAgenda(_ agenda: AgendaDoAparelho) async {
        preferencias.agenda = agenda
        let porChave = Dictionary(agenda.doses.map { ($0.key, $0) }, uniquingKeysWith: { primeira, _ in primeira })
        let naFila = Set(preferencias.filaDeMarcacoes.map(\.chave))
        preferencias.marcadasLocalmente = preferencias.marcadasLocalmente.filter { chave in
            naFila.contains(chave) || porChave[chave]?.marcada == false
        }
        await reagendar()
        aoMudarAgenda()
    }

    /// Remonta todas as notificações de dose a partir da última agenda (funciona sem internet).
    public func reagendar() async {
        let agora = relogio()
        // Planejar não precisa do token (o widget pode não ter acesso a ele): só a conta desconectada limpa tudo.
        guard preferencias.conectado else {
            await notificacoes.substituirLembretes([], adiarMinutos: preferencias.adiarMinutos)
            return
        }
        let agenda = preferencias.agenda
        let chaves = Set(agenda?.doses.map(\.key) ?? [])
        preferencias.adiados = preferencias.adiados.filter { chaves.contains($0.key) && $0.value > agora }
        let planejados = PlanejadorDeLembretes.planejar(
            agenda: agenda,
            agora: agora,
            marcadas: preferencias.marcadasLocalmente,
            adiados: preferencias.adiados,
            jaPlanejados: preferencias.agendados,
            insistir: preferencias.insistir,
            intervaloDeInsistenciaMinutos: preferencias.adiarMinutos
        )
        preferencias.agendados = PlanejadorDeLembretes.registrar(planejados, anteriores: preferencias.agendados, agenda: agenda, agora: agora)
        await notificacoes.substituirLembretes(planejados, adiarMinutos: preferencias.adiarMinutos)
    }

    /// Próximo lembrete planejado (Conta › Lembretes neste iPhone).
    public func proximoLembrete() -> LembretePlanejado? {
        PlanejadorDeLembretes.planejar(
            agenda: preferencias.agenda,
            agora: relogio(),
            marcadas: preferencias.marcadasLocalmente,
            adiados: preferencias.adiados,
            jaPlanejados: preferencias.agendados,
            insistir: false,
            intervaloDeInsistenciaMinutos: preferencias.adiarMinutos
        ).first
    }

    /// Avisos de dose (`kind = dose`) não viram notificação aqui: quem avisa a dose é o lembrete local, na hora.
    func mostrarAvisosNovos() async {
        guard let resposta = try? await api.avisosNovos(desde: preferencias.avisosDesde) else {
            return
        }
        let jaMostrados = preferencias.avisosMostrados
        for aviso in resposta.notices.reversed() where aviso.kind != "dose" && !jaMostrados.contains(aviso.id) {
            await notificacoes.mostrarAviso(id: aviso.id, titulo: aviso.title, corpo: aviso.body, caminho: aviso.url ?? "/avisos")
        }
        preferencias.avisosMostrados = Set(resposta.notices.map(\.id))
        if let marca = resposta.checkedAt, !marca.isEmpty {
            preferencias.avisosDesde = marca
        }
    }

    /// Saída da conta ou token recusado: nada do paciente continua lembrando neste aparelho.
    public func desconectarLocalmente() async {
        emAndamento?.cancel()
        await notificacoes.removerTudo()
        aoMudarAgenda()
    }

    public func testar() async {
        await notificacoes.testar(emSegundos: 5, adiarMinutos: preferencias.adiarMinutos)
    }
}
