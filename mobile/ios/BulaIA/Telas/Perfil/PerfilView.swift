import BulaiCore
import SwiftUI

/// "Meus remédios": autorização dos dados de saúde, abas Remédios, Receitas e Alergias, "Dose e horários" e
/// "Apagar meus dados de saúde" (discreto, no fim). As frases de sucesso e de erro vêm do servidor.
struct PerfilView: View {
    let ambiente: Ambiente
    @State private var tela: PerfilViewModel
    @State private var aba: Int
    @State private var adicionando = false
    @State private var novaReceita = false
    @State private var novaAlergia = false
    @State private var tirando: RemedioDoPerfil?
    @State private var apagarDados = false

    init(ambiente: Ambiente, abaInicial: Int) {
        self.ambiente = ambiente
        _tela = State(initialValue: PerfilViewModel(ambiente.dependencias))
        _aba = State(initialValue: abaInicial)
    }

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 16) {
                if let erro = tela.carga.erro {
                    FaixaDeErro(texto: erro) { Task { await tela.atualizar() } }
                }
                if let perfil = tela.carga.dados {
                    if !perfil.consentimento {
                        consentimento
                    } else {
                        Picker("Seção", selection: $aba) {
                            Text("Remédios (\(perfil.remedios.count))").tag(0)
                            Text("Receitas (\(perfil.receitas.count))").tag(1)
                            Text("Alergias (\(perfil.alergias.count))").tag(2)
                        }
                        .pickerStyle(.segmented)
                        switch aba {
                        case 1: receitas(perfil)
                        case 2: alergias(perfil)
                        default: remedios(perfil)
                        }
                        Divider().padding(.top, 12)
                        Text("Seus dados de saúde ficam só na sua conta.").font(Fonte.pequena).foregroundStyle(Cores.textoSuave)
                        Button("Apagar meus dados de saúde", role: .destructive) { apagarDados = true }
                            .font(Fonte.pequena).frame(minHeight: 44)
                    }
                } else if tela.carga.esqueleto {
                    EsqueletoDeLista(cartoes: 3)
                }
            }
            .padding(16)
        }
        .telaBulai()
        .refreshable { await tela.atualizar() }
        .task { await tela.carregar() }
        .recado($tela.recado)
        .sheet(isPresented: $adicionando) {
            BuscaDeRemedioView(titulo: "Adicionar remédio", busca: tela.busca, usarPeloNome: { nome in
                adicionando = false
                Task { await tela.adicionar(catalogo: nil, nome: nome) }
            }) { remedio in
                adicionando = false
                Task { await tela.adicionar(catalogo: remedio.id, nome: nil) }
            }
        }
        .sheet(item: $tela.editando) { remedio in
            PosologiaView(
                nome: remedio.name,
                inicial: remedio.posologia,
                atalhos: tela.carga.dados?.atalhos ?? AtalhosDePosologia(),
                receitas: tela.carga.dados?.receitas ?? [],
                salvando: tela.ocupado,
                erro: tela.erro,
                sugerir: { frequencia, posologia, atalho in await tela.sugerir(frequencia: frequencia, posologia: posologia, atalho: atalho) },
                aoSalvar: { posologia in Task { await tela.salvar(posologia) } }
            )
        }
        .sheet(isPresented: $novaReceita) {
            NovaReceitaDoPerfilView(tela: tela)
        }
        .sheet(isPresented: $novaAlergia) {
            NovaAlergiaView(tela: tela)
        }
        .confirmationDialog(tirando.map { "Tirar \($0.name) da lista?" } ?? "", isPresented: Binding(get: { tirando != nil }, set: { if !$0 { tirando = nil } }), titleVisibility: .visible) {
            Button("Tirar", role: .destructive) {
                if let remedio = tirando {
                    Task { await tela.tirarRemedio(remedio) }
                }
                tirando = nil
            }
            Button("Cancelar", role: .cancel) { tirando = nil }
        } message: {
            Text("Os horários e os lembretes deste remédio deixam de aparecer. As doses já anotadas saem junto.")
        }
        .confirmationDialog("Apagar seus dados de saúde?", isPresented: $apagarDados, titleVisibility: .visible) {
            Button("Apagar", role: .destructive) { Task { await tela.apagarDadosDeSaude() } }
            Button("Cancelar", role: .cancel) {}
        } message: {
            Text("Remédios, horários, doses anotadas, receitas, alergias e perguntas serão apagados, e a autorização será revogada. Sua conta continua. Não dá para desfazer.")
        }
    }

    private var consentimento: some View {
        Cartao {
            Label("Guardar seus dados de saúde", systemImage: "lock.shield.fill").font(Fonte.subtitulo).foregroundStyle(Cores.marca)
            Text("Para lembrar os horários, avisar sobre alergias e interações e montar o resumo para o médico, o BULA-IA precisa guardar seus remédios, receitas e alergias.").font(Fonte.corpo)
            Text("Esses dados ficam só na sua conta, não são vendidos nem compartilhados, e você pode apagá-los quando quiser (LGPD).").font(Fonte.pequena).foregroundStyle(Cores.textoSuave)
            BotaoPrincipal(titulo: "Autorizar e continuar", simbolo: "checkmark.shield.fill", carregando: tela.ocupado) {
                Task { await tela.consentir() }
            }
        }
    }

    @ViewBuilder
    private func remedios(_ perfil: Perfil) -> some View {
        BotaoPrincipal(titulo: "Adicionar remédio", simbolo: "plus") { adicionando = true }
        if perfil.remedios.isEmpty {
            EstadoVazio(simbolo: "pills", titulo: "Nenhum remédio em uso", texto: "Adicione pelo nome ou fotografe a receita no Início.")
        }
        ForEach(perfil.remedios) { remedio in
            let abrirBula: (() -> Void)? = remedio.remedioDoCatalogo.map { id in { ambiente.navegador.abrir(.bula(id)) } }
            CartaoDoRemedio(remedio: remedio, horarios: { tela.editando = remedio }, bula: abrirBula, tirar: { tirando = remedio })
        }
        if !perfil.encerrados.isEmpty {
            TituloDeSecao(texto: "Tratamentos encerrados").padding(.top, 8)
            ForEach(perfil.encerrados) { remedio in
                HStack {
                    VStack(alignment: .leading) {
                        Text(remedio.name + (remedio.fim.map { " · até \($0)" } ?? "")).font(Fonte.corpo)
                        if let dose = remedio.dosage {
                            Text(dose).font(.caption).foregroundStyle(Cores.textoSuave)
                        }
                    }
                    Spacer()
                    Button {
                        tirando = remedio
                    } label: {
                        Image(systemName: "trash").frame(width: 44, height: 44)
                    }
                    .accessibilityLabel("Tirar \(remedio.name) da lista")
                }
                .padding(.horizontal, 14)
                .background(Cores.superficie, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
            }
        }
    }

    @ViewBuilder
    private func receitas(_ perfil: Perfil) -> some View {
        Text("Receitas fotografadas entram aqui sozinhas. Você também pode registrar uma receita à mão.").font(Fonte.corpo).foregroundStyle(Cores.textoSuave)
        HStack(spacing: 10) {
            BotaoSecundario(titulo: "Registrar receita", simbolo: "square.and.pencil") { novaReceita = true }
            BotaoSecundario(titulo: "Fotografar", simbolo: "camera.fill") { ambiente.navegador.abrir(.receita) }
        }
        if perfil.receitas.isEmpty {
            Text("Nenhuma receita registrada.").font(Fonte.corpo).foregroundStyle(Cores.textoSuave)
        }
        ForEach(perfil.receitas) { receita in
            Cartao {
                HStack(alignment: .top) {
                    VStack(alignment: .leading, spacing: 4) {
                        Text(receita.prescriber).font(Fonte.corpoForte)
                        Text([receita.registration, receita.data].compactMap { $0 }.joined(separator: " · ")).font(Fonte.pequena).foregroundStyle(Cores.textoSuave)
                        if !receita.remedios.isEmpty {
                            Text(receita.remedios.joined(separator: ", ")).font(Fonte.pequena)
                        }
                        if let notas = receita.notes {
                            Text(notas).font(.caption).foregroundStyle(Cores.textoSuave)
                        }
                    }
                    Spacer()
                    Button {
                        Task { await tela.tirarReceita(receita.id) }
                    } label: {
                        Image(systemName: "trash").foregroundStyle(Cores.erro).frame(width: 44, height: 44)
                    }
                    .accessibilityLabel("Apagar a receita de \(receita.prescriber)")
                }
            }
        }
    }

    @ViewBuilder
    private func alergias(_ perfil: Perfil) -> some View {
        Text("O BULA-IA avisa quando um remédio que você perguntar, fotografar ou verificar tiver essa substância ou classe.").font(Fonte.corpo).foregroundStyle(Cores.textoSuave)
        BotaoSecundario(titulo: "Registrar alergia", simbolo: "plus") { novaAlergia = true }
        if perfil.alergias.isEmpty {
            Text("Nenhuma alergia registrada.").font(Fonte.corpo).foregroundStyle(Cores.textoSuave)
        }
        ForEach(perfil.alergias) { alergia in
            HStack(alignment: .top) {
                Image(systemName: "allergens").foregroundStyle(Cores.erro).font(.title3).accessibilityHidden(true)
                VStack(alignment: .leading, spacing: 2) {
                    Text(alergia.substance).font(Fonte.corpoForte)
                    if let reacao = alergia.reaction {
                        Text(reacao).font(Fonte.pequena).foregroundStyle(Cores.textoSuave)
                    }
                }
                Spacer()
                Button {
                    Task { await tela.tirarAlergia(alergia.id) }
                } label: {
                    Image(systemName: "trash").foregroundStyle(Cores.erro).frame(width: 44, height: 44)
                }
                .accessibilityLabel("Apagar a alergia a \(alergia.substance)")
            }
            .padding(14)
            .background(Cores.erroFundo, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
        }
    }
}

/// Cartão de um remédio em uso: dose, horários em pílulas (ou o intervalo), botões Horários e Bula.
struct CartaoDoRemedio: View {
    let remedio: RemedioDoPerfil
    let horarios: (() -> Void)?
    let bula: (() -> Void)?
    var tirar: (() -> Void)?

    var body: some View {
        Cartao {
            HStack(alignment: .top, spacing: 12) {
                Image(systemName: "pills.fill").font(.title2).foregroundStyle(Cores.marca)
                    .frame(width: 44, height: 44).background(Cores.marcaSuave, in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                    .accessibilityHidden(true)
                VStack(alignment: .leading, spacing: 4) {
                    Text(remedio.name).font(Fonte.corpoForte)
                    if let principio = remedio.principioAtivo {
                        Text(principio).font(.caption).foregroundStyle(Cores.textoSuave)
                    }
                    if let dose = remedio.dosage, !dose.isEmpty {
                        Text(dose).font(Fonte.pequena)
                    }
                }
                Spacer(minLength: 0)
                if let tirar {
                    Menu {
                        Button(role: .destructive, action: tirar) {
                            Label("Tirar da lista", systemImage: "trash")
                        }
                    } label: {
                        Image(systemName: "ellipsis").frame(width: 44, height: 44)
                    }
                    .accessibilityLabel("Mais ações de \(remedio.name)")
                }
            }
            if !remedio.noCatalogo {
                Label("Fora do catálogo · nossa equipe vai conferir", systemImage: "exclamationmark.triangle.fill").font(.caption).foregroundStyle(Cores.naDestaqueSuave)
            }
            if !remedio.horarios.chips.isEmpty {
                Fileira {
                    ForEach(remedio.horarios.chips, id: \.self) { chip in
                        Text(chip).font(.subheadline.monospacedDigit().weight(.semibold)).padding(.horizontal, 12).padding(.vertical, 6)
                            .foregroundStyle(Cores.marca).background(Cores.marcaSuave, in: Capsule())
                    }
                }
            }
            if let nota = remedio.horarios.note {
                Text(nota).font(Fonte.pequena).foregroundStyle(remedio.horarios.missing ? Cores.naDestaqueSuave : Cores.textoSuave)
            }
            let detalhes = [remedio.fim.map { "até \($0)" }, remedio.prescritor.map { "receita de \($0)" }, remedio.lembretes ? nil : "sem lembretes"].compactMap { $0 }
            if !detalhes.isEmpty {
                Text(detalhes.joined(separator: " · ")).font(.caption).foregroundStyle(Cores.textoSuave)
            }
            HStack(spacing: 10) {
                if let horarios {
                    BotaoSecundario(titulo: "Horários", simbolo: "clock", acao: horarios)
                        .accessibilityLabel("Dose e horários de \(remedio.name)")
                }
                if let bula {
                    BotaoSecundario(titulo: "Bula", simbolo: "book.closed", acao: bula)
                }
            }
        }
    }
}

private struct NovaReceitaDoPerfilView: View {
    @Bindable var tela: PerfilViewModel
    @State private var prescritor = ""
    @State private var registro = ""
    @State private var data: Date?
    @State private var escolhendoData = false
    @State private var observacoes = ""
    @Environment(\.dismiss) private var fechar

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(alignment: .leading, spacing: 16) {
                    CampoDeTexto(titulo: "Quem receitou", texto: $prescritor, ajuda: "Ex.: Dra. Ana Souza", conteudo: .name, maiusculas: .words)
                    CampoDeTexto(titulo: "Registro (CRM, opcional)", texto: $registro, maiusculas: .characters)
                    Button {
                        escolhendoData = true
                    } label: {
                        Label(data.map { "Data da receita: \(Datas.formatar($0, "dd/MM/yyyy"))" } ?? "Data da receita (opcional)", systemImage: "calendar").frame(minHeight: 44)
                    }
                    CampoDeTexto(titulo: "Observações (opcional)", texto: $observacoes)
                    if let erro = tela.erro {
                        FaixaDeErro(texto: erro)
                    }
                    BotaoPrincipal(titulo: "Guardar receita", carregando: tela.ocupado, desabilitado: prescritor.trimmingCharacters(in: .whitespaces).count < 3) {
                        Task {
                            let pedido = PedidoDeReceitaDoPerfil(
                                prescriber: prescritor.trimmingCharacters(in: .whitespaces),
                                registration: registro.isEmpty ? nil : registro,
                                data: data.map { Datas.paraFormulario($0) },
                                notes: observacoes.isEmpty ? nil : observacoes
                            )
                            if await tela.adicionarReceita(pedido) {
                                fechar()
                            }
                        }
                    }
                }
                .padding(20)
            }
            .background(Cores.fundo.ignoresSafeArea())
            .navigationTitle("Nova receita")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar { ToolbarItem(placement: .cancellationAction) { Button("Cancelar") { fechar() } } }
            .sheet(isPresented: $escolhendoData) {
                SeletorDeData(titulo: "Data da receita", inicial: data ?? Date()) { data = $0 }
            }
        }
    }
}

private struct NovaAlergiaView: View {
    @Bindable var tela: PerfilViewModel
    @State private var substancia = ""
    @State private var reacao = ""
    @Environment(\.dismiss) private var fechar

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(alignment: .leading, spacing: 16) {
                    CampoDeTexto(titulo: "Substância ou classe", texto: $substancia, ajuda: "Ex.: dipirona, penicilinas")
                    CampoDeTexto(titulo: "O que acontece (opcional)", texto: $reacao, ajuda: "Ex.: manchas na pele, falta de ar")
                    if let erro = tela.erro {
                        FaixaDeErro(texto: erro)
                    }
                    BotaoPrincipal(titulo: "Registrar alergia", carregando: tela.ocupado, desabilitado: substancia.trimmingCharacters(in: .whitespaces).count < 3) {
                        Task {
                            if await tela.adicionarAlergia(substancia: substancia, reacao: reacao) {
                                fechar()
                            }
                        }
                    }
                }
                .padding(20)
            }
            .background(Cores.fundo.ignoresSafeArea())
            .navigationTitle("Registrar alergia")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar { ToolbarItem(placement: .cancellationAction) { Button("Cancelar") { fechar() } } }
        }
    }
}
