import BulaiCore
import SwiftUI

/// Perguntar: conversa com o BULA-IA. A pergunta aparece na hora, "digitando" até a resposta; a caixa é uma só (texto,
/// microfone e enviar juntos) e acompanha o teclado. Alergia → balão vermelho e aviso em destaque.
struct PerguntarView: View {
    let ambiente: Ambiente
    @State private var tela: PerguntarViewModel
    @State private var ditado = DitadoDaPergunta()
    @State private var buscandoRemedio = false
    @State private var alergiaAvisada: String?
    @FocusState private var caixaEmFoco: Bool

    init(ambiente: Ambiente, remedio: Int64?) {
        self.ambiente = ambiente
        _tela = State(initialValue: PerguntarViewModel(ambiente.dependencias, remedio: remedio))
    }

    var body: some View {
        ScrollViewReader { rolagem in
            ScrollView {
                LazyVStack(alignment: .leading, spacing: 16) {
                    boasVindas
                    ForEach(tela.trocas) { troca in
                        let ultima = troca.id == tela.trocas.last?.id
                        VStack(alignment: .leading, spacing: 12) {
                            BalaoDaPessoa(texto: troca.pergunta, enviada: ultima && !troca.esperando)
                                .transition(.move(edge: .bottom).combined(with: .opacity))
                            if let resposta = troca.resposta {
                                BalaoDaResposta(resposta: resposta, acoes: ultima ? acoes : nil) { id in ambiente.navegador.abrir(.bula(id)) }
                            } else if let erro = troca.erro {
                                VStack(alignment: .leading, spacing: 10) {
                                    FaixaDeErro(texto: erro) { tela.tentarDeNovo() }
                                }
                            } else {
                                Digitando()
                            }
                        }
                        .id(troca.id)
                    }
                    Color.clear.frame(height: 4).id("fim")
                }
                .padding(16)
                .frame(maxWidth: 720)
                .frame(maxWidth: .infinity)
                .animation(.spring(duration: 0.35), value: tela.trocas.count)
            }
            .scrollDismissesKeyboard(.interactively)
            .onChange(of: tela.trocas.last?.id) {
                withAnimation { rolagem.scrollTo(tela.trocas.last?.id ?? 0, anchor: .top) }
            }
            .onChange(of: tela.trocas.last?.resposta?.uuid) {
                withAnimation { rolagem.scrollTo(tela.trocas.last?.id ?? 0, anchor: .top) }
            }
        }
        .safeAreaInset(edge: .bottom) { caixa }
        .background(Cores.fundo.ignoresSafeArea())
        .toolbar {
            if !tela.trocas.isEmpty {
                ToolbarItem(placement: .topBarLeading) {
                    Button {
                        tela.novaConversa()
                        ambiente.voz.parar()
                    } label: {
                        Image(systemName: "square.and.pencil")
                    }
                    .accessibilityLabel("Começar outra conversa")
                }
            }
        }
        .task { await tela.carregar() }
        .onDisappear {
            ditado.parar()
        }
        .sheet(isPresented: $buscandoRemedio) {
            BuscaDeRemedioView(titulo: "Qual remédio você quis dizer?", busca: tela.busca) { remedio in
                buscandoRemedio = false
                tela.corrigir(remedio.id)
            }
        }
        .alert("Atenção à sua alergia", isPresented: Binding(get: { avisoDeAlergia != nil }, set: { if !$0 { alergiaAvisada = tela.trocas.last?.resposta?.uuid } })) {
            Button("Entendi") { alergiaAvisada = tela.trocas.last?.resposta?.uuid }
        } message: {
            Text(avisoDeAlergia ?? "")
        }
    }

    private var avisoDeAlergia: String? {
        guard let resposta = tela.trocas.last?.resposta, resposta.uuid != alergiaAvisada, resposta.alert?.alergia == true else {
            return nil
        }
        return resposta.alert?.warning
    }

    private var acoes: AcoesDaResposta {
        AcoesDaResposta(
            corrigir: { tela.corrigir($0) },
            remover: { tela.remover($0) },
            sugerir: { texto in
                tela.sugerir(texto)
                caixaEmFoco = true
            },
            responder: { tela.responder($0) },
            trocarRemedio: { buscandoRemedio = true },
            abrirBula: { ambiente.navegador.abrir(.bula($0)) }
        )
    }

    @ViewBuilder
    private var boasVindas: some View {
        HStack(alignment: .top, spacing: 10) {
            AvatarDoBulai()
            VStack(alignment: .leading, spacing: 10) {
                Text("Oi! Sou o BULA-IA. Qual é a sua dúvida sobre um remédio?").font(Fonte.corpoForte)
                Text("Escreva ou fale, citando o nome do remédio. Depois você pode continuar: “e com café?”, “e à noite?”.").font(Fonte.corpo).foregroundStyle(Cores.textoSuave)
                if tela.trocas.isEmpty, let exemplos = tela.inicio?.examples, !exemplos.isEmpty {
                    Fileira {
                        ForEach(exemplos, id: \.self) { exemplo in
                            Pilula(texto: exemplo) {
                                tela.sugerir(exemplo)
                                caixaEmFoco = true
                            }
                        }
                    }
                }
            }
            .padding(14)
            .background(Cores.superficie, in: RoundedRectangle(cornerRadius: 20, style: .continuous))
        }
        if let previa = tela.inicio?.preview, tela.trocas.isEmpty {
            PainelDoTratamento(tratamento: previa)
        }
    }

