import BulaiCore
import SwiftUI
import UIKit

/// Botão grande da ação principal (altura mínima de 56 pt, como os 56–60 dp do Android).
struct BotaoPrincipal: View {
    var titulo: String
    var simbolo: String?
    var carregando = false
    var desabilitado = false
    var cor: Color = Cores.marca
    var corDoTexto: Color = Cores.naMarca
    var acao: () -> Void

    var body: some View {
        Button(action: acao) {
            HStack(spacing: 10) {
                if carregando {
                    ProgressView().tint(corDoTexto)
                } else if let simbolo {
                    Image(systemName: simbolo).imageScale(.large)
                }
                Text(titulo).font(Fonte.rotulo).multilineTextAlignment(.center)
            }
            .frame(maxWidth: .infinity, minHeight: 56)
            .padding(.horizontal, 16)
            .foregroundStyle(corDoTexto)
            .background(cor.opacity(desabilitado ? 0.45 : 1), in: RoundedRectangle(cornerRadius: 16, style: .continuous))
        }
        .buttonStyle(.plain)
        .disabled(carregando || desabilitado)
        .accessibilityAddTraits(.isButton)
    }
}

/// Botão com contorno (ação secundária).
struct BotaoSecundario: View {
    var titulo: String
    var simbolo: String?
    var cor: Color = Cores.marca
    var carregando = false
    var acao: () -> Void

    var body: some View {
        Button(action: acao) {
            HStack(spacing: 8) {
                if carregando {
                    ProgressView()
                } else if let simbolo {
                    Image(systemName: simbolo)
                }
                Text(titulo).font(Fonte.rotulo).multilineTextAlignment(.center)
            }
            .frame(maxWidth: .infinity, minHeight: 52)
            .padding(.horizontal, 14)
            .foregroundStyle(cor)
            .overlay(RoundedRectangle(cornerRadius: 16, style: .continuous).strokeBorder(cor.opacity(0.45), lineWidth: 2))
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
        .disabled(carregando)
    }
}

/// Cartão das telas: superfície com borda suave.
struct Cartao<Conteudo: View>: View {
    var cor: Color = Cores.superficie
    var borda: Color = Cores.contorno
    @ViewBuilder var conteudo: Conteudo

    var body: some View {
        VStack(alignment: .leading, spacing: 12) {
            conteudo
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        .padding(16)
        .background(cor, in: RoundedRectangle(cornerRadius: 20, style: .continuous))
        .overlay(RoundedRectangle(cornerRadius: 20, style: .continuous).strokeBorder(borda, lineWidth: 1))
    }
}

/// Faixa de erro por cima dos dados que já estão na tela, com "Tentar de novo".
struct FaixaDeErro: View {
    var texto: String
    var tentar: (() -> Void)?

    var body: some View {
        HStack(alignment: .top, spacing: 12) {
            Image(systemName: "exclamationmark.triangle.fill").foregroundStyle(Cores.erro).accessibilityHidden(true)
            VStack(alignment: .leading, spacing: 8) {
                Text(texto).font(Fonte.corpo).foregroundStyle(Cores.texto).fixedSize(horizontal: false, vertical: true)
                if let tentar {
                    Button("Tentar de novo", action: tentar).font(Fonte.rotulo).foregroundStyle(Cores.marca)
                        .frame(minHeight: 44)
                }
            }
            Spacer(minLength: 0)
        }
        .padding(14)
        .background(Cores.erroFundo, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
        .accessibilityElement(children: .combine)
    }
}

/// Estado vazio com símbolo, título, texto e ação opcional.
struct EstadoVazio: View {
    var simbolo: String
    var titulo: String
    var texto: String
    var acao: (titulo: String, executar: () -> Void)?

    var body: some View {
        VStack(spacing: 14) {
            Image(systemName: simbolo).font(.system(size: 44)).foregroundStyle(Cores.marca.opacity(0.7)).accessibilityHidden(true)
            Text(titulo).font(Fonte.subtitulo).foregroundStyle(Cores.texto).multilineTextAlignment(.center).accessibilityAddTraits(.isHeader)
            Text(texto).font(Fonte.corpo).foregroundStyle(Cores.textoSuave).multilineTextAlignment(.center)
            if let acao {
                BotaoPrincipal(titulo: acao.titulo, acao: acao.executar).padding(.top, 4)
            }
        }
        .frame(maxWidth: .infinity)
        .padding(.vertical, 28)
        .padding(.horizontal, 12)
    }
}

/// Bloco de esqueleto com brilho (desligado com "Reduzir movimento").
struct Esqueleto: View {
    var altura: CGFloat = 20
    var largura: CGFloat? = nil
    var raio: CGFloat = 10
    @Environment(\.accessibilityReduceMotion) private var reduzirMovimento
    @State private var fase: CGFloat = -1

