import BulaiCore
import SwiftUI
import UIKit

/// Remédios do dia: Ontem/Hoje/Amanhã com deslize lateral, linha do tempo por período, "Tomei" e "Pular" com Desfazer.
/// A mesma tela serve para o dia de quem a pessoa cuida (``DiaDeQuemCuido``): a faixa diz se pode anotar e, sem a
/// permissão "marcar e editar", os botões somem (o servidor recusa de qualquer jeito).
struct DiaView: View {
    let ambiente: Ambiente
    @State private var tela: DiaViewModel
    @State private var deslocamento = 0

    init(ambiente: Ambiente, fonte: FonteDoDia) {
        self.ambiente = ambiente
        _tela = State(initialValue: DiaViewModel(fonte: fonte))
    }

    var body: some View {
        VStack(spacing: 0) {
            Picker("Dia", selection: $deslocamento.animation()) {
                Text("Ontem").tag(-1)
                Text("Hoje").tag(0)
                Text("Amanhã").tag(1)
            }
            .pickerStyle(.segmented)
            .padding(.horizontal, 16)
            .padding(.vertical, 8)
            TabView(selection: $deslocamento) {
                ForEach(DiaViewModel.deslocamentos, id: \.self) { dia in
                    PaginaDoDia(ambiente: ambiente, tela: tela, deslocamento: dia)
                        .tag(dia)
                }
            }
            .tabViewStyle(.page(indexDisplayMode: .never))
        }
        .background(Cores.fundo.ignoresSafeArea())
        .task { await tela.carregar() }
        .onChange(of: deslocamento) { _, novo in
            Task { await tela.atualizar(novo) }
        }
        .onAppear {
            if let recado = ambiente.recadoGlobal {
                tela.recado = recado
                ambiente.recadoGlobal = nil
            }
        }
        .recado($tela.recado)
    }
}

private struct PaginaDoDia: View {
    let ambiente: Ambiente
    @Bindable var tela: DiaViewModel
    let deslocamento: Int

    var body: some View {
        let carga = tela.carga(deslocamento)
        ScrollView {
            LazyVStack(alignment: .leading, spacing: 16) {
                if let erro = carga.erro {
                    FaixaDeErro(texto: erro) { Task { await tela.atualizar(deslocamento) } }
                }
                if let dia = carga.dados {
                    conteudo(dia)
                } else if carga.esqueleto {
                    Esqueleto(altura: 28, largura: 200)
                    EsqueletoDeLista(cartoes: 3)
                }
            }
            .padding(16)
            .frame(maxWidth: 640)
            .frame(maxWidth: .infinity)
        }
        .refreshable { await tela.atualizar(deslocamento) }
    }

    @ViewBuilder
    private func conteudo(_ dia: Dia) -> some View {
        if let cuidando = dia.cuidandoDe {
            faixaDoCuidador(cuidando, podeMarcar: dia.podeMarcar)
        }
        VStack(alignment: .leading, spacing: 4) {
            Text(dia.data).font(Fonte.titulo).foregroundStyle(Cores.marca).accessibilityAddTraits(.isHeader)
            if dia.summary.total > 0 {
                Text(resumo(dia.summary)).font(Fonte.corpo).foregroundStyle(dia.summary.late > 0 ? Cores.erro : Cores.textoSuave)
            }
        }
        if !dia.consentimento {
            semConsentimento(dia)
        } else if dia.doses.isEmpty, dia.seNecessario.isEmpty {
            EstadoVazio(
                simbolo: "calendar.badge.clock",
                titulo: dia.temRemedios ? "Nenhum horário neste dia" : "Nenhum remédio cadastrado",
                texto: textoSemDoses(dia),
                acao: acaoSemDoses(dia)
            )
        } else {
            ForEach(DiaViewModel.porPeriodo(dia.doses), id: \.periodo) { grupo in
                VStack(alignment: .leading, spacing: 10) {
                    Label(nomeDoPeriodo(grupo.periodo), systemImage: simboloDoPeriodo(grupo.periodo))
                        .font(Fonte.rotulo).foregroundStyle(Cores.textoSuave).accessibilityAddTraits(.isHeader)
                    ForEach(grupo.doses) { dose in
                        LinhaDaDose(
                            dose: dose,
                            podeMarcar: dia.podeMarcar,
                            deQuem: dia.cuidandoDe?.primeiroNome,
                            enviando: tela.enviando.contains(dose.key),
                            acabouDeTomar: tela.acabouDeTomar == dose.key,
                            tomei: { Task { await tela.tomei(dose, deslocamento: deslocamento) } },
                            pular: { Task { await tela.pular(dose, deslocamento: deslocamento) } },
                            desfazer: { Task { await tela.desfazer(dose, deslocamento: deslocamento) } }
                        )
                    }
                }
            }
            if dia.temDinamico {
                Label("Alguns horários são recontados a partir da hora em que você tomou a dose anterior.", systemImage: "arrow.triangle.2.circlepath")
                    .font(Fonte.pequena).foregroundStyle(Cores.textoSuave)
            }
            if !dia.seNecessario.isEmpty {
                VStack(alignment: .leading, spacing: 10) {
                    TituloDeSecao(texto: "Quando precisar")
                    ForEach(dia.seNecessario) { remedio in
                        Cartao {
                            Text(remedio.name).font(Fonte.corpoForte)
                            Text(remedio.dosage ?? "Dose não informada").font(Fonte.pequena).foregroundStyle(Cores.textoSuave)
                        }
                    }
                }
            }
        }
    }

