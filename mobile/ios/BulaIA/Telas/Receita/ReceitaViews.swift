import BulaiCore
import PhotosUI
import SwiftUI
import UIKit

/// Etapas Foto → Conferir → Pronto.
struct EtapasDaReceita: View {
    let atual: Int

    var body: some View {
        HStack(spacing: 8) {
            ForEach(Array(["Foto", "Conferir", "Pronto"].enumerated()), id: \.offset) { indice, nome in
                HStack(spacing: 6) {
                    Text("\(indice + 1)").font(.caption.weight(.bold)).frame(width: 24, height: 24)
                        .foregroundStyle(indice <= atual ? Cores.naMarca : Cores.textoSuave)
                        .background(indice <= atual ? Cores.marca : Cores.superficieVariante, in: Circle())
                    Text(nome).font(Fonte.pequena.weight(indice == atual ? .bold : .regular)).foregroundStyle(indice <= atual ? Cores.marca : Cores.textoSuave)
                }
                if indice < 2 {
                    Rectangle().fill(Cores.contorno).frame(height: 2).frame(maxWidth: 32)
                }
            }
        }
        .accessibilityElement(children: .ignore)
        .accessibilityLabel("Etapa \(atual + 1) de 3")
    }
}

/// Passo 1: fotografar (câmera) ou escolher da galeria (seletor de fotos, sem permissão), prévia e envio.
struct NovaReceitaView: View {
    let ambiente: Ambiente
    @State private var tela: NovaReceitaViewModel
    @State private var imagem: UIImage?
    @State private var item: PhotosPickerItem?
    @State private var camera = false

    init(ambiente: Ambiente) {
        self.ambiente = ambiente
        _tela = State(initialValue: NovaReceitaViewModel(api: ambiente.api))
    }

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 18) {
                EtapasDaReceita(atual: 0)
                if tela.semConsentimento {
                    Cartao(cor: Cores.destaqueSuave, borda: .clear) {
                        Text("Antes, autorize guardar seus dados de saúde").font(Fonte.subtitulo)
                        Text("A receita vira remédios em uso no seu perfil, e isso é dado de saúde. Aceite o consentimento no seu perfil e volte aqui.").font(Fonte.corpo)
                        BotaoPrincipal(titulo: "Abrir Meus remédios") { ambiente.navegador.abrir(.perfil(aba: 0)) }
                    }
                } else if let erro = tela.erro {
                    FaixaDeErro(texto: erro)
                }
                if let imagem {
                    Image(uiImage: imagem).resizable().scaledToFit().frame(maxHeight: 420).frame(maxWidth: .infinity)
                        .clipShape(RoundedRectangle(cornerRadius: 18, style: .continuous))
                        .accessibilityLabel("Prévia da receita")
                    Text("Dá para ler o texto? Se sim, toque em “Ler esta receita”.").font(Fonte.corpo)
                    BotaoPrincipal(titulo: "Ler esta receita", simbolo: "text.viewfinder", carregando: tela.enviando) {
                        Task {
                            let dados = FotoDaReceita.jpeg(imagem)
                            if let id = await tela.enviar(jpeg: dados) {
                                ambiente.navegador.substituir(por: .revisarReceita(id))
                            }
                        }
                    }
                    BotaoSecundario(titulo: "Tentar outra foto", simbolo: "arrow.counterclockwise") {
                        self.imagem = nil
                        tela.erro = nil
                    }
                } else {
                    Text("Tire uma foto da receita inteira, com o texto nítido.").font(Fonte.corpo)
                    if CameraDaReceita.disponivel {
                        BotaoPrincipal(titulo: "Tirar foto da receita", simbolo: "camera.fill") { camera = true }
                    }
                    PhotosPicker(selection: $item, matching: .images, photoLibrary: .shared()) {
                        Label("Escolher da galeria", systemImage: "photo.on.rectangle")
                            .font(Fonte.rotulo)
                            .frame(maxWidth: .infinity, minHeight: 52)
                            .foregroundStyle(Cores.marca)
                            .overlay(RoundedRectangle(cornerRadius: 16, style: .continuous).strokeBorder(Cores.marca.opacity(0.45), lineWidth: 2))
                    }
                }
                Label("A foto é lida e apagada em seguida. Você confere cada remédio antes de salvar.", systemImage: "lock.shield").font(Fonte.pequena).foregroundStyle(Cores.textoSuave)
                Text("O BULA-IA não muda a receita do seu médico.").font(Fonte.pequena).foregroundStyle(Cores.textoSuave)
            }
            .padding(16)
        }
        .telaBulai()
        .onChange(of: item) { _, novo in
            guard let novo else {
                return
            }
            Task {
                imagem = await FotoDaReceita.carregar(novo)
                if imagem == nil {
                    tela.erro = "Não conseguimos abrir esta imagem. Tente outra foto."
                }
                item = nil
            }
        }
        .fullScreenCover(isPresented: $camera) {
            CameraDaReceita { foto in
                camera = false
                if let foto {
                    imagem = foto
                }
            }
            .ignoresSafeArea()
        }
    }
}

