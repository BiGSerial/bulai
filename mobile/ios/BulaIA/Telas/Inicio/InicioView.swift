import BulaiCore
import SwiftUI
import UIKit

/// Início no formato do site e do Android: saudação, cartão azul do dia com o anel de doses e o próximo remédio,
/// ações grandes uma por linha e, para quem cuida de alguém, "Quem você acompanha".
struct InicioView: View {
    let ambiente: Ambiente
    @State private var tela: InicioViewModel

    init(ambiente: Ambiente) {
        self.ambiente = ambiente
        _tela = State(initialValue: InicioViewModel(ambiente.dependencias))
    }

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 18) {
                if let erro = tela.carga.erro {
                    FaixaDeErro(texto: erro) { Task { await tela.atualizar() } }
                }
                if let inicio = tela.carga.dados {
                    saudacao(inicio)
                    cartaoDoDia(inicio)
                    acoes(inicio)
                    if !inicio.cuidando.isEmpty {
                        quemAcompanho(inicio.cuidando)
                    }
                } else if tela.carga.esqueleto {
                    Esqueleto(altura: 34, largura: 220)
                    Esqueleto(altura: 220, raio: 24)
                    EsqueletoDeLista(cartoes: 3)
                }
            }
            .padding(16)
        }
        .telaBulai()
        .refreshable { await tela.atualizar() }
        .task { await tela.carregar() }
        .onAppear { Task { await tela.atualizar() } }
        .onChange(of: tela.carga.dados?.avisosNaoLidos) { _, novo in
            if let novo {
                ambiente.naoLidos = novo
            }
        }
        .recado($tela.recado)
    }

    private func saudacao(_ inicio: Inicio) -> some View {
        VStack(alignment: .leading, spacing: 8) {
            Text("\(Datas.saudacao()), \(inicio.primeiroNome)!").font(Fonte.tituloGrande).foregroundStyle(Cores.marca).accessibilityAddTraits(.isHeader)
            Text(inicio.data).font(Fonte.corpo).foregroundStyle(Cores.textoSuave)
            if inicio.avisosNaoLidos > 0 {
                Button {
                    ambiente.navegador.abrir(.avisos)
                } label: {
                    Label(inicio.avisosNaoLidos == 1 ? "1 aviso novo" : "\(inicio.avisosNaoLidos) avisos novos", systemImage: "bell.badge.fill")
                        .font(Fonte.pequena.weight(.semibold))
                        .padding(.horizontal, 14)
                        .frame(minHeight: 40)
                        .foregroundStyle(Cores.naDestaqueSuave)
                        .background(Cores.destaqueSuave, in: Capsule())
                }
                .buttonStyle(.plain)
            }
        }
    }

    @ViewBuilder
    private func cartaoDoDia(_ inicio: Inicio) -> some View {
        if !inicio.consentimento {
            Cartao(cor: Cores.destaqueSuave, borda: .clear) {
                Label("Seus remédios", systemImage: "lock.shield.fill").font(Fonte.subtitulo).foregroundStyle(Cores.naDestaqueSuave)
                Text("Para guardar seus remédios e receber lembretes, autorize o uso dos seus dados de saúde.").font(Fonte.corpo)
                BotaoPrincipal(titulo: "Autorizar agora", cor: Cores.destaque, corDoTexto: Cores.naDestaque) { ambiente.navegador.abrir(.perfil(aba: 0)) }
            }
        } else if !inicio.temRemedios {
            Cartao {
                Label("Nenhum remédio cadastrado", systemImage: "pills").font(Fonte.subtitulo).foregroundStyle(Cores.marca)
                Text("Fotografe sua receita para ver os horários aqui e receber os lembretes.").font(Fonte.corpo)
                BotaoPrincipal(titulo: "Fotografar minha receita", simbolo: "camera.fill") { ambiente.navegador.abrir(.receita) }
            }
        } else {
            CartaoDoDia(inicio: inicio, marcando: tela.marcando) { dose in
                UINotificationFeedbackGenerator().notificationOccurred(.success)
                Task { await tela.tomei(dose) }
            } abrirDia: {
                ambiente.navegador.abrir(.aba(.hoje))
            }
        }
    }

    private func acoes(_ inicio: Inicio) -> some View {
        VStack(spacing: 12) {
            AcaoDoInicio(simbolo: "bubble.left.and.text.bubble.right.fill", titulo: "Perguntar sobre um remédio", texto: "Escreva ou fale sua dúvida", destaque: true) {
                ambiente.navegador.abrir(.aba(.perguntar))
            }
            AcaoDoInicio(simbolo: "pills.fill", titulo: "Remédios de hoje", texto: proximoTexto(inicio)) {
                ambiente.navegador.abrir(.aba(.hoje))
            }
            AcaoDoInicio(simbolo: "cross.case.fill", titulo: "Meus remédios", texto: "Dose, horários, receitas e alergias") {
                ambiente.navegador.abrir(.perfil(aba: 0))
            }
            AcaoDoInicio(simbolo: "camera.fill", titulo: "Fotografar minha receita", texto: "O app lê os remédios para você conferir") {
                ambiente.navegador.abrir(.receita)
            }
            AcaoDoInicio(simbolo: "arrow.triangle.branch", titulo: "Verificar interações", texto: "Veja se seus remédios podem interagir") {
                ambiente.navegador.abrir(.interacoes(com: nil))
            }
            AcaoDoInicio(simbolo: "book.pages.fill", titulo: "Bula simples", texto: "A bula explicada em linguagem simples") {
                ambiente.navegador.abrir(.bulas)
            }
            AcaoDoInicio(simbolo: "clock.arrow.circlepath", titulo: "Ver o que já perguntei", texto: "Suas perguntas anteriores") {
                ambiente.navegador.abrir(.perguntas)
            }
        }
    }

    private func proximoTexto(_ inicio: Inicio) -> String {
        guard let proxima = inicio.proxima else {
            return "Veja os horários e marque o que já tomou"
        }
        if proxima.status == Dose.atrasada {
            return "Atrasado: \(proxima.time) · \(proxima.name)"
        }
        return proxima.ehHoje ? "Próximo: \(proxima.time) · \(proxima.name)" : "Amanhã: \(proxima.time) · \(proxima.name)"
    }

    private func quemAcompanho(_ pessoas: [PessoaCuidada]) -> some View {
        VStack(alignment: .leading, spacing: 10) {
            HStack {
                TituloDeSecao(texto: "Quem você acompanha")
                Button("Ver todas") { ambiente.navegador.abrir(.cuidar) }.font(Fonte.rotulo)
            }
            ForEach(pessoas) { pessoa in
                Button {
                    ambiente.navegador.abrir(.cuidando(pessoa.vinculo, nome: pessoa.primeiroNome))
                } label: {
                    HStack(spacing: 12) {
                        Image(systemName: "person.crop.circle.fill").font(.largeTitle).foregroundStyle(Cores.marca).accessibilityHidden(true)
                        VStack(alignment: .leading) {
                            Text(pessoa.name).font(Fonte.corpoForte).foregroundStyle(Cores.texto)
                            Text("Ver os remédios de hoje").font(Fonte.pequena).foregroundStyle(Cores.textoSuave)
                        }
                        Spacer()
                        Image(systemName: "chevron.right").foregroundStyle(Cores.textoSuave).accessibilityHidden(true)
                    }
                    .padding(14)
                    .background(Cores.superficie, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
                }
                .buttonStyle(.plain)
            }
        }
    }
}

