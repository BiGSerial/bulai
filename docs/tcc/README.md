# Material técnico para o TCC

## Finalidade

Esta pasta reúne a base técnica do BULA-IA para uso no Trabalho de Conclusão de Curso de Sistemas de Informação. O conteúdo foi separado por tema para facilitar sua adaptação aos capítulos de especificação, desenvolvimento, validação e resultados.

Os textos 00–06 descrevem o estado do sistema em 1º de outubro de 2026; os textos 17–23 descrevem o MVP de consulta, RAG e avaliação em 4 de novembro de 2026; o texto 24 descreve a segunda iteração do MVP (bula humanizada, contexto de tratamento, registro analítico das respostas, embeddings reais) do mesmo dia. O projeto de pesquisa inicial é usado como referência de contexto, não como especificação técnica obrigatória. Eles não substituem a redação acadêmica final: decisões precisam ser relacionadas ao problema de pesquisa, citações devem vir de fontes bibliográficas e resultados quantitativos somente podem ser apresentados após medição reproduzível.

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
- A recuperação lexical, a análise de interações e a geração validada **existem** (`17`–`19`); embeddings/busca vetorial/híbrida foram **medidos na iteração 2** com `bge-m3` local (amostra pequena; `23`, seção 3) e o provedor Anthropic foi **validado com chamadas reais** (`23`, seção 3a; `20` descreve a integração). A bula humanizada, o contexto de tratamento, a pergunta livre com guarda de entrada, a receita por foto e o registro analítico estão em `24`. Resultados e suas limitações estão em `23`; validação clínica e SUS **não foram feitas** (`22`). A acurácia do OCR continua não avaliada.
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

## Notas do MVP, RAG e avaliação (17 a 23)

Redigidas em 04/11/2026 a partir do código, dos testes e de execuções registradas. Não editam o `.docx`.

| Documento | Seções do docx relacionadas |
| --- | --- |
| [17-pipeline-implementado-e-desvios-do-projeto.md](17-pipeline-implementado-e-desvios-do-projeto.md) | 3.3.2, 3.3.3, 3.4 |
| [18-catalogo-farmacologico-e-resolucao-de-ingredientes.md](18-catalogo-farmacologico-e-resolucao-de-ingredientes.md) | 3.2.2, 3.3.2 |
| [19-recuperacao-rag-e-controle-de-alucinacao.md](19-recuperacao-rag-e-controle-de-alucinacao.md) | 2.7.1, 2.7.2, 3.3.1, 3.3.2 |
| [20-integracao-anthropic.md](20-integracao-anthropic.md) | 2.6.3, 3.3.1, 3.3.3 |
| [21-pwa-privacidade-e-seguranca-do-usuario-final.md](21-pwa-privacidade-e-seguranca-do-usuario-final.md) | 3.3.2 (etapa 4), 3.4 (Fase 4), 3.5.3 |
| [22-protocolos-de-validacao-profissional-e-usabilidade.md](22-protocolos-de-validacao-profissional-e-usabilidade.md) | 3.5.1, 3.5.2 (protocolos; **não executados**) |
| [23-resultados-experimentais.md](23-resultados-experimentais.md) | Histórico do desenvolvimento; **não usado no TCC** (o texto final usa só a produção de 11/11/2026: [`../qa/avaliacao-producao-2026-11-11.md`](../qa/avaliacao-producao-2026-11-11.md) e registros `prod-*`) |
| [24-bula-humanizada-contexto-de-tratamento-e-analytics.md](24-bula-humanizada-contexto-de-tratamento-e-analytics.md) | 2.7.1, 2.7.2, 3.3.1, 3.3.2 (etapas 3–5), 3.5.1, 3.5.3 |
| [25-programacao-do-tratamento-avisos-e-remedios-fora-do-catalogo.md](25-programacao-do-tratamento-avisos-e-remedios-fora-do-catalogo.md) | 3.3.2 (etapas 4 e 5), 3.4 (Fase 4), 3.5.3 |
| [26-experiencia-nativa-conversa-com-contexto-e-tolerancia-a-erros.md](26-experiencia-nativa-conversa-com-contexto-e-tolerancia-a-erros.md) | 3.3.2 (etapas 4 e 5), 3.4 (Fase 4), 3.5.3 |
| [27-observabilidade-do-pipeline-progresso-em-tempo-real-e-retaguarda-do-classificador.md](27-observabilidade-do-pipeline-progresso-em-tempo-real-e-retaguarda-do-classificador.md) | 3.3.2 (pipeline de ingestão), 3.3.3 (classificação híbrida), 3.5.2, 3.5.3 |
| [28-indice-de-busca-como-etapa-propria-lotes-de-embeddings-e-fuso-horario.md](28-indice-de-busca-como-etapa-propria-lotes-de-embeddings-e-fuso-horario.md) | 3.3.2 (etapa de embeddings), 3.5.2, 3.5.3 |
| [29-perguntar-confiavel-bula-inteira-evidencia-auditavel-e-revisao-humana.md](29-perguntar-confiavel-bula-inteira-evidencia-auditavel-e-revisao-humana.md) | 3.3.2 (etapas 4 e 5), 3.3.3 (recuperação e geração), 3.4 (Fase 4), 3.5.3 |
| [30-motor-conversacional-do-perguntar-humanizar-a-bula.md](30-motor-conversacional-do-perguntar-humanizar-a-bula.md) | 3.3.2 (etapas 4 e 5), 3.3.3 (geração e validação), 3.4 (Fase 4), 3.5.1, 3.5.3 |

