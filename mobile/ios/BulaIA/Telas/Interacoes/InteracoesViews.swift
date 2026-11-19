import BulaiCore
import SwiftUI

/// Verificar interações: os remédios em uso já entram; buscar mais; o servidor decide se cada um pode entrar.
struct PesquisaView: View {
    let ambiente: Ambiente
    @State private var tela: PesquisaViewModel
    @State private var adicionando = false

    init(ambiente: Ambiente, comRemedio: Int64?) {
        self.ambiente = ambiente
        _tela = State(initialValue: PesquisaViewModel(api: ambiente.api, comRemedio: comRemedio))
    }

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 16) {
                Text("Veja se dois ou mais remédios podem interagir. Os remédios que você usa já estão na lista.").font(Fonte.corpo).foregroundStyle(Cores.textoSuave)
                if let mensagem = tela.mensagem {
                    FaixaDeErro(texto: mensagem)
                }
                if tela.carregando {
                    EsqueletoDeLista(cartoes: 2)
                } else {
                    TituloDeSecao(texto: "Remédios para verificar (\(tela.escolhidos.count) de \(tela.maximo))")
                    ForEach(tela.escolhidos) { remedio in
                        HStack(alignment: .top, spacing: 12) {
                            Image(systemName: "pills.fill").foregroundStyle(Cores.marca).font(.title3).accessibilityHidden(true)
                            VStack(alignment: .leading, spacing: 4) {
                                Text(remedio.name).font(Fonte.corpoForte)
                                if !remedio.ingredients.isEmpty {
                                    Text(remedio.ingredients).font(.caption).foregroundStyle(Cores.textoSuave)
                                }
                                if !remedio.identified {
                                    Text("Princípio ativo não identificado: o resultado pode ficar incompleto.").font(.caption).foregroundStyle(Cores.naDestaqueSuave)
                                }
                                ForEach(remedio.alerts, id: \.self) { alerta in
                                    Label(alerta, systemImage: "exclamationmark.triangle.fill").font(Fonte.pequena.weight(.semibold)).foregroundStyle(Cores.erro)
                                }
                            }
                            Spacer()
                            Button {
                                tela.tirar(remedio.id)
                            } label: {
                                Image(systemName: "xmark.circle.fill").font(.title2).foregroundStyle(Cores.textoSuave).frame(width: 44, height: 44)
                            }
                            .accessibilityLabel("Tirar \(remedio.name) da lista")
                        }
                        .padding(14)
                        .background(remedio.alerts.isEmpty ? Cores.superficie : Cores.erroFundo, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
                    }
                    if tela.escolhidos.count < tela.maximo {
                        BotaoSecundario(titulo: "Adicionar remédio", simbolo: "plus") { adicionando = true }
                    }
                    BotaoPrincipal(titulo: "Verificar interações", simbolo: "arrow.triangle.branch", carregando: tela.verificando, desabilitado: tela.escolhidos.count < 2, cor: Cores.destaque, corDoTexto: Cores.naDestaque) {
                        Task {
                            if let uuid = await tela.verificar() {
                                ambiente.navegador.abrir(.resultado(uuid))
                            }
                        }
                    }
                    if !tela.historico.isEmpty {
                        TituloDeSecao(texto: "Consultas anteriores").padding(.top, 12)
                        ForEach(tela.historico) { consulta in
                            HStack {
                                Button {
                                    ambiente.navegador.abrir(.resultado(consulta.uuid))
                                } label: {
                                    VStack(alignment: .leading, spacing: 2) {
                                        Text(consulta.medicines.joined(separator: " + ")).font(Fonte.corpoForte).foregroundStyle(Cores.texto).multilineTextAlignment(.leading)
                                        Text("\(Datas.curta(consulta.criadaEm)) · \(consulta.status == "FAILED" ? "não concluída" : consulta.pares == 1 ? "1 combinação" : "\(consulta.pares) combinações")").font(.caption).foregroundStyle(Cores.textoSuave)
                                    }
                                    .frame(maxWidth: .infinity, alignment: .leading)
                                }
                                .buttonStyle(.plain)
                                Button {
                                    Task { await tela.apagar(consulta.uuid) }
                                } label: {
                                    Image(systemName: "trash").foregroundStyle(Cores.erro).frame(width: 44, height: 44)
                                }
                                .accessibilityLabel("Apagar esta consulta")
                            }
                            .padding(12)
                            .background(Cores.superficie, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                        }
                    }
                }
            }
            .padding(16)
        }
        .telaBulai()
        .task { await tela.carregar() }
        .sheet(isPresented: $adicionando) {
            BuscaDeRemedioView(titulo: "Adicionar remédio", busca: tela.busca) { remedio in
                adicionando = false
                Task { await tela.adicionar(remedio.id) }
            }
        }
    }
}

/// Resultado: acompanhado a cada 2 s até terminar; um cartão por par com a faixa de cor pela gravidade e a frase do site.
struct ResultadoView: View {
    let ambiente: Ambiente
    @State private var tela: ResultadoViewModel