/// Cartão azul do dia: anel "X/Y DOSES", "HOJE · X de Y doses tomadas" e o próximo remédio com a barra de espera.
struct CartaoDoDia: View {
    let inicio: Inicio
    let marcando: Bool
    let tomei: (Dose) -> Void
    let abrirDia: () -> Void

    var body: some View {
        VStack(alignment: .leading, spacing: 16) {
            HStack(spacing: 16) {
                AnelDeDoses(feitas: inicio.progress.taken, total: inicio.progress.total)
                VStack(alignment: .leading, spacing: 4) {
                    Text("HOJE").font(.caption.weight(.heavy)).tracking(1.2).foregroundStyle(.white.opacity(0.8))
                    Text(resumo).font(Fonte.subtitulo).foregroundStyle(.white)
                }
                Spacer(minLength: 0)
            }
            .accessibilityElement(children: .combine)
            .onTapGesture(perform: abrirDia)
            if let proxima = inicio.proxima {
                ProximaDoseView(dose: proxima, marcando: marcando) { tomei(proxima) }
            }
        }
        .padding(18)
        .background(Color(UIColor(hex: 0x12344D)), in: RoundedRectangle(cornerRadius: 24, style: .continuous))
    }

    private var resumo: String {
        let progresso = inicio.progress
        if progresso.total > 0, progresso.done >= progresso.total {
            return "Tudo tomado por hoje!"
        }
        return progresso.total == 1 ? "\(progresso.taken) de 1 dose tomada" : "\(progresso.taken) de \(progresso.total) doses tomadas"
    }
}