## Texto final (artigo científico)

O texto final está em [tcc-bula-ia-texto-final.md](tcc-bula-ia-texto-final.md), espelho do artigo em Word `Word/TCC_BULA-IA_artigo_2026-11-12.docx` (a versão em Word é a de referência: título, tema e formatação da FAESA não mudam). Todos os números vêm do servidor de produção, em 11 e 12/11/2026 (registros `prod-*` e API `/audit/*`, Apêndices A a C): a identificação e a verificação de interações foram repetidas após a correção dos princípios ativos duplicados (PR #60); a recuperação e a comparação sem recuperação continuam as de 11/11 (base de 82 bulas) e devem ser refeitas com a base atual. O artigo tem até 25 páginas até a última referência; os apêndices vêm depois.

O texto segue o formato de artigo científico e foi revisado em 11/11/2026 contra estas normas:

- **Estrutura (ABNT NBR 6022:2018):** título em português e em inglês; autores com a qualificação em nota de rodapé; resumo e *abstract* de até 250 palavras (ABNT NBR 6028:2021), cada um com palavras-chave em minúsculas, separadas por ponto e vírgula e terminadas em ponto; depois, introdução, desenvolvimento, considerações finais, referências e apêndices.
- **Citações (ABNT NBR 10520:2023):** sobrenome e entidade em maiúsculas e minúsculas nas citações entre parênteses, por exemplo (Lewis et al., 2020) e (World Health Organization, 2017). As referências continuam em caixa alta.
- **Referências (ABNT NBR 6023:2018):** documentos *online* com "Disponível em:" e "Acesso em:"; DOI quando existe; alinhamento à esquerda, espaço simples e uma linha em branco entre as referências. Toda citação tem referência, e toda referência é citada.
- **Siglas:** cada sigla é definida por extenso na primeira ocorrência: ANVISA, OMS, LLM, RAG, OCR, API, ATC, IC, MRR, SUS, PWA, LGPD e SAMU.
- **Tabelas e quadros:** título acima, "Fonte:" e "Nota:" abaixo. Tabelas trazem dados numéricos e têm as laterais abertas; quadros trazem texto e são fechados.
- **Datas:** por extenso no texto ("11 de novembro de 2026"); abreviadas só nas referências e nos títulos dos quadros do apêndice.
- **Formato do .docx:** A4; margens de 3 cm (superior e esquerda) e 2 cm (inferior e direita); Times New Roman 12 com espaçamento 1,5; recuo de 1,25 cm; tabelas, notas e referências em tamanho menor, com espaço simples; número da página no canto superior direito.
- **Extensão:** limite de 25 páginas sem contar referências e apêndices. A contagem foi feita no Word sobre o .docx: o texto vai da página 1 à 24, as referências começam na 25, e os apêndices, na 28.
- **Caminhos de código:** saíram do corpo do texto e ficaram no Apêndice B, junto com os comandos de reprodução.

Não há manual de artigo da FAESA publicado. Se a coordenação ou o orientador tiver um modelo próprio (fonte, espaçamento, folha de rosto, notas dos autores), ele prevalece sobre os itens de formato acima.

Pendente: reexecutar a identificação e as interações após o deploy da correção dos princípios ativos duplicados (#60) e o `knowledge:build-ingredients`, e então atualizar as seções 5.1 e 5.2.
