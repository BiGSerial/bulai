import BulaiCore
import SwiftUI

/// Sessão → telas: Entrar (visitante), troca da senha provisória ou o app com o menu inferior.
struct RaizView: View {
    @Environment(Ambiente.self) private var ambiente
    @Environment(\.accessibilityReduceMotion) private var reduzirMovimento
    @State private var abertura = true

    var body: some View {
        ZStack {
            switch ambiente.sessao.estado {
            case .visitante:
                ContaVisitanteView(ambiente: ambiente)
                    .transition(.opacity)
            case let .trocarSenha(usuario):
                PrimeiroAcessoView(ambiente: ambiente, usuario: usuario)
                    .transition(.opacity)
            case .conectado:
                AbasView()
                    .transition(.opacity)
                    .task { await ambiente.pedirNotificacoesSePreciso() }
            }
            if abertura, VideoDeAbertura.deveTocar(reduzirMovimento: reduzirMovimento) {
                VideoDeAbertura { abertura = false }
                    .zIndex(1)
            }
        }
        .animation(.easeInOut(duration: 0.25), value: estadoResumido)
        .onOpenURL { _ in abertura = false }
    }

    private var estadoResumido: Int {
        switch ambiente.sessao.estado {
        case .visitante: 0
        case .trocarSenha: 1
        case .conectado: 2
        }
    }
}

/// Início, Perguntar, Hoje e Conta no menu inferior; o sininho dos avisos fica na barra de cima de cada aba.
struct AbasView: View {
    @Environment(Ambiente.self) private var ambiente

    var body: some View {
        @Bindable var navegador = ambiente.navegador
        TabView(selection: $navegador.aba) {
            ForEach(Aba.allCases, id: \.self) { aba in
                NavigationStack(path: navegador.caminho(aba)) {
                    raiz(aba)
                        .toolbar { barraDaAba }
                        .navigationBarTitleDisplayMode(.inline)
                        .navigationDestination(for: Rota.self) { rota in
                            DestinoView(rota: rota)
                        }
                }
                .tabItem { Label(aba.titulo, systemImage: aba.simbolo) }
                .tag(aba)
            }
        }
    }

    @ViewBuilder
    private func raiz(_ aba: Aba) -> some View {
        switch aba {
        case .inicio: InicioView(ambiente: ambiente)
        case .perguntar: PerguntarView(ambiente: ambiente, remedio: nil)
        case .hoje: DiaView(ambiente: ambiente, fonte: MeuDia(ambiente.dependencias))
        case .conta: ContaView(ambiente: ambiente)
        }
    }

    @ToolbarContentBuilder
    private var barraDaAba: some ToolbarContent {
        ToolbarItem(placement: .principal) {
            Logo(altura: 28)
        }
        ToolbarItem(placement: .topBarTrailing) {
            SininhoDosAvisos()
        }
    }
}

/// Sininho com o número de avisos não lidos.
struct SininhoDosAvisos: View {
    @Environment(Ambiente.self) private var ambiente

    var body: some View {
        Button {
            ambiente.navegador.abrir(.avisos)
        } label: {
            Image(systemName: ambiente.naoLidos > 0 ? "bell.badge.fill" : "bell")
                .symbolRenderingMode(.multicolor)
                .font(.title3)
                .frame(minWidth: 44, minHeight: 44)
                .overlay(alignment: .topTrailing) {
                    if ambiente.naoLidos > 0 {
                        Text(ambiente.naoLidos > 9 ? "9+" : "\(ambiente.naoLidos)")
                            .font(.caption2.weight(.bold))
                            .foregroundStyle(.white)
                            .padding(.horizontal, 5)
                            .padding(.vertical, 1)
                            .background(Cores.destaque, in: Capsule())
                            .offset(x: 2, y: 2)
                    }
                }
        }
        .accessibilityLabel(ambiente.naoLidos == 0 ? "Avisos" : ambiente.naoLidos == 1 ? "Avisos, 1 não lido" : "Avisos, \(ambiente.naoLidos) não lidos")
    }
}

/// Telas empilhadas (botão voltar, sem o menu inferior escondido: no iOS o menu continua, como manda a convenção).
struct DestinoView: View {
    @Environment(Ambiente.self) private var ambiente
    let rota: Rota

    var body: some View {
        conteudo
            .navigationTitle(rota.titulo)
            .navigationBarTitleDisplayMode(.inline)
    }

    @ViewBuilder
    private var conteudo: some View {
        switch rota {
        case let .aba(aba):
            Text(aba.titulo)
        case .avisos:
            AvisosView(ambiente: ambiente)
        case .perguntas:
            HistoricoView(ambiente: ambiente)
        case let .pergunta(uuid):
            PerguntaAnteriorView(ambiente: ambiente, uuid: uuid)
        case .receita:
            NovaReceitaView(ambiente: ambiente)
        case let .revisarReceita(id):
            RevisarReceitaView(ambiente: ambiente, id: id)
        case let .bula(id):
            BulaView(ambiente: ambiente, remedio: id)
        case let .perguntarSobre(id):
            PerguntarView(ambiente: ambiente, remedio: id)
        case .cuidadores:
            QuemCuidaDeMimView(ambiente: ambiente)
        case .cuidar:
            CuidarDeAlguemView(ambiente: ambiente)
        case let .cuidando(vinculo, nome):
            DiaView(ambiente: ambiente, fonte: DiaDeQuemCuido(ambiente.dependencias, vinculo: vinculo, primeiroNome: nome))
        case let .cuidandoRemedios(vinculo):
            RemediosDeQuemCuidoView(ambiente: ambiente, vinculo: vinculo)
        case let .perfil(aba):
            PerfilView(ambiente: ambiente, abaInicial: aba)
        case .bulas:
            BulasView(ambiente: ambiente)
        case let .interacoes(com):
            PesquisaView(ambiente: ambiente, comRemedio: com)
        case let .resultado(uuid):
            ResultadoView(ambiente: ambiente, uuid: uuid)
        }
    }
}
