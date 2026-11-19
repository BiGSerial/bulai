import BulaiCore
import SwiftUI

/// "Quem cuida de mim": gerar o QR Code do convite, aprovar/recusar pedidos com as permissões marcadas, mudar e tirar
/// o acesso. As permissões valem no servidor (`CaregiverLinkPolicy`); aqui só se marca o que cada pessoa pode fazer.
struct QuemCuidaDeMimView: View {
    let ambiente: Ambiente
    @State private var tela: QuemCuidaDeMimViewModel
    @State private var tirando: Cuidador?

    init(ambiente: Ambiente) {
        self.ambiente = ambiente
        _tela = State(initialValue: QuemCuidaDeMimViewModel(api: ambiente.api))
    }

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 18) {
                if let erro = tela.carga.erro {
                    FaixaDeErro(texto: erro) { Task { await tela.carregar() } }
                }
                if let dados = tela.carga.dados {
                    if !dados.consentimento {
                        Cartao(cor: Cores.destaqueSuave, borda: .clear) {
                            Text("Autorize no seu perfil").font(Fonte.subtitulo)
                            Text("Para alguém acompanhar seus remédios, primeiro autorize guardar seus dados de saúde no perfil.").font(Fonte.corpo)
                            BotaoPrincipal(titulo: "Abrir Meus remédios") { ambiente.navegador.abrir(.perfil(aba: 0)) }
                        }
                    } else {
                        Text("Quem cuida de você pode ver seus remédios, receber aviso quando uma dose atrasar e anotar as doses. Você escolhe o que cada pessoa pode fazer.").font(Fonte.corpo).foregroundStyle(Cores.textoSuave)
                        convite(dados)
                        if !dados.pedidos.isEmpty {
                            TituloDeSecao(texto: "Pedindo para acompanhar você")
                            ForEach(dados.pedidos) { pessoa in
                                CartaoDoCuidador(pessoa: pessoa, rotulos: dados.rotulos, pedido: true, ocupado: tela.ocupado == "\(pessoa.id)") { permissoes in
                                    Task { await tela.aprovar(pessoa, permissoes: permissoes) }
                                } recusarOuTirar: {
                                    Task { await tela.recusar(pessoa) }
                                }
                            }
                        }
                        TituloDeSecao(texto: "Acompanham você")
                        if dados.ativos.isEmpty {
                            Text("Ninguém acompanha você ainda.").font(Fonte.corpo).foregroundStyle(Cores.textoSuave)
                        }
                        ForEach(dados.ativos) { pessoa in
                            CartaoDoCuidador(pessoa: pessoa, rotulos: dados.rotulos, pedido: false, ocupado: tela.ocupado == "\(pessoa.id)") { permissoes in
                                Task { await tela.mudarPermissoes(pessoa, permissoes: permissoes) }
                            } recusarOuTirar: {
                                tirando = pessoa
                            }
                        }
                        if !dados.encerrados.isEmpty {
                            TituloDeSecao(texto: "Já acompanharam você")
                            ForEach(dados.encerrados) { antigo in
                                Text("\(antigo.name) · \(antigo.status == "LEFT" ? "deixou de acompanhar" : "acesso retirado")\(antigo.terminouEm.map { " em \(Datas.curta($0))" } ?? "")")
                                    .font(Fonte.pequena).foregroundStyle(Cores.textoSuave)
                            }
                        }
                    }
                } else if tela.carga.esqueleto {
                    EsqueletoDeLista(cartoes: 2)
                }
            }
            .padding(16)
        }
        .telaBulai()
        .refreshable { await tela.carregar() }
        .task { await tela.carregar() }
        .recado($tela.recado)
        .confirmationDialog(tirando.map { "Tirar o acesso de \($0.primeiroNome)?" } ?? "", isPresented: Binding(get: { tirando != nil }, set: { if !$0 { tirando = nil } }), titleVisibility: .visible) {
            Button("Tirar acesso", role: .destructive) {
                if let pessoa = tirando {
                    Task { await tela.tirarAcesso(pessoa) }
                }
                tirando = nil
            }
            Button("Cancelar", role: .cancel) { tirando = nil }
        } message: {
            Text(tirando.map { "\($0.primeiroNome) deixa de ver seus remédios e de receber avisos na hora. Para voltar, será preciso um convite novo." } ?? "")
        }
    }

    @ViewBuilder
    private func convite(_ dados: MeusCuidadores) -> some View {
        Cartao {
            Label("Convidar quem cuida de você", systemImage: "qrcode").font(Fonte.subtitulo).foregroundStyle(Cores.marca)
            if let convite = dados.convite {
                Text("Peça para a pessoa abrir o BULA-IA em Conta › Cuidar de alguém e tocar em Ler QR Code.").font(Fonte.corpo)
                if let imagem = GeradorDeQR.imagem(convite.url) {
                    Image(uiImage: imagem).interpolation(.none).resizable().scaledToFit()
                        .frame(maxWidth: 260).padding(14)
                        .background(.white, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
                        .frame(maxWidth: .infinity)
                        .accessibilityLabel("QR Code do convite")
                }
                Text("Ou digite o código \(convite.code)").font(.title3.monospacedDigit().weight(.bold)).frame(maxWidth: .infinity)
                    .accessibilityLabel("Código do convite: \(convite.code.map(String.init).joined(separator: " "))")
                Text("Vale uma vez, até \(Datas.curta(convite.venceEm)).").font(Fonte.pequena).foregroundStyle(Cores.textoSuave).frame(maxWidth: .infinity)
                ShareLink(item: QuemCuidaDeMimViewModel.textoDoConvite(convite), subject: Text("Convite do BULA-IA")) {
                    Label("Enviar o link do convite", systemImage: "square.and.arrow.up").font(Fonte.rotulo).frame(maxWidth: .infinity, minHeight: 52)
                        .foregroundStyle(Cores.marca)
                        .overlay(RoundedRectangle(cornerRadius: 16, style: .continuous).strokeBorder(Cores.marca.opacity(0.45), lineWidth: 2))
                }
                Button("Cancelar convite", role: .destructive) { Task { await tela.cancelarConvite() } }
                    .frame(maxWidth: .infinity, minHeight: 44)
            } else {
                if dados.conviteVencido {
                    Text("O último convite venceu. Gere um novo QR Code.").font(Fonte.corpo).foregroundStyle(Cores.naDestaqueSuave)
                }
                Text("Gere um QR Code e mostre para a pessoa. Ela abre o BULA-IA no celular dela, em Conta › Cuidar de alguém, e lê o código.").font(Fonte.corpo)
                BotaoPrincipal(titulo: "Gerar QR Code", simbolo: "qrcode", carregando: tela.ocupado == "convite") {
                    Task { await tela.gerarConvite() }
                }
            }
        }
    }
}

