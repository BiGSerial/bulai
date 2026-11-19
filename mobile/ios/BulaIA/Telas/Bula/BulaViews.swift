import BulaiCore
import SwiftUI

/// Bula simples: capa com "Para que serve" e "Ouvir", frase de risco, seções recolhíveis por cor (as duas primeiras
/// abertas) com "De onde veio?", nota de preparo, "No seu tratamento" e o botão fixo "Perguntar sobre este remédio".
struct BulaView: View {
    let ambiente: Ambiente
    @State private var tela: BulaViewModel
    @State private var abertas: Set<String> = []

    init(ambiente: Ambiente, remedio: Int64) {
        self.ambiente = ambiente
        _tela = State(initialValue: BulaViewModel(ambiente.dependencias, remedio: remedio))
    }

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 16) {
                if let erro = tela.carga.erro, tela.carga.dados == nil {
                    FaixaDeErro(texto: erro) { Task { await tela.carregar() } }
                }
                if let bula = tela.carga.dados {
                    conteudo(bula)
                } else {
                    Esqueleto(altura: 30, largura: 220)
                    Esqueleto(altura: 140, raio: 20)
                    EsqueletoDeLista(cartoes: 3)
                }
            }
            .padding(16)
            .padding(.bottom, 80)
        }
        .telaBulai()
        .safeAreaInset(edge: .bottom) {
            if let bula = tela.carga.dados, !bula.preparando {
                BotaoPrincipal(titulo: "Perguntar sobre este remédio", simbolo: "bubble.left.and.text.bubble.right.fill", cor: Cores.destaque, corDoTexto: Cores.naDestaque) {
                    ambiente.voz.parar()
                    ambiente.navegador.abrir(.perguntarSobre(tela.remedio))
                }
                .padding(.horizontal, 16)
                .padding(.vertical, 10)
                .background(.bar)
            }
        }
        .task { await tela.carregar() }
        .onDisappear { ambiente.voz.parar() }
        .onChange(of: tela.carga.dados?.sections.count) {
            if abertas.isEmpty, let secoes = tela.carga.dados?.sections {
                abertas = Set(secoes.prefix(2).map(\.key))
            }
        }
    }

    @ViewBuilder
    private func conteudo(_ bula: Bula) -> some View {
        VStack(alignment: .leading, spacing: 6) {
            Text("BULA SIMPLES").font(.caption.weight(.heavy)).tracking(1.2).foregroundStyle(Cores.destaque)
            Text(bula.medicine.name).font(Fonte.tituloGrande).foregroundStyle(Cores.marca).accessibilityAddTraits(.isHeader)
            Text(bula.medicine.ingredient ?? "substância não informada").font(Fonte.corpo).foregroundStyle(Cores.textoSuave)
            if let titular = bula.medicine.holder {
                Text(titular).font(.caption).foregroundStyle(Cores.textoSuave)
            }
        }
        switch bula.state {
        case Bula.carregando:
            VStack(alignment: .leading, spacing: 12) {
                Text("Preparando a bula humanizada…").font(Fonte.corpoForte)
                Text("Leva alguns segundos na primeira vez; depois abre na hora.").font(Fonte.corpo).foregroundStyle(Cores.textoSuave)
                EsqueletoDeLista(cartoes: 3)
            }
            .accessibilityElement(children: .combine)
        case Bula.naoIdentificado:
            aviso("Não identificamos o princípio ativo (a substância) deste remédio no catálogo, então não conseguimos localizar a bula certa. Consulte a bula da embalagem ou um farmacêutico.")
        case Bula.semBula:
            aviso("Ainda não há bula deste remédio cadastrada no BULA-IA. Não montamos resumos “de memória”: consulte a bula na embalagem ou o farmacêutico.")
        case Bula.limite:
            aviso("Você atingiu o limite de bulas geradas por hora. Tente novamente mais tarde; as bulas já preparadas continuam disponíveis.")
        default:
            if let falha = bula.failure {
                aviso("Não foi possível montar a bula humanizada com segurança agora. " + (falha == "unavailable" ? "O serviço está indisponível ou a bula não trouxe trechos suficientes." : "O resumo não passou na conferência automática e foi descartado.") + " Você ainda pode fazer uma pergunta ou consultar a bula da embalagem.")
            }
            pronta(bula)
        }
        if let tratamento = bula.treatment {
            PainelDoTratamento(tratamento: tratamento)
        }
        if bula.state == Bula.pronta {
            BotaoSecundario(titulo: "Ver interações com os meus remédios", simbolo: "arrow.triangle.branch") {
                ambiente.navegador.abrir(.interacoes(com: bula.medicine.id))
            }
        }
    }

    @ViewBuilder
    private func pronta(_ bula: Bula) -> some View {
        if let capa = bula.cover {
            Cartao(cor: Cores.marcaSuave, borda: .clear) {
                Text(capa.label).font(Fonte.subtitulo).foregroundStyle(Cores.naMarcaSuave)
                ForEach(capa.claims, id: \.self) { afirmacao in
                    Text(afirmacao).font(Fonte.corpo)
                }
                HStack {
                    Button {
                        ambiente.voz.alternar(bula.textoCorrido, id: "bula\(bula.medicine.id)")
                    } label: {
                        Label(ambiente.voz.lendo == "bula\(bula.medicine.id)" ? "Parar de ouvir" : "Ouvir a bula simples", systemImage: ambiente.voz.lendo == "bula\(bula.medicine.id)" ? "stop.circle.fill" : "speaker.wave.2.fill")
                            .font(Fonte.rotulo).frame(minHeight: 44)
                    }
                    Spacer()
                }
                if !capa.sources.isEmpty {
                    DeOndeVeio(titulo: "De onde veio “\(capa.label)”?", fontes: capa.sources)
                }
            }
        }
        if let risco = bula.risk {
            let tom = Cores.doTom(risco.tone)
            Label(risco.text, systemImage: "exclamationmark.triangle.fill").font(Fonte.corpoForte).foregroundStyle(tom.cor)
                .padding(14).frame(maxWidth: .infinity, alignment: .leading)
                .background(tom.fundo, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
        }
        ForEach(bula.sections) { secao in
            let tom = Cores.doTom(secao.tone)
            DisclosureGroup(isExpanded: Binding(get: { abertas.contains(secao.key) }, set: { aberta in
                if aberta {
                    abertas.insert(secao.key)
                } else {
                    abertas.remove(secao.key)
                }
            })) {
                VStack(alignment: .leading, spacing: 10) {
                    ForEach(secao.claims, id: \.self) { afirmacao in
                        HStack(alignment: .top, spacing: 8) {
                            Circle().fill(tom.cor).frame(width: 7, height: 7).padding(.top, 8).accessibilityHidden(true)
                            Text(afirmacao).font(Fonte.corpo).fixedSize(horizontal: false, vertical: true)
                        }
                    }
                    if !secao.sources.isEmpty {
                        DeOndeVeio(titulo: "De onde veio?", fontes: secao.sources)
                    }
                }
                .padding(.top, 8)
            } label: {
                Text(secao.label).font(Fonte.subtitulo).foregroundStyle(tom.cor)
            }
            .padding(14)
            .background(tom.fundo.opacity(0.55), in: RoundedRectangle(cornerRadius: 18, style: .continuous))
            .overlay(alignment: .leading) {
                UnevenRoundedRectangle(topLeadingRadius: 18, bottomLeadingRadius: 18, style: .continuous).fill(tom.cor).frame(width: 6)
            }
        }
        if !bula.missing.isEmpty {
            Text("A bula cadastrada não trouxe trechos suficientes para: \(bula.missing.joined(separator: ", ")). Consulte a bula completa ou um farmacêutico.").font(Fonte.pequena).foregroundStyle(Cores.textoSuave)
        }
        if let preparo = bula.prepared {
            Label(preparo, systemImage: "checkmark.shield").font(.caption).foregroundStyle(Cores.textoSuave)
        }
    }

    private func aviso(_ texto: String) -> some View {
        Cartao(cor: Cores.destaqueSuave, borda: .clear) {
            Text(texto).font(Fonte.corpo)
        }
    }
}