/// Anel de progresso: fundo claro, avanço em laranja, verde quando completo.
struct AnelDeDoses: View {
    let feitas: Int
    let total: Int

    var body: some View {
        let fracao = total == 0 ? 0 : min(1, Double(feitas) / Double(total))
        ZStack {
            Circle().stroke(.white.opacity(0.25), lineWidth: 9)
            Circle()
                .trim(from: 0, to: fracao)
                .stroke(fracao >= 1 ? Color(UIColor(hex: 0x4ADE80)) : Color(UIColor(hex: 0xEF9E6A)), style: StrokeStyle(lineWidth: 9, lineCap: .round))
                .rotationEffect(.degrees(-90))
                .animation(.easeOut(duration: 0.6), value: fracao)
            VStack(spacing: 0) {
                Text("\(feitas)").font(.title2.weight(.bold)) + Text("/\(total)").font(.subheadline.weight(.semibold))
                Text("DOSES").font(.caption2.weight(.heavy))
            }
            .foregroundStyle(.white)
        }
        .frame(width: 84, height: 84)
        .accessibilityLabel("\(feitas) de \(total) doses tomadas")
    }
}

/// Bloco do próximo remédio: o fundo enche de `since` até o horário (atualiza a cada 20 s); na hora, cheio.
struct ProximaDoseView: View {
    let dose: Dose
    let marcando: Bool
    let tomei: () -> Void
    @Environment(\.accessibilityReduceMotion) private var reduzirMovimento

