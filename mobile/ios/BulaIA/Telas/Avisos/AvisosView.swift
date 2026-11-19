import BulaiCore
import SwiftUI

/// Central de avisos (sininho): tocar abre a tela do aviso; deslizar (ou a ação do VoiceOver) apaga.
struct AvisosView: View {
    let ambiente: Ambiente
    @State private var tela: AvisosViewModel
    @State private var confirmarApagarTodos = false

    init(ambiente: Ambiente) {
        self.ambiente = ambiente
        _tela = State(initialValue: AvisosViewModel(ambiente.dependencias))
    }

    var body: some View {
        List {
            if let erro = tela.carga.erro {
                FaixaDeErro(texto: erro) { Task { await tela.atualizar() } }
                    .listRowBackground(Color.clear)
            }
            if let central = tela.carga.dados {
                Section {
                    if central.notices.isEmpty {
                        EstadoVazio(simbolo: "bell.slash", titulo: "Nenhum aviso", texto: "Quando houver um lembrete ou uma novidade dos seus remédios, ele aparece aqui.")
                            .listRowBackground(Color.clear)
                    }
                    ForEach(central.notices) { aviso in
                        Button {
                            Task { await tela.abrir(aviso) }
                        } label: {
                            LinhaDoAviso(aviso: aviso)
                        }
                        .buttonStyle(.plain)
                        .swipeActions(edge: .trailing) {
                            Button(role: .destructive) {
                                Task { await tela.apagar(aviso) }
                            } label: {
                                Label("Apagar aviso", systemImage: "trash")
                            }
                        }
                        .accessibilityAction(named: "Apagar aviso") { Task { await tela.apagar(aviso) } }
                    }
                } header: {
                    Text(central.unread == 0 ? "Nenhum aviso novo." : central.unread == 1 ? "1 aviso novo." : "\(central.unread) avisos novos.")
                }
                Section {
                    Button {
                        ambiente.navegador.abrir(.aba(.conta))
                    } label: {
                        Label("Lembretes: ajuste em Conta", systemImage: "gearshape")
                    }
                }
            } else if tela.carga.esqueleto {
                EsqueletoDeLista(cartoes: 4).listRowBackground(Color.clear)
            }
        }
        .scrollContentBackground(.hidden)
        .background(Cores.fundo.ignoresSafeArea())
        .toolbar {
            if let central = tela.carga.dados, !central.notices.isEmpty {
                ToolbarItem(placement: .topBarTrailing) {
                    Menu {
                        Button {
                            Task { await tela.marcarTodosLidos() }
                        } label: {
                            Label("Marcar como lidos", systemImage: "checkmark.circle")
                        }
                        Button(role: .destructive) {
                            confirmarApagarTodos = true
                        } label: {
                            Label("Apagar todos", systemImage: "trash")
                        }
                    } label: {
                        Image(systemName: "ellipsis.circle").frame(minWidth: 44, minHeight: 44)
                    }
                    .accessibilityLabel("Mais ações dos avisos")
                }
            }
        }
        .confirmationDialog("Apagar todos os avisos?", isPresented: $confirmarApagarTodos, titleVisibility: .visible) {
            Button("Apagar todos", role: .destructive) { Task { await tela.apagarTodos() } }
            Button("Cancelar", role: .cancel) {}
        } message: {
            Text("Os avisos lidos e não lidos serão apagados. Isso não muda seus remédios nem os lembretes.")
        }
        .refreshable { await tela.atualizar() }
        .task { await tela.carregar() }
        .onChange(of: tela.destino) { _, destino in
            if let destino {
                tela.destino = nil
                ambiente.navegador.abrir(destino)
            }
        }
        .onChange(of: tela.carga.dados?.unread) { _, novo in
            if let novo {
                ambiente.naoLidos = novo
            }
        }
        .recado($tela.recado)
    }
}

private struct LinhaDoAviso: View {
    let aviso: Aviso

    var body: some View {
        HStack(alignment: .top, spacing: 12) {
            Circle().fill(aviso.read ? Color.clear : Cores.destaque).frame(width: 10, height: 10).padding(.top, 8).accessibilityHidden(true)
            VStack(alignment: .leading, spacing: 4) {
                Text(aviso.title).font(aviso.read ? Fonte.corpo : Fonte.corpoForte).foregroundStyle(Cores.texto)
                Text(aviso.body).font(Fonte.pequena).foregroundStyle(Cores.textoSuave).fixedSize(horizontal: false, vertical: true)
                HStack {
                    Text(aviso.quando).font(.caption).foregroundStyle(Cores.textoSuave)
                    if !aviso.action.isEmpty {
                        Text("· \(aviso.action)").font(.caption.weight(.semibold)).foregroundStyle(Cores.marca)
                    }
                }
            }
            Spacer(minLength: 0)
        }
        .padding(.vertical, 6)
        .contentShape(Rectangle())
        .accessibilityElement(children: .combine)
        .accessibilityHint(aviso.read ? "" : "Não lido")
    }
}
