import AppIntents
import BulaiCore
import SwiftUI
import WidgetKit

/// Widget "Próximo remédio": nome e horário da próxima dose ainda não marcada e o botão "Tomei", que só aparece perto
/// do horário (a partir de 1 hora antes), como no Android, para ninguém marcar sem querer a dose da noite de manhã.
/// Lê a agenda que o app guardou no App Group (funciona sem internet).
@main
struct WidgetsDoBulai: WidgetBundle {
    var body: some Widget {
        ProximaDoseWidget()
    }
}

struct ProximaDoseWidget: Widget {
    var body: some WidgetConfiguration {
        StaticConfiguration(kind: "ProximaDose", provider: Fornecedor()) { entrada in
            VistaDaProximaDose(entrada: entrada)
                .containerBackground(for: .widget) { Color("FundoDoWidget") }
                .widgetURL(Rota.link("hoje"))
        }
        .configurationDisplayName("Próximo remédio")
        .description("O próximo horário e o “Tomei”.")
        .supportedFamilies([.systemSmall, .systemMedium, .accessoryRectangular, .accessoryInline])
    }
}

struct EntradaDaDose: TimelineEntry {
    enum Situacao {
        case desconectado
        case vazio
        case dose(DoseDaAgenda, quando: Date, amanha: Bool, mostrarTomei: Bool)
    }

    let date: Date
    let situacao: Situacao
}

struct Fornecedor: TimelineProvider {
    static let antecedenciaDoBotao: TimeInterval = 60 * 60

    func placeholder(in _: Context) -> EntradaDaDose {
        EntradaDaDose(date: Date(), situacao: .dose(DoseDaAgenda(key: "x", remedioId: 0, name: "Losartana", dosage: "1 comprimido", time: "08:00", agendadaPara: "", status: Dose.pendente, remind: true), quando: Date(), amanha: false, mostrarTomei: true))
    }

    func getSnapshot(in _: Context, completion: @escaping (EntradaDaDose) -> Void) {
        completion(Fornecedor.entrada(em: Date()))
    }

    func getTimeline(in _: Context, completion: @escaping (Timeline<EntradaDaDose>) -> Void) {
        let agora = Date()
        var entradas = [Fornecedor.entrada(em: agora)]
        // Muda sozinho quando o "Tomei" deve aparecer e quando a dose passa do atraso tolerado.
        if case let .dose(_, quando, _, mostrar) = entradas[0].situacao {
            if !mostrar {
                entradas.append(Fornecedor.entrada(em: quando.addingTimeInterval(-Fornecedor.antecedenciaDoBotao)))
            }
            entradas.append(Fornecedor.entrada(em: quando.addingTimeInterval(61 * 60)))
        }
        let proxima = Calendar.current.date(byAdding: .minute, value: 30, to: agora) ?? agora
        completion(Timeline(entries: entradas, policy: .after(proxima)))
    }

    static func entrada(em data: Date) -> EntradaDaDose {
        let preferencias = PreferenciasLocais(grupo: Configuracao.doBundle().grupoDoApp)
        guard preferencias.conectado else {
            return EntradaDaDose(date: data, situacao: .desconectado)
        }
        guard let dose = PlanejadorDeLembretes.proximaDose(agenda: preferencias.agenda, agora: data, marcadas: preferencias.marcadasLocalmente), let quando = dose.momento else {
            return EntradaDaDose(date: data, situacao: .vazio)
        }
        return EntradaDaDose(date: data, situacao: .dose(dose, quando: quando, amanha: !Calendar.current.isDate(quando, inSameDayAs: data), mostrarTomei: quando.timeIntervalSince(data) <= antecedenciaDoBotao))
    }
}

struct VistaDaProximaDose: View {
    let entrada: EntradaDaDose
    @Environment(\.widgetFamily) private var familia

    var body: some View {
        switch entrada.situacao {
        case .desconectado:
            texto("Abra o BULA-IA e entre na sua conta")
        case .vazio:
            texto("Nenhum remédio programado")
        case let .dose(dose, _, amanha, mostrarTomei):
            switch familia {
            case .accessoryInline:
                Label("\(dose.time) · \(dose.name)", systemImage: "pills.fill")
            case .accessoryRectangular:
                VStack(alignment: .leading, spacing: 2) {
                    Text("Próximo remédio").font(.caption2.weight(.semibold))
                    Text(dose.name).font(.headline).lineLimit(1)
                    Text(amanha ? "amanhã às \(dose.time)" : "às \(dose.time)").font(.caption)
                }
            default:
                VStack(alignment: .leading, spacing: 6) {
                    Label("Próximo remédio", systemImage: "pills.fill").font(.caption.weight(.bold)).foregroundStyle(Color("MarcaDoWidget"))
                    Text(dose.name).font(.headline).lineLimit(2).minimumScaleFactor(0.8)
                    Text([amanha ? "amanhã às \(dose.time)" : "às \(dose.time)", dose.dosage].compactMap { $0 }.joined(separator: " · "))
                        .font(.subheadline).foregroundStyle(.secondary).lineLimit(2)
                    Spacer(minLength: 0)
                    if mostrarTomei {
                        Button(intent: MarcarTomeiIntent(chave: dose.key, remedioId: Int(dose.remedioId), agendadaPara: dose.agendadaPara)) {
                            Label("Tomei", systemImage: "checkmark").font(.headline).frame(maxWidth: .infinity)
                        }
                        .tint(Color(red: 0.11, green: 0.48, blue: 0.27))
                        .accessibilityLabel("Tomei \(dose.name)")
                    }
                }
            }
        }
    }

    private func texto(_ mensagem: String) -> some View {
        VStack(alignment: .leading, spacing: 6) {
            Label("Próximo remédio", systemImage: "pills.fill").font(.caption.weight(.bold)).foregroundStyle(Color("MarcaDoWidget"))
            Text(mensagem).font(.subheadline)
        }
    }
}

/// "Tomei" do widget: vale na hora no aparelho (a dose some do widget e não lembra mais) e vai para o servidor com a
/// hora real do toque. Sem rede (ou sem o grupo de Keychain para ler o token), fica na fila e o app envia ao abrir.
struct MarcarTomeiIntent: AppIntent {
    static var title: LocalizedStringResource { "Tomei" }
    static var isDiscoverable: Bool { false }

    @Parameter(title: "Dose") var chave: String
    @Parameter(title: "Remédio") var remedioId: Int
    @Parameter(title: "Horário") var agendadaPara: String

    init() {}

    init(chave: String, remedioId: Int, agendadaPara: String) {
        self.chave = chave
        self.remedioId = remedioId
        self.agendadaPara = agendadaPara
    }

    @MainActor
    func perform() async throws -> some IntentResult {
        let configuracao = Configuracao.doBundle()
        let dependencias = Dependencias.montar(
            configuracao: configuracao,
            cofre: KeychainCofre(servico: configuracao.servicoDoKeychain, grupo: configuracao.grupoDoKeychain),
            notificacoes: NotificacoesLocais(),
            aparelho: "widget",
            processoPrincipal: false
        )
        await dependencias.sincronizador.executar(.tomei, chave: chave, remedioId: Int64(remedioId), agendadaPara: agendadaPara)
        WidgetCenter.shared.reloadAllTimelines()
        return .result()
    }
}
