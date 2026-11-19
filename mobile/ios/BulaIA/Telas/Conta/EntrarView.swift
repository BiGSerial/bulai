import BulaiCore
import SwiftUI

/// Sem conta conectada: Entrar, com Cadastro e Esqueci a senha empilhados.
struct ContaVisitanteView: View {
    let ambiente: Ambiente
    @State private var caminho: [TelaDeVisitante] = []

    enum TelaDeVisitante: Hashable {
        case cadastro, esqueci
    }

    var body: some View {
        NavigationStack(path: $caminho) {
            EntrarView(ambiente: ambiente) { caminho.append($0) }
                .navigationDestination(for: TelaDeVisitante.self) { tela in
                    switch tela {
                    case .cadastro: CadastroView(ambiente: ambiente) { caminho.removeAll() }
                    case .esqueci: EsqueciSenhaView(ambiente: ambiente) { caminho.removeAll() }
                    }
                }
        }
    }
}

struct EntrarView: View {
    @State private var tela: EntrarViewModel
    @FocusState private var foco: Campo?
    let abrir: (ContaVisitanteView.TelaDeVisitante) -> Void

    enum Campo { case email, senha }

    init(ambiente: Ambiente, abrir: @escaping (ContaVisitanteView.TelaDeVisitante) -> Void) {
        _tela = State(initialValue: EntrarViewModel(ambiente.dependencias))
        self.abrir = abrir
    }

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 22) {
                VStack(alignment: .leading, spacing: 8) {
                    Logo(altura: 52)
                    Text("BULA-IA — Bulas Inteligentes").font(Fonte.pequena).foregroundStyle(Cores.textoSuave)
                }
                .padding(.top, 8)
                VStack(alignment: .leading, spacing: 6) {
                    Text("Entrar").font(Fonte.tituloGrande).foregroundStyle(Cores.marca).accessibilityAddTraits(.isHeader)
                    Text("Acesse com o e-mail e a senha da sua conta.").font(Fonte.corpo).foregroundStyle(Cores.textoSuave)
                }
                if let erro = tela.formulario.erro {
                    FaixaDeErro(texto: erro)
                }
                CampoDeTexto(titulo: "E-mail", texto: $tela.email, erro: tela.formulario.campos["email"], teclado: .emailAddress, conteudo: .username, maiusculas: .never) {
                    tela.formulario.limpar("email")
                }
                .focused($foco, equals: .email)
                .submitLabel(.next)
                .onSubmit { foco = .senha }
                CampoDeSenha(titulo: "Senha", texto: $tela.senha, erro: tela.formulario.campos["password"]) {
                    tela.formulario.limpar("password")
                }
                .focused($foco, equals: .senha)
                .submitLabel(.go)
                .onSubmit { Task { await tela.entrar() } }
                BotaoPrincipal(titulo: "Entrar", carregando: tela.formulario.enviando) {
                    foco = nil
                    Task { await tela.entrar() }
                }
                Button("Esqueci minha senha") { abrir(.esqueci) }
                    .font(Fonte.rotulo).frame(maxWidth: .infinity, minHeight: 44)
                Divider()
                VStack(spacing: 10) {
                    Text("Ainda não tem conta?").font(Fonte.corpo).foregroundStyle(Cores.textoSuave)
                    BotaoSecundario(titulo: "Criar minha conta", simbolo: "person.badge.plus") { abrir(.cadastro) }
                }
                Text("O BULA-IA não substitui seu médico ou farmacêutico.")
                    .font(Fonte.pequena).foregroundStyle(Cores.textoSuave).frame(maxWidth: .infinity).multilineTextAlignment(.center)
            }
            .padding(20)
        }
        .scrollDismissesKeyboard(.interactively)
        .telaBulai()
        .toolbar(.hidden, for: .navigationBar)
    }
}

struct CadastroView: View {
    @State private var tela: CadastroViewModel
    let voltar: () -> Void

    init(ambiente: Ambiente, voltar: @escaping () -> Void) {
        _tela = State(initialValue: CadastroViewModel(api: ambiente.api))
        self.voltar = voltar
    }

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 20) {
                if let concluido = tela.concluido {
                    EstadoVazio(simbolo: "checkmark.seal.fill", titulo: "Cadastro enviado", texto: concluido, acao: ("Voltar para entrar", voltar))
                } else {
                    Text("A equipe aprova cada pedido e avisa você por e-mail.").font(Fonte.corpo).foregroundStyle(Cores.textoSuave)
                    Cartao(cor: Cores.destaqueSuave, borda: .clear) {
                        Label("Projeto acadêmico: acesso limitado", systemImage: "graduationcap.fill").font(Fonte.rotulo).foregroundStyle(Cores.naDestaqueSuave)
                        Text("O BULA-IA é um protótipo criado como Trabalho de Conclusão de Curso (TCC). Por isso, o número de acessos é limitado e cada cadastro passa pela aprovação da equipe do projeto antes do primeiro uso. Você recebe um e-mail assim que o seu acesso for liberado.")
                            .font(Fonte.corpo).foregroundStyle(Cores.texto)
                    }
                    if let erro = tela.formulario.erro {
                        FaixaDeErro(texto: erro)
                    }
                    CampoDeTexto(titulo: "Nome completo", texto: $tela.nome, erro: tela.formulario.campos["name"], conteudo: .name, maiusculas: .words) { tela.formulario.limpar("name") }
                    CampoDeTexto(titulo: "E-mail", texto: $tela.email, erro: tela.formulario.campos["email"], teclado: .emailAddress, conteudo: .username, maiusculas: .never) { tela.formulario.limpar("email") }
                    CampoDeSenha(titulo: "Senha", texto: $tela.senha, erro: tela.formulario.campos["password"], ajuda: "Pelo menos 12 caracteres, com letras e números.", nova: true) { tela.formulario.limpar("password") }
                    CampoDeSenha(titulo: "Repita a senha", texto: $tela.confirmacao, erro: tela.formulario.campos["password_confirmation"], nova: true) { tela.formulario.limpar("password_confirmation") }
                    Toggle(isOn: $tela.aceitou) {
                        Text("Li e concordo com este aviso. Entendo que o BULA-IA é um projeto acadêmico, que não substitui médico ou farmacêutico e que o meu acesso depende de aprovação.")
                            .font(Fonte.corpo).foregroundStyle(Cores.texto)
                    }
                    .toggleStyle(CaixaDeMarcar())
                    if let erro = tela.formulario.campos["terms"] {
                        Label(erro, systemImage: "exclamationmark.circle.fill").font(Fonte.pequena).foregroundStyle(Cores.erro)
                    }
                    BotaoPrincipal(titulo: "Enviar cadastro", carregando: tela.formulario.enviando) {
                        Task { await tela.cadastrar() }
                    }
                }
            }
            .padding(20)
        }
        .scrollDismissesKeyboard(.interactively)
        .telaBulai()
        .navigationTitle("Criar minha conta")
        .navigationBarTitleDisplayMode(.inline)
    }
}

