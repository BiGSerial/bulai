import BulaiCore
import SwiftUI
import UIKit
import UserNotifications

@main
struct BulaiApp: App {
    @UIApplicationDelegateAdaptor(AppDelegate.self) private var delegado
    @Environment(\.scenePhase) private var fase
    @State private var ambiente = Ambiente.compartilhado

    var body: some Scene {
        WindowGroup {
            RaizView()
                .environment(ambiente)
                .preferredColorScheme(ambiente.esquemaDeCor)
                .tint(Cores.marca)
                .onOpenURL { url in
                    if let rota = Rota.doLink(url), ambiente.sessao.conectado {
                        ambiente.navegador.abrir(rota)
                    }
                }
        }
        .onChange(of: fase) { _, nova in
            switch nova {
            case .active:
                Task { await ambiente.ficouAtivo() }
            case .background:
                TarefasEmSegundoPlano.agendar()
                ambiente.voz.parar()
            default:
                break
            }
        }
        .backgroundTask(.appRefresh(TarefasEmSegundoPlano.identificador)) {
            await TarefasEmSegundoPlano.executar()
        }
    }
}

/// Notificações (mostrar com o app aberto, botões "Tomei"/"Adiar"/"Pular", toque abre a tela) e categorias.
final class AppDelegate: NSObject, UIApplicationDelegate, UNUserNotificationCenterDelegate {
    func application(_: UIApplication, didFinishLaunchingWithOptions _: [UIApplication.LaunchOptionsKey: Any]? = nil) -> Bool {
        UNUserNotificationCenter.current().delegate = self
        let ambiente = Ambiente.compartilhado
        ambiente.notificacoes.registrarCategorias(adiarMinutos: ambiente.dependencias.preferencias.adiarMinutos)
        Task { await ambiente.sessao.retomar() }
        return true
    }

    nonisolated func userNotificationCenter(_: UNUserNotificationCenter, willPresent _: UNNotification) async -> UNNotificationPresentationOptions {
        [.banner, .list, .sound]
    }

    nonisolated func userNotificationCenter(_: UNUserNotificationCenter, didReceive resposta: UNNotificationResponse) async {
        let acao = resposta.actionIdentifier
        let info = resposta.notification.request.content.userInfo
        let chave = info[NotificacoesLocais.Chave.dose] as? String
        let remedio = (info[NotificacoesLocais.Chave.remedio] as? NSNumber)?.int64Value ?? 0
        let agendada = info[NotificacoesLocais.Chave.agendada] as? String ?? ""
        let caminho = info[NotificacoesLocais.Chave.caminho] as? String
        // Espera a marcação terminar: o iOS pode suspender o app assim que este método voltar.
        await AppDelegate.tratar(acao: acao, chave: chave, remedio: remedio, agendada: agendada, caminho: caminho)
    }

    @MainActor
    static func tratar(acao: String, chave: String?, remedio: Int64, agendada: String, caminho: String?) async {
        let ambiente = Ambiente.compartilhado
        if let escolhida = AcaoDaDose(rawValue: acao), let chave {
            await ambiente.acaoDaNotificacao(escolhida, chave: chave, remedio: remedio, agendada: agendada)
        } else if acao == UNNotificationDefaultActionIdentifier {
            ambiente.abrir(caminho: caminho)
        }
    }
}
