import BulaiCore
import SwiftUI
import UIKit

/// O que a resposta mais recente permite fazer (as anteriores ficam só como texto).
struct AcoesDaResposta {
    var corrigir: (Int64) -> Void
    var remover: (Int64) -> Void
    var sugerir: (String) -> Void
    var responder: (String) -> Void
    var trocarRemedio: () -> Void
    var abrirBula: (Int64) -> Void
}

/// Texto com os destaques do servidor (remédios, alertas e o que é crítico em negrito).
func textoComDestaques(_ paragrafo: [Trecho], negritoGeral: Bool = false) -> Text {
    paragrafo.reduce(Text("")) { parcial, trecho in
        parcial + Text(trecho.text).fontWeight(trecho.bold || negritoGeral ? .semibold : .regular)
    }
}

struct BalaoDaPessoa: View {
    let texto: String
    var enviada = false

    var body: some View {
        VStack(alignment: .trailing, spacing: 4) {
            Text(texto)
                .font(Fonte.corpo)
                .foregroundStyle(Cores.naMarca)
                .padding(.horizontal, 16)
                .padding(.vertical, 12)
                .background(Cores.marca, in: UnevenRoundedRectangle(topLeadingRadius: 20, bottomLeadingRadius: 20, bottomTrailingRadius: 6, topTrailingRadius: 20, style: .continuous))
                .accessibilityLabel("Você perguntou: \(texto)")
            if enviada {
                Label("Enviada", systemImage: "checkmark").font(.caption).foregroundStyle(Cores.textoSuave)
            }
        }
        .frame(maxWidth: .infinity, alignment: .trailing)
        .padding(.leading, 48)
    }
}

/// Símbolo do BULA-IA num círculo branco (a marca precisa de fundo claro, nos dois temas).
struct AvatarDoBulai: View {
    var body: some View {
        Image("Simbolo").resizable().scaledToFit().padding(6)
            .frame(width: 36, height: 36)
            .background(.white, in: Circle())
            .overlay(Circle().strokeBorder(Cores.contorno))
            .accessibilityHidden(true)
    }
}

struct Digitando: View {
    @Environment(\.accessibilityReduceMotion) private var reduzirMovimento
    @State private var fase = 0

    var body: some View {
        HStack(alignment: .top, spacing: 10) {
            AvatarDoBulai()
            HStack(spacing: 6) {
                ForEach(0 ..< 3, id: \.self) { indice in
                    Circle().fill(Cores.textoSuave).frame(width: 9, height: 9)
                        .opacity(reduzirMovimento || fase == indice ? 1 : 0.35)
                }
                Text("O BULA-IA está procurando na bula").font(Fonte.pequena).foregroundStyle(Cores.textoSuave).padding(.leading, 6)
            }
            .padding(14)
            .background(Cores.superficie, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
            Spacer(minLength: 0)
        }
        .accessibilityElement(children: .ignore)
        .accessibilityLabel("Digitando…")
        .task {
            guard !reduzirMovimento else {
                return
            }
            while !Task.isCancelled {
                try? await Task.sleep(nanoseconds: 350_000_000)
                fase = (fase + 1) % 3
            }
        }
    }
}

/// Balão da resposta do BULA-IA, na mesma ordem do Android e do PWA.
struct BalaoDaResposta: View {
    let resposta: Resposta
    var acoes: AcoesDaResposta?
    var abrirBula: ((Int64) -> Void)?
    @Environment(Ambiente.self) private var ambiente

