import Foundation
#if canImport(FoundationNetworking)
import FoundationNetworking
#endif
import Testing
@testable import BulaiCore

/// Cliente HTTP: cabeçalhos, token, tradução dos erros (as mesmas regras do `RedeTest` do Android).
@Suite("Rede e erros HTTP")
@MainActor
struct RedeTests {
    @Test("Pedidos levam JSON, o agente do iOS e o token do aparelho")
    func cabecalhos() async throws {
        let app = AppDeTeste.conectado()
        app.transporte.responder("GET inicio", 200, contrato("home"))
        _ = try await app.api.inicio()
        let pedido = try #require(app.transporte.ultimo("GET inicio"))
        #expect(pedido.value(forHTTPHeaderField: "Accept") == "application/json")
        #expect(pedido.value(forHTTPHeaderField: "Authorization") == "Bearer bulai_app_guardado")
        #expect(pedido.value(forHTTPHeaderField: "User-Agent") == "BulaiIOS/1.0.0-teste")
        #expect(pedido.timeoutInterval == ClienteDaApi.tempoNormal)
    }

    @Test("Pergunta, receita e bula humanizada esperam mais (IA)")
    func tempoDasChamadasLentas() async throws {
        let app = AppDeTeste.conectado()
        app.transporte.responder("POST perguntar", 200, contrato("questions.ask"))
        _ = try await app.api.perguntar(PedidoDePergunta(question: "Posso tomar dipirona com café?"))
        #expect(app.transporte.ultimo("POST perguntar")?.timeoutInterval == ClienteDaApi.tempoLento)
    }