    var body: some View {
        RoundedRectangle(cornerRadius: raio, style: .continuous)
            .fill(Cores.esqueleto)
            .overlay {
                if !reduzirMovimento {
                    GeometryReader { geo in
                        LinearGradient(colors: [.clear, Cores.esqueletoBrilho.opacity(0.8), .clear], startPoint: .leading, endPoint: .trailing)
                            .frame(width: geo.size.width * 0.6)
                            .offset(x: fase * geo.size.width * 1.6)
                    }
                    .clipShape(RoundedRectangle(cornerRadius: raio, style: .continuous))
                }
            }
            .frame(width: largura, height: altura)
            .onAppear {
                guard !reduzirMovimento else {
                    return
                }
                withAnimation(.linear(duration: 1.3).repeatForever(autoreverses: false)) {
                    fase = 1
                }
            }
            .accessibilityHidden(true)
    }
}

/// Esqueleto de uma lista de cartões (primeira abertura de uma tela).
struct EsqueletoDeLista: View {
    var cartoes = 3

    var body: some View {
        VStack(alignment: .leading, spacing: 16) {
            ForEach(0 ..< cartoes, id: \.self) { _ in
                VStack(alignment: .leading, spacing: 10) {
                    Esqueleto(altura: 22, largura: 180)
                    Esqueleto(altura: 16)
                    Esqueleto(altura: 16, largura: 220)
                }
                .padding(16)
                .background(Cores.superficie, in: RoundedRectangle(cornerRadius: 20, style: .continuous))
            }
        }
        .accessibilityElement()
        .accessibilityLabel("Carregando")
    }
}

/// Campo com o rótulo em cima e o erro (do servidor) embaixo, anunciado pelo VoiceOver.
struct CampoDeTexto: View {
    var titulo: String
    @Binding var texto: String
    var erro: String?
    var ajuda: String?
    var teclado: UIKeyboardType = .default
    var conteudo: UITextContentType?
    var maiusculas: TextInputAutocapitalization = .sentences
    var aoMudar: () -> Void = {}

    var body: some View {
        VStack(alignment: .leading, spacing: 6) {
            Text(titulo).font(Fonte.rotulo).foregroundStyle(Cores.texto)
            TextField(titulo, text: $texto, axis: .vertical)
                .font(Fonte.corpo)
                .keyboardType(teclado)
                .textContentType(conteudo)
                .textInputAutocapitalization(maiusculas)
                .autocorrectionDisabled(teclado == .emailAddress)
                .padding(.horizontal, 14)
                .padding(.vertical, 14)
                .frame(minHeight: 52)
                .background(Cores.superficie, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                .overlay(RoundedRectangle(cornerRadius: 14, style: .continuous).strokeBorder(erro == nil ? Cores.contorno : Cores.erro, lineWidth: erro == nil ? 1 : 2))
                .onChange(of: texto) { aoMudar() }
                .accessibilityHint(erro ?? ajuda ?? "")
            if let erro {
                Label(erro, systemImage: "exclamationmark.circle.fill").font(Fonte.pequena).foregroundStyle(Cores.erro)
            } else if let ajuda {
                Text(ajuda).font(Fonte.pequena).foregroundStyle(Cores.textoSuave)
            }
        }
    }
}

/// Campo de senha com "Mostrar senha" (gerenciador de senhas do iOS ligado pelo `textContentType`).
struct CampoDeSenha: View {
    var titulo: String
    @Binding var texto: String
    var erro: String?
    var ajuda: String?
    var nova = false
    var aoMudar: () -> Void = {}
    @State private var mostrar = false

    var body: some View {
        VStack(alignment: .leading, spacing: 6) {
            Text(titulo).font(Fonte.rotulo).foregroundStyle(Cores.texto)
            HStack {
                Group {
                    if mostrar {
                        TextField(titulo, text: $texto)
                    } else {
                        SecureField(titulo, text: $texto)
                    }
                }
                .font(Fonte.corpo)
                .textContentType(nova ? .newPassword : .password)
                .textInputAutocapitalization(.never)
                .autocorrectionDisabled()
                .onChange(of: texto) { aoMudar() }
                Button {
                    mostrar.toggle()
                } label: {
                    Image(systemName: mostrar ? "eye.slash" : "eye").foregroundStyle(Cores.textoSuave).frame(width: 44, height: 44)
                }
                .accessibilityLabel(mostrar ? "Esconder senha" : "Mostrar senha")
            }
            .padding(.leading, 14)
            .frame(minHeight: 52)
            .background(Cores.superficie, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
            .overlay(RoundedRectangle(cornerRadius: 14, style: .continuous).strokeBorder(erro == nil ? Cores.contorno : Cores.erro, lineWidth: erro == nil ? 1 : 2))
            if let erro {
                Label(erro, systemImage: "exclamationmark.circle.fill").font(Fonte.pequena).foregroundStyle(Cores.erro)
            } else if let ajuda {
                Text(ajuda).font(Fonte.pequena).foregroundStyle(Cores.textoSuave)
            }
        }
    }
}

/// Pílula selecionável (sugestões, atalhos de dose), alvo de toque de 48 pt.
struct Pilula: View {
    var texto: String
    var escolhida = false
    var acao: () -> Void