    private var caixa: some View {
        VStack(alignment: .leading, spacing: 6) {
            if let aviso = tela.aviso ?? ditado.erro {
                Label(aviso, systemImage: "info.circle.fill").font(Fonte.pequena).foregroundStyle(Cores.erro)
                    .accessibilityAddTraits(.updatesFrequently)
            }
            HStack(alignment: .bottom, spacing: 8) {
                TextField("Sua dúvida sobre um remédio", text: $tela.texto, axis: .vertical)
                    .font(Fonte.corpo)
                    .lineLimit(1 ... 5)
                    .focused($caixaEmFoco)
                    .submitLabel(.send)
                    .onSubmit(enviar)
                    .padding(.vertical, 12)
                    .padding(.leading, 14)
                    .onChange(of: tela.texto) { _, novo in
                        // Enter de teclado físico (ou texto colado com quebra de linha) envia, como no site.
                        if novo.contains("\n") {
                            tela.texto = novo.replacingOccurrences(of: "\n", with: " ")
                            enviar()
                        }
                    }
                Button {
                    Task {
                        await ditado.comecar { texto in
                            tela.texto = texto
                        }
                    }
                } label: {
                    Image(systemName: ditado.ouvindo ? "stop.circle.fill" : "mic.fill")
                        .font(.title3)
                        .foregroundStyle(ditado.ouvindo ? Cores.erro : Cores.marca)
                        .frame(width: 44, height: 44)
                        .symbolEffect(.pulse, isActive: ditado.ouvindo)
                }
                .accessibilityLabel(ditado.ouvindo ? "Parar de ouvir" : "Falar a pergunta")
                Button(action: enviar) {
                    Image(systemName: tela.enviando ? "hourglass" : "arrow.up")
                        .font(.headline.weight(.bold))
                        .foregroundStyle(Cores.naDestaque)
                        .frame(width: 44, height: 44)
                        .background(podeEnviar ? Cores.destaque : Cores.destaque.opacity(0.4), in: Circle())
                }
                .disabled(!podeEnviar)
                .accessibilityLabel("Perguntar")
                .padding(.trailing, 6)
                .padding(.bottom, 4)
            }
            .background(Cores.superficie, in: RoundedRectangle(cornerRadius: 24, style: .continuous))
            .overlay(RoundedRectangle(cornerRadius: 24, style: .continuous).strokeBorder(caixaEmFoco ? Cores.marca : Cores.contorno, lineWidth: caixaEmFoco ? 2 : 1))
            if tela.texto.count > tela.maximo - 80 {
                Text("\(tela.texto.count)/\(tela.maximo)").font(.caption).foregroundStyle(Cores.textoSuave).frame(maxWidth: .infinity, alignment: .trailing)
            }
        }
        .padding(.horizontal, 12)
        .padding(.top, 8)
        .padding(.bottom, 8)
        .frame(maxWidth: 720)
        .frame(maxWidth: .infinity)
        .background(.bar)
    }

    private var podeEnviar: Bool {
        !tela.enviando && !tela.texto.trimmingCharacters(in: .whitespaces).isEmpty
    }

    private func enviar() {
        ditado.parar()
        ambiente.voz.parar()
        withAnimation(.spring(duration: 0.4)) {
            if tela.enviar() {
                caixaEmFoco = false
            }
        }
    }
}

/// Busca no catálogo (correção do remédio, receita, perfil, interações), com "usar pelo nome" opcional.
struct BuscaDeRemedioView: View {
    let titulo: String
    let busca: BuscaNoCatalogo
    var usarPeloNome: ((String) -> Void)?
    let escolher: (RemedioDoCatalogo) -> Void
    @State private var termo = ""
    @Environment(\.dismiss) private var fechar
    @FocusState private var foco: Bool