    @Test("422 vira mensagens por campo")
    func validacao() async {
        let app = AppDeTeste()
        app.transporte.responder("POST cadastro", 422, json(#"{"message":"Dados inválidos","errors":{"email":["O e-mail já está em uso."],"password":["A senha é curta."]}}"#))
        await #expect(throws: FalhaDaApi.validacao(mensagem: "O e-mail já está em uso.", campos: ["email": "O e-mail já está em uso.", "password": "A senha é curta."])) {
            _ = try await app.api.cadastrar(PedidoDeCadastro(name: "A", email: "a@a", password: "x", confirmacao: "x", terms: true))
        }
    }

    @Test("401 com token encerra a sessão local; sem token (entrar) não")
    func naoAutorizado() async {
        let app = AppDeTeste.conectado()
        #expect(app.sessao.conectado)
        app.transporte.responder("GET inicio", 401, json(#"{"message":"Aparelho desconectado. Entre de novo no aplicativo."}"#))
        await #expect(throws: FalhaDaApi.naoAutorizado) { _ = try await app.api.inicio() }
        #expect(app.sessao.estado == .visitante)
        #expect(app.cofre.ler() == nil)
        #expect(app.preferencias.conta == nil)
        #expect(app.notificacoes.removeuTudo == 1)
    }

    @Test("403 de senha provisória, 403 de permissão, 404, 409 com código e 429")
    func outrosStatus() async {
        let app = AppDeTeste.conectado()
        app.transporte.responder("GET inicio", 403, json(#"{"message":"Troque a senha provisória para continuar.","code":"must_change_password"}"#))
        await #expect(throws: FalhaDaApi.trocarSenha) { _ = try await app.api.inicio() }

        app.transporte.responder("POST cuidando/2/dia/doses", 403, json(#"{"message":"This action is unauthorized."}"#))
        await #expect(throws: FalhaDaApi.proibido("This action is unauthorized.")) {
            _ = try await app.api.marcarParaQuemCuido(2, PedidoDeMarcacao(remedioId: 1, agendadaPara: "x", status: "taken", dia: 0))
        }

        app.transporte.responder("GET perguntas/abc", 404, json("{}"))
        await #expect(throws: FalhaDaApi.naoEncontrado) { _ = try await app.api.pergunta("abc") }

        app.transporte.responder("POST cuidadores/convite", 409, json(#"{"message":"Autorize seus dados de saúde.","code":"invite_unavailable"}"#))
        do {
            _ = try await app.api.gerarConvite()
            Issue.record("devia falhar")
        } catch let falha as FalhaDaApi {
            #expect(falha.codigo == "invite_unavailable")
            #expect(falha.mensagem == "Autorize seus dados de saúde.")
        } catch {
            Issue.record("falha inesperada \(error)")
        }

        app.transporte.responder("POST perguntar", 429, json(#"{"message":"Você fez muitas perguntas nesta hora."}"#))
        await #expect(throws: FalhaDaApi.limite("Você fez muitas perguntas nesta hora.")) { _ = try await app.api.perguntar(PedidoDePergunta(question: "dipirona?")) }
        #expect(app.sessao.conectado, "nenhum desses encerra a sessão")
    }

    @Test("Sem rede e tempo esgotado viram frases próprias")
    func rede() async {
        let app = AppDeTeste.conectado()
        app.transporte.falhar("GET inicio", .notConnectedToInternet)
        await #expect(throws: FalhaDaApi.semConexao) { _ = try await app.api.inicio() }
        app.transporte.limpar("GET inicio")
        app.transporte.falhar("GET inicio", .timedOut)
        await #expect(throws: FalhaDaApi.tempoEsgotado) { _ = try await app.api.inicio() }
        #expect(FalhaDaApi.semConexao.mensagem == "Sem conexão com a internet. Confira o Wi-Fi ou os dados móveis.")
    }

    @Test("Resposta fora do formato não derruba o app")
    func respostaInesperada() async {
        let app = AppDeTeste.conectado()
        app.transporte.responder("GET inicio", 200, json(#"{"inesperado":true}"#))
        do {
            _ = try await app.api.inicio()
            Issue.record("devia falhar")
        } catch let falha as FalhaDaApi {
            if case .servidor = falha {} else { Issue.record("esperava .servidor, veio \(falha)") }
        } catch {
            Issue.record("falha inesperada \(error)")
        }
    }

    @Test("Foto da receita vai em multipart com o campo photo")
    func multipart() async throws {
        let app = AppDeTeste.conectado()
        app.transporte.responder("POST receitas", 201, contrato("prescriptions.store"))
        let receita = try await app.api.enviarReceita(jpeg: Data([0xFF, 0xD8, 0xFF]))
        #expect(receita.id > 0)
        let pedido = try #require(app.transporte.ultimo("POST receitas"))
        #expect(pedido.value(forHTTPHeaderField: "Content-Type")?.hasPrefix("multipart/form-data; boundary=") == true)
        let corpo = String(decoding: pedido.httpBody ?? Data(), as: UTF8.self)
        #expect(corpo.contains("name=\"photo\"; filename=\"receita.jpg\""))
        #expect(corpo.contains("Content-Type: image/jpeg"))
    }

    @Test("DELETE com corpo (desfazer a marcação) e consulta com exclude")
    func deleteComCorpoEConsulta() async throws {
        let app = AppDeTeste.conectado()
        app.transporte.responder("DELETE dia/doses", 200, contrato("day.unmark"))
        _ = try await app.api.desmarcar(PedidoDeMarcacao(remedioId: 12, agendadaPara: "2026-11-05T08:00:00-03:00", status: nil, dia: 0))
        let corpo = try #require(app.transporte.corpo("DELETE dia/doses"))
        #expect(corpo["medication_id"] as? Int == 12)
        #expect(corpo["status"] == nil)

        app.transporte.responder("GET remedios", 200, contrato("medicines.search"))
        _ = try await app.api.buscarRemedios(" losar ", excluir: [3, 4])
        let url = try #require(app.transporte.ultimo("GET remedios")?.url?.absoluteString)
        #expect(url.contains("q=losar"))
        #expect(url.contains("exclude=3,4") || url.contains("exclude=3%2C4"))
    }

    @Test("Nome de chamada vazio vai como null (volta ao primeiro nome)")
    func nomeNulo() throws {
        let corpo = try JSONSerialization.jsonObject(with: codificar(PedidoDeNomeDeChamada(nome: nil))) as? [String: Any]
        #expect(corpo?.keys.contains("preferred_name") == true)
        #expect(corpo?["preferred_name"] is NSNull)
    }

    @Test("Itens da receita vão com o modo de horário mesmo no valor padrão")
    func confirmacaoComPadroes() throws {
        let item = ItemDaReceita(nomeEscrito: "Losartana", remedioId: 3, dosage: "1 comprimido", frequency: "1 vez ao dia", times: ["08:00"])
        let corpo = try JSONSerialization.jsonObject(with: codificar(PedidoDeConfirmacao(header: CabecalhoDaReceita(prescriber: "Dra. Ana"), items: [item]))) as? [String: Any]
        let primeiro = (corpo?["items"] as? [[String: Any]])?.first
        #expect(primeiro?["schedule_mode"] as? String == "fixed")
        #expect(primeiro?["skip"] as? Bool == false)
        #expect(primeiro?["medicine_id"] as? Int == 3)
    }
}
