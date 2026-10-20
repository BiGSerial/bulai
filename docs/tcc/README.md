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
- Não apresente embeddings, busca vetorial, RAG ou geração de respostas como resultados prontos: não existem no sistema (apenas a infraestrutura de provedores de IA foi preparada). O OCR existe como fallback, mas sua acurácia ainda não foi avaliada.
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

## Notas acadêmicas por tema (07 a 16)

Notas redigidas em 18/10/2026 a partir do código e da documentação técnica, mapeadas às seções de `Word/PROJETO_TCC_ 2026-10-18.docx` (Cap. 1 Introdução; Cap. 2 Referencial Teórico; Cap. 3 Metodologia; Cap. 4 Cronograma). Referências bibliográficas ainda não incluídas estão marcadas como "[referência a incluir: ...]". O registro de continuidade está em [_registro-de-notas.md](_registro-de-notas.md).

| Documento | Seções do docx relacionadas |
| --- | --- |
| [07-ocr-e-extracao-de-texto.md](07-ocr-e-extracao-de-texto.md) | 3.3.2 (etapa 2), 3.4 |
| [08-classificacao-hibrida-heuristica-llm.md](08-classificacao-hibrida-heuristica-llm.md) | 3.3, 3.3.2 (etapas 1 e 2), 3.4 |
| [09-llms-locais-quantizacao-llamafile.md](09-llms-locais-quantizacao-llamafile.md) | 2.6.3, 3.3.3 |
| [10-arquitetura-de-provedores-de-ia-desacoplados.md](10-arquitetura-de-provedores-de-ia-desacoplados.md) | 3.3.1, 3.3.3 |
| [11-validacao-estrita-e-grounding.md](11-validacao-estrita-e-grounding.md) | 2.7.2, 3.3.1, 3.5.3 |
| [12-auditabilidade-e-explicabilidade.md](12-auditabilidade-e-explicabilidade.md) | 2.7.2, 3.3.1, 3.5.1 |
| [13-observabilidade-e-saude-de-servicos.md](13-observabilidade-e-saude-de-servicos.md) | 3.3.3, 3.4 |
| [14-comunicacao-em-tempo-real-sse-websocket.md](14-comunicacao-em-tempo-real-sse-websocket.md) | 3.3.2 (etapa 6), 3.3.3 |
| [15-ameacas-a-validade-e-limitacoes.md](15-ameacas-a-validade-e-limitacoes.md) | 3.5.1, 3.5.3 |
| [16-trabalhos-futuros.md](16-trabalhos-futuros.md) | 3.4, Cap. 4 |