/// Passo 2: leitura acompanhada e revisão editável de cada item; nada entra no tratamento sem "Confirmar e salvar".
struct RevisarReceitaView: View {
    let ambiente: Ambiente
    @State private var tela: RevisarReceitaViewModel
    @State private var buscandoItem: Int?
    @State private var descartar = false
    @State private var editandoData = false
    @State private var horario: EdicaoDeHorario?

    struct EdicaoDeHorario: Identifiable {
        let item: Int
        let posicao: Int?
        let inicial: String
        var id: String { "\(item)-\(posicao ?? -1)" }
    }

    init(ambiente: Ambiente, id: Int64) {
        self.ambiente = ambiente
        _tela = State(initialValue: RevisarReceitaViewModel(ambiente.dependencias, id: id))
    }

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 18) {
                EtapasDaReceita(atual: tela.receita?.status == Receita.confirmada ? 2 : 1)
                conteudo
            }
            .padding(16)
            .padding(.bottom, 90)
        }
        .scrollDismissesKeyboard(.interactively)
        .telaBulai()
        .safeAreaInset(edge: .bottom) {
            if tela.receita?.status == Receita.emRevisao, !tela.itens.isEmpty {
                VStack(spacing: 8) {
                    if let aviso = tela.aviso {
                        Text(aviso).font(Fonte.pequena).foregroundStyle(Cores.erro)
                    }
                    BotaoPrincipal(titulo: "Confirmar e salvar", simbolo: "checkmark.seal.fill", carregando: tela.salvando) {
                        Task {
                            if let mensagem = await tela.confirmar() {
                                ambiente.recadoGlobal = Recado(mensagem)
                                ambiente.navegador.voltar()
                                ambiente.navegador.abrir(.aba(.hoje))
                            }
                        }
                    }
                }
                .padding(.horizontal, 16)
                .padding(.vertical, 10)
                .background(.bar)
            }
        }
        .task { await tela.acompanhar() }
        .sheet(item: Binding(get: { buscandoItem.map { ItemEmBusca(indice: $0) } }, set: { buscandoItem = $0?.indice })) { alvo in
            BuscaDeRemedioView(titulo: "Qual é o remédio “\(tela.itens[safe: alvo.indice]?.nomeEscrito ?? "")”?", busca: tela.busca, usarPeloNome: { _ in
                tela.manterPeloNome(alvo.indice)
                buscandoItem = nil
            }) { remedio in
                tela.escolher(alvo.indice, remedio: remedio.id, nome: remedio.name)
                buscandoItem = nil
            }
        }
        .sheet(item: $horario) { edicao in
            SeletorDeHora(titulo: "Horário do remédio", inicial: edicao.inicial) { hora in
                if let posicao = edicao.posicao {
                    tela.trocarHorario(edicao.item, posicao: posicao, hora)
                } else {
                    tela.adicionarHorario(edicao.item, hora)
                }
            }
        }
        .sheet(isPresented: $editandoData) {
            SeletorDeData(titulo: "Data da receita", inicial: Datas.doFormulario(tela.cabecalho.date) ?? Date()) { data in
                tela.cabecalho.date = data.map { Datas.paraFormulario($0) }
            }
        }
        .confirmationDialog("Descartar esta receita?", isPresented: $descartar, titleVisibility: .visible) {
            Button("Descartar", role: .destructive) {
                Task {
                    if let mensagem = await tela.descartar() {
                        ambiente.recadoGlobal = Recado(mensagem)
                        ambiente.navegador.voltar()
                    }
                }
            }
            Button("Cancelar", role: .cancel) {}
        } message: {
            Text("Nada desta receita será salvo no seu tratamento.")
        }
    }

    struct ItemEmBusca: Identifiable {
        let indice: Int
        var id: Int { indice }
    }

    @ViewBuilder
    private var conteudo: some View {
        if let erro = tela.erroDeCarga {
            FaixaDeErro(texto: erro) { Task { await tela.acompanhar() } }
        } else if tela.lendo {
            VStack(alignment: .leading, spacing: 12) {
                Text("Lendo a receita…").font(Fonte.titulo).foregroundStyle(Cores.marca)
                Text("Leva alguns segundos. A foto é apagada quando terminar.").font(Fonte.corpo).foregroundStyle(Cores.textoSuave)
                EsqueletoDeLista(cartoes: 2)
            }
            .accessibilityElement(children: .combine)
        } else if let receita = tela.receita {
            switch receita.status {
            case Receita.falhou:
                Cartao(cor: Cores.erroFundo, borda: .clear) {
                    Text("Não conseguimos ler esta receita.").font(Fonte.subtitulo)
                    Text("\(receita.mensagemDoErro ?? "") Nada foi salvo.").font(Fonte.corpo)
                    if let codigo = receita.codigoDoErro {
                        Text("Código técnico: \(codigo).").font(.caption).foregroundStyle(Cores.textoSuave)
                    }
                    BotaoPrincipal(titulo: "Tentar outra foto", simbolo: "camera.fill") { ambiente.navegador.substituir(por: .receita) }
                }
            case Receita.confirmada:
                EstadoVazio(simbolo: "checkmark.seal.fill", titulo: "Pronto!", texto: "Esta receita já foi confirmada e seus remédios estão na sua lista.", acao: ("Ver remédios de hoje", { ambiente.navegador.abrir(.aba(.hoje)) }))
            default:
                if tela.itens.isEmpty {
                    EstadoVazio(simbolo: "doc.text.magnifyingglass", titulo: "Nenhum remédio foi identificado na foto.", texto: "Tente outra foto mais nítida, com a receita inteira.", acao: ("Tentar outra foto", { ambiente.navegador.substituir(por: .receita) }))
                } else {
                    revisao(receita)
                }
            }
        }
    }

    @ViewBuilder
    private func revisao(_ receita: Receita) -> some View {
        if receita.textoIgnorado {
            Cartao(cor: Cores.destaqueSuave, borda: .clear) {
                Text("Parte do que estava escrito na receita foi ignorada porque não parecia ser receita. Confira os remédios e complete o que faltar.").font(Fonte.corpo)
            }
        }
        if receita.legibility == "poor" {
            Cartao(cor: Cores.destaqueSuave, borda: .clear) {
                Text("A foto ficou pouco legível.").font(Fonte.corpoForte)
                Text("Confira cada item com atenção ou tente outra foto.").font(Fonte.corpo)
            }
        } else {
            Text("Está tudo certo? Confira cada remédio e toque em Confirmar.").font(Fonte.corpoForte)
            Text("Remédio que não achamos no catálogo também entra no seu tratamento; só fica marcado para a nossa equipe conferir.").font(Fonte.pequena).foregroundStyle(Cores.textoSuave)
            if receita.legibility == "partial" {
                Text("Itens marcados com “confira” ficaram difíceis de ler.").font(Fonte.pequena).foregroundStyle(Cores.naDestaqueSuave)
            }
        }
        Cartao {
            CampoDeTexto(titulo: "Quem receitou", texto: $tela.cabecalho.prescriber, erro: tela.erroDoPrescritor, ajuda: "Médico(a)", conteudo: .name, maiusculas: .words)
            CampoDeTexto(titulo: "CRM (opcional)", texto: $tela.cabecalho.registration, maiusculas: .characters)
            Button {
                editandoData = true
            } label: {
                Label(Datas.dataDoFormulario(tela.cabecalho.date).map { "Data da receita: \($0)" } ?? "Informar a data da receita", systemImage: "calendar")
                    .font(Fonte.rotulo).frame(minHeight: 44)
            }
        }
        ForEach(Array(tela.itens.enumerated()), id: \.offset) { indice, item in
            ItemDaReceitaView(
                indice: indice,
                item: item,
                trocarRemedio: {
                    tela.busca.buscar(item.nomeEscrito)
                    buscandoItem = indice
                },
                mudar: { mudanca in tela.mudarItem(indice, mudanca) },
                mudarQuandoTomar: { tela.mudarQuandoTomar(indice, $0) },
                pular: { tela.pular(indice) },
                escolherCandidato: { tela.escolher(indice, remedio: $0.id, nome: $0.name) },
                editarHorario: { posicao in
                    horario = EdicaoDeHorario(item: indice, posicao: posicao, inicial: posicao.flatMap { item.times[safe: $0] } ?? sugestao(item))
                },
                tirarHorario: { tela.tirarHorario(indice, posicao: $0) }
            )
        }
        Button(role: .destructive) {
            descartar = true
        } label: {
            Label("Descartar esta receita", systemImage: "trash").frame(maxWidth: .infinity, minHeight: 48)
        }
    }

    private func sugestao(_ item: ItemDaReceita) -> String {
        guard let ultimo = item.times.last, let hora = Int(ultimo.prefix(2)) else {
            return "08:00"
        }
        return String(format: "%02d:00", (hora + 12) % 24)
    }
}

