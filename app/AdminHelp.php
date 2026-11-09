<?php

namespace App;

use Illuminate\Support\Str;

/**
 * Ajuda contextual de cada tela da administração: para que serve, quando usar, passo a passo e telas relacionadas.
 *
 * Fonte única do painel "Ajuda desta tela" do shell e do fluxo de trabalho (Adicionar → Importações → Pendências →
 * Documentos) mostrado na Visão geral. Texto operacional, em português simples.
 */
final class AdminHelp
{
    /**
     * Etapas do fluxo de incorporação de bulas, na ordem em que o administrador as percorre.
     *
     * @return list<array{route: string, label: string, hint: string}>
     */
    public static function workflow(): array
    {
        return [
            ['route' => 'admin.knowledge.upload', 'label' => 'Adicionar documentos', 'hint' => 'Envie os PDFs das bulas'],
            ['route' => 'admin.knowledge.batches.index', 'label' => 'Importações', 'hint' => 'Acompanhe o processamento'],
            ['route' => 'admin.knowledge.pending.index', 'label' => 'Pendências', 'hint' => 'Decida o que o sistema não resolveu'],
            ['route' => 'admin.knowledge.documents.index', 'label' => 'Documentos', 'hint' => 'Bulas prontas para responder'],
        ];
    }