    init(ambiente: Ambiente, uuid: String) {
        self.ambiente = ambiente
        _tela = State(initialValue: ResultadoViewModel(api: ambiente.api, uuid: uuid))
    }

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 16) {
                if let erro = tela.carga.erro {
                    FaixaDeErro(texto: erro) { Task { await tela.acompanhar() } }
                }
                if let consulta = tela.carga.dados {
                    Text(consulta.medicines.joined(separator: " + ")).font(Fonte.titulo).foregroundStyle(Cores.marca).accessibilityAddTraits(.isHeader)
                    ForEach(consulta.alertas, id: \.self) { alerta in
                        Cartao(cor: Cores.erroFundo, borda: Cores.erro) {
                            Label("Atenção à sua alergia: \(alerta.medicine)", systemImage: "exclamationmark.triangle.fill").font(Fonte.corpoForte).foregroundStyle(Cores.erro)
                            Text(alerta.text).font(Fonte.corpo)
                        }
                    }
                    if consulta.running {
                        VStack(alignment: .leading, spacing: 10) {
                            Text(consulta.waiting ? "Aguardando o início da análise…" : "Analisando…").font(Fonte.corpoForte)
                            ProgressView(value: Double(consulta.done), total: Double(max(consulta.total, 1)))
                            Text("\(consulta.done) de \(consulta.total) combinações prontas.").font(Fonte.pequena).foregroundStyle(Cores.textoSuave)
                        }
                        .accessibilityElement(children: .combine)
                    } else if consulta.failed {
                        Cartao(cor: Cores.erroFundo, borda: .clear) {
                            Text("Não foi possível concluir a análise.").font(Fonte.corpoForte)
                            Text(consulta.failure ?? "Nenhum resultado parcial deve ser lido como ausência de interação.").font(Fonte.corpo)
                            BotaoPrincipal(titulo: "Tentar novamente", simbolo: "arrow.clockwise") { repetir() }
                        }
                    }
                    ForEach(consulta.pairs) { par in
                        CartaoDoPar(par: par)
                    }
                    if !consulta.running {
                        BotaoSecundario(titulo: "Repetir esta consulta", simbolo: "arrow.clockwise") { repetir() }
                        Text("O BULA-IA não substitui seu médico ou farmacêutico. Na dúvida, pergunte a eles antes de usar os remédios juntos.").font(Fonte.pequena).foregroundStyle(Cores.textoSuave)
                    }
                } else if tela.carga.esqueleto {
                    EsqueletoDeLista(cartoes: 2)
                }
            }
            .padding(16)
        }
        .telaBulai()
        .task { await tela.acompanhar() }
    }

    private func repetir() {
        Task {
            if let novo = await tela.repetir() {
                ambiente.navegador.substituir(por: .resultado(novo))
            }
        }
    }
}

private struct CartaoDoPar: View {
    let par: ParDeRemedios
    @State private var aberto = false

    var body: some View {
        let tom = Cores.doTom(par.tone == "neutral" ? par.level : par.tone)
        HStack(spacing: 0) {
            Rectangle().fill(tom.cor).frame(width: 6).accessibilityHidden(true)
            VStack(alignment: .leading, spacing: 10) {
                Text("\(par.a) + \(par.b)").font(Fonte.corpoForte)
                if let selo = par.badge {
                    Selo(texto: selo, cor: tom.cor, fundo: tom.fundo)
                }
                Text(par.phrase).font(Fonte.corpo).fixedSize(horizontal: false, vertical: true)
                if par.explanation != nil || !par.evidences.isEmpty || !par.limitations.isEmpty {
                    DisclosureGroup(aberto ? "Esconder detalhes" : "O que a bula diz", isExpanded: $aberto) {
                        VStack(alignment: .leading, spacing: 10) {
                            if let explicacao = par.explanation {
                                Text(explicacao).font(Fonte.corpo)
                            }
                            ForEach(par.evidences, id: \.self) { evidencia in
                                VStack(alignment: .leading, spacing: 4) {
                                    Text("\(evidencia.title) · \(evidencia.section)\(evidencia.page.map { " · p. \($0)" } ?? "")").font(.caption.weight(.semibold))
                                    if let trecho = evidencia.excerpt {
                                        Text(trecho).font(Fonte.pequena).foregroundStyle(Cores.textoSuave)
                                    }
                                }
                            }
                            ForEach(par.limitations, id: \.self) { limitacao in
                                Text("• \(limitacao)").font(Fonte.pequena).foregroundStyle(Cores.textoSuave)
                            }
                            if let fontes = par.sources {
                                Text("Base de interações: \(fontes)").font(.caption).foregroundStyle(Cores.textoSuave)
                            }
                            Text("Baseado na bula oficial, conferido automaticamente.").font(.caption).foregroundStyle(Cores.textoSuave)
                        }
                        .padding(.top, 6)
                    }
                    .font(Fonte.rotulo)
                }
            }
            .padding(14)
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(Cores.superficie)
        .clipShape(RoundedRectangle(cornerRadius: 16, style: .continuous))
        .overlay(RoundedRectangle(cornerRadius: 16, style: .continuous).strokeBorder(Cores.contorno))
    }
}
