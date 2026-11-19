import Foundation
import Testing
@testable import BulaiCore

/// Regras dos lembretes locais (a versão iOS do `AgendadorDeAlarmesTest` do Android).
@Suite("Planejamento dos lembretes")
struct PlanejadorTests {
    let agora = data("2026-11-05T07:30:00-03:00")

    private func planejar(_ doses: [DoseDaAgenda], marcadas: Set<String> = [], adiados: [String: Date] = [:], jaPlanejados: [String: Date] = [:], insistir: Bool = false, agora: Date? = nil) -> [LembretePlanejado] {
        PlanejadorDeLembretes.planejar(agenda: agenda(doses), agora: agora ?? self.agora, marcadas: marcadas, adiados: adiados, jaPlanejados: jaPlanejados, insistir: insistir, intervaloDeInsistenciaMinutos: 10)
    }

    @Test("Lembra só doses pendentes, com lembrete, não marcadas no servidor nem no aparelho")
    func filtro() {
        let planejados = planejar([
            doseDaAgenda("a", "2026-11-05T08:00:00-03:00"),
            doseDaAgenda("b", "2026-11-05T09:00:00-03:00", status: Dose.tomada),
            doseDaAgenda("c", "2026-11-05T10:00:00-03:00", lembrar: false),
            doseDaAgenda("d", "2026-11-05T11:00:00-03:00"),
        ], marcadas: ["d"])
        #expect(planejados.map(\.chave) == ["a"])
        #expect(planejados.first?.quando == data("2026-11-05T08:00:00-03:00"))
        #expect(planejados.first?.identificador == "dose|a")
    }

    @Test("Dose que passou há até 15 min e nunca foi lembrada toca logo; mais antiga, não")
    func atrasoRecente() {
        let planejados = planejar([
            doseDaAgenda("recente", "2026-11-05T07:20:00-03:00"),
            doseDaAgenda("antiga", "2026-11-05T07:00:00-03:00"),
        ])
        #expect(planejados.map(\.chave) == ["recente"])
        #expect(planejados.first!.quando > agora)
    }

    @Test("Lembrete já entregue não toca de novo numa nova sincronização")
    func jaEntregue() {
        let planejados = planejar([doseDaAgenda("a", "2026-11-05T07:25:00-03:00")], jaPlanejados: ["a": data("2026-11-05T07:25:00-03:00")])
        #expect(planejados.isEmpty)
    }

    @Test("Dose adiada lembra no novo horário; adiamento que já passou não volta")
    func adiada() {
        let futuro = agora.addingTimeInterval(600)
        #expect(planejar([doseDaAgenda("a", "2026-11-05T07:25:00-03:00")], adiados: ["a": futuro]).first?.quando == futuro)
        #expect(planejar([doseDaAgenda("a", "2026-11-05T07:25:00-03:00")], adiados: ["a": agora.addingTimeInterval(-60)]).isEmpty)
    }

    @Test("Modo insistir repete enquanto não marcar, inclusive depois do primeiro lembrete")
    func insistir() {
        let antes = planejar([doseDaAgenda("a", "2026-11-05T08:00:00-03:00")], insistir: true)
        #expect(antes.map(\.repeticao) == [0, 1, 2])
        #expect(antes[1].quando == data("2026-11-05T08:10:00-03:00"))
        #expect(antes[2].identificador == "dose|a#2")

        let depois = planejar([doseDaAgenda("a", "2026-11-05T08:00:00-03:00")], jaPlanejados: ["a": data("2026-11-05T08:00:00-03:00")], insistir: true, agora: data("2026-11-05T08:05:00-03:00"))
        #expect(depois.map(\.repeticao) == [1, 2])
    }

    @Test("Fica abaixo do limite de notificações pendentes do iOS, com as mais próximas")
    func limite() {
        let doses = (0 ..< 80).map { indice in
            doseDaAgenda("d\(indice)", Datas.isoAgora(agora.addingTimeInterval(TimeInterval((indice + 1) * 600))))
        }
        let planejados = planejar(doses)
        #expect(planejados.count == PlanejadorDeLembretes.limite)
        #expect(planejados.first?.chave == "d0")
    }

    @Test("Sem lembretes na conta (ou sem agenda), nada é planejado")
    func semLembretes() {
        #expect(PlanejadorDeLembretes.planejar(agenda: nil, agora: agora, marcadas: [], adiados: [:], jaPlanejados: [:], insistir: false, intervaloDeInsistenciaMinutos: 10).isEmpty)
        #expect(planejar([doseDaAgenda("a", "2026-11-05T08:00:00-03:00", lembrar: false)]).isEmpty)
    }

    @Test("Identificador da notificação volta para a chave da dose (inclusive as repetições)")
    func identificadores() {
        #expect(LembretePlanejado.chave(doIdentificador: "dose|12|2026-11-05T11:00:00+00:00") == "12|2026-11-05T11:00:00+00:00")
        #expect(LembretePlanejado.chave(doIdentificador: "dose|12|2026-11-05T11:00:00+00:00#2") == "12|2026-11-05T11:00:00+00:00")
        #expect(LembretePlanejado.chave(doIdentificador: "aviso|x") == nil)
        #expect(LembretePlanejado.identificadores(chave: "a") == ["dose|a", "dose|a#1", "dose|a#2"])
    }

