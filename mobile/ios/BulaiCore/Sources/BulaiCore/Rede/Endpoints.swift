import Foundation
#if canImport(FoundationNetworking)
import FoundationNetworking
#endif

/// Endpoints de `/api/mobile/v1` usados pelo app (routes/api.php), os mesmos do Android.
public extension ClienteDaApi {
    // MARK: Conta

    func entrar(_ pedido: PedidoDeEntrada) async throws -> RespostaDeEntrada {
        try await pedir("POST", "entrar", corpo: pedido)
    }

    func cadastrar(_ pedido: PedidoDeCadastro) async throws -> Mensagem {
        try await pedir("POST", "cadastro", corpo: pedido)
    }

    func esqueciSenha(_ email: String) async throws -> Mensagem {
        try await pedir("POST", "esqueci-senha", corpo: PedidoDeEmail(email: email))
    }

    func eu() async throws -> Usuario {
        try await pedir("GET", "eu", como: RespostaDoUsuario.self).user
    }

    func primeiroAcesso(senha: String, confirmacao: String) async throws -> Usuario {
        try await pedir("POST", "primeiro-acesso", corpo: PedidoDeNovaSenha(password: senha, confirmacao: confirmacao), como: RespostaDoUsuario.self).user
    }

    func sair() async throws {
        _ = try await pedir("DELETE", "aparelho", como: Qualquer.self)
    }

    func conta() async throws -> Usuario {
        try await pedir("GET", "conta", como: RespostaDoUsuario.self).user
    }

    func mudarNomeDeChamada(_ nome: String?) async throws -> Usuario {
        try await pedir("PUT", "conta/nome", corpo: PedidoDeNomeDeChamada(nome: nome), como: RespostaDoUsuario.self).user
    }

    func trocarSenha(atual: String, nova: String, confirmacao: String) async throws -> Mensagem {
        try await pedir("PUT", "conta/senha", corpo: PedidoDeTrocaDeSenha(atual: atual, password: nova, confirmacao: confirmacao))
    }

    func apagarConta(senha: String) async throws -> Mensagem {
        try await pedir("DELETE", "conta", corpo: PedidoDeExclusao(password: senha))
    }

    // MARK: Tratamento

    func inicio() async throws -> Inicio {
        try await pedir("GET", "inicio")
    }

    func dia(_ deslocamento: Int) async throws -> Dia {
        try await pedir("GET", "dia", consulta: [URLQueryItem(name: "dia", value: String(deslocamento))])
    }

    func marcar(_ pedido: PedidoDeMarcacao) async throws -> Dia {
        try await pedir("POST", "dia/doses", corpo: pedido)
    }

    func desmarcar(_ pedido: PedidoDeMarcacao) async throws -> Dia {
        try await pedir("DELETE", "dia/doses", corpo: pedido)
    }

    // MARK: Alarmes do aparelho

    func agenda() async throws -> AgendaDoAparelho {
        try await pedir("GET", "agenda")
    }

    func marcarPeloAparelho(_ pedido: PedidoDeMarcacaoDoAparelho) async throws -> AgendaDoAparelho {
        try await pedir("POST", "doses", corpo: pedido)
    }

    // MARK: Avisos

    func avisos() async throws -> CentralDeAvisos {
        try await pedir("GET", "avisos")
    }

    func avisosNovos(desde: String?) async throws -> AvisosNovos {
        try await pedir("GET", "avisos/novos", consulta: [URLQueryItem(name: "desde", value: desde)])
    }

    func abrirAviso(_ id: String) async throws -> Destino {
        try await pedir("POST", "avisos/\(id)/abrir", como: RespostaDeAbertura.self).destination
    }

    func marcarAvisosLidos() async throws -> CentralDeAvisos {
        try await pedir("POST", "avisos/lidos")
    }

    func apagarAviso(_ id: String) async throws -> CentralDeAvisos {
        try await pedir("DELETE", "avisos/\(id)")
    }

    func apagarAvisos() async throws -> CentralDeAvisos {
        try await pedir("DELETE", "avisos")
    }

    func preferencias(_ alteracao: AlteracaoDePreferencias) async throws -> CentralDeAvisos {
        try await pedir("PUT", "avisos/preferencias", corpo: alteracao)
    }

    // MARK: Catálogo e Perguntar

    func buscarRemedios(_ termo: String, limite: Int = 6, excluir: [Int64] = []) async throws -> BuscaDeRemedios {
        try await pedir("GET", "remedios", consulta: [
            URLQueryItem(name: "q", value: termo.trimmingCharacters(in: .whitespacesAndNewlines)),
            URLQueryItem(name: "limit", value: String(limite)),
            URLQueryItem(name: "exclude", value: excluir.isEmpty ? nil : excluir.map(String.init).joined(separator: ",")),
        ])
    }