    private func textoSemDoses(_ dia: Dia) -> String {
        if dia.temRemedios {
            return "Os remédios não têm horário programado para \(dia.data.lowercased())."
        }
        if let cuidando = dia.cuidandoDe {
            return "\(cuidando.primeiroNome) ainda não cadastrou remédios."
        }
        return "Cadastre seus remédios no perfil para ver os horários aqui."
    }

    private func acaoSemDoses(_ dia: Dia) -> (titulo: String, executar: () -> Void)? {
        guard !dia.temRemedios, dia.cuidandoDe == nil else {
            return nil
        }
        let navegador = ambiente.navegador
        return ("Abrir Meus remédios", { navegador.abrir(.perfil(aba: 0)) })
    }

    private func faixaDoCuidador(_ cuidando: CuidandoDe, podeMarcar: Bool) -> some View {
        Cartao(cor: podeMarcar ? Cores.marcaSuave : Cores.destaqueSuave, borda: .clear) {
            Label("Remédios de \(cuidando.primeiroNome)", systemImage: "person.2.fill").font(Fonte.rotulo).foregroundStyle(podeMarcar ? Cores.naMarcaSuave : Cores.naDestaqueSuave)
            if !cuidando.banner.isEmpty {
                Text(cuidando.banner).font(Fonte.corpo).foregroundStyle(Cores.texto)
            }
            Button {
                ambiente.navegador.abrir(.cuidandoRemedios(cuidando.vinculo))
            } label: {
                Label(podeMarcar ? "Remédios, dose e horários" : "Ver remédios e horários", systemImage: "list.bullet.rectangle")
            }
            .font(Fonte.rotulo)
            .frame(minHeight: 44)
        }
    }

    private func semConsentimento(_ dia: Dia) -> some View {
        Cartao(cor: Cores.destaqueSuave, borda: .clear) {
            if let cuidando = dia.cuidandoDe {
                Text("Remédios indisponíveis").font(Fonte.subtitulo)
                Text("\(cuidando.primeiroNome) ainda não autorizou guardar os dados de saúde no BULA-IA.").font(Fonte.corpo)
            } else {
                Text("Autorize seus dados de saúde").font(Fonte.subtitulo)
                Text("Para ver os horários dos seus remédios, autorize o uso dos seus dados de saúde.").font(Fonte.corpo)
                BotaoPrincipal(titulo: "Abrir Meus remédios") { ambiente.navegador.abrir(.perfil(aba: 0)) }
            }
        }
    }

    private func resumo(_ resumo: ResumoDoDia) -> String {
        var texto = "\(resumo.done) de \(resumo.total) doses anotadas"
        if resumo.late > 0 {
            texto += resumo.late == 1 ? " · 1 atrasada" : " · \(resumo.late) atrasadas"
        }
        return texto
    }

    private func nomeDoPeriodo(_ periodo: String) -> String {
        switch periodo {
        case "tarde": "Tarde"
        case "noite": "Noite"
        case "madrugada": "Madrugada"
        default: "Manhã"
        }
    }

    private func simboloDoPeriodo(_ periodo: String) -> String {
        switch periodo {
        case "tarde": "sun.max.fill"
        case "noite": "moon.stars.fill"
        case "madrugada": "moon.zzz.fill"
        default: "sunrise.fill"
        }
    }
}

/// Uma dose na linha do tempo: horário à esquerda, bolinha no trilho e o cartão com nome, dose e selo do estado.
private struct LinhaDaDose: View {
    let dose: Dose
    let podeMarcar: Bool
    let deQuem: String?
    let enviando: Bool
    let acabouDeTomar: Bool
    let tomei: () -> Void
    let pular: () -> Void
    let desfazer: () -> Void
    @Environment(\.accessibilityReduceMotion) private var reduzirMovimento
    @State private var joinha = false

    var body: some View {
        HStack(alignment: .top, spacing: 12) {
            Text(dose.time).font(.title3.monospacedDigit().weight(.semibold)).foregroundStyle(Cores.texto).frame(width: 64, alignment: .leading)
            VStack(spacing: 0) {
                Circle()
                    .fill(corDaBolinha)
                    .overlay(Circle().strokeBorder(corDaBolinha == .clear ? Cores.marca : .clear, lineWidth: 2))
                    .frame(width: 16, height: 16)
                Rectangle().fill(Cores.contorno).frame(width: 2).frame(maxHeight: .infinity)
            }
            .padding(.top, 6)
            .accessibilityHidden(true)
            cartao
        }
        .accessibilityElement(children: .contain)
    }