private struct DeOndeVeio: View {
    let titulo: String
    let fontes: [String]
    @State private var aberto = false

    var body: some View {
        DisclosureGroup(titulo, isExpanded: $aberto) {
            VStack(alignment: .leading, spacing: 4) {
                ForEach(fontes, id: \.self) { fonte in
                    Text("• \(fonte)").font(.caption).foregroundStyle(Cores.textoSuave)
                }
            }
            .padding(.top, 4)
        }
        .font(Fonte.pequena.weight(.semibold))
    }
}

/// Lista de bulas: as prontas filtradas a cada letra; com 3 letras, também o catálogo.
struct BulasView: View {
    let ambiente: Ambiente
    @State private var tela: BulasViewModel

    init(ambiente: Ambiente) {
        self.ambiente = ambiente
        _tela = State(initialValue: BulasViewModel(api: ambiente.api))
    }

    var body: some View {
        List {
            Section {
                Text("A bula explicada em linguagem simples: para que serve, como usar, cuidados e efeitos.").font(Fonte.corpo).foregroundStyle(Cores.textoSuave)
            }
            if let erro = tela.erro {
                FaixaDeErro(texto: erro) { Task { await tela.carregar() } }
            }
            if tela.termo.trimmingCharacters(in: .whitespaces).count >= 3 {
                Section("No catálogo") {
                    if tela.limitado {
                        Text("Muitas buscas em pouco tempo. Espere um minuto.").foregroundStyle(Cores.naDestaqueSuave)
                    }
                    if tela.buscando {
                        ProgressView()
                    } else if tela.catalogo.isEmpty, !tela.limitado {
                        Text("Nenhum remédio encontrado para “\(tela.termo)”.").foregroundStyle(Cores.textoSuave)
                    }
                    ForEach(tela.catalogo) { remedio in
                        Button {
                            ambiente.navegador.abrir(.bula(remedio.id))
                        } label: {
                            VStack(alignment: .leading, spacing: 2) {
                                Text(remedio.name).font(Fonte.corpoForte).foregroundStyle(Cores.texto)
                                Text(remedio.temBula ? "bula simples pronta" : "a bula simples é preparada na hora").font(.caption).foregroundStyle(Cores.textoSuave)
                            }
                            .frame(minHeight: 44)
                        }
                    }
                }
            }
            Section(tela.filtradas.isEmpty ? "Bulas prontas" : "Bulas prontas (\(tela.filtradas.count))") {
                ForEach(tela.filtradas) { bula in
                    Button {
                        ambiente.navegador.abrir(.bula(bula.id))
                    } label: {
                        Label(bula.name, systemImage: "book.closed.fill").font(Fonte.corpo).foregroundStyle(Cores.texto).frame(minHeight: 44)
                    }
                }
            }
        }
        .scrollContentBackground(.hidden)
        .background(Cores.fundo.ignoresSafeArea())
        .searchable(text: $tela.termo, placement: .navigationBarDrawer(displayMode: .always), prompt: "Nome do remédio")
        .task { await tela.carregar() }
    }
}
