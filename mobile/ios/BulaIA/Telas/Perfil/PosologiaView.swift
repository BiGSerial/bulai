import BulaiCore
import SwiftUI

/// "Dose e horários" em três passos numerados (igual ao Android e ao PWA): 1. Quanto tomar, 2. Quando tomar,
/// 3. Horários do dia. Datas, receita e observações ficam em "Mais detalhes". As regras e frases de validação são do
/// servidor; o erro dele aparece no fim da folha.
struct PosologiaView: View {
    let nome: String
    let receitas: [ReceitaDoPerfil]
    let salvando: Bool
    let erro: String?
    let aoSalvar: (DoseEHorarios) -> Void
    @State private var editor: EditorDePosologia
    @State private var horario: Edicao?
    @State private var detalhes = false
    @State private var data: CampoDeData?
    @Environment(\.dismiss) private var fechar

    struct Edicao: Identifiable {
        let posicao: Int?
        let inicial: String
        var id: String { "\(posicao ?? -1)-\(inicial)" }
    }

    enum CampoDeData: String, Identifiable {
        case inicio, fim
        var id: String { rawValue }
    }

    init(nome: String, inicial: DoseEHorarios, atalhos: AtalhosDePosologia, receitas: [ReceitaDoPerfil], salvando: Bool, erro: String?, sugerir: @escaping (String, DoseEHorarios, Bool) async -> DoseEHorarios?, aoSalvar: @escaping (DoseEHorarios) -> Void) {
        self.nome = nome
        self.receitas = receitas
        self.salvando = salvando
        self.erro = erro
        self.aoSalvar = aoSalvar
        _editor = State(initialValue: EditorDePosologia(inicial: inicial, atalhos: atalhos, sugerir: sugerir))
    }

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(alignment: .leading, spacing: 22) {
                    Text(nome).font(Fonte.corpo).foregroundStyle(Cores.textoSuave)
                    passo(1, "Quanto tomar") { quantoTomar }
                    passo(2, "Quando tomar") { quandoTomar }
                    passo(3, "Horários do dia") { horariosDoDia }
                    maisDetalhes
                    if let erro {
                        Label(erro, systemImage: "exclamationmark.circle.fill").font(Fonte.corpo).foregroundStyle(Cores.erro)
                    }
                    BotaoPrincipal(titulo: "Salvar", carregando: salvando) { aoSalvar(editor.posologia) }
                }
                .padding(20)
            }
            .scrollDismissesKeyboard(.interactively)
            .background(Cores.fundo.ignoresSafeArea())
            .navigationTitle("Dose e horários")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancelar") { fechar() }.disabled(salvando)
                }
            }
            .sheet(item: $horario) { edicao in
                SeletorDeHora(titulo: edicao.posicao == nil ? "Novo horário" : "Mudar horário", inicial: edicao.inicial) { hora in
                    if let posicao = edicao.posicao {
                        editor.trocarHorario(posicao, hora)
                    } else {
                        editor.adicionarHorario(hora)
                    }
                }
            }
            .sheet(item: $data) { campo in
                SeletorDeData(titulo: campo == .inicio ? "Começou em" : "Termina em", inicial: Datas.doFormulario(campo == .inicio ? editor.posologia.inicio : editor.posologia.fim) ?? Date()) { escolhida in
                    let texto = escolhida.map { Datas.paraFormulario($0) }
                    if campo == .inicio {
                        editor.posologia.inicio = texto
                    } else {
                        editor.posologia.fim = texto
                    }
                }
            }
        }
        .interactiveDismissDisabled(salvando)
    }

    private func passo<Conteudo: View>(_ numero: Int, _ titulo: String, @ViewBuilder conteudo: () -> Conteudo) -> some View {
        VStack(alignment: .leading, spacing: 12) {
            HStack(spacing: 10) {
                Text("\(numero)").font(.headline).frame(width: 30, height: 30).foregroundStyle(Cores.naMarca).background(Cores.marca, in: Circle())
                Text(titulo).font(Fonte.subtitulo).foregroundStyle(Cores.marca)
            }
            .accessibilityElement(children: .combine)
            .accessibilityAddTraits(.isHeader)
            conteudo()
        }
    }

    @ViewBuilder
    private var quantoTomar: some View {
        if !editor.atalhos.doses.isEmpty {
            Fileira {
                ForEach(editor.atalhos.doses, id: \.self) { dose in
                    Pilula(texto: dose, escolhida: editor.doseEscolhida == dose) { editor.escolherDose(dose) }
                }
            }
        }
        CampoDeTexto(titulo: "Ou escreva", texto: Binding(get: { editor.posologia.dosage }, set: { editor.escreverDose($0) }), ajuda: "Ex.: 1 comprimido de 500 mg")
    }

    @ViewBuilder
    private var quandoTomar: some View {
        LazyVGrid(columns: [GridItem(.flexible(), spacing: 10), GridItem(.flexible(), spacing: 10)], spacing: 10) {
            ForEach(editor.atalhos.frequencias, id: \.self) { atalho in
                let escolhido = editor.atalhoEscolhido == atalho
                Button {
                    Task { await editor.escolherAtalho(atalho) }
                } label: {
                    Text(atalho).font(Fonte.corpoForte).multilineTextAlignment(.center)
                        .frame(maxWidth: .infinity, minHeight: 64)
                        .padding(.horizontal, 8)
                        .foregroundStyle(escolhido ? Cores.naMarca : Cores.marca)
                        .background(escolhido ? Cores.marca : Cores.superficie, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
                        .overlay(RoundedRectangle(cornerRadius: 16, style: .continuous).strokeBorder(escolhido ? .clear : Cores.contorno))
                }
                .buttonStyle(.plain)
                .accessibilityAddTraits(escolhido ? .isSelected : [])
            }
        }
        if editor.outroJeito {
            CampoDeTexto(titulo: "Como está na receita", texto: Binding(get: { editor.posologia.frequency }, set: { editor.escreverFrequencia($0) }), ajuda: "Ex.: pela manhã e à noite. Os horários são sugeridos a partir disto.")
        } else {
            Button("Outro jeito? Escreva como está na receita") { editor.abrirOutroJeito() }.font(Fonte.rotulo).frame(minHeight: 44)
        }
    }

    @ViewBuilder
    private var horariosDoDia: some View {
        let posologia = editor.posologia
        if posologia.seNecessario {
            Text("Sem horário fixo: toma só quando precisar. Para ter horários, escolha outra opção em “Quando tomar”.")
                .font(Fonte.corpo).padding(14)
                .background(Cores.superficieVariante.opacity(0.6), in: RoundedRectangle(cornerRadius: 16, style: .continuous))
        } else {
            if let horas = posologia.intervaloEmHoras {
                OpcaoDeModo(titulo: "Contar a partir de quando eu tomar (recomendado)", texto: "A cada \(horas) h depois da hora em que você marcar “Tomei”.", escolhida: posologia.modo == DoseEHorarios.modoIntervalo) {
                    editor.usarModo(DoseEHorarios.modoIntervalo)
                }
                OpcaoDeModo(titulo: "Horários fixos todos os dias", texto: "Sempre nos mesmos horários, mesmo se atrasar uma dose.", escolhida: posologia.modo == DoseEHorarios.modoFixo) {
                    editor.usarModo(DoseEHorarios.modoFixo)
                }
            }
            if posologia.dinamico {
                LinhaDeHorario(horario: posologia.times.first, rotulo: "Primeira dose do dia", editar: {
                    horario = Edicao(posicao: posologia.times.isEmpty ? nil : 0, inicial: posologia.times.first ?? "08:00")
                }, tirar: nil)
                Text("As próximas doses vêm a cada \(posologia.intervaloEmHoras ?? 0) h a partir da última que você marcar.").font(Fonte.pequena).foregroundStyle(Cores.textoSuave)
            } else {
                ForEach(Array(posologia.times.enumerated()), id: \.offset) { posicao, hora in
                    LinhaDeHorario(horario: hora, rotulo: nil, editar: {
                        horario = Edicao(posicao: posicao, inicial: hora)
                    }, tirar: {
                        editor.tirarHorario(posicao)
                    })
                }
                if posologia.times.isEmpty {
                    Text("Nenhum horário ainda. Escolha em “Quando tomar” ou adicione.").font(Fonte.pequena).foregroundStyle(Cores.textoSuave)
                }
                if posologia.times.count < EditorDePosologia.maximoDeHorarios {
                    BotaoSecundario(titulo: "Adicionar horário", simbolo: "plus") {
                        horario = Edicao(posicao: nil, inicial: editor.sugestaoDeNovoHorario)
                    }
                }
            }
            Toggle(isOn: Binding(get: { editor.posologia.lembretes }, set: { editor.posologia.lembretes = $0 })) {
                Label {
                    VStack(alignment: .leading, spacing: 2) {
                        Text("Lembrar nos horários").font(Fonte.corpo)
                        Text("Notificação no iPhone na hora da dose").font(Fonte.pequena).foregroundStyle(Cores.textoSuave)
                    }
                } icon: {
                    Image(systemName: "bell.badge.fill").foregroundStyle(Cores.marca)
                }
            }
            .frame(minHeight: 52)
        }
    }

    private var maisDetalhes: some View {
        DisclosureGroup(isExpanded: $detalhes) {
            VStack(alignment: .leading, spacing: 12) {
                HStack(spacing: 10) {
                    BotaoDeData(rotulo: "Começou em", valor: editor.posologia.inicio) { data = .inicio }
                    BotaoDeData(rotulo: "Termina em", valor: editor.posologia.fim) { data = .fim }
                }
                Text("Sem data de término: uso contínuo.").font(.caption).foregroundStyle(Cores.textoSuave)
                if !receitas.isEmpty {
                    Picker("Receita", selection: Binding(get: { editor.posologia.receita }, set: { editor.posologia.receita = $0 })) {
                        Text("Nenhuma").tag(Int64?.none)
                        ForEach(receitas) { receita in
                            Text([receita.prescriber, receita.data].compactMap { $0 }.joined(separator: " · ")).tag(Int64?.some(receita.id))
                        }
                    }
                }
                CampoDeTexto(titulo: "Observações", texto: Binding(get: { editor.posologia.notes }, set: { editor.posologia.notes = String($0.prefix(255)) }), ajuda: "Ex.: em jejum")
            }
            .padding(.top, 10)
        } label: {
            Label {
                VStack(alignment: .leading, spacing: 2) {
                    Text("Mais detalhes").font(Fonte.corpoForte)
                    Text(receitas.isEmpty ? "Datas e observações" : "Datas, receita e observações").font(Fonte.pequena).foregroundStyle(Cores.textoSuave)
                }
            } icon: {
                Image(systemName: "calendar").foregroundStyle(Cores.marca)
            }
        }
        .padding(14)
        .background(Cores.superficie, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
        .overlay(RoundedRectangle(cornerRadius: 18, style: .continuous).strokeBorder(Cores.contorno))
    }
}

/// Um horário numa linha: ícone e nome do período (Manhã/Tarde/Noite/Madrugada), a hora grande, tocar para mudar.
private struct LinhaDeHorario: View {
    let horario: String?
    let rotulo: String?
    let editar: () -> Void
    let tirar: (() -> Void)?

    var body: some View {
        let periodo = horario.flatMap(PeriodoDoDia.init(horario:))
        HStack(spacing: 12) {
            Button(action: editar) {
                HStack(spacing: 12) {
                    Image(systemName: periodo?.simbolo ?? "clock").font(.title2).foregroundStyle(Cores.destaque).frame(width: 36)
                    VStack(alignment: .leading, spacing: 0) {
                        Text(rotulo ?? periodo?.nome ?? "Escolha a hora").font(Fonte.pequena).foregroundStyle(Cores.textoSuave)
                        Text(horario ?? "--:--").font(.title.monospacedDigit().weight(.semibold)).foregroundStyle(Cores.texto)
                    }
                    Spacer()
                    Text("Toque para mudar").font(.caption).foregroundStyle(Cores.textoSuave)
                }
                .contentShape(Rectangle())
            }
            .buttonStyle(.plain)
            if let tirar {
                Button(action: tirar) {
                    Image(systemName: "minus.circle.fill").font(.title2).foregroundStyle(Cores.erro).frame(width: 44, height: 44)
                }
                .accessibilityLabel("Tirar o horário \(horario ?? "")")
            }
        }
        .padding(12)
        .background(Cores.superficie, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
        .overlay(RoundedRectangle(cornerRadius: 16, style: .continuous).strokeBorder(Cores.contorno))
    }
}

private struct BotaoDeData: View {
    let rotulo: String
    let valor: String?
    let acao: () -> Void

    var body: some View {
        Button(action: acao) {
            VStack(alignment: .leading, spacing: 2) {
                Text(rotulo).font(.caption).foregroundStyle(Cores.textoSuave)
                Text(Datas.dataDoFormulario(valor) ?? "Escolher").font(Fonte.corpoForte).foregroundStyle(Cores.texto)
            }
            .padding(12)
            .frame(maxWidth: .infinity, minHeight: 56, alignment: .leading)
            .background(Cores.superficieElevada, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
            .overlay(RoundedRectangle(cornerRadius: 14, style: .continuous).strokeBorder(Cores.contorno))
        }
        .buttonStyle(.plain)
    }
}