/// Um item da receita: remédio do catálogo (ou "não encontrado"), dose, quando tomar, horários e "Não cadastrar este".
private struct ItemDaReceitaView: View {
    let indice: Int
    let item: ItemDaReceita
    let trocarRemedio: () -> Void
    let mudar: ((inout ItemDaReceita) -> Void) -> Void
    let mudarQuandoTomar: (String) -> Void
    let pular: () -> Void
    let escolherCandidato: (Candidato) -> Void
    let editarHorario: (Int?) -> Void
    let tirarHorario: (Int) -> Void
    @State private var corrigindo = false

    var body: some View {
        Cartao(cor: item.skip ? Cores.superficieVariante : Cores.superficie) {
            HStack(alignment: .top) {
                Text("\(indice + 1)").font(.headline).frame(width: 30, height: 30).foregroundStyle(Cores.naMarca).background(Cores.marca, in: Circle())
                VStack(alignment: .leading, spacing: 4) {
                    Text(item.remedioNome ?? item.nomeEscrito).font(Fonte.corpoForte)
                    if item.remedioNome != nil, item.remedioNome != item.nomeEscrito {
                        Text("Na receita: \(item.nomeEscrito)").font(.caption).foregroundStyle(Cores.textoSuave)
                    }
                    if item.incerto {
                        Selo(texto: "Leitura incerta: confira", cor: Cores.naDestaqueSuave, fundo: Cores.destaqueSuave)
                    }
                }
            }
            if item.skip {
                Text("Este item não vai ser cadastrado.").font(Fonte.corpo)
                Button("Incluir este item de novo", action: pular).font(Fonte.rotulo).frame(minHeight: 44)
            } else {
                if item.remedioId != nil {
                    Label("Remédio do catálogo", systemImage: "checkmark.seal.fill").font(Fonte.pequena).foregroundStyle(Cores.sucesso)
                } else {
                    VStack(alignment: .leading, spacing: 4) {
                        Label("Não encontrado no catálogo: vamos conferir", systemImage: "exclamationmark.triangle.fill").font(Fonte.pequena).foregroundStyle(Cores.naDestaqueSuave)
                        Text("Ele entra no seu tratamento com o nome escrito na receita.").font(.caption).foregroundStyle(Cores.textoSuave)
                    }
                }
                if item.remedioId == nil, !item.candidates.isEmpty {
                    Text("Parecidos no catálogo:").font(Fonte.pequena)
                    Fileira {
                        ForEach(item.candidates) { candidato in
                            Pilula(texto: candidato.name) { escolherCandidato(candidato) }
                        }
                    }
                }
                HStack {
                    Text("Está certo?").font(Fonte.pequena)
                    Button("Não, trocar o remédio", action: trocarRemedio).font(Fonte.rotulo).frame(minHeight: 44)
                }
                horarios
                DisclosureGroup("Corrigir dose ou horário", isExpanded: $corrigindo) {
                    VStack(alignment: .leading, spacing: 12) {
                        CampoDeTexto(titulo: "Dose", texto: ligacao(\.dosage), ajuda: "Ex.: 1 comprimido de 500 mg")
                        CampoDeTexto(titulo: "Quando tomar", texto: Binding(get: { item.frequency }, set: { mudarQuandoTomar($0) }), ajuda: "Ex.: de 8 em 8 horas")
                        CampoDeTexto(titulo: "Por quanto tempo", texto: ligacao(\.duration), ajuda: "Ex.: 7 dias ou uso contínuo")
                        CampoDeTexto(titulo: "Observações", texto: ligacao(\.notes), ajuda: "Ex.: após as refeições")
                    }
                    .padding(.top, 8)
                }
                .font(Fonte.rotulo)
                Button(role: .destructive, action: pular) {
                    Label("Não cadastrar este", systemImage: "xmark.circle")
                }
                .frame(minHeight: 44)
            }
        }
    }