/// Pedido ou cuidador ativo com as três permissões em caixas de marcar.
private struct CartaoDoCuidador: View {
    let pessoa: Cuidador
    let rotulos: [RotuloDePermissao]
    let pedido: Bool
    let ocupado: Bool
    let salvar: ([String: Bool]) -> Void
    let recusarOuTirar: () -> Void
    @State private var permissoes: [String: Bool] = [:]

    var body: some View {
        Cartao {
            VStack(alignment: .leading, spacing: 2) {
                Text(pessoa.name).font(Fonte.corpoForte)
                if let email = pessoa.email {
                    Text(email).font(.caption).foregroundStyle(Cores.textoSuave)
                }
                if let quando = pedido ? pessoa.pediuEm : pessoa.desde {
                    Text(pedido ? "Pediu em \(Datas.curta(quando))" : "Desde \(Datas.curta(quando))").font(.caption).foregroundStyle(Cores.textoSuave)
                }
            }
            Text(pedido ? "Marque o que \(pessoa.primeiroNome) pode fazer:" : "\(pessoa.primeiroNome) pode:").font(Fonte.pequena.weight(.semibold))
            ForEach(rotulos) { rotulo in
                Toggle(isOn: Binding(get: { permissoes[rotulo.key] ?? false }, set: { permissoes[rotulo.key] = $0 })) {
                    Text(rotulo.label).font(Fonte.corpo)
                }
                .toggleStyle(CaixaDeMarcar())
            }
            HStack(spacing: 10) {
                BotaoPrincipal(titulo: pedido ? "Aprovar" : "Salvar permissões", carregando: ocupado, desabilitado: !pedido && permissoes == pessoa.permissoes) {
                    salvar(permissoes)
                }
                BotaoSecundario(titulo: pedido ? "Recusar" : "Tirar acesso", cor: Cores.erro, acao: recusarOuTirar)
            }
        }
        .onAppear {
            // Pedido novo: as três vêm marcadas; a pessoa desmarca o que não quer.
            permissoes = pedido ? Dictionary(uniqueKeysWithValues: rotulos.map { ($0.key, true) }) : pessoa.permissoes
        }
        .onChange(of: pessoa.permissoes) { _, novas in
            if !pedido {
                permissoes = novas
            }
        }
    }
}

