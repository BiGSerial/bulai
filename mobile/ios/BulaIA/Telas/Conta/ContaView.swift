import BulaiCore
import QuickLook
import SwiftUI
import UIKit

/// Aba Conta: nome de chamada, aparência, lembretes (conta e este iPhone), senha, atalhos, resumo em PDF, sair e
/// apagar a conta. As mesmas seções do Android, em lista agrupada do iOS.
struct ContaView: View {
    let ambiente: Ambiente
    @State private var tela: ContaViewModel
    @State private var resumo: ResumoViewModel
    @State private var senhaAberta = false
    @State private var confirmarSaida = false
    @State private var apagando = false
    @State private var notificacoes: SituacaoDasNotificacoes = .desconhecida
    @Environment(\.scenePhase) private var fase

    enum SituacaoDasNotificacoes { case desconhecida, permitidas, negadas, naoPedidas }

    init(ambiente: Ambiente) {
        self.ambiente = ambiente
        _tela = State(initialValue: ContaViewModel(ambiente.dependencias))
        _resumo = State(initialValue: ResumoViewModel(api: ambiente.api))
    }

    var body: some View {
        List {
            Section {
                VStack(alignment: .leading, spacing: 4) {
                    Text("Olá, \(tela.usuario?.primeiroNome ?? "")!").font(Fonte.titulo).foregroundStyle(Cores.marca).accessibilityAddTraits(.isHeader)
                    Text(tela.usuario?.email ?? "").font(Fonte.pequena).foregroundStyle(Cores.textoSuave)
                }
                .padding(.vertical, 6)
            }

            Section {
                CampoDeTexto(titulo: "Nome de chamada", texto: $tela.nomeDeChamada, erro: tela.formularioDoNome.campos["preferred_name"], ajuda: "Deixe vazio para usar “\(primeiroNomeDoCadastro)”.", conteudo: .givenName, maiusculas: .words) {
                    tela.formularioDoNome.limpar("preferred_name")
                }
                Button {
                    Task { await tela.salvarNome() }
                } label: {
                    HStack {
                        Text("Salvar nome")
                        if tela.formularioDoNome.enviando {
                            ProgressView()
                        }
                    }
                }
                .frame(minHeight: 44)
            } header: {
                Text("Como quer ser chamado(a)?")
            } footer: {
                Text("É assim que o BULA-IA cumprimenta você no Início e nos avisos.")
            }

            Section("Aparência") {
                Picker("Aparência", selection: $tela.tema) {
                    ForEach(Tema.allCases) { tema in
                        Text(tema.nome).tag(tema)
                    }
                }
                .pickerStyle(.inline)
                .labelsHidden()
                .onChange(of: tela.tema) { _, novo in ambiente.tema = novo }
            }

            secaoLembretes
            secaoLembretesDoAparelho

            Section("Seus dados") {
                LinhaDeNavegacao(simbolo: "pills.fill", titulo: "Meus remédios", texto: "Dose, horários, receitas, alergias e autorização dos dados de saúde") { ambiente.navegador.abrir(.perfil(aba: 0)) }
                LinhaDeNavegacao(simbolo: "text.bubble.fill", titulo: "Minhas perguntas", texto: "O que você perguntou ao BULA-IA") { ambiente.navegador.abrir(.perguntas) }
                Button {
                    Task { await resumo.baixar() }
                } label: {
                    LinhaDeConteudo(simbolo: "doc.richtext.fill", titulo: "Resumo para o médico", texto: resumo.baixando ? "Preparando o PDF…" : "PDF com seus remédios e horários, para levar à consulta", carregando: resumo.baixando)
                }
                .disabled(resumo.baixando)
                if let erro = resumo.erro {
                    Text(erro).font(Fonte.pequena).foregroundStyle(Cores.erro)
                }
            }

            Section("Cuidadores") {
                LinhaDeNavegacao(simbolo: "person.2.fill", titulo: "Quem cuida de mim", texto: "Convide alguém pelo QR Code e escolha o que pode ver") { ambiente.navegador.abrir(.cuidadores) }
                LinhaDeNavegacao(simbolo: "qrcode.viewfinder", titulo: "Cuidar de alguém", texto: "Leia o QR Code e acompanhe os remédios da pessoa") { ambiente.navegador.abrir(.cuidar) }
            }

            Section("Senha") {
                DisclosureGroup(isExpanded: $senhaAberta) {
                    VStack(alignment: .leading, spacing: 14) {
                        if let erro = tela.formularioDaSenha.erro {
                            FaixaDeErro(texto: erro)
                        }
                        CampoDeSenha(titulo: "Senha atual", texto: $tela.senhaAtual, erro: tela.formularioDaSenha.campos["current_password"]) { tela.formularioDaSenha.limpar("current_password") }
                        CampoDeSenha(titulo: "Nova senha", texto: $tela.novaSenha, erro: tela.formularioDaSenha.campos["password"], ajuda: "Pelo menos 8 caracteres, diferente da atual.", nova: true) { tela.formularioDaSenha.limpar("password") }
                        CampoDeSenha(titulo: "Repita a nova senha", texto: $tela.confirmacao, erro: tela.formularioDaSenha.campos["password_confirmation"], nova: true) { tela.formularioDaSenha.limpar("password_confirmation") }
                        Text("Ao trocar, as outras sessões abertas com a sua conta são encerradas; este iPhone continua conectado.").font(Fonte.pequena).foregroundStyle(Cores.textoSuave)
                        BotaoPrincipal(titulo: "Trocar senha", carregando: tela.formularioDaSenha.enviando) {
                            Task {
                                if await tela.trocarSenha() {
                                    senhaAberta = false
                                }
                            }
                        }
                    }
                    .padding(.vertical, 8)
                } label: {
                    Label("Trocar a senha de acesso", systemImage: "key.fill")
                }
            }

            Section {
                Button {
                    confirmarSaida = true
                } label: {
                    LinhaDeConteudo(simbolo: "rectangle.portrait.and.arrow.right", titulo: "Sair da conta", texto: "Os lembretes deixam de aparecer neste iPhone")
                }
                Button(role: .destructive) {
                    apagando = true
                } label: {
                    LinhaDeConteudo(simbolo: "trash.fill", titulo: "Apagar minha conta", texto: "Remove seus dados pessoais e de saúde", cor: Cores.erro)
                }
            } footer: {
                Text("BULA-IA \(ambiente.configuracao.versao) (\(ambiente.configuracao.build)) · não substitui seu médico ou farmacêutico.")
                    .frame(maxWidth: .infinity).multilineTextAlignment(.center).padding(.top, 12)
            }
        }
        .scrollContentBackground(.hidden)
        .background(Cores.fundo.ignoresSafeArea())
        .refreshable { await tela.carregar() }
        .task { await tela.carregar() }
        .task { await lerNotificacoes() }
        .onChange(of: fase) { _, nova in
            if nova == .active {
                Task { await lerNotificacoes() }
                tela.lerProximoLembrete()
            }
        }
        .recado($tela.recado)
        .quickLookPreview($resumo.arquivo)
        .confirmationDialog("Sair da conta?", isPresented: $confirmarSaida, titleVisibility: .visible) {
            Button("Sair", role: .destructive) { Task { await tela.sair() } }
            Button("Cancelar", role: .cancel) {}
        } message: {
            Text("Os lembretes dos remédios deixam de aparecer neste iPhone até você entrar de novo.")
        }
        .sheet(isPresented: $apagando) {
            ApagarContaView(tela: tela)
        }
    }

