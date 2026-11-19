import Foundation
import Testing
@testable import BulaiCore

/// Estados das telas (carga, cache, marcação otimista, erros) com as respostas reais do servidor.
@Suite("Estados das telas")
@MainActor
struct TelasTests {
    @Test("Início abre com o guardado, atualiza por cima e mostra o erro sem apagar a tela")
    func inicio() async {
        let app = AppDeTeste.conectado()
        let guardado = try! JSONDecoder().decode(Inicio.self, from: contrato("home"))
        await app.cache.gravar("inicio", guardado)
        app.transporte.falhar("GET inicio", .notConnectedToInternet)
        let tela = InicioViewModel(app.dependencias)
        #expect(tela.carga.esqueleto)
        await tela.carregar()
        #expect(tela.carga.dados == guardado)
        #expect(tela.carga.erro == FalhaDaApi.semConexao.mensagem)
        #expect(!tela.carga.atualizando)
    }

    @Test("Tomei no Início muda na hora e volta se o servidor recusar")
    func tomeiNoInicio() async throws {
        let app = AppDeTeste.conectado()
        app.transporte.responder("GET inicio", 200, contrato("home"))
        let tela = InicioViewModel(app.dependencias)
        await tela.carregar()
        let proxima = try #require(tela.carga.dados?.proxima)
        app.transporte.responder("POST dia/doses", 422, json(#"{"message":"x","errors":{"scheduled_for":["Horário fora da janela."]}}"#))
        await tela.tomei(proxima)
        #expect(tela.carga.dados?.proxima == proxima)
        #expect(tela.recado?.texto == "Horário fora da janela.")
    }

    @Test("Remédios do dia: marcação otimista, recado com Desfazer e resposta do servidor por cima")
    func marcarNoDia() async throws {
        let app = AppDeTeste.conectado()
        app.transporte.responder("GET dia", 200, contrato("day"))
        app.transporte.responder("POST dia/doses", 200, contrato("day.mark"))
        app.transporte.responder("GET agenda", 200, contrato("agenda"))
        let tela = DiaViewModel(fonte: MeuDia(app.dependencias))
        await tela.carregar()
        let dose = try #require(tela.carga(0).dados?.doses.first)
        await tela.tomei(dose, deslocamento: 0)
        #expect(tela.recado?.texto == "Anotado: você tomou \(dose.name).")
        #expect(tela.recado?.desfazer != nil)
        #expect(tela.enviando.isEmpty)
        let corpo = try #require(app.transporte.corpo("POST dia/doses"))
        #expect(corpo["status"] as? String == "taken")
        #expect(corpo["dia"] as? Int == 0)
        #expect(tela.acabouDeTomar == dose.key)
    }

    @Test("Resumo do dia é recalculado no toque (antes da resposta do servidor)")
    func comSituacao() throws {
        let dia = try JSONDecoder().decode(Dia.self, from: contrato("day"))
        let chave = try #require(dia.doses.first?.key)
        let marcado = dia.comSituacao(chave: chave, situacao: Dose.tomada)
        #expect(marcado.summary.taken == dia.summary.taken + 1)
        #expect(marcado.summary.done == dia.summary.done + 1)
        #expect(marcado.doses.first?.feita == true)
    }

    @Test("Falha ao marcar volta a dose e explica")
    func falhaAoMarcar() async throws {
        let app = AppDeTeste.conectado()
        app.transporte.responder("GET dia", 200, contrato("day"))
        app.transporte.falhar("POST dia/doses", .notConnectedToInternet)
        let tela = DiaViewModel(fonte: MeuDia(app.dependencias))
        await tela.carregar()
        let antes = try #require(tela.carga(0).dados)
        await tela.pular(antes.doses[0], deslocamento: 0)
        #expect(tela.carga(0).dados == antes)
        #expect(tela.recado?.texto == FalhaDaApi.semConexao.mensagem)
        #expect(tela.acabouDeTomar == nil)
    }

    @Test("Quem cuida sem a permissão de marcar recebe a recusa do servidor (403) e a dose não muda")
    func cuidadorSemPermissao() async throws {
        let app = AppDeTeste.conectado()
        app.transporte.responder("GET cuidando/2/dia", 200, contrato("caring.day"))
        app.transporte.responder("POST cuidando/2/dia/doses", 403, json(#"{"message":"Você não tem permissão para anotar as doses de Maria."}"#))
        let tela = DiaViewModel(fonte: DiaDeQuemCuido(app.dependencias, vinculo: 2, primeiroNome: "Maria"))
        await tela.carregar()
        let dia = try #require(tela.carga(0).dados)
        #expect(dia.podeMarcar == false, "a tela esconde os botões")
        await tela.tomei(dia.doses[0], deslocamento: 0)
        #expect(tela.carga(0).dados == dia)
        #expect(tela.recado?.texto == "Você não tem permissão para anotar as doses de Maria.")
        #expect(!app.transporte.feitos.contains("GET agenda"), "o dia de outra pessoa não mexe nos lembretes deste aparelho")
    }

    @Test("Quem cuida com permissão: o recado fala da pessoa")
    func cuidadorComPermissao() async throws {
        let app = AppDeTeste.conectado()
        app.transporte.responder("GET cuidando/2/dia", 200, contrato("caring.day"))
        app.transporte.responder("POST cuidando/2/dia/doses", 200, contrato("caring.mark"))
        let tela = DiaViewModel(fonte: DiaDeQuemCuido(app.dependencias, vinculo: 2, primeiroNome: "Maria"))
        await tela.carregar()
        let dose = try #require(tela.carga(0).dados?.doses.first)
        await tela.tomei(dose, deslocamento: 0)
        #expect(tela.recado?.texto == "Anotado: Maria tomou \(dose.name).")
    }

    @Test("Avisos: abrir leva à tela certa e marca como lido; apagar falho volta")
    func avisos() async throws {
        let app = AppDeTeste.conectado()
        app.transporte.responder("GET avisos", 200, contrato("notices"))
        app.transporte.responder("POST avisos/\(try JSONDecoder().decode(CentralDeAvisos.self, from: contrato("notices")).notices[0].id)/abrir", 200, contrato("notices.open"))
        let tela = AvisosViewModel(app.dependencias)
        await tela.carregar()
        let aviso = try #require(tela.carga.dados?.notices.first)
        let naoLidos = tela.carga.dados!.unread
        await tela.abrir(aviso)
        #expect(tela.destino == Rota.doDestino(aviso.destination))
        #expect(tela.carga.dados?.unread == naoLidos - 1)

        app.transporte.falhar("DELETE avisos/\(aviso.id)", .notConnectedToInternet)
        let antes = tela.carga.dados
        await tela.apagar(aviso)
        #expect(tela.carga.dados == antes)
    }

    @Test("Perguntar: curta demais avisa; a conversa manda só os uuid das trocas anteriores")
    func perguntar() async throws {
        let app = AppDeTeste.conectado()
        app.transporte.responder("GET perguntar", 200, contrato("questions.start"))
        let tela = PerguntarViewModel(app.dependencias)
        await tela.carregar()
        tela.texto = "oi"
        #expect(tela.enviar() == false)
        #expect(tela.aviso == PerguntarViewModel.avisoDePerguntaCurta)

        app.transporte.responder("POST perguntar", 200, contrato("questions.ask"))
        tela.texto = "Posso tomar losartana à noite?"
        #expect(tela.enviar())
        #expect(tela.texto.isEmpty)
        #expect(tela.enviando)
        await tela.aguardar()
        #expect(tela.trocas.last?.resposta?.status == Resposta.apoio)

        tela.texto = "E com café?"
        tela.enviar()
        await tela.aguardar()
        let corpo = try #require(app.transporte.corpo("POST perguntar"))
        #expect(corpo["turns"] as? [String] == ["5783cdea-2901-497b-af6d-9cd579ecc7c7"])
        #expect(corpo["question"] as? String == "E com café?")
    }

    @Test("Perguntar: falha mostra Tentar de novo; correção fixa o remédio escolhido")
    func perguntarFalhaECorrecao() async throws {
        let app = AppDeTeste.conectado()
        let tela = PerguntarViewModel(app.dependencias)
        app.transporte.responder("POST perguntar", 429, json(#"{"message":"Você atingiu o limite de perguntas por hora."}"#))
        tela.texto = "Posso tomar dipirona?"
        tela.enviar()
        await tela.aguardar()
        #expect(tela.trocas.last?.erro == "Você atingiu o limite de perguntas por hora.")

        app.transporte.limpar("POST perguntar")
        app.transporte.responder("POST perguntar", 200, json(#"{"answer":{"uuid":"u2","status":"answered","releases_fixed":false}}"#))
        tela.tentarDeNovo()
        await tela.aguardar()
        #expect(tela.trocas.count == 1)
        #expect(tela.trocas.last?.resposta?.uuid == "u2")

        tela.corrigir(99)
        await tela.aguardar()
        let corpo = try #require(app.transporte.corpo("POST perguntar"))
        #expect(corpo["fixed"] as? [Int] == [99])
        #expect(tela.fixos == [99], "a resposta não liberou o remédio fixado")
    }

    @Test("Perguntar sobre um remédio (vindo da bula) começa com o painel e o texto pronto")
    func perguntarSobre() async throws {
        let app = AppDeTeste.conectado()
        app.transporte.responder("GET perguntar", 200, json(#"{"examples":[],"preview":{"medicine":"Losartana"}}"#))
        let tela = PerguntarViewModel(app.dependencias, remedio: 5)
        await tela.carregar()
        #expect(tela.texto == "Sobre Losartana: ")
        #expect(app.transporte.ultimo("GET perguntar")?.url?.query?.contains("medicamento=5") == true)
    }

    @Test("Receita: acompanha a leitura até a revisão, exige quem prescreveu e confirma")
    func receita() async throws {
        let app = AppDeTeste.conectado()
        var lendo = try JSONDecoder().decode(RespostaDaReceita.self, from: contrato("prescriptions.show"))
        lendo.prescription.reading = true
        lendo.prescription.status = "READING"
        lendo.prescription.items = []
        app.transporte.responder("GET receitas/1", 200, codificar(["prescription": lendo.prescription]))
        app.transporte.responder("GET receitas/1", 200, contrato("prescriptions.show"))
        let tela = RevisarReceitaViewModel(app.dependencias, id: 1)
        tela.intervaloDeConsulta = 1
        await tela.acompanhar()
        #expect(!tela.lendo)
        #expect(tela.cabecalho.prescriber == "Dra. Ana Souza")
        #expect(!tela.itens.isEmpty)
        #expect(app.transporte.feitos.filter { $0 == "GET receitas/1" }.count == 2)

        tela.cabecalho.prescriber = "Dr"
        #expect(await tela.confirmar() == nil)
        #expect(tela.erroDoPrescritor == "O nome de quem prescreveu precisa ter pelo menos 3 letras.")

        tela.cabecalho.prescriber = "Dra. Ana Souza"
        app.transporte.responder("POST receitas/1/confirmar", 200, contrato("prescriptions.confirm"))
        let mensagem = await tela.confirmar()
        #expect(mensagem != nil)
    }

    @Test("Receita: mudar quando tomar refaz os horários; sem consentimento o envio explica")
    func receitaHorariosEConsentimento() async throws {
        let app = AppDeTeste.conectado()
        app.transporte.responder("GET receitas/1", 200, contrato("prescriptions.show"))
        app.transporte.responder("POST receitas/horarios", 200, json(#"{"schedule":{"times":["08:00","20:00"],"as_needed":false,"interval_hours":12,"schedule_mode":"interval"}}"#))
        let tela = RevisarReceitaViewModel(app.dependencias, id: 1)
        await tela.acompanhar()
        tela.mudarQuandoTomar(0, "de 12 em 12 horas")
        await tela.esperarHorarios(0)
        #expect(tela.itens[0].times == ["08:00", "20:00"])
        #expect(tela.itens[0].contaDoUltimo)

        app.transporte.responder("POST receitas", 409, json(#"{"message":"Autorize o uso dos seus dados de saúde.","code":"health_consent_required"}"#))
        let nova = NovaReceitaViewModel(api: app.api)
        #expect(await nova.enviar(jpeg: Data([1, 2, 3])) == nil)
        #expect(nova.semConsentimento)
        #expect(await nova.enviar(jpeg: nil) == nil)
        #expect(nova.erro == "Não conseguimos abrir esta imagem. Tente outra foto.")
    }

    @Test("Interações: os remédios em uso entram; repetido é recusado pelo servidor com a frase dele")
    func interacoes() async throws {
        let app = AppDeTeste.conectado()
        app.transporte.responder("GET interacoes", 200, contrato("interactions"))
        let tela = PesquisaViewModel(api: app.api)
        await tela.carregar()
        let emUso = tela.escolhidos.count
        app.transporte.responder("GET interacoes/remedios/7", 422, json(#"{"message":"x","errors":{"medicine":["Este remédio já está na lista."]}}"#))
        await tela.adicionar(7)
        #expect(tela.escolhidos.count == emUso)
        #expect(tela.mensagem == "Este remédio já está na lista.")

        app.transporte.responder("POST interacoes", 201, contrato("interactions.store"))
        let uuid = await tela.verificar()
        #expect(uuid != nil)
    }

    @Test("Resultado das interações acompanha até terminar")
    func resultado() async throws {
        let app = AppDeTeste.conectado()
        var andamento = try JSONDecoder().decode(ConsultaDeInteracoes.self, from: contrato("interactions.show"))
        andamento.running = true
        app.transporte.responder("GET interacoes/x", 200, codificar(andamento))
        app.transporte.responder("GET interacoes/x", 200, contrato("interactions.show"))
        let tela = ResultadoViewModel(api: app.api, uuid: "x")
        tela.intervaloDeConsulta = 1
        await tela.acompanhar()
        #expect(tela.carga.dados?.running == false)
        #expect(tela.carga.dados?.pairs.first?.phrase.isEmpty == false)
    }

    @Test("Bula ainda não pronta: mostra o esqueleto e pede para gerar; pronta fica guardada")
    func bula() async throws {
        let app = AppDeTeste.conectado()
        var carregando = try JSONDecoder().decode(RespostaDaBula.self, from: contrato("leaflets.generate"))
        carregando.leaflet.state = Bula.carregando
        app.transporte.responder("GET bulas/29", 200, codificar(["leaflet": carregando.leaflet]))
        app.transporte.responder("POST bulas/29/gerar", 200, contrato("leaflets.generate"))
        let tela = BulaViewModel(app.dependencias, remedio: 29)
        await tela.carregar()
        #expect(tela.carga.dados?.state == Bula.pronta)
        #expect(await app.cache.ler("bula29", como: Bula.self) != nil)
    }

    @Test("Lista de bulas filtra sem acento nem maiúsculas")
    func bulas() {
        #expect(BulasViewModel.normalizar(" Dipirona Sódica ") == BulasViewModel.normalizar("dipirona sodica"))
    }

    @Test("Meus remédios: adicionar abre Dose e horários; atalho refaz os horários pelo servidor")
    func perfilEPosologia() async throws {
        let app = AppDeTeste.conectado()
        app.transporte.responder("POST perfil/remedios", 200, contrato("profile.medications.store"))
        app.transporte.responder("GET agenda", 200, contrato("agenda"))
        let tela = PerfilViewModel(app.dependencias)
        await tela.adicionar(catalogo: nil, nome: "  Remédio da farmácia ")
        let corpo = try #require(app.transporte.corpo("POST perfil/remedios"))
        #expect(corpo["name"] as? String == "Remédio da farmácia")
        let perfil = try JSONDecoder().decode(Perfil.self, from: contrato("profile.medications.store"))
        if perfil.remedioNovo != nil {
            #expect(tela.editando?.id == perfil.remedioNovo)
        }

        app.transporte.responder("POST posologia/sugestao", 200, contrato("posology.suggest"))
        let sugerida = try JSONDecoder().decode(RespostaDaSugestao.self, from: contrato("posology.suggest")).posology
        let editor = EditorDePosologia(inicial: DoseEHorarios(), atalhos: AtalhosDePosologia(frequencias: ["2 vezes ao dia"], doses: ["1 comprimido"])) { frequencia, posologia, atalho in
            await tela.sugerir(frequencia: frequencia, posologia: posologia, atalho: atalho)
        }
        await editor.escolherAtalho("2 vezes ao dia")
        #expect(editor.posologia == sugerida)
        #expect(app.transporte.corpo("POST posologia/sugestao")?["preset"] as? Bool == true)
    }

    @Test("Dose e horários: novo horário sugerido 4 h depois, em ordem, sem repetir, até 12")
    func editorDeHorarios() {
        let editor = EditorDePosologia(inicial: DoseEHorarios(times: ["08:00"]), atalhos: AtalhosDePosologia()) { _, _, _ in nil }
        #expect(editor.sugestaoDeNovoHorario == "12:00")
        editor.adicionarHorario("06:00")
        editor.adicionarHorario("08:00")
        #expect(editor.posologia.times == ["06:00", "08:00"])
        editor.trocarHorario(0, "22:00")
        #expect(editor.posologia.times == ["08:00", "22:00"])
        #expect(editor.sugestaoDeNovoHorario == "02:00")
        for hora in 0 ..< 20 {
            editor.adicionarHorario(String(format: "%02d:30", hora))
        }
        #expect(editor.posologia.times.count == EditorDePosologia.maximoDeHorarios)
        let comTexto = EditorDePosologia(inicial: DoseEHorarios(frequency: "pela manhã e à noite"), atalhos: AtalhosDePosologia(frequencias: ["1 vez ao dia"])) { _, _, _ in nil }
        #expect(comTexto.outroJeito, "texto livre que não é atalho abre o campo da receita")
    }

    @Test("Quem cuida de mim: aprovar manda só as permissões marcadas")
    func aprovarCuidador() async throws {
        let app = AppDeTeste.conectado()
        app.transporte.responder("GET cuidadores", 200, contrato("caregivers"))
        app.transporte.responder("POST cuidadores/2/aprovar", 200, contrato("caregivers.approve"))
        let tela = QuemCuidaDeMimViewModel(api: app.api)
        await tela.carregar()
        let pessoa = try #require(tela.carga.dados?.pedidos.first)
        await tela.aprovar(pessoa, permissoes: [PermissaoDeCuidador.verRemedios: true, PermissaoDeCuidador.marcarEEditar: false, PermissaoDeCuidador.receberAlertas: false])
        let corpo = try #require(app.transporte.corpo("POST cuidadores/2/aprovar"))
        #expect((corpo["permissions"] as? [String: Bool])?[PermissaoDeCuidador.marcarEEditar] == false)
        #expect(tela.recado?.texto.contains("agora acompanha você") == true)
    }

    @Test("Cuidar de alguém: código errado aparece embaixo do campo; o link inteiro do QR é aceito")
    func usarConvite() async throws {
        let app = AppDeTeste.conectado()
        let tela = CuidarDeAlguemViewModel(app.dependencias)
        app.transporte.responder("POST cuidando", 422, json(#"{"message":"x","errors":{"code":["Este convite não vale mais."]}}"#))
        #expect(await tela.usarConvite("1234 5678") == false)
        #expect(tela.erroDoCodigo == "Este convite não vale mais.")

        app.transporte.limpar("POST cuidando")
        app.transporte.responder("POST cuidando", 201, contrato("caring.accept"))
        #expect(await tela.usarConvite(" https://bulai.vixforge.com.br/cuidar?codigo=12345678 "))
        #expect(app.transporte.corpo("POST cuidando")?["code"] as? String == "https://bulai.vixforge.com.br/cuidar?codigo=12345678")
        #expect(tela.erroDoCodigo == nil)
    }

    @Test("Resumo para o médico sem consentimento explica (409)")
    func resumo() async {
        let app = AppDeTeste.conectado()
        app.transporte.responder("GET resumo.pdf", 409, json(#"{"message":"Autorize seus dados de saúde para gerar o resumo.","code":"health_consent_required"}"#))
        let tela = ResumoViewModel(api: app.api)
        await tela.baixar()
        #expect(tela.erro == "Autorize seus dados de saúde para gerar o resumo.")
        #expect(tela.arquivo == nil)

        app.transporte.limpar("GET resumo.pdf")
        app.transporte.responder("GET resumo.pdf", 200, Data("%PDF-1.4".utf8))
        await tela.baixar()
        #expect(tela.arquivo?.lastPathComponent == "resumo-para-o-medico.pdf")
    }

    @Test("Conta: lembretes desligados mudam na hora e voltam se o servidor recusar")
    func preferenciasDeLembrete() async {
        let app = AppDeTeste.conectado()
        app.transporte.responder("GET avisos", 200, contrato("notices"))
        app.transporte.responder("GET conta", 200, contrato("account"))
        let tela = ContaViewModel(app.dependencias)
        await tela.carregar()
        app.transporte.responder("PUT avisos/preferencias", 409, json(#"{"message":"Autorize seus dados de saúde.","code":"health_consent_required"}"#))
        await tela.lembretes(false)
        #expect(tela.central.dados?.preferences.reminders == true)
        #expect(tela.recado?.texto == "Autorize seus dados de saúde.")
    }
}