/// "Cuidar de alguém": as pessoas que acompanho (tocar abre o dia dela), ler o QR Code ou digitar o código.
struct CuidarDeAlguemView: View {
    let ambiente: Ambiente
    @State private var tela: CuidarDeAlguemViewModel
    @State private var lendo = false
    @State private var digitando = false
    @State private var codigo = ""
    @State private var saindo: PessoaAcompanhada?

    init(ambiente: Ambiente) {
        self.ambiente = ambiente
        _tela = State(initialValue: CuidarDeAlguemViewModel(ambiente.dependencias))
    }

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 18) {
                if let erro = tela.carga.erro {
                    FaixaDeErro(texto: erro) { Task { await tela.carregar() } }
                }
                TituloDeSecao(texto: "Pessoas que você acompanha")
                if let dados = tela.carga.dados {
                    if dados.pessoas.isEmpty, dados.aguardando.isEmpty {
                        Text("Você ainda não acompanha ninguém. Peça para a pessoa gerar o QR Code em Conta › Quem cuida de mim, no BULA-IA dela.").font(Fonte.corpo).foregroundStyle(Cores.textoSuave)
                    }
                    ForEach(dados.pessoas) { pessoa in
                        pessoaAtiva(pessoa)
                    }
                    ForEach(dados.aguardando) { pessoa in
                        Cartao(cor: Cores.superficieVariante, borda: .clear) {
                            Text(pessoa.name).font(Fonte.corpoForte)
                            Selo(texto: "Esperando aprovação", cor: Cores.naDestaqueSuave, fundo: Cores.destaqueSuave)
                            Text("Assim que a pessoa aprovar, os remédios dela aparecem aqui.").font(Fonte.pequena).foregroundStyle(Cores.textoSuave)
                            Button("Desistir", role: .destructive) { saindo = pessoa }.frame(minHeight: 44)
                        }
                    }
                } else if tela.carga.esqueleto {
                    EsqueletoDeLista(cartoes: 2)
                }
                Cartao {
                    Label("Acompanhar mais alguém", systemImage: "person.badge.plus").font(Fonte.subtitulo).foregroundStyle(Cores.marca)
                    Text("A pessoa gera o QR Code no BULA-IA dela, em Conta › Quem cuida de mim. Depois de ler, ela aprova o que você pode ver e fazer.").font(Fonte.corpo)
                    if LeitorDeQR.disponivel {
                        BotaoPrincipal(titulo: "Ler QR Code", simbolo: "qrcode.viewfinder", carregando: tela.enviando && !digitando) { lendo = true }
                    }
                    if digitando || !LeitorDeQR.disponivel {
                        CampoDeTexto(titulo: "Código do convite (8 números)", texto: $codigo, erro: tela.erroDoCodigo, teclado: .numberPad) { tela.erroDoCodigo = nil }
                        BotaoSecundario(titulo: "Usar código", simbolo: "checkmark", carregando: tela.enviando) {
                            Task {
                                if await tela.usarConvite(codigo) {
                                    codigo = ""
                                    digitando = false
                                }
                            }
                        }
                    } else {
                        BotaoSecundario(titulo: "Digitar o código", simbolo: "keyboard") { digitando = true }
                        if let erro = tela.erroDoCodigo {
                            Label(erro, systemImage: "exclamationmark.circle.fill").font(Fonte.pequena).foregroundStyle(Cores.erro)
                        }
                    }
                    if PermissaoDaCamera.negada {
                        Text("A câmera está bloqueada para o BULA-IA. Libere nos Ajustes do iPhone ou digite o código.").font(.caption).foregroundStyle(Cores.textoSuave)
                    }
                }
            }
            .padding(16)
        }
        .telaBulai()
        .refreshable { await tela.carregar() }
        .task { await tela.carregar() }
        .recado($tela.recado)
        .fullScreenCover(isPresented: $lendo) {
            NavigationStack {
                LeitorDeQR { texto in
                    lendo = false
                    Task { await tela.usarConvite(texto) }
                }
                .ignoresSafeArea()
                .navigationTitle("Ler QR Code")
                .navigationBarTitleDisplayMode(.inline)
                .toolbar {
                    ToolbarItem(placement: .cancellationAction) { Button("Fechar") { lendo = false } }
                    ToolbarItem(placement: .bottomBar) {
                        Button("Digitar o código") {
                            lendo = false
                            digitando = true
                        }
                    }
                }
            }
        }
        .confirmationDialog(saindo.map { "Deixar de acompanhar \($0.primeiroNome)?" } ?? "", isPresented: Binding(get: { saindo != nil }, set: { if !$0 { saindo = nil } }), titleVisibility: .visible) {
            Button("Deixar de acompanhar", role: .destructive) {
                if let pessoa = saindo {
                    Task { await tela.deixarDeAcompanhar(pessoa) }
                }
                saindo = nil
            }
            Button("Cancelar", role: .cancel) { saindo = nil }
        } message: {
            Text(saindo.map { "Você deixa de ver os remédios e de receber os avisos de \($0.primeiroNome). Para voltar, será preciso um convite novo." } ?? "")
        }
    }

    private func pessoaAtiva(_ pessoa: PessoaAcompanhada) -> some View {
        Cartao {
            Button {
                ambiente.navegador.abrir(.cuidando(pessoa.id, nome: pessoa.primeiroNome))
            } label: {
                HStack(spacing: 12) {
                    Image(systemName: "person.crop.circle.fill").font(.largeTitle).foregroundStyle(Cores.marca).accessibilityHidden(true)
                    VStack(alignment: .leading, spacing: 2) {
                        Text(pessoa.name).font(Fonte.corpoForte).foregroundStyle(Cores.texto)
                        Text(pessoa.acesso).font(Fonte.pequena).foregroundStyle(Cores.textoSuave)
                    }
                    Spacer()
                    Image(systemName: "chevron.right").foregroundStyle(Cores.textoSuave).accessibilityHidden(true)
                }
            }
            .buttonStyle(.plain)
            .disabled(!pessoa.podeVer)
            ForEach(pessoa.frases, id: \.self) { frase in
                Text("• \(frase)").font(.caption).foregroundStyle(Cores.textoSuave)
            }
            if !pessoa.consentimento {
                Text("\(pessoa.primeiroNome) ainda não autorizou guardar os dados de saúde no BULA-IA.").font(.caption).foregroundStyle(Cores.naDestaqueSuave)
            }
            Button("Deixar de acompanhar", role: .destructive) { saindo = pessoa }.font(Fonte.pequena).frame(minHeight: 44)
        }
    }
}

