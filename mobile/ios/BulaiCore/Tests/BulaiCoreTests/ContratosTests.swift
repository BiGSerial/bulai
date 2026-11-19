import Foundation
import Testing
@testable import BulaiCore

/// Decodificação das respostas reais de `/api/mobile/v1` (capturadas dos testes Laravel do app).
@Suite("Contratos JSON da API")
struct ContratosTests {
    private func ler<T: Decodable>(_ tipo: T.Type, _ nome: String) throws -> T {
        try JSONDecoder().decode(tipo, from: contrato(nome))
    }

    @Test("Todas as respostas capturadas são lidas pelo tipo do app")
    func todasAsRespostas() throws {
        let tipos: [(String, any Decodable.Type)] = [
            ("login", RespostaDeEntrada.self), ("me", RespostaDoUsuario.self), ("first-access", RespostaDoUsuario.self),
            ("account", RespostaDoUsuario.self), ("account.name", RespostaDoUsuario.self), ("account.password", Mensagem.self),
            ("account.destroy", Mensagem.self), ("register", Mensagem.self), ("password.forgot", Mensagem.self),
            ("home", Inicio.self), ("day", Dia.self), ("day.mark", Dia.self), ("day.unmark", Dia.self),
            ("agenda", AgendaDoAparelho.self), ("doses.mark", AgendaDoAparelho.self),
            ("notices", CentralDeAvisos.self), ("notices.read-all", CentralDeAvisos.self), ("notices.destroy", CentralDeAvisos.self),
            ("notices.destroy-all", CentralDeAvisos.self), ("notices.preferences", CentralDeAvisos.self),
            ("notices.open", RespostaDeAbertura.self), ("notices.fresh", AvisosNovos.self),
            ("medicines.search", BuscaDeRemedios.self),
            ("questions.start", InicioDaConversa.self), ("questions.ask", RespostaDaPergunta.self), ("questions.show", RespostaDaPergunta.self),
            ("questions.history", HistoricoDePerguntas.self),
            ("prescriptions.store", RespostaDaReceita.self), ("prescriptions.show", RespostaDaReceita.self),
            ("prescriptions.schedule", RespostaDeHorarios.self), ("prescriptions.confirm", ResultadoDaConfirmacao.self),
            ("prescriptions.destroy", Mensagem.self),
            ("leaflets.show", RespostaDaBula.self), ("leaflets.generate", RespostaDaBula.self), ("leaflets", ListaDeBulas.self),
            ("caregivers", MeusCuidadores.self), ("caregivers.invite", MeusCuidadores.self), ("caregivers.invite.cancel", MeusCuidadores.self),
            ("caregivers.approve", MeusCuidadores.self), ("caregivers.permissions", MeusCuidadores.self), ("caregivers.revoke", MeusCuidadores.self),
            ("caring", QuemEuCuido.self), ("caring.accept", QuemEuCuido.self), ("caring.leave", QuemEuCuido.self),
            ("caring.day", Dia.self), ("caring.mark", Dia.self),
            ("caring.medications", RemediosDeQuemCuido.self), ("caring.medications.update", RemediosDeQuemCuido.self),
            ("profile", Perfil.self), ("profile.consent", Perfil.self), ("profile.health-data.destroy", Perfil.self),
            ("profile.medications.store", Perfil.self), ("profile.medications.update", Perfil.self), ("profile.medications.destroy", Perfil.self),
            ("profile.prescriptions.store", Perfil.self), ("profile.allergies.store", Perfil.self),
            ("posology.suggest", RespostaDaSugestao.self),
            ("interactions", InicioDasInteracoes.self), ("interactions.medicine", RemedioDaConsulta.self),
            ("interactions.store", ConsultaDeInteracoes.self), ("interactions.show", ConsultaDeInteracoes.self),
            ("interactions.destroy", Mensagem.self),
        ]
        for (nome, tipo) in tipos {
            do {
                _ = try JSONDecoder().decode(tipo, from: contrato(nome))
            } catch {
                Issue.record("\(nome).json não foi lido como \(tipo): \(error)")
            }
        }
    }

    @Test("Início traz o próximo remédio com a barra de espera e o progresso do dia")
    func inicio() throws {
        let inicio = try ler(Inicio.self, "home")
        #expect(inicio.proxima?.name == "Losartana")
        #expect(inicio.proxima?.desde == "2026-11-05T00:00:00-03:00")
        #expect(inicio.progress.total == 3)
        #expect(inicio.avisosNaoLidos == 1)
    }

    @Test("Dia de quem a pessoa cuida vem com a faixa e sem permissão de marcar")
    func diaDeQuemCuido() throws {
        let dia = try ler(Dia.self, "caring.day")
        #expect(dia.cuidandoDe?.primeiroNome == "Maria")
        #expect(dia.podeMarcar == false)
        #expect(dia.doses.first?.dosage == nil)
        // Campo ausente no dia comum: vale o padrão (pode marcar).
        #expect(try ler(Dia.self, "day").podeMarcar == true)
        #expect(try ler(Dia.self, "day").seNecessario.first?.name == "Dipirona SOS")
    }