    private var primeiroNomeDoCadastro: String {
        tela.usuario?.name.split(separator: " ").first.map(String.init) ?? ""
    }

    @ViewBuilder
    private var secaoLembretes: some View {
        Section {
            if let central = tela.central.dados {
                if central.consentimento {
                    Toggle(isOn: Binding(get: { central.preferences.reminders }, set: { novo in Task { await tela.lembretes(novo) } })) {
                        VStack(alignment: .leading, spacing: 2) {
                            Text("Lembrar nos horários dos remédios").font(Fonte.corpo)
                            Text("O iPhone avisa em cada horário.").font(Fonte.pequena).foregroundStyle(Cores.textoSuave)
                        }
                    }
                    .frame(minHeight: 48)
                    Toggle("Receber também por e-mail", isOn: Binding(get: { central.preferences.email }, set: { novo in Task { await tela.email(novo) } }))
                        .frame(minHeight: 48)
                    if !central.timezones.isEmpty {
                        Picker("Fuso horário", selection: Binding(get: { central.preferences.timezone }, set: { novo in Task { await tela.fuso(novo) } })) {
                            ForEach(central.timezones) { fuso in
                                Text(fuso.label).tag(fuso.id)
                            }
                        }
                        .pickerStyle(.navigationLink)
                    }
                } else {
                    Text("Para receber lembretes dos remédios, autorize guardar seus dados de saúde no perfil.").font(Fonte.corpo)
                    Button("Abrir Meus remédios") { ambiente.navegador.abrir(.perfil(aba: 0)) }
                }
            } else if let erro = tela.central.erro {
                FaixaDeErro(texto: erro) { Task { await tela.carregar() } }
            } else {
                Esqueleto(altura: 44)
            }
        } header: {
            Text("Lembretes")
        }
    }