/// Remédios de quem a pessoa cuida: com a permissão "marcar e editar", cada remédio abre "Dose e horários".
struct RemediosDeQuemCuidoView: View {
    let ambiente: Ambiente
    @State private var tela: RemediosDeQuemCuidoViewModel

    init(ambiente: Ambiente, vinculo: Int64) {
        self.ambiente = ambiente
        _tela = State(initialValue: RemediosDeQuemCuidoViewModel(api: ambiente.api, vinculo: vinculo))
    }

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 16) {
                if let erro = tela.carga.erro {
                    FaixaDeErro(texto: erro) { Task { await tela.carregar() } }
                }
                if let dados = tela.carga.dados {
                    Text("Remédios de \(dados.cuidandoDe.primeiroNome)").font(Fonte.titulo).foregroundStyle(Cores.marca).accessibilityAddTraits(.isHeader)
                    if !dados.intro.isEmpty {
                        Text(dados.intro).font(Fonte.corpo).foregroundStyle(Cores.textoSuave)
                    }
                    if dados.remedios.isEmpty {
                        Text("\(dados.cuidandoDe.primeiroNome) ainda não tem remédios cadastrados.").font(Fonte.corpo).foregroundStyle(Cores.textoSuave)
                    }
                    ForEach(dados.remedios) { remedio in
                        let editar: (() -> Void)? = dados.podeEditar ? { tela.editando = remedio } : nil
                        let abrirBula: (() -> Void)? = remedio.remedioDoCatalogo.map { id in { ambiente.navegador.abrir(.bula(id)) } }
                        CartaoDoRemedio(remedio: remedio, horarios: editar, bula: abrirBula)
                    }
                } else if tela.carga.esqueleto {
                    EsqueletoDeLista(cartoes: 3)
                }
            }
            .padding(16)
        }
        .telaBulai()
        .refreshable { await tela.carregar() }
        .task { await tela.carregar() }
        .recado($tela.recado)
        .sheet(item: $tela.editando) { remedio in
            PosologiaView(
                nome: remedio.name,
                inicial: remedio.posologia,
                atalhos: tela.carga.dados?.atalhos ?? AtalhosDePosologia(),
                receitas: [],
                salvando: tela.salvando,
                erro: tela.erro,
                sugerir: { frequencia, posologia, atalho in await tela.sugerir(frequencia: frequencia, posologia: posologia, atalho: atalho) },
                aoSalvar: { posologia in Task { await tela.salvar(posologia) } }
            )
        }
    }
}