    @Test("Próxima dose do widget: não marcada, até o atraso tolerado")
    func proxima() {
        let doses = [
            doseDaAgenda("velha", "2026-11-05T06:00:00-03:00"),
            doseDaAgenda("feita", "2026-11-05T07:00:00-03:00", status: Dose.tomada),
            doseDaAgenda("atrasada", "2026-11-05T07:00:00-03:00"),
            doseDaAgenda("depois", "2026-11-05T12:00:00-03:00"),
        ]
        #expect(PlanejadorDeLembretes.proximaDose(agenda: agenda(doses), agora: agora, marcadas: [])?.key == "atrasada")
        #expect(PlanejadorDeLembretes.proximaDose(agenda: agenda(doses), agora: agora, marcadas: ["atrasada"])?.key == "depois")
    }
}

/// Sincronização, marcação pela notificação/widget e fila sem rede.
@Suite("Sincronização e marcação de doses")
@MainActor
struct SincronizadorTests {
    private func agendaJSON(_ doses: [DoseDaAgenda]) -> Data {
        codificar(agenda(doses))
    }

    @Test("Sincronizar guarda a agenda, agenda os lembretes e mostra só avisos novos que não são de dose")
    func sincronizar() async {
        let app = AppDeTeste.conectado()
        app.transporte.responder("GET agenda", 200, agendaJSON([doseDaAgenda("a", "2026-11-05T08:00:00-03:00")]))
        app.transporte.responder("GET avisos/novos", 200, json(#"{"checked_at":"2026-11-05T07:30:00-03:00","notices":[{"id":"1","kind":"dose","title":"Hora","body":"x"},{"id":"2","kind":"caregiver","title":"Pedido","body":"Ana quer acompanhar você","url":"/cuidadores"}]}"#))
        #expect(await app.sincronizador.sincronizar())
        #expect(app.notificacoes.lembretes.map(\.chave) == ["a"])
        #expect(app.notificacoes.avisos == ["2"])
        #expect(app.preferencias.avisosDesde == "2026-11-05T07:30:00-03:00")
        #expect(app.mudancasDaAgenda > 0, "o widget é recarregado")

        _ = await app.sincronizador.sincronizar()
        #expect(app.notificacoes.avisos == ["2"], "o mesmo aviso não notifica duas vezes")
    }

    @Test("Tomei pela notificação sem internet: vale na hora, fica na fila e vai depois com a hora real do toque")
    func tomeiSemRede() async throws {
        let app = AppDeTeste.conectado()
        let dose = doseDaAgenda("1|2026-11-05T11:00:00+00:00", "2026-11-05T08:00:00-03:00")
        await app.sincronizador.salvarAgenda(agenda([dose]))
        #expect(app.notificacoes.lembretes.count == 1)

        app.transporte.falhar("POST doses", .notConnectedToInternet)
        app.relogio.data = data("2026-11-05T08:03:00-03:00")
        await app.sincronizador.executar(.tomei, chave: dose.key, remedioId: dose.remedioId, agendadaPara: dose.agendadaPara)
        #expect(app.notificacoes.cancelados == [dose.key])
        #expect(app.notificacoes.lembretes.isEmpty, "dose marcada não lembra mais")
        #expect(app.preferencias.filaDeMarcacoes.count == 1)
        #expect(app.preferencias.marcadasLocalmente.contains(dose.key))

        // A rede volta: a fila segue na próxima sincronização.
        app.transporte.limpar("POST doses")
        var marcada = dose
        marcada.status = Dose.tomada
        app.transporte.responder("POST doses", 200, agendaJSON([marcada]))
        app.transporte.responder("GET agenda", 200, agendaJSON([marcada]))
        app.transporte.responder("GET avisos/novos", 200, json(#"{"notices":[]}"#))
        await app.sincronizador.sincronizar()
        #expect(app.preferencias.filaDeMarcacoes.isEmpty)
        #expect(app.preferencias.marcadasLocalmente.isEmpty, "o servidor já confirmou")
        let corpo = try #require(app.transporte.corpo("POST doses"))
        #expect(corpo["status"] as? String == "taken")
        #expect(corpo["scheduled_for"] as? String == "2026-11-05T08:00:00-03:00")
        #expect(Datas.iso(corpo["recorded_at"] as? String) == data("2026-11-05T08:03:00-03:00"))
    }

    @Test("Marcação recusada pelo servidor sai da fila e a dose volta a lembrar")
    func recusada() async {
        let app = AppDeTeste.conectado()
        let dose = doseDaAgenda("a", "2026-11-05T08:00:00-03:00")
        await app.sincronizador.salvarAgenda(agenda([dose]))
        app.transporte.responder("POST doses", 422, json(#"{"message":"x","errors":{"scheduled_for":["Horário fora da janela de marcação."]}}"#))
        await app.sincronizador.executar(.pular, chave: dose.key, remedioId: 1, agendadaPara: dose.agendadaPara)
        #expect(app.preferencias.filaDeMarcacoes.isEmpty)
        #expect(!app.preferencias.marcadasLocalmente.contains("a"))
        #expect(app.notificacoes.lembretes.map(\.chave) == ["a"])
    }

    @Test("Adiar lembra de novo depois dos minutos escolhidos")
    func adiar() async {
        let app = AppDeTeste.conectado(agora: data("2026-11-05T08:00:30-03:00"))
        app.preferencias.adiarMinutos = 15
        let dose = doseDaAgenda("a", "2026-11-05T08:00:00-03:00")
        app.preferencias.agendados = ["a": data("2026-11-05T08:00:00-03:00")]
        await app.sincronizador.salvarAgenda(agenda([dose]))
        #expect(app.notificacoes.lembretes.isEmpty, "já tocou")
        await app.sincronizador.executar(.adiar, chave: "a", remedioId: 1, agendadaPara: dose.agendadaPara)
        #expect(app.notificacoes.lembretes.first?.quando == data("2026-11-05T08:15:30-03:00"))
    }

    @Test("Tomei pelo widget sem acesso ao token: marca na hora, mantém os outros lembretes e deixa na fila para o app")
    func widgetSemToken() async {
        let app = AppDeTeste(token: nil, conta: AppDeTeste.maria)
        app.preferencias.conectado = true
        app.preferencias.agenda = agenda([doseDaAgenda("a", "2026-11-05T08:00:00-03:00"), doseDaAgenda("b", "2026-11-05T20:00:00-03:00")])
        await app.sincronizador.executar(.tomei, chave: "a", remedioId: 1, agendadaPara: "2026-11-05T08:00:00-03:00")
        #expect(app.notificacoes.lembretes.map(\.chave) == ["b"])
        #expect(app.preferencias.filaDeMarcacoes.map(\.chave) == ["a"])
        #expect(app.transporte.feitos.isEmpty)
    }

    @Test("Sem conta conectada nada é sincronizado nem lembrado")
    func desconectado() async {
        let app = AppDeTeste()
        #expect(await app.sincronizador.sincronizar() == false)
        #expect(app.transporte.feitos.isEmpty)
    }

    @Test("Sem rede, a sincronização falha sem apagar a agenda guardada (lembretes continuam)")
    func offline() async {
        let app = AppDeTeste.conectado()
        await app.sincronizador.salvarAgenda(agenda([doseDaAgenda("a", "2026-11-05T08:00:00-03:00")]))
        app.transporte.falhar("GET agenda", .notConnectedToInternet)
        #expect(await app.sincronizador.sincronizar() == false)
        #expect(app.preferencias.agenda?.doses.count == 1)
        #expect(app.notificacoes.lembretes.map(\.chave) == ["a"])
    }
}

@Suite("Navegação por avisos, notificações e atalhos")
struct RotaTests {
    @Test("Rotas do site viram telas do app")
    func destinos() {
        #expect(Rota.doDestino(Destino(route: "consulta.hoje")) == .aba(.hoje))
        #expect(Rota.doDestino(Destino(route: "consulta.receita.revisar", params: ["prescription": "9"])) == .revisarReceita(9))
        #expect(Rota.doDestino(Destino(route: "consulta.cuidando.hoje", params: ["vinculo": "4"])) == .cuidando(4, nome: nil))
        #expect(Rota.doDestino(Destino(route: "consulta.resultado", params: ["consultation": "abc"])) == .resultado("abc"))
        #expect(Rota.doDestino(Destino(route: "consulta.pergunta")) == .perguntas)
        #expect(Rota.doDestino(Destino(route: "rota.que.nao.existe")) == .inicio)
    }

    @Test("Caminhos e links bulai://abrir/… (widget, notificação, atalhos e url dos avisos)")
    func caminhos() {
        #expect(Rota.doCaminho("/hoje") == .aba(.hoje))
        #expect(Rota.doCaminho("receitas/12/revisar") == .revisarReceita(12))
        #expect(Rota.doCaminho("/cuidando/3/hoje") == .cuidando(3, nome: nil))
        #expect(Rota.doCaminho("/cuidando/3/remedios") == .cuidandoRemedios(3))
        #expect(Rota.doCaminho("/consulta/86632270-f7a0-429b-bcff-db368860195e") == .resultado("86632270-f7a0-429b-bcff-db368860195e"))
        #expect(Rota.doCaminho("/historico/perguntas/u-1") == .pergunta("u-1"))
        #expect(Rota.doCaminho("/perfil?aba=2") == .perfil(aba: 0))
        #expect(Rota.doCaminho("") == nil)
        #expect(Rota.doLink(URL(string: "bulai://abrir/hoje")!) == .aba(.hoje))
        #expect(Rota.doLink(URL(string: "bulai://abrir/receitas/5/revisar")!) == .revisarReceita(5))
        #expect(Rota.doLink(Rota.link("/avisos")) == .avisos)
        #expect(Rota.doLink(URL(string: "https://outro/hoje")!) == nil)
    }
}