    @Test("Avisos trazem o destino com parâmetros como objeto")
    func avisos() throws {
        let central = try ler(CentralDeAvisos.self, "notices")
        let aviso = try #require(central.notices.first)
        #expect(aviso.quando == "Hoje, 07:30")
        #expect(!aviso.destination.route.isEmpty)
        #expect(central.timezones.isEmpty == false)
    }

    @Test("Destino aceita params vazio do PHP ([]) e números")
    func destinoTolerante() throws {
        let vazio = try JSONDecoder().decode(Destino.self, from: json(#"{"route":"consulta.hoje","params":[]}"#))
        #expect(vazio.params.isEmpty)
        let numero = try JSONDecoder().decode(Destino.self, from: json(#"{"route":"consulta.bula","params":{"medicine":42}}"#))
        #expect(numero.parametro("medicine") == "42")
    }

    @Test("Resposta do Perguntar em trechos, com blocos de ajuda e telefones")
    func perguntar() throws {
        let resposta = try ler(RespostaDaPergunta.self, "questions.ask").answer
        #expect(resposta.status == Resposta.apoio)
        #expect(resposta.ajuda.first?.telefones.first?.tel == "192")
        #expect(resposta.ajuda.map(\.tone).contains("acolhimento"))
        #expect(!resposta.textoCorrido.isEmpty)
    }

    @Test("Receita em revisão com itens e horários sugeridos")
    func receita() throws {
        let receita = try ler(RespostaDaReceita.self, "prescriptions.show").prescription
        #expect(receita.status == Receita.emRevisao)
        #expect(receita.header?.prescriber == "Dra. Ana Souza")
        #expect(!receita.items.isEmpty)
    }

    @Test("Bula pronta com capa, seções que faltam, risco e painel do tratamento")
    func bula() throws {
        let bula = try ler(RespostaDaBula.self, "leaflets.generate").leaflet
        #expect(bula.state == Bula.pronta)
        #expect(bula.cover?.label == "Para que serve")
        #expect(bula.missing.contains("Como usar"))
        #expect(bula.risk?.tone == "danger")
        #expect(bula.treatment?.alergias.isEmpty == false)
    }

    @Test("Resultado de interações com o par, a frase do site e o alerta de alergia")
    func interacoes() throws {
        let consulta = try ler(ConsultaDeInteracoes.self, "interactions.show")
        #expect(consulta.running == false)
        let par = try #require(consulta.pairs.first)
        #expect(par.level == "major")
        #expect(par.a == "ALFA" && par.b == "BETA")
        #expect(consulta.alertas.first?.medicine == "ALFA")
    }

    @Test("Cuidadores: pedido com permissões e rótulos; quem cuida vê a frase de acesso")
    func cuidadores() throws {
        let meus = try ler(MeusCuidadores.self, "caregivers")
        #expect(meus.pedidos.first?.permissoes.isEmpty == false)
        #expect(meus.rotulos.map(\.key).contains(PermissaoDeCuidador.receberAlertas))
        let quem = try ler(QuemEuCuido.self, "caring")
        #expect(quem.pessoas.first?.acesso == "Ver os remédios e horários")
        #expect(quem.pessoas.first?.podeMarcar == false)
    }

    @Test("Agenda do aparelho com o que deve lembrar")
    func agendaDoAparelho() throws {
        let agenda = try ler(AgendaDoAparelho.self, "agenda")
        #expect(agenda.lembretesLigados)
        #expect(agenda.atrasoMinutos == 60)
        #expect(agenda.doses.first?.remind == true)
        #expect(agenda.doses.first?.momento != nil)
    }

    @Test("O que o app guarda no cache volta igual (codificar e ler de novo)")
    func idaEVolta() throws {
        let dia = try ler(Dia.self, "caring.day")
        #expect(try JSONDecoder().decode(Dia.self, from: codificar(dia)) == dia)
        let bula = try ler(RespostaDaBula.self, "leaflets.generate").leaflet
        #expect(try JSONDecoder().decode(Bula.self, from: codificar(bula)) == bula)
        let perfil = try ler(Perfil.self, "profile.medications.update")
        #expect(try JSONDecoder().decode(Perfil.self, from: codificar(perfil)) == perfil)
    }

    @Test("Chaves que faltam ou vêm nulas usam o padrão em vez de quebrar a tela")
    func padroes() throws {
        let dose = try JSONDecoder().decode(Dose.self, from: json(#"{"key":"1|x","medication_id":1,"name":"A","time":"08:00","scheduled_for":"2026-11-05T08:00:00-03:00","status":"pending","period":null}"#))
        #expect(dose.period == "manha")
        #expect(dose.ehHoje)
        let resposta = try JSONDecoder().decode(Resposta.self, from: json(#"{"status":"answered"}"#))
        #expect(resposta.liberaFixos)
        #expect(resposta.paragraphs.isEmpty)
    }
}
