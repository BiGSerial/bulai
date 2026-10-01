# Material técnico para o TCC

## Finalidade

Esta pasta reúne a base técnica do BULA-IA para uso no Trabalho de Conclusão de Curso de Sistemas de Informação. O conteúdo foi separado por tema para facilitar sua adaptação aos capítulos de especificação, desenvolvimento, validação e resultados.

Os textos descrevem o estado implementado do sistema em 1º de outubro de 2026. O projeto de pesquisa inicial é usado como referência de contexto, não como especificação técnica obrigatória. Eles não substituem a redação acadêmica final: decisões precisam ser relacionadas ao problema de pesquisa, citações devem vir de fontes bibliográficas e resultados quantitativos somente podem ser apresentados após medição reproduzível.

## Estrutura sugerida

| Documento | Uso sugerido no TCC |
| --- | --- |
| [00-contexto-e-alinhamento-com-o-projeto.md](00-contexto-e-alinhamento-com-o-projeto.md) | Relação entre o projeto acadêmico, o código atual e o escopo futuro |
| [01-visao-geral-e-arquitetura.md](01-visao-geral-e-arquitetura.md) | Contexto técnico, escopo, requisitos e arquitetura |
| [02-modelagem-de-dados.md](02-modelagem-de-dados.md) | Modelo de domínio, entidades, estados e integridade |
| [03-ingestao-e-processamento.md](03-ingestao-e-processamento.md) | Fluxos principais, algoritmos e decisões de implementação |
| [04-seguranca-e-privacidade.md](04-seguranca-e-privacidade.md) | Ameaças, controles, privacidade e riscos residuais |
| [05-qualidade-operacao-e-evolucao.md](05-qualidade-operacao-e-evolucao.md) | Testes, implantação, observabilidade, limitações e trabalhos futuros |
| [06-evidencias-e-checklist.md](06-evidencias-e-checklist.md) | Evidências que devem ser coletadas antes da entrega do TCC |

## Como utilizar no texto acadêmico

- Converta as descrições de implementação em argumentos relacionados aos objetivos específicos do trabalho.
- Inclua referências bibliográficas para RAG, recuperação de informação, segurança, qualidade de software e arquitetura; esta pasta não inventa nem antecipa referências.
- Diferencie claramente funcionalidade implementada, decisão de projeto, limitação e trabalho futuro.
- Não apresente embeddings, busca vetorial, RAG, OCR ou geração de respostas como resultados prontos: esses recursos ainda não existem no sistema.
- Use capturas de tela sem dados pessoais, segredos, tokens, caminhos internos ou conteúdo farmacêutico restrito.
- Identifique versão do código, ambiente, massa de teste e procedimento sempre que apresentar uma medição.
- Descreva a arquitetura efetivamente construída e justifique suas decisões pelos requisitos do sistema. Linguagens, bancos e frameworks citados no projeto preliminar eram possibilidades iniciais, não compromissos técnicos.

## Fontes primárias no repositório

- visão funcional: `docs/atividades-implementadas.md`;
- decisões e detalhes internos: `docs/technical-foundation.md`;
- rotas: `routes/web.php` e `routes/api.php`;
- domínio: `app/Models/Knowledge` e `app/Services/Knowledge`;
- interface administrativa: `app/Livewire/Admin`;
- estrutura persistente: `database/migrations`;
- evidências automatizadas: `tests/Feature` e `tests/Unit`.