    private var cartao: some View {
        VStack(alignment: .leading, spacing: 10) {
            HStack(alignment: .top) {
                VStack(alignment: .leading, spacing: 2) {
                    Text(dose.name).font(Fonte.corpoForte).foregroundStyle(Cores.texto)
                    Text(dose.dosage ?? "Dose não informada").font(Fonte.pequena).foregroundStyle(Cores.textoSuave)
                }
                Spacer(minLength: 8)
                Selo(texto: textoDoSelo, cor: corDoSelo.cor, fundo: corDoSelo.fundo)
            }
            if let ajuste = dose.ajustadaDe {
                Text("Ajustado: contado a partir das \(ajuste), quando você tomou a anterior").font(Fonte.pequena).foregroundStyle(Cores.textoSuave)
            }
            if let notas = dose.notes, !notas.isEmpty {
                Text(notas).font(Fonte.pequena).foregroundStyle(Cores.textoSuave)
            }
            if podeMarcar {
                botoes
            }
        }
        .padding(14)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(fundoDoCartao, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
        .overlay(RoundedRectangle(cornerRadius: 18, style: .continuous).strokeBorder(Cores.contorno))
        .overlay {
            if joinha {
                Image(systemName: "hand.thumbsup.fill")
                    .font(.system(size: 56))
                    .foregroundStyle(.white)
                    .shadow(radius: 6)
                    .transition(.scale(scale: 0.3).combined(with: .opacity))
                    .accessibilityHidden(true)
            }
        }
        .animation(reduzirMovimento ? nil : .spring(response: 0.45, dampingFraction: 0.55), value: joinha)
        .animation(reduzirMovimento ? nil : .easeInOut(duration: 0.5), value: dose.status)
        .onChange(of: acabouDeTomar) { _, novo in
            guard novo, !reduzirMovimento else {
                return
            }
            joinha = true
            Task {
                try? await Task.sleep(nanoseconds: 1_100_000_000)
                joinha = false
            }
        }
        .accessibilityElement(children: .contain)
        .accessibilityLabel("\(dose.time), \(dose.name), \(textoDoSelo)")
    }

    @ViewBuilder
    private var botoes: some View {
        if dose.feita {
            Button("Desfazer", action: desfazer).font(Fonte.rotulo).frame(minHeight: 44).disabled(enviando)
        } else {
            HStack(spacing: 10) {
                Button {
                    UINotificationFeedbackGenerator().notificationOccurred(.success)
                    tomei()
                } label: {
                    HStack {
                        if enviando {
                            ProgressView().tint(.white)
                        } else {
                            Image(systemName: "checkmark")
                        }
                        Text(deQuem == nil ? "Tomei" : "Tomou").font(Fonte.rotulo)
                    }
                    .frame(maxWidth: .infinity, minHeight: 50)
                    .foregroundStyle(.white)
                    .background(Color(UIColor(hex: 0x1D7A46)), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                }
                .buttonStyle(.plain)
                .accessibilityLabel(deQuem == nil ? "Tomei \(dose.name)" : "\(deQuem!) tomou \(dose.name)")
                Button(action: pular) {
                    Text("Pular").font(Fonte.rotulo).frame(minWidth: 86, minHeight: 50).foregroundStyle(Cores.marca)
                        .overlay(RoundedRectangle(cornerRadius: 14, style: .continuous).strokeBorder(Cores.marca.opacity(0.4), lineWidth: 2))
                }
                .buttonStyle(.plain)
                .accessibilityLabel("Pular \(dose.name)")
            }
            .disabled(enviando)
        }
    }

    private var fundoDoCartao: Color {
        switch dose.status {
        case Dose.tomada: Cores.sucessoFundo
        case Dose.atrasada: Cores.erroFundo
        case Dose.agora: Cores.destaqueSuave
        default: Cores.superficie
        }
    }

    private var corDaBolinha: Color {
        switch dose.status {
        case Dose.tomada: Cores.sucesso
        case Dose.pulada: Cores.textoSuave
        case Dose.atrasada: Cores.erro
        case Dose.agora: Cores.destaque
        default: .clear
        }
    }

    private var textoDoSelo: String {
        switch dose.status {
        case Dose.tomada:
            let quem = deQuem == nil ? "Tomei" : "Tomou"
            return Datas.hora(dose.tomadaEm).map { "\(quem) \($0)" } ?? quem
        case Dose.pulada: return deQuem == nil ? "Pulei" : "Pulou"
        case Dose.atrasada: return "Atrasado"
        case Dose.agora: return "Agora"
        default: return "A tomar"
        }
    }

    private var corDoSelo: (cor: Color, fundo: Color) {
        switch dose.status {
        case Dose.tomada: (Cores.sucesso, Cores.sucessoFundo)
        case Dose.atrasada: (Cores.erro, Cores.erroFundo)
        case Dose.agora: (Cores.naDestaqueSuave, Cores.destaqueSuave)
        default: (Cores.textoSuave, Cores.superficieVariante)
        }
    }
}