    @ViewBuilder
    private var horarios: some View {
        VStack(alignment: .leading, spacing: 8) {
            Text("Horários").font(Fonte.rotulo)
            if !item.dosage.isEmpty || !item.frequency.isEmpty {
                Text([item.dosage, item.frequency].filter { !$0.isEmpty }.joined(separator: " · ")).font(Fonte.pequena).foregroundStyle(Cores.textoSuave)
            }
            if item.seNecessario {
                Text("Só quando precisar: sem horário fixo.").font(Fonte.corpo)
            } else {
                if let horas = item.intervaloHoras {
                    OpcaoDeModo(titulo: "Contar a partir de quando eu tomar (recomendado)", texto: "A cada \(horas) h depois do horário em que você marcar “Tomei”.", escolhida: item.modo == ItemDaReceita.modoIntervalo) {
                        mudar { $0.modo = ItemDaReceita.modoIntervalo }
                    }
                    OpcaoDeModo(titulo: "Horários fixos todos os dias", texto: "Sempre nos mesmos horários, mesmo se atrasar uma dose.", escolhida: item.modo == ItemDaReceita.modoFixo) {
                        mudar { $0.modo = ItemDaReceita.modoFixo }
                    }
                }
                if item.contaDoUltimo {
                    Text("Primeiro horário do dia:").font(Fonte.pequena)
                    if let primeiro = item.times.first {
                        PilulaDeHorario(hora: primeiro, editar: { editarHorario(0) }, tirar: nil)
                    } else {
                        Button("Adicionar horário") { editarHorario(nil) }.font(Fonte.rotulo)
                    }
                    Text("Os outros horários são calculados a cada \(item.intervaloHoras ?? 0) h a partir da última dose que você marcar.").font(.caption).foregroundStyle(Cores.textoSuave)
                } else {
                    if item.times.isEmpty {
                        Text("Sem horário definido. Toque em “Adicionar horário” se quiser lembretes.").font(Fonte.pequena).foregroundStyle(Cores.textoSuave)
                    }
                    Fileira {
                        ForEach(Array(item.times.enumerated()), id: \.offset) { posicao, hora in
                            PilulaDeHorario(hora: hora, editar: { editarHorario(posicao) }, tirar: { tirarHorario(posicao) })
                        }
                    }
                    if item.times.count < 12 {
                        Button {
                            editarHorario(nil)
                        } label: {
                            Label("Adicionar horário", systemImage: "plus")
                        }
                        .font(Fonte.rotulo).frame(minHeight: 44)
                    }
                }
            }
        }
    }