    var body: some View {
        NavigationStack {
            List {
                if busca.buscando {
                    HStack { ProgressView(); Text("Buscando…") }
                }
                if let resultados = busca.resultados {
                    if resultados.isEmpty {
                        Text("Nenhum remédio encontrado para “\(termo)”.").foregroundStyle(Cores.textoSuave)
                    }
                    ForEach(resultados) { remedio in
                        Button {
                            escolher(remedio)
                        } label: {
                            VStack(alignment: .leading, spacing: 2) {
                                Text(remedio.name).font(Fonte.corpoForte).foregroundStyle(Cores.texto)
                                if !remedio.ingredients.isEmpty {
                                    Text(remedio.ingredients).font(Fonte.pequena).foregroundStyle(Cores.textoSuave)
                                }
                                if let titular = remedio.holder {
                                    Text(titular).font(.caption).foregroundStyle(Cores.textoSuave)
                                }
                            }
                            .frame(minHeight: 44)
                        }
                    }
                } else {
                    Text("Digite o nome (pelo menos 3 letras)").foregroundStyle(Cores.textoSuave)
                }
                if let usarPeloNome, termo.trimmingCharacters(in: .whitespaces).count >= 3 {
                    Button {
                        usarPeloNome(termo.trimmingCharacters(in: .whitespaces))
                    } label: {
                        Label("Não achei: usar “\(termo.trimmingCharacters(in: .whitespaces))” pelo nome", systemImage: "text.badge.plus")
                    }
                }
            }
            .searchable(text: $termo, placement: .navigationBarDrawer(displayMode: .always), prompt: "Nome do remédio")
            .onChange(of: termo) { _, novo in busca.buscar(novo) }
            .navigationTitle(titulo)
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Fechar") {
                        busca.fechar()
                        fechar()
                    }
                }
            }
        }
        .presentationDetents([.large])
    }
}

/// Minhas perguntas: o histórico (fica pelo tempo de retenção), abrir uma pergunta e apagar.
struct HistoricoView: View {
    let ambiente: Ambiente
    @State private var tela: HistoricoViewModel

    init(ambiente: Ambiente) {
        self.ambiente = ambiente
        _tela = State(initialValue: HistoricoViewModel(api: ambiente.api))
    }

    var body: some View {
        List {
            if let erro = tela.carga.erro {
                FaixaDeErro(texto: erro) { Task { await tela.carregar() } }.listRowBackground(Color.clear)
            }
            if let historico = tela.carga.dados {
                if historico.items.isEmpty {
                    EstadoVazio(simbolo: "text.bubble", titulo: "Nenhuma pergunta ainda", texto: "O que você perguntar ao BULA-IA fica aqui por \(historico.retencao) dias. Você pode apagar quando quiser.", acao: ("Perguntar sobre um remédio", { ambiente.navegador.abrir(.aba(.perguntar)) }))
                        .listRowBackground(Color.clear)
                } else {
                    Section {
                        ForEach(historico.items) { pergunta in
                            Button {
                                ambiente.navegador.abrir(.pergunta(pergunta.uuid))
                            } label: {
                                VStack(alignment: .leading, spacing: 6) {
                                    Text(pergunta.question).font(Fonte.corpoForte).foregroundStyle(Cores.texto).lineLimit(3)
                                    HStack {
                                        Selo(texto: HistoricoViewModel.situacao(pergunta.status), cor: Cores.marca, fundo: Cores.marcaSuave)
                                        if let remedios = pergunta.medicines {
                                            Text(remedios).font(.caption).foregroundStyle(Cores.textoSuave).lineLimit(1)
                                        }
                                    }
                                    Text("Perguntado em \(Datas.longa(pergunta.em))").font(.caption).foregroundStyle(Cores.textoSuave)
                                }
                                .padding(.vertical, 4)
                            }
                            .swipeActions {
                                Button(role: .destructive) {
                                    Task { await tela.apagar(pergunta) }
                                } label: {
                                    Label("Apagar", systemImage: "trash")
                                }
                            }
                            .accessibilityAction(named: "Apagar esta pergunta") { Task { await tela.apagar(pergunta) } }
                            .onAppear {
                                if pergunta.uuid == historico.items.last?.uuid {
                                    Task { await tela.carregarMais() }
                                }
                            }
                        }
                    } footer: {
                        Text("Suas perguntas ficam guardadas por \(historico.retencao) dias e depois são apagadas.")
                    }
                }
            } else if tela.carga.esqueleto {
                EsqueletoDeLista(cartoes: 4).listRowBackground(Color.clear)
            }
        }
        .scrollContentBackground(.hidden)
        .background(Cores.fundo.ignoresSafeArea())
        .refreshable { await tela.carregar() }
        .task { await tela.carregar() }
        .recado($tela.recado)
    }
}

/// Uma pergunta anterior, no mesmo balão da conversa (sem as ações da resposta mais recente).
struct PerguntaAnteriorView: View {
    let ambiente: Ambiente
    @State private var tela: PerguntaAnteriorViewModel

    init(ambiente: Ambiente, uuid: String) {
        self.ambiente = ambiente
        _tela = State(initialValue: PerguntaAnteriorViewModel(api: ambiente.api, uuid: uuid))
    }

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 14) {
                if let erro = tela.carga.erro {
                    FaixaDeErro(texto: erro) { Task { await tela.carregar() } }
                }
                if let resposta = tela.carga.dados {
                    BalaoDaPessoa(texto: resposta.question)
                    BalaoDaResposta(resposta: resposta, acoes: nil) { id in ambiente.navegador.abrir(.bula(id)) }
                    if let quando = resposta.perguntadaEm {
                        Text("Perguntado em \(Datas.longa(quando))").font(.caption).foregroundStyle(Cores.textoSuave)
                    }
                } else if tela.carga.esqueleto {
                    EsqueletoDeLista(cartoes: 2)
                }
            }
            .padding(16)
        }
        .telaBulai()
        .task { await tela.carregar() }
    }
}
