import AppIntents
import BulaiCore

/// Atalhos do app (Siri, Spotlight, botão de Ação e o toque longo no ícone): os mesmos três do Android.
struct AbrirHojeIntent: AppIntent {
    static var title: LocalizedStringResource { "Remédios de hoje" }
    static var description: IntentDescription { IntentDescription("Abre os remédios de hoje no BULA-IA.") }
    static var openAppWhenRun: Bool { true }

    @MainActor
    func perform() async throws -> some IntentResult {
        Ambiente.compartilhado.abrir(caminho: "hoje")
        return .result()
    }
}

struct PerguntarIntent: AppIntent {
    static var title: LocalizedStringResource { "Perguntar sobre um remédio" }
    static var description: IntentDescription { IntentDescription("Abre o Perguntar do BULA-IA.") }
    static var openAppWhenRun: Bool { true }

    @MainActor
    func perform() async throws -> some IntentResult {
        Ambiente.compartilhado.abrir(caminho: "perguntar")
        return .result()
    }
}

struct FotografarReceitaIntent: AppIntent {
    static var title: LocalizedStringResource { "Fotografar receita" }
    static var description: IntentDescription { IntentDescription("Abre a câmera da receita no BULA-IA.") }
    static var openAppWhenRun: Bool { true }

    @MainActor
    func perform() async throws -> some IntentResult {
        Ambiente.compartilhado.abrir(caminho: "receitas/nova")
        return .result()
    }
}

struct AtalhosDoBulai: AppShortcutsProvider {
    static var appShortcuts: [AppShortcut] {
        AppShortcut(intent: AbrirHojeIntent(), phrases: ["Remédios de hoje no \(.applicationName)", "Abrir meus remédios no \(.applicationName)"], shortTitle: "Hoje", systemImageName: "pills.fill")
        AppShortcut(intent: PerguntarIntent(), phrases: ["Perguntar ao \(.applicationName)", "Perguntar sobre um remédio no \(.applicationName)"], shortTitle: "Perguntar", systemImageName: "bubble.left.and.text.bubble.right.fill")
        AppShortcut(intent: FotografarReceitaIntent(), phrases: ["Fotografar receita no \(.applicationName)"], shortTitle: "Receita", systemImageName: "camera.fill")
    }
}