    /**
     * @return array{title: string, purpose: string, when: string, steps: list<string>, related: list<array{route: string, label: string}>}|null
     */
    public static function for(?string $routeName): ?array
    {
        if ($routeName === null) {
            return null;
        }
        foreach (self::entries() as $pattern => $entry) {
            if (Str::is($pattern, $routeName)) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @return array<string, array{title: string, purpose: string, when: string, steps: list<string>, related: list<array{route: string, label: string}>}>
     */
    private static function entries(): array
    {
        $r = fn (string $route, string $label): array => ['route' => $route, 'label' => $label];

        return [
            'admin.knowledge.dashboard' => [
                'title' => 'Visão geral',
                'purpose' => 'Resumo do que precisa da sua decisão, do estado da base de bulas, da operação e do uso do aplicativo pelos pacientes.',
                'when' => 'Ao começar o dia ou depois de uma importação: os pontos de atenção levam direto à tela certa.',
                'steps' => ['Veja "Precisa de atenção" e abra cada item.', 'Siga o fluxo Adicionar → Importações → Pendências → Documentos.', 'Confira se os serviços estão prontos antes de enviar bulas novas.'],
                'related' => [$r('admin.knowledge.pending.index', 'Pendências'), $r('admin.knowledge.queue', 'Fila e serviços'), $r('admin.analytics.index', 'Analytics')],
            ],
            'admin.knowledge.upload' => [
                'title' => 'Adicionar documentos',
                'purpose' => 'Envia PDFs de bulas para a base. Cada envio vira uma importação, e cada arquivo é lido, classificado e ligado ao medicamento do catálogo ANVISA.',
                'when' => 'Sempre que receber bulas novas ou atualizadas. Pode enviar vários arquivos de uma vez.',
                'steps' => ['Arraste os PDFs ou escolha os arquivos.', 'Confirme o envio: a importação começa sozinha.', 'Acompanhe em Importações; o que o sistema não resolver aparece em Pendências.'],
                'related' => [$r('admin.knowledge.batches.index', 'Importações'), $r('admin.knowledge.pending.index', 'Pendências'), $r('admin.knowledge.sources.index', 'Origens')],
            ],
            'admin.knowledge.batches.show' => [
                'title' => 'Detalhe da importação',
                'purpose' => 'Mostra o que aconteceu com cada arquivo de um envio: incorporado, duplicado, com erro ou aguardando sua revisão.',
                'when' => 'Logo depois de enviar bulas, para conferir o resultado, ou quando um arquivo deu erro.',
                'steps' => ['Veja o andamento no topo (atualiza sozinho).', 'Abra "Revisar" nos arquivos pendentes.', 'Reenvie os arquivos com erro depois de corrigir o PDF.'],
                'related' => [$r('admin.knowledge.pending.index', 'Pendências'), $r('admin.knowledge.upload', 'Adicionar documentos')],
            ],
            'admin.knowledge.batches.*' => [
                'title' => 'Importações',
                'purpose' => 'Histórico de todos os envios de bulas, com quantos arquivos entraram, ficaram pendentes ou deram erro.',
                'when' => 'Para acompanhar um envio em andamento ou reencontrar um arquivo enviado antes.',
                'steps' => ['Abra a importação para ver cada arquivo.', 'Use os filtros para achar envios com erro ou pendências.', 'Resolva as pendências na tela Pendências.'],
                'related' => [$r('admin.knowledge.upload', 'Adicionar documentos'), $r('admin.knowledge.pending.index', 'Pendências'), $r('admin.knowledge.documents.index', 'Documentos')],
            ],
            'admin.knowledge.pending.review' => [
                'title' => 'Revisar pendência',
                'purpose' => 'Decide o destino de um arquivo que o sistema não conseguiu incorporar sozinho: criar um documento, associar a um existente ou não incorporar.',
                'when' => 'Para cada item listado em Pendências. A tela sugere o medicamento identificado e já oferece a próxima pendência ao terminar.',
                'steps' => ['Confira o medicamento identificado e a origem no resumo.', 'Use a ação principal sugerida; as demais ficam em "Mais".', 'Avance para a próxima pendência ou fique na tela.'],
                'related' => [$r('admin.knowledge.pending.index', 'Pendências'), $r('admin.knowledge.documents.index', 'Documentos'), $r('admin.knowledge.catalog.index', 'Catálogo ANVISA')],
            ],
            'admin.knowledge.pending.*' => [
                'title' => 'Pendências',
                'purpose' => 'Arquivos enviados que precisam da sua decisão antes de entrar na base: o sistema não teve certeza do medicamento, do tipo de bula ou achou possível duplicata.',
                'when' => 'Logo depois de uma importação, para liberar os arquivos que o sistema não conseguiu classificar sozinho.',
                'steps' => ['Abra "Revisar" no primeiro item.', 'Decida: criar documento, associar a um existente ou não incorporar.', 'Siga para a próxima pendência até zerar a lista.'],
                'related' => [$r('admin.knowledge.batches.index', 'Importações'), $r('admin.knowledge.documents.index', 'Documentos')],
            ],
            'admin.knowledge.unanswered.*' => [
                'title' => 'Perguntas sem resposta',
                'purpose' => 'Perguntas de pacientes que o Perguntar não conseguiu responder mesmo depois de procurar nos trechos e ler a bula inteira: falta a bula, falta o princípio ativo no catálogo ou a bula não traz o assunto.',
                'when' => 'Periodicamente, para adquirir as fontes que faltam. Cada linha agrupa as perguntas de um remédio por motivo.',
                'steps' => ['Veja o motivo: cadastre a bula que falta, ligue o princípio ativo ou busque uma bula mais completa.', 'Teste a pergunta no Perguntar.', 'Marque como resolvida (ou descarte o que não tem fonte a adquirir).'],
                'related' => [$r('admin.knowledge.upload', 'Adicionar documentos'), $r('admin.knowledge.documents.index', 'Documentos'), $r('admin.knowledge.catalog.index', 'Catálogo ANVISA')],
            ],
            'admin.knowledge.unmatched.*' => [
                'title' => 'Remédios não encontrados',
                'purpose' => 'Remédios que pacientes cadastraram pelo nome (receita ou à mão) e o catálogo ANVISA não reconheceu. Ligar ao medicamento certo libera a bula simples e o cruzamento de interações para essas pessoas.',
                'when' => 'Sempre que o contador do menu estiver acima de zero; os pacientes são avisados quando você resolve.',
                'steps' => ['Veja o nome como foi escrito e quantos pacientes o usam.', 'Busque o produto no catálogo e toque em "Ligar ao catálogo".', 'Descarte só o que não é remédio (o paciente continua com o cadastro).'],
                'related' => [$r('admin.knowledge.catalog.index', 'Catálogo ANVISA'), $r('admin.knowledge.documents.index', 'Documentos')],
            ],
            'admin.knowledge.documents.create' => [
                'title' => 'Cadastrar documento sem PDF',
                'purpose' => 'Cria o registro de uma bula antes de ter o arquivo, para associar o PDF depois ou registrar uma origem manual.',
                'when' => 'Raro: quando a bula chega por outro meio ou precisa existir antes do arquivo.',
                'steps' => ['Preencha título, tipo e origem.', 'Salve e associe o PDF pela revisão de uma pendência.'],
                'related' => [$r('admin.knowledge.documents.index', 'Documentos'), $r('admin.knowledge.upload', 'Adicionar documentos')],
            ],
            'admin.knowledge.documents.show' => [
                'title' => 'Detalhe do documento',
                'purpose' => 'Tudo sobre uma bula incorporada: situação do PDF, do texto e do índice de busca, princípios ativos ligados e trechos.',
                'when' => 'Quando uma resposta do aplicativo citou esta bula, quando o processamento falhou ou para reprocessar depois de trocar o PDF.',
                'steps' => ['Confira os três estados (PDF, texto, índice).', 'Use "Reprocessar" só se o texto ou os trechos estiverem errados.', 'Verifique os princípios ativos: sem eles a bula não entra nas respostas.'],
                'related' => [$r('admin.knowledge.documents.index', 'Documentos'), $r('admin.knowledge.queue', 'Fila e serviços')],
            ],
            'admin.*documents.*' => [
                'title' => 'Documentos',
                'purpose' => 'Todas as bulas e materiais já incorporados à base. É daqui que o aplicativo tira as respostas e a bula simples.',
                'when' => 'Para conferir se uma bula está pronta (PDF, texto e índice), achar uma bula específica ou reprocessar uma com falha.',
                'steps' => ['Busque por título, chave externa ou tipo.', 'Filtre por situação do PDF para achar o que falta.', 'Abra o documento para ver e corrigir o processamento.'],
                'related' => [$r('admin.knowledge.upload', 'Adicionar documentos'), $r('admin.knowledge.pending.index', 'Pendências'), $r('admin.knowledge.sources.index', 'Origens')],
            ],
            'admin.knowledge.sources.create' => [
                'title' => 'Nova origem',
                'purpose' => 'Cadastra de onde vêm os documentos (por exemplo, ANVISA ou um laboratório).',
                'when' => 'Antes de importar bulas de uma procedência ainda não cadastrada.',
                'steps' => ['Informe nome e tipo.', 'Salve e escolha a origem ao adicionar documentos.'],
                'related' => [$r('admin.knowledge.sources.index', 'Origens'), $r('admin.knowledge.upload', 'Adicionar documentos')],
            ],
            'admin.knowledge.sources.*' => [
                'title' => 'Origens',
                'purpose' => 'De onde vêm os documentos da base (ANVISA, laboratórios, materiais internos). Cada documento pertence a uma origem.',
                'when' => 'Para cadastrar uma procedência nova ou desativar uma que não deve mais receber documentos.',
                'steps' => ['Abra uma origem para ver seus documentos.', 'Edite nome, tipo e situação.', 'Desative em vez de apagar: o histórico fica.'],
                'related' => [$r('admin.knowledge.documents.index', 'Documentos'), $r('admin.knowledge.upload', 'Adicionar documentos')],
            ],
            'admin.knowledge.catalog.*' => [
                'title' => 'Catálogo ANVISA',
                'purpose' => 'Lista oficial de medicamentos registrados, usada para identificar bulas, validar os remédios dos pacientes e ligar princípios ativos às interações.',
                'when' => 'Ao instalar o sistema e sempre que a ANVISA publicar uma lista nova (o arquivo original é CSV em ISO-8859-1).',
                'steps' => ['Importe o arquivo da ANVISA.', 'Acompanhe o progresso (atualiza sozinho).', 'Depois, rode a ligação de princípios ativos pela Fila e serviços, se indicado.'],
                'related' => [$r('admin.knowledge.interactions.index', 'Interações'), $r('admin.knowledge.unmatched.index', 'Remédios não encontrados')],
            ],
            'admin.knowledge.interactions.*' => [
                'title' => 'Interações',
                'purpose' => 'Base de interações medicamentosas (DDInter) usada como informação complementar nas consultas e no contexto do tratamento.',
                'when' => 'Ao instalar o sistema e quando houver arquivos novos da DDInter. Reenviar o mesmo arquivo não duplica.',
                'steps' => ['Importe os arquivos CSV (um por letra).', 'Confira os totais por gravidade.', 'Verifique se os princípios ativos estão ligados ao catálogo.'],
                'related' => [$r('admin.knowledge.catalog.index', 'Catálogo ANVISA'), $r('admin.analytics.consultations.index', 'Consultas de interação')],
            ],
            'admin.knowledge.queue' => [
                'title' => 'Fila e serviços',
                'purpose' => 'Mostra a fila de análises, o processador e os serviços de leitura de PDF, IA local e índice de busca; permite reenfileirar, cancelar e reiniciar.',
                'when' => 'Quando uma importação parece parada, um serviço aparece fora do ar ou há tarefas com falha.',
                'steps' => ['Veja os selos dos serviços e a hora da verificação.', 'Reenfileire análises paradas; cancele as que não fazem mais sentido.', 'Reinicie um serviço só se ele continuar fora depois de alguns minutos.'],
                'related' => [$r('admin.knowledge.batches.index', 'Importações'), $r('admin.knowledge.documents.index', 'Documentos')],
            ],
            'admin.analytics.consultations.*' => [
                'title' => 'Consultas de interação',
                'purpose' => 'Cada verificação de interações feita no aplicativo: medicamentos, resultado, evidências, limitações e tempos.',
                'when' => 'Para auditar uma resposta, investigar uma falha ou entender como os pacientes usam a verificação.',
                'steps' => ['Filtre por período e situação.', 'Abra a consulta para ver pares, gravidade e trechos usados.'],
                'related' => [$r('admin.analytics.index', 'Analytics'), $r('admin.knowledge.interactions.index', 'Interações')],
            ],
            'admin.analytics.questions.*' => [
                'title' => 'Perguntas',
                'purpose' => 'Perguntas livres feitas no aplicativo, com a resposta validada, as evidências da bula e as que foram recusadas pela triagem.',
                'when' => 'Para auditar respostas, revisar recusas e descobrir bulas que faltam.',
                'steps' => ['Veja as recusadas e os motivos.', 'Abra a pergunta para ver evidências e contexto usado.'],
                'related' => [$r('admin.analytics.index', 'Analytics'), $r('admin.knowledge.documents.index', 'Documentos')],
            ],
            'admin.analytics.leaflets.*' => [
                'title' => 'Bulas humanizadas',
                'purpose' => 'Versões em linguagem simples geradas a partir das bulas, com evidências, métricas e quantas vezes foram vistas.',
                'when' => 'Para revisar a qualidade do texto simples ou reaproveitar bulas mais procuradas.',
                'steps' => ['Ordene pelas mais vistas.', 'Abra a bula para comparar cada seção com o trecho original.'],
                'related' => [$r('admin.analytics.index', 'Analytics'), $r('admin.knowledge.documents.index', 'Documentos')],
            ],
            'admin.analytics.ai-usage.*' => [
                'title' => 'Consumo de IA',
                'purpose' => 'Tokens e custo de cada provedor de IA, separados, e de cada usuário: médias por pessoa, modelos, telas que geraram as chamadas e o registro original devolvido pela API.',
                'when' => 'Para acompanhar o custo, achar quem mais consome e estimar planos de preço.',
                'steps' => ['Escolha o período e, se quiser, um provedor.', 'Compare as médias por usuário com o custo total.', 'Abra um usuário para ver cada chamada e o uso informado pela API.'],
                'related' => [$r('admin.analytics.index', 'Analytics'), $r('admin.reports.index', 'Relatórios'), $r('admin.users.index', 'Usuários')],
            ],
            'admin.analytics.*' => [
                'title' => 'Analytics',
                'purpose' => 'Métricas das respostas da IA (ancoragem nas bulas, recusas, latência), custos por provedor e auditoria das consultas, perguntas e bulas simples.',
                'when' => 'Semanalmente, para acompanhar qualidade e custo, e sempre que alguém relatar uma resposta estranha.',
                'steps' => ['Escolha o período.', 'Veja ancoragem e recusas; abra as listas para os casos.', 'Exporte com "Relatórios" quando precisar de um arquivo.'],
                'related' => [$r('admin.reports.index', 'Relatórios'), $r('admin.analytics.consultations.index', 'Consultas'), $r('admin.analytics.questions.index', 'Perguntas')],
            ],
            'admin.reports.*' => [
                'title' => 'Relatórios',
                'purpose' => 'Gera e baixa arquivos com os dados da base, do uso, dos custos e da auditoria, para prestar contas ou analisar fora do sistema.',
                'when' => 'No fechamento do mês, para o TCC ou quando alguém pedir os números.',
                'steps' => ['Escolha o tipo e o período.', 'Gere; o arquivo fica disponível para baixar nesta tela.'],
                'related' => [$r('admin.analytics.index', 'Analytics')],
            ],
            'admin.users.create' => [
                'title' => 'Novo usuário',
                'purpose' => 'Cria um acesso ao sistema com perfil (administrador ou usuário comum) e senha provisória.',
                'when' => 'Ao cadastrar uma pessoa da equipe ou um paciente que não consegue se cadastrar sozinho.',
                'steps' => ['Informe nome, e-mail e perfil.', 'Copie a senha provisória mostrada uma única vez ou envie por e-mail.', 'A pessoa troca a senha no primeiro acesso.'],
                'related' => [$r('admin.users.index', 'Usuários')],
            ],
            'admin.users.show' => [
                'title' => 'Detalhe do usuário',
                'purpose' => 'Situação do acesso, uso do aplicativo, sessões ativas e histórico de auditoria de uma pessoa.',
                'when' => 'Para redefinir senha, encerrar sessões, desativar o acesso ou entender o que a pessoa fez.',
                'steps' => ['Use a ação principal (editar) ou "Mais" para senha, sessões e desativação.', 'Toda ação de risco pede confirmação.'],
                'related' => [$r('admin.users.index', 'Usuários')],
            ],
            'admin.users.edit' => [
                'title' => 'Editar usuário',
                'purpose' => 'Altera nome, e-mail, perfil e se a pessoa precisa trocar a senha no próximo acesso.',
                'when' => 'Quando a pessoa mudou de função ou de e-mail.',
                'steps' => ['Altere os campos e salve.', 'Para senha, sessões e desativação use o detalhe do usuário.'],
                'related' => [$r('admin.users.index', 'Usuários')],
            ],
            'admin.users.*' => [
                'title' => 'Usuários',
                'purpose' => 'Quem acessa o BULAI e com qual perfil: administradores e usuários comuns (pacientes).',
                'when' => 'Para dar acesso a alguém, ver quem nunca entrou, desativar contas e acompanhar a troca de senha pendente.',
                'steps' => ['Busque por nome ou e-mail e filtre por situação e perfil.', 'Abra a pessoa para ver detalhes e agir.', 'Crie um acesso novo pela ação principal.'],
                'related' => [$r('admin.users.create', 'Novo usuário'), $r('admin.analytics.index', 'Analytics')],
            ],
        ];
    }
}
