import BackgroundTasks
import BulaiCore
import Observation
import SwiftUI
import UIKit
import WidgetKit

/// Abas e pilhas de telas. Avisos, notificações, widget e atalhos chegam como ``Rota``.
@MainActor
@Observable
final class Navegador {
    var aba: Aba = .inicio
    var caminhos: [Aba: [Rota]] = [:]

    func caminho(_ aba: Aba) -> Binding<[Rota]> {
        Binding(get: { self.caminhos[aba] ?? [] }, set: { self.caminhos[aba] = $0 })
    }

    /// Abas trocam de aba (e voltam à raiz dela); as outras telas entram por cima da aba atual.
    func abrir(_ rota: Rota) {
        if case let .aba(destino) = rota {
            aba = destino
            caminhos[destino] = []
            return
        }
        if caminhos[aba]?.last == rota {
            return
        }
        caminhos[aba, default: []].append(rota)
    }

    /// Troca a tela atual por outra (ex.: receita confirmada → Hoje; consulta repetida → novo resultado).
    func substituir(por rota: Rota) {
        if !(caminhos[aba]?.isEmpty ?? true) {
            caminhos[aba]?.removeLast()
        }
        abrir(rota)
    }

    func voltar() {
        if !(caminhos[aba]?.isEmpty ?? true) {
            caminhos[aba]?.removeLast()
        }
    }

    func limpar() {
        aba = .inicio
        caminhos = [:]
    }
}

/// Tudo que as telas usam, criado uma vez (também pelo AppDelegate, para as ações das notificações e o segundo plano).
@MainActor
@Observable
final class Ambiente {
    static let compartilhado = Ambiente()

    @ObservationIgnored let configuracao: Configuracao
    @ObservationIgnored let dependencias: Dependencias
    @ObservationIgnored let notificacoes: NotificacoesLocais
    let navegador = Navegador()
    let voz = LeitorDeVoz()
    /// Avisos não lidos (sininho), atualizado pelo Início, pela central e pela agenda.
    var naoLidos = 0
    /// Recado que precisa aparecer em outra tela (ex.: "Receita salva…" ao abrir Hoje).
    var recadoGlobal: Recado?
    var tema: Tema

    private init() {
        let configuracao = Configuracao.doBundle()
        self.configuracao = configuracao
        let notificacoes = NotificacoesLocais()
        self.notificacoes = notificacoes
        dependencias = Dependencias.montar(
            configuracao: configuracao,
            cofre: KeychainCofre(servico: configuracao.servicoDoKeychain, grupo: configuracao.grupoDoKeychain),
            notificacoes: notificacoes,
            aparelho: Ambiente.nomeDoAparelho(),
            aoMudarAgenda: { WidgetCenter.shared.reloadAllTimelines() }
        )
        tema = dependencias.preferencias.tema
        dependencias.sessao.aoDesconectar = { [dependencias, navegador] in
            await dependencias.sincronizador.desconectarLocalmente()
            navegador.limpar()
        }
    }

    var sessao: Sessao { dependencias.sessao }
    var api: ClienteDaApi { dependencias.api }

    var esquemaDeCor: ColorScheme? {
        switch tema {
        case .sistema: nil
        case .claro: .light
        case .escuro: .dark
        }
    }

    /// App em primeiro plano: retoma a sessão guardada, sincroniza e confere a conta.
    func ficouAtivo() async {
        guard sessao.conectado else {
            return
        }
        await dependencias.sincronizador.sincronizar()
        naoLidos = dependencias.preferencias.agenda?.avisosNaoLidos ?? naoLidos
        if case let .success(usuario) = await tentar({ try await api.eu() }) {
            await sessao.atualizar(usuario)
        }
    }

    /// Primeira vez conectado: pede a permissão de notificações (os lembretes dependem dela).
    func pedirNotificacoesSePreciso() async {
        guard !dependencias.preferencias.pediuNotificacoes else {
            return
        }
        dependencias.preferencias.pediuNotificacoes = true
        await notificacoes.pedirPermissao()
        await dependencias.sincronizador.reagendar()
    }

    /// Toque numa notificação, no widget ou num atalho.
    func abrir(caminho: String?) {
        guard sessao.conectado else {
            return
        }
        navegador.abrir(Rota.doCaminho(caminho) ?? .aba(.hoje))
    }

    /// "Tomei", "Pular" ou "Adiar" tocado na notificação (com o app aberto ou acordado em segundo plano).
    func acaoDaNotificacao(_ acao: AcaoDaDose, chave: String, remedio: Int64, agendada: String) async {
        if chave.hasPrefix(NotificacoesLocais.prefixoTeste) {
            if acao == .adiar {
                await notificacoes.testar(emSegundos: dependencias.preferencias.adiarMinutos * 60, adiarMinutos: dependencias.preferencias.adiarMinutos)
            }
            return
        }
        guard sessao.conectado else {
            return
        }
        await dependencias.sincronizador.executar(acao, chave: chave, remedioId: remedio, agendadaPara: agendada)
    }

    static func nomeDoAparelho() -> String {
        var sistema = utsname()
        uname(&sistema)
        let modelo = withUnsafeBytes(of: &sistema.machine) { bytes in
            String(decoding: bytes.prefix { $0 != 0 }, as: UTF8.self)
        }
        return "\(UIDevice.current.model) (\(modelo)) · iOS \(UIDevice.current.systemVersion)"
    }
}

/// Atualização em segundo plano (BGAppRefresh): o iOS decide quando roda, em geral algumas vezes por dia.
/// Busca a agenda, envia marcações pendentes, remonta os lembretes e mostra avisos novos.
enum TarefasEmSegundoPlano {
    static let identificador = "br.com.vixforge.bulai.sincronizar"

    static func agendar() {
        let pedido = BGAppRefreshTaskRequest(identifier: identificador)
        pedido.earliestBeginDate = Date(timeIntervalSinceNow: 30 * 60)
        try? BGTaskScheduler.shared.submit(pedido)
    }

    @MainActor
    static func executar() async {
        agendar()
        let ambiente = Ambiente.compartilhado
        guard ambiente.sessao.conectado else {
            return
        }
        await ambiente.dependencias.sincronizador.sincronizar()
    }
}