struct EsqueciSenhaView: View {
    @State private var tela: EsqueciSenhaViewModel
    let voltar: () -> Void

    init(ambiente: Ambiente, voltar: @escaping () -> Void) {
        _tela = State(initialValue: EsqueciSenhaViewModel(api: ambiente.api))
        self.voltar = voltar
    }

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 20) {
                if let enviado = tela.enviado {
                    EstadoVazio(simbolo: "envelope.badge.fill", titulo: "Confira o seu e-mail", texto: "\(enviado) Abra o link no celular ou no computador.", acao: ("Voltar para entrar", voltar))
                } else {
                    Text("Enviamos um link para você criar uma senha nova.").font(Fonte.corpo).foregroundStyle(Cores.textoSuave)
                    if let erro = tela.formulario.erro {
                        FaixaDeErro(texto: erro)
                    }
                    CampoDeTexto(titulo: "E-mail", texto: $tela.email, erro: tela.formulario.campos["email"], teclado: .emailAddress, conteudo: .username, maiusculas: .never) { tela.formulario.limpar("email") }
                    BotaoPrincipal(titulo: "Enviar o link", carregando: tela.formulario.enviando) {
                        Task { await tela.pedir() }
                    }
                }
            }
            .padding(20)
        }
        .telaBulai()
        .navigationTitle("Esqueci minha senha")
        .navigationBarTitleDisplayMode(.inline)
    }
}

/// Senha provisória: só esta tela até a troca (as outras rotas respondem 403 no servidor).
struct PrimeiroAcessoView: View {
    @State private var tela: PrimeiroAcessoViewModel
    let ambiente: Ambiente
    let usuario: Usuario

    init(ambiente: Ambiente, usuario: Usuario) {
        _tela = State(initialValue: PrimeiroAcessoViewModel(ambiente.dependencias))
        self.ambiente = ambiente
        self.usuario = usuario
    }

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(alignment: .leading, spacing: 20) {
                    Logo(altura: 44)
                    Text("Olá, \(usuario.primeiroNome)").font(Fonte.tituloGrande).foregroundStyle(Cores.marca).accessibilityAddTraits(.isHeader)
                    Text("Você entrou com uma senha provisória. Crie a sua senha para continuar.").font(Fonte.corpo).foregroundStyle(Cores.textoSuave)
                    if let erro = tela.formulario.erro {
                        FaixaDeErro(texto: erro)
                    }
                    CampoDeSenha(titulo: "Nova senha", texto: $tela.senha, erro: tela.formulario.campos["password"], ajuda: "Pelo menos 12 caracteres, com letras e números.", nova: true) { tela.formulario.limpar("password") }
                    CampoDeSenha(titulo: "Repita a nova senha", texto: $tela.confirmacao, erro: tela.formulario.campos["password_confirmation"], nova: true) { tela.formulario.limpar("password_confirmation") }
                    BotaoPrincipal(titulo: "Salvar e continuar", carregando: tela.formulario.enviando) {
                        Task { await tela.salvar() }
                    }
                    Button("Sair e entrar com outra conta") {
                        Task { await ContaViewModel(ambiente.dependencias).sair() }
                    }
                    .font(Fonte.rotulo).frame(maxWidth: .infinity, minHeight: 44)
                }
                .padding(20)
            }
            .telaBulai()
        }
    }
}

/// Caixa de marcar grande (o `Toggle` padrão do iOS é um interruptor; aqui é um aceite).
struct CaixaDeMarcar: ToggleStyle {
    func makeBody(configuration: Configuration) -> some View {
        Button {
            configuration.isOn.toggle()
        } label: {
            HStack(alignment: .top, spacing: 12) {
                Image(systemName: configuration.isOn ? "checkmark.square.fill" : "square")
                    .font(.title2)
                    .foregroundStyle(configuration.isOn ? Cores.marca : Cores.textoSuave)
                configuration.label
                Spacer(minLength: 0)
            }
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
        .accessibilityAddTraits(configuration.isOn ? [.isSelected] : [])
    }
}