    func inicioDaConversa(remedio: Int64? = nil) async throws -> InicioDaConversa {
        try await pedir("GET", "perguntar", consulta: [URLQueryItem(name: "medicamento", value: remedio.map(String.init))])
    }

    func perguntar(_ pedido: PedidoDePergunta) async throws -> Resposta {
        try await pedir("POST", "perguntar", corpo: pedido, lento: true, como: RespostaDaPergunta.self).answer
    }

    func perguntas(pagina: Int = 1) async throws -> HistoricoDePerguntas {
        try await pedir("GET", "perguntas", consulta: [URLQueryItem(name: "page", value: String(pagina))])
    }

    func pergunta(_ uuid: String) async throws -> Resposta {
        try await pedir("GET", "perguntas/\(uuid)", como: RespostaDaPergunta.self).answer
    }

    func apagarPergunta(_ uuid: String) async throws {
        _ = try await pedir("DELETE", "perguntas/\(uuid)", como: Qualquer.self)
    }

    // MARK: Receita por foto

    func enviarReceita(jpeg: Data) async throws -> Receita {
        try await enviarArquivo("receitas", anexo: Anexo(campo: "photo", nomeDoArquivo: "receita.jpg", tipo: "image/jpeg", dados: jpeg), como: RespostaDaReceita.self).prescription
    }

    func receita(_ id: Int64) async throws -> Receita {
        try await pedir("GET", "receitas/\(id)", como: RespostaDaReceita.self).prescription
    }

    func horarios(quandoTomar: String) async throws -> Horarios {
        try await pedir("POST", "receitas/horarios", corpo: PedidoDeHorarios(frequency: quandoTomar), como: RespostaDeHorarios.self).schedule
    }

    func confirmarReceita(_ id: Int64, _ pedido: PedidoDeConfirmacao) async throws -> ResultadoDaConfirmacao {
        try await pedir("POST", "receitas/\(id)/confirmar", corpo: pedido)
    }

    func descartarReceita(_ id: Int64) async throws -> Mensagem {
        try await pedir("DELETE", "receitas/\(id)")
    }

    // MARK: Bula humanizada

    func bula(_ remedio: Int64) async throws -> Bula {
        try await pedir("GET", "bulas/\(remedio)", como: RespostaDaBula.self).leaflet
    }

    func gerarBula(_ remedio: Int64) async throws -> Bula {
        try await pedir("POST", "bulas/\(remedio)/gerar", lento: true, como: RespostaDaBula.self).leaflet
    }

    func bulas(termo: String? = nil) async throws -> ListaDeBulas {
        let limpo = termo?.trimmingCharacters(in: .whitespacesAndNewlines)
        return try await pedir("GET", "bulas", consulta: [URLQueryItem(name: "q", value: (limpo?.isEmpty ?? true) ? nil : limpo)])
    }

    // MARK: Quem cuida de mim (paciente)

    func cuidadores() async throws -> MeusCuidadores {
        try await pedir("GET", "cuidadores")
    }

    func gerarConvite() async throws -> MeusCuidadores {
        try await pedir("POST", "cuidadores/convite")
    }

    func cancelarConvite() async throws -> MeusCuidadores {
        try await pedir("DELETE", "cuidadores/convite")
    }

    func aprovarCuidador(_ vinculo: Int64, permissoes: [String: Bool]) async throws -> MeusCuidadores {
        try await pedir("POST", "cuidadores/\(vinculo)/aprovar", corpo: PedidoDePermissoes(permissions: permissoes))
    }

    func recusarCuidador(_ vinculo: Int64) async throws -> MeusCuidadores {
        try await pedir("POST", "cuidadores/\(vinculo)/recusar")
    }

    func mudarPermissoes(_ vinculo: Int64, permissoes: [String: Bool]) async throws -> MeusCuidadores {
        try await pedir("PUT", "cuidadores/\(vinculo)/permissoes", corpo: PedidoDePermissoes(permissions: permissoes))
    }

    func tirarAcesso(_ vinculo: Int64) async throws -> MeusCuidadores {
        try await pedir("DELETE", "cuidadores/\(vinculo)")
    }

    // MARK: Cuidar de alguém

    func quemEuCuido() async throws -> QuemEuCuido {
        try await pedir("GET", "cuidando")
    }

    func usarConvite(_ codigo: String) async throws -> QuemEuCuido {
        try await pedir("POST", "cuidando", corpo: PedidoDeCodigo(code: codigo))
    }

    func deixarDeAcompanhar(_ vinculo: Int64) async throws -> QuemEuCuido {
        try await pedir("DELETE", "cuidando/\(vinculo)")
    }