    var body: some View {
        TimelineView(.periodic(from: .now, by: 20)) { contexto in
            let fracao = progresso(em: contexto.date)
            VStack(alignment: .leading, spacing: 10) {
                Text(titulo).font(.caption.weight(.heavy)).tracking(1).foregroundStyle(.white.opacity(0.85))
                Text(dose.name).font(Fonte.titulo).foregroundStyle(.white)
                if let dosagem = dose.dosage, !dosagem.isEmpty {
                    Text(dosagem).font(Fonte.corpo).foregroundStyle(.white.opacity(0.9))
                }
                if let ajuste = dose.ajustadaDe {
                    Text("Ajustado pela dose das \(ajuste)").font(Fonte.pequena).foregroundStyle(.white.opacity(0.85))
                }
                if let falta = faltam(em: contexto.date) {
                    Text(falta).font(Fonte.pequena.weight(.semibold)).foregroundStyle(.white)
                }
                if dose.ehHoje {
                    Button(action: tomei) {
                        HStack {
                            if marcando {
                                ProgressView().tint(Cores.sucesso)
                            } else {
                                Image(systemName: "checkmark.circle.fill")
                            }
                            Text("Tomei").font(Fonte.rotulo)
                        }
                        .frame(maxWidth: .infinity, minHeight: 52)
                        .foregroundStyle(Color(UIColor(hex: 0x1D7A46)))
                        .background(.white, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                    }
                    .buttonStyle(.plain)
                    .disabled(marcando)
                    .accessibilityLabel("Marcar \(dose.name) como tomado")
                }
            }
            .padding(16)
            .frame(maxWidth: .infinity, alignment: .leading)
            .background(alignment: .leading) {
                GeometryReader { geo in
                    Rectangle()
                        .fill(Color(UIColor(hex: 0xD9661F)).opacity(0.55))
                        .frame(width: geo.size.width * fracao)
                        .animation(reduzirMovimento ? nil : .easeInOut(duration: 1.2), value: fracao)
                }
            }
            .background(.white.opacity(0.12))
            .clipShape(RoundedRectangle(cornerRadius: 18, style: .continuous))
        }
    }

    private var titulo: String {
        if dose.status == Dose.atrasada {
            return "ATRASADO · \(dose.time)"
        }
        return dose.ehHoje ? "PRÓXIMO · \(dose.time)" : "AMANHÃ · \(dose.time)"
    }

    private func progresso(em agora: Date) -> Double {
        guard let alvo = Datas.iso(dose.agendadaPara) else {
            return 0
        }
        guard dose.status != Dose.atrasada, dose.status != Dose.agora, alvo > agora else {
            return 1
        }
        guard let inicio = Datas.iso(dose.desde), alvo > inicio else {
            return 0
        }
        return min(1, max(0, agora.timeIntervalSince(inicio) / alvo.timeIntervalSince(inicio)))
    }

    private func faltam(em agora: Date) -> String? {
        guard let alvo = Datas.iso(dose.agendadaPara), alvo > agora, dose.status != Dose.atrasada else {
            return nil
        }
        let minutos = Int(alvo.timeIntervalSince(agora) / 60) + 1
        if minutos < 60 {
            return "Faltam \(minutos) min"
        }
        return minutos % 60 == 0 ? "Faltam \(minutos / 60) h" : "Faltam \(minutos / 60) h \(minutos % 60) min"
    }
}

/// Ação grande do Início, uma por linha (Perguntar em laranja).
struct AcaoDoInicio: View {
    var simbolo: String
    var titulo: String
    var texto: String
    var destaque = false
    var acao: () -> Void

    var body: some View {
        Button(action: acao) {
            HStack(spacing: 14) {
                Image(systemName: simbolo)
                    .font(.title2)
                    .frame(width: 48, height: 48)
                    .foregroundStyle(destaque ? Cores.naDestaque : Cores.marca)
                    .background(destaque ? Color.white.opacity(0.2) : Cores.marcaSuave, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                    .accessibilityHidden(true)
                VStack(alignment: .leading, spacing: 2) {
                    Text(titulo).font(Fonte.corpoForte).foregroundStyle(destaque ? Cores.naDestaque : Cores.texto)
                    Text(texto).font(Fonte.pequena).foregroundStyle(destaque ? Cores.naDestaque.opacity(0.9) : Cores.textoSuave).lineLimit(2)
                }
                Spacer(minLength: 0)
                Image(systemName: "chevron.right").foregroundStyle(destaque ? Cores.naDestaque : Cores.textoSuave).accessibilityHidden(true)
            }
            .padding(14)
            .frame(minHeight: 72)
            .background(destaque ? Cores.destaque : Cores.superficie, in: RoundedRectangle(cornerRadius: 20, style: .continuous))
            .overlay(RoundedRectangle(cornerRadius: 20, style: .continuous).strokeBorder(destaque ? .clear : Cores.contorno))
        }
        .buttonStyle(.plain)
    }
}
