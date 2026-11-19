#if canImport(UserNotifications)
import Foundation
import UserNotifications

/// Lembretes das doses e avisos como notificações locais do iOS (UserNotifications).
///
/// Diferenças inevitáveis em relação ao Android: o iOS não tem alarme exato de app nem tela cheia; a notificação é
/// entregue pelo sistema no horário (com "Tomei", "Adiar" e "Pular"), como "Sensível ao tempo" quando a capacidade está
/// assinada, e o modo "insistir" repete o lembrete enquanto a dose não for marcada. Silencioso/Foco seguem as regras
/// do sistema. No máximo 64 notificações pendentes por app (ver ``PlanejadorDeLembretes/limite``).
public final class NotificacoesLocais: ServicoDeNotificacoes, @unchecked Sendable {
    public static let categoriaDose = "DOSE"
    public static let categoriaTeste = "DOSE_TESTE"
    public static let categoriaAviso = "AVISO"
    public static let prefixoTeste = "teste|"

    /// Chaves do `userInfo` das notificações.
    public enum Chave {
        public static let dose = "chave"
        public static let remedio = "remedio"
        public static let agendada = "agendada"
        public static let caminho = "caminho"
    }

    private let central: UNUserNotificationCenter

    public init(central: UNUserNotificationCenter = .current()) {
        self.central = central
    }

    /// Pede a permissão (alerta, som e selo). Chamado ao entrar, como o pedido de notificações do Android 13+.
    @discardableResult
    public func pedirPermissao() async -> Bool {
        (try? await central.requestAuthorization(options: [.alert, .sound, .badge])) ?? false
    }

    public func permitidas() async -> Bool {
        let situacao = await central.notificationSettings().authorizationStatus
        #if os(iOS)
        if situacao == .ephemeral {
            return true
        }
        #endif
        return situacao == .authorized || situacao == .provisional
    }

    public func negadas() async -> Bool {
        await central.notificationSettings().authorizationStatus == .denied
    }

    /// Botões da notificação de dose. "Adiar" mostra os minutos escolhidos na Conta.
    public func registrarCategorias(adiarMinutos: Int) {
        let tomei = UNNotificationAction(identifier: AcaoDaDose.tomei.rawValue, title: "Tomei", options: [], icon: UNNotificationActionIcon(systemImageName: "checkmark.circle.fill"))
        let adiar = UNNotificationAction(identifier: AcaoDaDose.adiar.rawValue, title: "Adiar \(adiarMinutos) min", options: [], icon: UNNotificationActionIcon(systemImageName: "clock.arrow.circlepath"))
        let pular = UNNotificationAction(identifier: AcaoDaDose.pular.rawValue, title: "Pular", options: [], icon: UNNotificationActionIcon(systemImageName: "forward.end"))
        let dose = UNNotificationCategory(identifier: NotificacoesLocais.categoriaDose, actions: [tomei, adiar, pular], intentIdentifiers: [], hiddenPreviewsBodyPlaceholder: "Hora do remédio", options: [])
        let teste = UNNotificationCategory(identifier: NotificacoesLocais.categoriaTeste, actions: [tomei, adiar, pular], intentIdentifiers: [], hiddenPreviewsBodyPlaceholder: "Hora do remédio", options: [])
        let aviso = UNNotificationCategory(identifier: NotificacoesLocais.categoriaAviso, actions: [], intentIdentifiers: [], hiddenPreviewsBodyPlaceholder: "Aviso do BULA-IA", options: [])
        central.setNotificationCategories([dose, teste, aviso])
    }

    public func substituirLembretes(_ lembretes: [LembretePlanejado], adiarMinutos: Int) async {
        registrarCategorias(adiarMinutos: adiarMinutos)
        let pendentes = await central.pendingNotificationRequests()
        let antigos = pendentes.map(\.identifier).filter { $0.hasPrefix(LembretePlanejado.prefixo) && !$0.hasPrefix(LembretePlanejado.prefixo + NotificacoesLocais.prefixoTeste) }
        central.removePendingNotificationRequests(withIdentifiers: antigos)
        for lembrete in lembretes {
            let conteudo = UNMutableNotificationContent()
            conteudo.title = "Hora do remédio: \(lembrete.dose.name)"
            conteudo.body = NotificacoesLocais.texto(horario: lembrete.dose.time, dose: lembrete.dose.dosage, repeticao: lembrete.repeticao)
            conteudo.sound = .default
            conteudo.categoryIdentifier = NotificacoesLocais.categoriaDose
            conteudo.threadIdentifier = "doses"
            conteudo.interruptionLevel = .timeSensitive
            conteudo.relevanceScore = 1
            conteudo.userInfo = [
                Chave.dose: lembrete.dose.key,
                Chave.remedio: NSNumber(value: lembrete.dose.remedioId),
                Chave.agendada: lembrete.dose.agendadaPara,
                Chave.caminho: "/hoje",
            ]
            let partes = Calendar.current.dateComponents([.year, .month, .day, .hour, .minute, .second], from: lembrete.quando)
            let gatilho = UNCalendarNotificationTrigger(dateMatching: partes, repeats: false)
            try? await central.add(UNNotificationRequest(identifier: lembrete.identificador, content: conteudo, trigger: gatilho))
        }
    }

    public func cancelarLembretes(chave: String) async {
        let identificadores = LembretePlanejado.identificadores(chave: chave)
        central.removePendingNotificationRequests(withIdentifiers: identificadores)
        central.removeDeliveredNotifications(withIdentifiers: identificadores)
    }

    public func mostrarAviso(id: String, titulo: String, corpo: String, caminho: String) async {
        let conteudo = UNMutableNotificationContent()
        conteudo.title = titulo
        conteudo.body = corpo
        conteudo.sound = .default
        conteudo.categoryIdentifier = NotificacoesLocais.categoriaAviso
        conteudo.threadIdentifier = "avisos"
        conteudo.userInfo = [Chave.caminho: caminho]
        try? await central.add(UNNotificationRequest(identifier: "aviso|" + id, content: conteudo, trigger: nil))
    }

    /// Lembrete de teste daqui a alguns segundos, com os mesmos botões do lembrete de verdade (não marca nada).
    public func testar(emSegundos: Int, adiarMinutos: Int) async {
        registrarCategorias(adiarMinutos: adiarMinutos)
        let conteudo = UNMutableNotificationContent()
        conteudo.title = "Hora do remédio: Lembrete de teste"
        conteudo.body = "Tudo certo com os lembretes deste iPhone."
        conteudo.sound = .default
        conteudo.categoryIdentifier = NotificacoesLocais.categoriaTeste
        conteudo.interruptionLevel = .timeSensitive
        let chave = NotificacoesLocais.prefixoTeste + String(Int(Date().timeIntervalSince1970))
        conteudo.userInfo = [Chave.dose: chave, Chave.caminho: "/conta"]
        let gatilho = UNTimeIntervalNotificationTrigger(timeInterval: TimeInterval(max(emSegundos, 1)), repeats: false)
        try? await central.add(UNNotificationRequest(identifier: LembretePlanejado.prefixo + chave, content: conteudo, trigger: gatilho))
    }

    public func removerTudo() async {
        central.removeAllPendingNotificationRequests()
        central.removeAllDeliveredNotifications()
        try? await central.setBadgeCount(0)
    }

    static func texto(horario: String, dose: String?, repeticao: Int) -> String {
        let base = (dose?.isEmpty ?? true) ? "Às \(horario)" : "Às \(horario), \(dose!)"
        return repeticao == 0 ? base : base + ". Ainda não anotado: toque em Tomei quando tomar."
    }
}
#endif