    func diaDeQuemCuido(_ vinculo: Int64, deslocamento: Int) async throws -> Dia {
        try await pedir("GET", "cuidando/\(vinculo)/dia", consulta: [URLQueryItem(name: "dia", value: String(deslocamento))])
    }

    func marcarParaQuemCuido(_ vinculo: Int64, _ pedido: PedidoDeMarcacao) async throws -> Dia {
        try await pedir("POST", "cuidando/\(vinculo)/dia/doses", corpo: pedido)
    }

    func desmarcarParaQuemCuido(_ vinculo: Int64, _ pedido: PedidoDeMarcacao) async throws -> Dia {
        try await pedir("DELETE", "cuidando/\(vinculo)/dia/doses", corpo: pedido)
    }

    func remediosDeQuemCuido(_ vinculo: Int64) async throws -> RemediosDeQuemCuido {
        try await pedir("GET", "cuidando/\(vinculo)/remedios")
    }

    func salvarPosologiaDeQuemCuido(_ vinculo: Int64, remedio: Int64, _ posologia: DoseEHorarios) async throws -> RemediosDeQuemCuido {
        try await pedir("PUT", "cuidando/\(vinculo)/remedios/\(remedio)", corpo: PedidoDeDoseEHorarios(posology: posologia))
    }

    /// "Resumo para o médico" (o mesmo PDF do site).
    func resumoParaOMedico() async throws -> Data {
        try await baixar("resumo.pdf")
    }

    // MARK: Meus remédios

    func perfil() async throws -> Perfil {
        try await pedir("GET", "perfil")
    }

    func consentir() async throws -> Perfil {
        try await pedir("POST", "perfil/consentimento")
    }

    func apagarDadosDeSaude() async throws -> Perfil {
        try await pedir("DELETE", "perfil/dados-de-saude")
    }

    func adicionarRemedio(catalogo: Int64? = nil, nome: String? = nil) async throws -> Perfil {
        try await pedir("POST", "perfil/remedios", corpo: PedidoDeRemedio(remedio: catalogo, name: nome))
    }

    func salvarPosologia(_ remedio: Int64, _ posologia: DoseEHorarios) async throws -> Perfil {
        try await pedir("PUT", "perfil/remedios/\(remedio)", corpo: PedidoDeDoseEHorarios(posology: posologia))
    }

    func tirarRemedio(_ remedio: Int64) async throws -> Perfil {
        try await pedir("DELETE", "perfil/remedios/\(remedio)")
    }

    func adicionarReceitaDoPerfil(_ pedido: PedidoDeReceitaDoPerfil) async throws -> Perfil {
        try await pedir("POST", "perfil/receitas", corpo: pedido)
    }

    func tirarReceita(_ receita: Int64) async throws -> Perfil {
        try await pedir("DELETE", "perfil/receitas/\(receita)")
    }

    func adicionarAlergia(substancia: String, reacao: String?) async throws -> Perfil {
        try await pedir("POST", "perfil/alergias", corpo: PedidoDeAlergia(substance: substancia, reaction: reacao))
    }

    func tirarAlergia(_ alergia: Int64) async throws -> Perfil {
        try await pedir("DELETE", "perfil/alergias/\(alergia)")
    }

    func sugerirHorarios(frequencia: String, posologia: DoseEHorarios, atalho: Bool) async throws -> DoseEHorarios {
        try await pedir("POST", "posologia/sugestao", corpo: PedidoDeSugestao(frequency: frequencia, posology: posologia, preset: atalho), como: RespostaDaSugestao.self).posology
    }

    // MARK: Interações

    func interacoes() async throws -> InicioDasInteracoes {
        try await pedir("GET", "interacoes")
    }

    func remedioDaConsulta(_ remedio: Int64, escolhidos: [Int64]) async throws -> RemedioDaConsulta {
        try await pedir("GET", "interacoes/remedios/\(remedio)", consulta: [URLQueryItem(name: "selected", value: escolhidos.map(String.init).joined(separator: ","))])
    }

    func verificarInteracoes(_ remedios: [Int64]) async throws -> ConsultaDeInteracoes {
        try await pedir("POST", "interacoes", corpo: PedidoDeConsulta(remedios: remedios))
    }

    func consulta(_ uuid: String) async throws -> ConsultaDeInteracoes {
        try await pedir("GET", "interacoes/\(uuid)")
    }

    func repetirConsulta(_ uuid: String) async throws -> ConsultaDeInteracoes {
        try await pedir("POST", "interacoes/\(uuid)/repetir")
    }

    func apagarConsulta(_ uuid: String) async throws -> Mensagem {
        try await pedir("DELETE", "interacoes/\(uuid)")
    }
}