    private var secaoLembretesDoAparelho: some View {
        Section {
            HStack(spacing: 12) {
                Image(systemName: notificacoes == .permitidas ? "checkmark.circle.fill" : "exclamationmark.triangle.fill")
                    .foregroundStyle(notificacoes == .permitidas ? Cores.sucesso : Cores.destaque)
                    .font(.title3)
                    .accessibilityHidden(true)
                VStack(alignment: .leading, spacing: 2) {
                    Text("Notificações").font(Fonte.corpo)
                    Text(notificacoes == .permitidas ? "Liberado" : "Falta liberar").font(Fonte.pequena).foregroundStyle(Cores.textoSuave)
                }
                Spacer()
                if notificacoes == .negadas {
                    Button("Liberar") { abrirAjustesDasNotificacoes() }.buttonStyle(.borderedProminent)
                } else if notificacoes == .naoPedidas {
                    Button("Liberar") {
                        Task {
                            await ambiente.notificacoes.pedirPermissao()
                            await lerNotificacoes()
                            await ambiente.dependencias.sincronizador.reagendar()
                        }
                    }
                    .buttonStyle(.borderedProminent)
                }
            }
            .frame(minHeight: 48)
            .accessibilityElement(children: .combine)

            Toggle(isOn: $tela.insistir) {
                VStack(alignment: .leading, spacing: 2) {
                    Text("Insistir até eu marcar").font(Fonte.corpo)
                    Text("Repete o lembrete duas vezes, no intervalo de “Adiar”, enquanto a dose não for anotada.").font(Fonte.pequena).foregroundStyle(Cores.textoSuave)
                }
            }
            .frame(minHeight: 48)

            Picker("Adiar por", selection: $tela.adiarMinutos) {
                ForEach(ContaViewModel.opcoesDeAdiamento, id: \.self) { minutos in
                    Text("\(minutos) min").tag(minutos)
                }
            }

            if let proximo = tela.proximoLembrete {
                Label("Próximo lembrete: \(proximo)", systemImage: "alarm").font(Fonte.pequena)
            } else {
                Label("Nenhum lembrete marcado agora.", systemImage: "alarm").font(Fonte.pequena).foregroundStyle(Cores.textoSuave)
            }

            Button {
                Task { await tela.testarLembrete() }
            } label: {
                Label("Testar o lembrete agora", systemImage: "bell.and.waves.left.and.right")
            }
            .frame(minHeight: 44)
        } header: {
            Text("Lembretes neste iPhone")
        } footer: {
            Text("No iPhone, o lembrete é uma notificação na hora da dose, com Tomei, Adiar e Pular, mesmo sem internet. O modo Silencioso e o Foco podem tirar o som: para não perder, deixe o BULA-IA liberado no Foco.")
        }
    }