    private func ligacao(_ campo: WritableKeyPath<ItemDaReceita, String>) -> Binding<String> {
        Binding(get: { item[keyPath: campo] }, set: { novo in mudar { $0[keyPath: campo] = String(novo.prefix(255)) } })
    }
}

/// Horário em pílula: tocar muda, "x" tira.
struct PilulaDeHorario: View {
    let hora: String
    let editar: () -> Void
    let tirar: (() -> Void)?

    var body: some View {
        HStack(spacing: 0) {
            Button(action: editar) {
                Label(hora, systemImage: "clock").font(.headline.monospacedDigit()).padding(.leading, 14).padding(.trailing, tirar == nil ? 14 : 6).frame(minHeight: 44)
            }
            .accessibilityHint("Toque para mudar")
            if let tirar {
                Button(action: tirar) {
                    Image(systemName: "xmark").font(.footnote.weight(.bold)).frame(width: 36, height: 44)
                }
                .accessibilityLabel("Tirar o horário \(hora)")
            }
        }
        .buttonStyle(.plain)
        .foregroundStyle(Cores.marca)
        .background(Cores.marcaSuave, in: Capsule())
    }
}

/// Opção de modo de horário (fixo ou contado a partir da dose), como rádio grande.
struct OpcaoDeModo: View {
    let titulo: String
    let texto: String
    let escolhida: Bool
    let acao: () -> Void