    var body: some View {
        HStack(alignment: .top, spacing: 10) {
            AvatarDoBulai()
            VStack(alignment: .leading, spacing: 12) {
                if let urgencia = resposta.urgency {
                    Text(urgencia).font(Fonte.corpoForte).foregroundStyle(Cores.erro)
                        .padding(12)
                        .frame(maxWidth: .infinity, alignment: .leading)
                        .background(Cores.erroFundo, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                        .overlay(RoundedRectangle(cornerRadius: 14, style: .continuous).strokeBorder(Cores.erro, lineWidth: 2))
                        .accessibilityAddTraits(.updatesFrequently)
                    BlocosDeAjuda(grupos: resposta.ajuda)
                }
                ForEach(Array(resposta.paragraphs.enumerated()), id: \.offset) { indice, paragrafo in
                    textoComDestaques(paragrafo, negritoGeral: resposta.status == Resposta.bloqueada && (!resposta.crisis || indice == 0))
                        .font(Fonte.corpo)
                        .foregroundStyle(Cores.texto)
                        .fixedSize(horizontal: false, vertical: true)
                        .textSelection(.enabled)
                }
                if let alerta = resposta.alert {
                    VStack(alignment: .leading, spacing: 8) {
                        ForEach(alerta.items, id: \.self) { item in
                            VStack(alignment: .leading, spacing: 4) {
                                Text(item.text).font(Fonte.corpoForte)
                                if let gravidade = item.severity, gravidade != "duplicate" {
                                    SeloDeGravidade(gravidade: gravidade, texto: item.gravidade)
                                }
                            }
                        }
                    }
                    .padding(12)
                    .frame(maxWidth: .infinity, alignment: .leading)
                    .background(Cores.superficie.opacity(0.75), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                }
                if resposta.urgency == nil {
                    BlocosDeAjuda(grupos: resposta.ajuda)
                }
                if let fonte = resposta.source, !resposta.foiRespondida {
                    Label(fonte, systemImage: "book.closed").font(Fonte.pequena).foregroundStyle(Cores.textoSuave)
                }
                if resposta.podeOuvir {
                    let id = resposta.uuid ?? resposta.question
                    Button {
                        ambiente.voz.alternar(resposta.textoCorrido, id: id)
                    } label: {
                        Label(ambiente.voz.lendo == id ? "Parar de ouvir" : "Ouvir", systemImage: ambiente.voz.lendo == id ? "stop.circle.fill" : "speaker.wave.2.fill")
                            .font(Fonte.rotulo).frame(minHeight: 48).padding(.horizontal, 14)
                            .overlay(Capsule().strokeBorder(Cores.marca.opacity(0.4), lineWidth: 1.5))
                    }
                    .buttonStyle(.plain)
                    .foregroundStyle(Cores.marca)
                }
                if let acoes {
                    acoesDoBalao(acoes)
                } else if resposta.status != Resposta.bloqueada, resposta.status != Resposta.semRemedio {
                    remedios(nil)
                }
                if !resposta.sources.isEmpty {
                    FontesUtilizadas(fontes: resposta.sources)
                }
            }
            .padding(14)
            .frame(maxWidth: .infinity, alignment: .leading)
            .background(fundo, in: UnevenRoundedRectangle(topLeadingRadius: 6, bottomLeadingRadius: 20, bottomTrailingRadius: 20, topTrailingRadius: 20, style: .continuous))
            .overlay(UnevenRoundedRectangle(topLeadingRadius: 6, bottomLeadingRadius: 20, bottomTrailingRadius: 20, topTrailingRadius: 20, style: .continuous).stroke(borda, lineWidth: borda == .clear ? 0 : 1.5))
        }
    }

    private var fundo: Color {
        if let alerta = resposta.alert {
            return alerta.alergia ? Cores.erroFundo : Cores.destaqueSuave
        }
        switch resposta.tone {
        case "danger": return Cores.erroFundo
        case "warning": return Cores.destaqueSuave
        default: return Cores.superficie
        }
    }

    private var borda: Color {
        guard let alerta = resposta.alert else {
            return .clear
        }
        return alerta.alergia ? Cores.erro : Cores.destaque
    }

    @ViewBuilder
    private func acoesDoBalao(_ acoes: AcoesDaResposta) -> some View {
        if !resposta.respostasRapidas.isEmpty {
            VStack(spacing: 8) {
                ForEach(resposta.respostasRapidas, id: \.self) { opcao in
                    Button {
                        acoes.responder(opcao)
                    } label: {
                        HStack {
                            Text(opcao).font(Fonte.corpoForte).multilineTextAlignment(.leading)
                            Spacer()
                            Image(systemName: "chevron.right")
                        }
                        .padding(.horizontal, 16)
                        .frame(maxWidth: .infinity, minHeight: 56)
                        .foregroundStyle(Cores.marca)
                        .background(Cores.superficie, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
                        .overlay(RoundedRectangle(cornerRadius: 16, style: .continuous).strokeBorder(Cores.marca.opacity(0.35)))
                    }
                    .buttonStyle(.plain)
                }
            }
        }
        if !resposta.guesses.isEmpty {
            Fileira {
                ForEach(resposta.guesses) { palpite in
                    Pilula(texto: palpite.name, escolhida: true) { acoes.corrigir(palpite.id) }
                }
            }
        }
        if !resposta.meusRemedios.isEmpty {
            Text("Ou toque em um dos seus remédios:").font(Fonte.pequena)
            Fileira {
                ForEach(resposta.meusRemedios) { remedio in
                    Pilula(texto: remedio.name) { acoes.corrigir(remedio.id) }
                }
            }
        }
        if !resposta.outrosRemedios.isEmpty {
            Fileira {
                ForEach(resposta.outrosRemedios, id: \.self) { nome in
                    Pilula(texto: nome) { acoes.sugerir("Sobre \(nome):") }
                }
            }
        }
        if !resposta.suggestions.isEmpty {
            Fileira {
                ForEach(resposta.suggestions, id: \.self) { sugestao in
                    Pilula(texto: sugestao, escolhida: true) { acoes.sugerir(sugestao) }
                }
            }
        }
        ForEach(resposta.corrections) { correcao in
            VStack(alignment: .leading, spacing: 8) {
                Text("Entendemos “\(correcao.escrito)” como \(correcao.name). Não é isso?").font(Fonte.corpoForte)
                Fileira {
                    Pilula(texto: "Escolher o certo", acao: acoes.trocarRemedio)
                    Pilula(texto: "Não era esse") { acoes.remover(correcao.id) }
                }
            }
            .padding(12)
            .background(Cores.marcaSuave.opacity(0.6), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
        }
        remedios(acoes)
    }

    /// "Falando de:": cada remédio abre a bula simples; na resposta mais recente dá para tirar um remédio ou corrigir.
    @ViewBuilder
    private func remedios(_ acoes: AcoesDaResposta?) -> some View {
        if !resposta.medicines.isEmpty {
            Divider()
            Text("Falando de:").font(Fonte.pequena).foregroundStyle(Cores.textoSuave)
            Fileira {
                ForEach(resposta.medicines) { remedio in
                    HStack(spacing: 2) {
                        Button {
                            (acoes?.abrirBula ?? abrirBula)?(remedio.id)
                        } label: {
                            HStack(spacing: 6) {
                                if remedio.temBula {
                                    Image(systemName: "book.closed.fill")
                                }
                                Text(remedio.name).font(Fonte.corpoForte)
                            }
                            .padding(.leading, 14)
                            .padding(.trailing, acoes != nil && resposta.medicines.count > 1 ? 4 : 14)
                            .frame(minHeight: 44)
                        }
                        .accessibilityHint(remedio.temBula ? "Ver a bula simples" : "Ver o remédio")
                        if let acoes, resposta.medicines.count > 1 {
                            Button {
                                acoes.remover(remedio.id)
                            } label: {
                                Image(systemName: "xmark").font(.footnote.weight(.bold)).frame(width: 36, height: 44)
                            }
                            .accessibilityLabel("Tirar \(remedio.name) da pergunta")
                        }
                    }
                    .foregroundStyle(Cores.marca)
                    .background(Cores.superficie, in: Capsule())
                    .overlay(Capsule().strokeBorder(Cores.marca.opacity(0.3)))
                    .buttonStyle(.plain)
                }
            }
            if let acoes {
                Button("Não é esse remédio?", action: acoes.trocarRemedio).font(Fonte.rotulo).frame(minHeight: 44)
            }
        }
    }
}

/// Selo de gravidade (cor + texto, nunca só cor).
struct SeloDeGravidade: View {
    let gravidade: String
    let texto: String?

    var body: some View {
        let tom = Cores.doTom(gravidade)
        Selo(texto: texto ?? gravidade, cor: tom.cor, fundo: tom.fundo)
    }
}

/// Blocos de ajuda do caso (crise, superdose, sintoma grave): título, texto curto e botões grandes de ligar.
struct BlocosDeAjuda: View {
    let grupos: [GrupoDeAjuda]
    @Environment(\.openURL) private var abrir

    var body: some View {
        ForEach(Array(grupos.enumerated()), id: \.offset) { _, grupo in
            let tom = Cores.doTom(grupo.tone)
            VStack(alignment: .leading, spacing: 8) {
                Text(grupo.title ?? "Precisa de ajuda agora? Toque para ligar.").font(Fonte.corpoForte).foregroundStyle(tom.cor).accessibilityAddTraits(.isHeader)
                if let texto = grupo.text {
                    Text(texto).font(Fonte.corpo).foregroundStyle(Cores.texto).fixedSize(horizontal: false, vertical: true)
                }
                ForEach(grupo.telefones, id: \.self) { telefone in
                    Button {
                        if let url = URL(string: "tel:\(telefone.tel)") {
                            abrir(url)
                        }
                    } label: {
                        HStack(spacing: 12) {
                            Image(systemName: "phone.fill").font(.title3)
                            VStack(alignment: .leading, spacing: 2) {
                                Text("Ligar \(telefone.number) · \(telefone.name)").font(Fonte.rotulo)
                                if !telefone.detail.isEmpty {
                                    Text(telefone.detail).font(Fonte.pequena).opacity(0.9)
                                }
                            }
                            Spacer(minLength: 0)
                        }
                        .padding(.horizontal, 16)
                        .frame(maxWidth: .infinity, minHeight: 60)
                        .foregroundStyle(.white)
                        .background(tom.cor == Cores.erro ? Color(UIColor(hex: 0xB42318)) : Color(UIColor(hex: 0x12344D)), in: RoundedRectangle(cornerRadius: 16, style: .continuous))
                    }
                    .buttonStyle(.plain)
                    .accessibilityLabel("Ligar para \(telefone.name), \(telefone.number). \(telefone.detail)")
                }
            }
            .padding(12)
            .frame(maxWidth: .infinity, alignment: .leading)
            .background(tom.fundo, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
        }
    }
}

/// "Fontes utilizadas" (recolhido): cada fonte num cartão com a bula, seção · página e a data; o trecho abre ao tocar.
struct FontesUtilizadas: View {
    let fontes: [Fonte]
    @State private var aberto = false
    @State private var trechoAberto: Int?

    var body: some View {
        DisclosureGroup(isExpanded: $aberto) {
            VStack(alignment: .leading, spacing: 8) {
                ForEach(Array(fontes.enumerated()), id: \.offset) { indice, fonte in
                    Button {
                        withAnimation { trechoAberto = trechoAberto == indice ? nil : indice }
                    } label: {
                        VStack(alignment: .leading, spacing: 4) {
                            Text(fonte.titulo).font(Fonte.corpoForte).foregroundStyle(Cores.texto).multilineTextAlignment(.leading)
                            Text([fonte.section, fonte.pagina.map { "página \($0)" }].compactMap { $0 }.joined(separator: " · ")).font(Fonte.pequena).foregroundStyle(Cores.textoSuave)
                            if let processada = fonte.processed {
                                Text("Bula de \(processada)").font(.caption).foregroundStyle(Cores.textoSuave)
                            }
                            if trechoAberto == indice, !fonte.excerpt.isEmpty {
                                ScrollView {
                                    Text(fonte.excerpt).font(Fonte.pequena).foregroundStyle(Cores.texto).frame(maxWidth: .infinity, alignment: .leading)
                                }
                                .frame(maxHeight: 220)
                                .padding(.top, 4)
                            }
                            Text(trechoAberto == indice ? "Esconder o trecho" : "Ver o trecho").font(.caption.weight(.semibold)).foregroundStyle(Cores.marca)
                        }
                        .padding(12)
                        .frame(maxWidth: .infinity, alignment: .leading)
                        .background(Cores.superficieElevada, in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                    }
                    .buttonStyle(.plain)
                }
            }
            .padding(.top, 6)
        } label: {
            Label("Fontes utilizadas", systemImage: "books.vertical").font(Fonte.rotulo).foregroundStyle(Cores.marca)
        }
    }
}

/// Painel "No seu tratamento": o remédio cruzado com o que a pessoa usa e com as alergias dela.
struct PainelDoTratamento: View {
    let tratamento: NoTratamento

    var body: some View {
        let alerta = !tratamento.alergias.isEmpty || tratamento.maiorGravidade == "major"
        let cuidado = tratamento.maiorGravidade == "moderate" || tratamento.interactions.contains { $0.severity == "duplicate" }
        VStack(alignment: .leading, spacing: 10) {
            Label("No seu tratamento", systemImage: alerta || cuidado ? "exclamationmark.triangle.fill" : "cross.case.fill")
                .font(Fonte.rotulo).foregroundStyle(alerta ? Cores.erro : cuidado ? Cores.naDestaqueSuave : Cores.marca)
                .accessibilityAddTraits(.isHeader)
            if !tratamento.consented {
                Text("Para cruzar com o que você já usa e com suas alergias, registre seu perfil de saúde.").font(Fonte.corpo)
            } else {
                if tratamento.emUso {
                    let posologia = [tratamento.posology?.dosage, tratamento.posology?.frequency].compactMap { $0 }.joined(separator: ", ")
                    let receita = tratamento.posology?.prescriber.map { " · receita de \($0)" } ?? ""
                    Text("✓ \(tratamento.medicine) está entre os remédios que você usa\(posologia.isEmpty ? "" : " (\(posologia))")\(receita).").font(Fonte.corpoForte)
                } else if tratamento.totalDeRemedios == 0 {
                    Text("Você ainda não registrou remédios em uso; não há o que cruzar.").font(Fonte.corpo)
                } else if tratamento.interactions.isEmpty, tratamento.alergias.isEmpty {
                    (Text("\(tratamento.medicine) não está na sua lista. Nenhuma interação registrada com o que você usa. ") + Text("Isso não garante que seja seguro.").bold()).font(Fonte.corpo)
                }
                ForEach(tratamento.alergias, id: \.self) { alergia in
                    Text("⚠ Você registrou alergia a \(alergia.allergy): este remédio \(alergia.via == "classe" ? "pertence à mesma classe de" : "contém") \(alergia.ingredient).")
                        .font(Fonte.corpoForte).foregroundStyle(Cores.erro)
                }
                ForEach(tratamento.interactions, id: \.self) { interacao in
                    VStack(alignment: .leading, spacing: 4) {
                        Text("Com \(interacao.medicine)").font(Fonte.corpoForte)
                        SeloDeGravidade(gravidade: interacao.severity, texto: interacao.gravidade)
                        if interacao.divergent {
                            Text("A base registra gravidades diferentes; mostramos a mais alta.").font(.caption)
                        }
                    }
                    .padding(12)
                    .frame(maxWidth: .infinity, alignment: .leading)
                    .background(Cores.superficie.opacity(0.85), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                }
                if !tratamento.interactions.isEmpty {
                    Text("Gravidade conforme a base DDInter; não é uma avaliação do seu caso.").font(.caption).foregroundStyle(Cores.textoSuave)
                } else if tratamento.emUso, tratamento.totalDeRemedios > 1 {
                    (Text("Nenhuma interação registrada com os seus outros remédios. ") + Text("Isso não garante que seja seguro.").bold()).font(Fonte.corpo)
                }
                if !tratamento.unresolved.isEmpty {
                    Text("Não foi possível verificar: \(tratamento.unresolved.joined(separator: ", ")). Remédio sem bula cadastrada ou substância não identificada: não há o que cruzar.")
                        .font(Fonte.pequena).foregroundStyle(Cores.textoSuave)
                }
            }
        }
        .padding(16)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(alerta ? Cores.erroFundo : cuidado ? Cores.destaqueSuave : Cores.superficie, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
        .overlay(RoundedRectangle(cornerRadius: 18, style: .continuous).strokeBorder(alerta ? Cores.erro : cuidado ? Cores.destaque : Cores.contorno))
    }
}