    var body: some View {
        Button(action: acao) {
            Text(texto)
                .font(Fonte.pequena.weight(.semibold))
                .multilineTextAlignment(.leading)
                .padding(.horizontal, 16)
                .frame(minHeight: 44)
                .foregroundStyle(escolhida ? Cores.naMarca : Cores.marca)
                .background(escolhida ? Cores.marca : Cores.marcaSuave, in: Capsule())
        }
        .buttonStyle(.plain)
        .accessibilityAddTraits(escolhida ? .isSelected : [])
    }
}

/// Selo curto ("A tomar", "Atrasado", "Tomei 08:21").
struct Selo: View {
    var texto: String
    var cor: Color
    var fundo: Color

    var body: some View {
        Text(texto)
            .font(.caption.weight(.bold))
            .padding(.horizontal, 10)
            .padding(.vertical, 5)
            .foregroundStyle(cor)
            .background(fundo, in: Capsule())
    }
}

/// Título de seção marcado como cabeçalho para o VoiceOver.
struct TituloDeSecao: View {
    var texto: String

    var body: some View {
        Text(texto).font(Fonte.subtitulo).foregroundStyle(Cores.marca).accessibilityAddTraits(.isHeader)
            .frame(maxWidth: .infinity, alignment: .leading)
    }
}

/// Logo do BULA-IA (versões para fundo claro e escuro no catálogo de imagens).
struct Logo: View {
    var altura: CGFloat = 44

    var body: some View {
        Image("Logo").resizable().scaledToFit().frame(height: altura).accessibilityLabel("BULA-IA")
    }
}

/// Fileira que quebra linha quando falta espaço (pílulas de sugestões e atalhos).
struct Fileira: Layout {
    var espaco: CGFloat = 8

    func sizeThatFits(proposal: ProposedViewSize, subviews: Subviews, cache _: inout ()) -> CGSize {
        let largura = proposal.width ?? .infinity
        var x: CGFloat = 0
        var y: CGFloat = 0
        var alturaDaLinha: CGFloat = 0
        for vista in subviews {
            let tamanho = vista.sizeThatFits(ProposedViewSize(width: largura, height: nil))
            if x > 0, x + tamanho.width > largura {
                x = 0
                y += alturaDaLinha + espaco
                alturaDaLinha = 0
            }
            x += tamanho.width + espaco
            alturaDaLinha = max(alturaDaLinha, tamanho.height)
        }
        return CGSize(width: proposal.width ?? x, height: y + alturaDaLinha)
    }

    func placeSubviews(in bounds: CGRect, proposal _: ProposedViewSize, subviews: Subviews, cache _: inout ()) {
        var x = bounds.minX
        var y = bounds.minY
        var alturaDaLinha: CGFloat = 0
        for vista in subviews {
            let tamanho = vista.sizeThatFits(ProposedViewSize(width: bounds.width, height: nil))
            if x > bounds.minX, x + tamanho.width > bounds.maxX {
                x = bounds.minX
                y += alturaDaLinha + espaco
                alturaDaLinha = 0
            }
            vista.place(at: CGPoint(x: x, y: y), proposal: ProposedViewSize(tamanho))
            x += tamanho.width + espaco
            alturaDaLinha = max(alturaDaLinha, tamanho.height)
        }
    }
}

/// Aviso flutuante ("Anotado: você tomou…") com "Desfazer" opcional; some sozinho e é lido pelo VoiceOver.
struct AvisoFlutuante: ViewModifier {
    @Binding var recado: Recado?

    func body(content: Content) -> some View {
        content.overlay(alignment: .bottom) {
            if let recado {
                HStack(spacing: 12) {
                    Text(recado.texto).font(Fonte.corpo).foregroundStyle(Cores.naMarca).fixedSize(horizontal: false, vertical: true)
                    Spacer(minLength: 0)
                    if let desfazer = recado.desfazer {
                        Button("Desfazer") {
                            self.recado = nil
                            desfazer()
                        }
                        .font(Fonte.rotulo)
                        .foregroundStyle(Cores.destaqueSuave)
                        .frame(minHeight: 44)
                    }
                }
                .padding(.horizontal, 18)
                .padding(.vertical, 12)
                .background(Cores.marca, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
                .shadow(color: .black.opacity(0.2), radius: 10, y: 4)
                .padding(.horizontal, 16)
                .padding(.bottom, 12)
                .transition(.move(edge: .bottom).combined(with: .opacity))
                .id(recado.id)
                .task(id: recado.id) {
                    UIAccessibility.post(notification: .announcement, argument: recado.texto)
                    try? await Task.sleep(nanoseconds: recado.desfazer == nil ? 3_500_000_000 : 6_000_000_000)
                    if self.recado?.id == recado.id {
                        withAnimation { self.recado = nil }
                    }
                }
            }
        }
        .animation(.spring(duration: 0.35), value: recado?.id)
    }
}

extension View {
    func recado(_ recado: Binding<Recado?>) -> some View {
        modifier(AvisoFlutuante(recado: recado))
    }

    /// Conteúdo das telas: largura limitada em iPad/telas largas e fundo da marca.
    func telaBulai() -> some View {
        frame(maxWidth: 640)
            .frame(maxWidth: .infinity)
            .background(Cores.fundo.ignoresSafeArea())
    }
}