    var body: some View {
        Button(action: acao) {
            HStack(alignment: .top, spacing: 12) {
                Image(systemName: escolhida ? "largecircle.fill.circle" : "circle").font(.title3).foregroundStyle(escolhida ? Cores.marca : Cores.textoSuave)
                VStack(alignment: .leading, spacing: 2) {
                    Text(titulo).font(Fonte.corpoForte).foregroundStyle(Cores.texto)
                    Text(texto).font(Fonte.pequena).foregroundStyle(Cores.textoSuave)
                }
                Spacer(minLength: 0)
            }
            .padding(12)
            .background(escolhida ? Cores.marcaSuave.opacity(0.5) : Color.clear, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
            .overlay(RoundedRectangle(cornerRadius: 14, style: .continuous).strokeBorder(escolhida ? Cores.marca : Cores.contorno))
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
        .accessibilityAddTraits(escolhida ? .isSelected : [])
    }
}

/// Relógio do iOS (24 h) numa folha, devolvendo "HH:MM".
struct SeletorDeHora: View {
    let titulo: String
    let inicial: String
    let aoEscolher: (String) -> Void
    @State private var hora = Date()
    @Environment(\.dismiss) private var fechar

    var body: some View {
        NavigationStack {
            DatePicker(titulo, selection: $hora, displayedComponents: .hourAndMinute)
                .datePickerStyle(.wheel)
                .labelsHidden()
                .environment(\.locale, Locale(identifier: "pt_BR"))
                .frame(maxWidth: .infinity)
                .padding()
                .navigationTitle(titulo)
                .navigationBarTitleDisplayMode(.inline)
                .toolbar {
                    ToolbarItem(placement: .cancellationAction) { Button("Cancelar") { fechar() } }
                    ToolbarItem(placement: .confirmationAction) {
                        Button("OK") {
                            aoEscolher(Datas.formatar(hora, "HH:mm"))
                            fechar()
                        }
                    }
                }
        }
        .presentationDetents([.height(340)])
        .onAppear {
            let partes = inicial.split(separator: ":").compactMap { Int($0) }
            if partes.count == 2 {
                hora = Calendar.current.date(bySettingHour: partes[0], minute: partes[1], second: 0, of: Date()) ?? Date()
            }
        }
    }
}

/// Calendário do iOS numa folha; "Limpar" devolve `nil`.
struct SeletorDeData: View {
    let titulo: String
    let inicial: Date
    let aoEscolher: (Date?) -> Void
    @State private var data = Date()
    @Environment(\.dismiss) private var fechar

    var body: some View {
        NavigationStack {
            DatePicker(titulo, selection: $data, displayedComponents: .date)
                .datePickerStyle(.graphical)
                .environment(\.locale, Locale(identifier: "pt_BR"))
                .padding()
                .navigationTitle(titulo)
                .navigationBarTitleDisplayMode(.inline)
                .toolbar {
                    ToolbarItem(placement: .cancellationAction) { Button("Cancelar") { fechar() } }
                    ToolbarItem(placement: .confirmationAction) {
                        Button("OK") {
                            aoEscolher(data)
                            fechar()
                        }
                    }
                    ToolbarItem(placement: .bottomBar) {
                        Button("Limpar", role: .destructive) {
                            aoEscolher(nil)
                            fechar()
                        }
                    }
                }
        }
        .presentationDetents([.medium, .large])
        .onAppear { data = inicial }
    }
}

extension Array {
    subscript(safe indice: Int) -> Element? {
        indices.contains(indice) ? self[indice] : nil
    }
}