    private func lerNotificacoes() async {
        if await ambiente.notificacoes.permitidas() {
            notificacoes = .permitidas
        } else if await ambiente.notificacoes.negadas() {
            notificacoes = .negadas
        } else {
            notificacoes = .naoPedidas
        }
    }

    private func abrirAjustesDasNotificacoes() {
        if let url = URL(string: UIApplication.openNotificationSettingsURLString) {
            UIApplication.shared.open(url)
        }
    }
}

/// Linha de lista com ícone, título e explicação, que abre outra tela.
struct LinhaDeNavegacao: View {
    var simbolo: String
    var titulo: String
    var texto: String
    var acao: () -> Void

    var body: some View {
        Button(action: acao) {
            HStack {
                LinhaDeConteudo(simbolo: simbolo, titulo: titulo, texto: texto)
                Image(systemName: "chevron.right").font(.footnote.weight(.semibold)).foregroundStyle(Cores.textoSuave).accessibilityHidden(true)
            }
        }
        .foregroundStyle(Cores.texto)
    }
}

struct LinhaDeConteudo: View {
    var simbolo: String
    var titulo: String
    var texto: String
    var cor: Color = Cores.marca
    var carregando = false

    var body: some View {
        HStack(spacing: 14) {
            Group {
                if carregando {
                    ProgressView()
                } else {
                    Image(systemName: simbolo).font(.title3).foregroundStyle(cor)
                }
            }
            .frame(width: 32)
            .accessibilityHidden(true)
            VStack(alignment: .leading, spacing: 2) {
                Text(titulo).font(Fonte.corpoForte).foregroundStyle(cor == Cores.erro ? Cores.erro : Cores.texto)
                Text(texto).font(Fonte.pequena).foregroundStyle(Cores.textoSuave)
            }
            Spacer(minLength: 0)
        }
        .frame(minHeight: 52)
        .contentShape(Rectangle())
    }
}

/// Apagar a conta (LGPD): o que será apagado, senha atual e botão vermelho.
struct ApagarContaView: View {
    @Bindable var tela: ContaViewModel
    @Environment(\.dismiss) private var fechar

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(alignment: .leading, spacing: 16) {
                    Text("Sua conta e tudo o que você guardou no BULA-IA serão apagados deste aparelho e dos nossos servidores.").font(Fonte.corpo)
                    Cartao(cor: Cores.erroFundo, borda: .clear) {
                        Text("O que será apagado").font(Fonte.rotulo).foregroundStyle(Cores.erro)
                        ForEach(["Remédios, horários e doses anotadas", "Receitas, alergias e perfil de saúde", "Perguntas e consultas de interação", "Avisos, cuidadores e aparelhos conectados", "Nome, nome de chamada e e-mail"], id: \.self) { item in
                            Label(item, systemImage: "minus.circle.fill").font(Fonte.corpo).foregroundStyle(Cores.texto)
                        }
                    }
                    Text("Fica apenas o registro de que uma conta foi apagada, sem nome, e-mail ou dados de saúde. Os lembretes param neste iPhone e, para voltar, basta fazer um novo cadastro.").font(Fonte.pequena).foregroundStyle(Cores.textoSuave)
                    Text("Não dá para desfazer.").font(Fonte.corpoForte).foregroundStyle(Cores.erro)
                    if let erro = tela.formularioDeApagar.erro {
                        FaixaDeErro(texto: erro)
                    }
                    CampoDeSenha(titulo: "Confirme com sua senha", texto: $tela.senhaParaApagar, erro: tela.formularioDeApagar.campos["password"]) { tela.formularioDeApagar.limpar("password") }
                    BotaoPrincipal(titulo: "Apagar minha conta", simbolo: "trash.fill", carregando: tela.formularioDeApagar.enviando, desabilitado: tela.senhaParaApagar.isEmpty, cor: Cores.erro, corDoTexto: .white) {
                        Task { await tela.apagarConta() }
                    }
                }
                .padding(20)
            }
            .telaBulai()
            .navigationTitle("Apagar sua conta?")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancelar") { fechar() }
                }
            }
        }
        .presentationDetents([.large])
    }
}
