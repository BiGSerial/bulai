# Contexto e alinhamento com o projeto de TCC

## Documento analisado

Este material foi alinhado ao projeto de pesquisa **“BULA-IA: Sistema Inteligente para Análise de Interações Medicamentosas”**, de Davi Tâmbara Rodrigues e Wilton Carvalho de Oliveira, para o curso de Sistemas de Informação do Centro Universitário Espírito-Santense, com entrega prevista em 2026.

O arquivo consultado foi `PROJETO_TCC_ (1).docx`, fornecido em 1º de outubro de 2026. Ele corresponde à etapa preliminar do projeto, anterior ao desenvolvimento do Trabalho de Conclusão de Curso. O `.docx` permanece como fonte de contexto acadêmico; suas sugestões de linguagem, banco, framework e arquitetura não restringem a solução final.

## Problema de pesquisa

O projeto parte de duas dificuldades relacionadas:

1. a linguagem técnica, o volume e a organização das bulas dificultam sua compreensão por pessoas não especialistas;
2. o uso simultâneo de medicamentos aumenta a necessidade de identificar e comunicar possíveis interações de maneira acessível e rastreável.

A pergunta de pesquisa investiga como LLMs podem facilitar a compreensão de bulas e apoiar dúvidas sobre interações medicamentosas.

## Hipótese e objetivo

A hipótese é que um sistema baseado em IA e LLMs pode tornar informações sobre posologia, frequência, indicação, contraindicação e interação mais acessíveis, contribuindo para uso mais consciente de medicamentos.

O objetivo geral declarado é desenvolver o BULA-IA com LLM e RAG para interpretar bulas, identificar potenciais interações e apresentar resultados em linguagem acessível, sustentados por fontes farmacológicas confiáveis.

Objetivos específicos do projeto:

- estudar técnicas de IA aplicáveis ao problema;
- levantar dados da ANVISA e de outras fontes farmacológicas;
- organizar e estruturar as informações;
- integrar um LLM para interpretação de bulas e análise de interações.

## Escopo de dados proposto

O projeto acadêmico prevê:

- Bulário Eletrônico da ANVISA como fonte regulatória brasileira;
- DrugBank 6.0 como fonte complementar de dados farmacológicos e interações;
- subconjunto de 30 medicamentos de uso prevalente no Brasil;
- classes como anti-hipertensivos, antidiabéticos, analgésicos, AINEs, anticoagulantes, antibióticos e psicotrópicos.

A utilização dessas fontes depende de acesso legítimo, licença, termos de uso, versão e registro de proveniência. O código atual permite cadastrar origens e documentos, mas não contém ainda integração automatizada com ANVISA ou DrugBank nem comprova a curadoria dos 30 medicamentos.

## Fluxo conceitual idealizado no projeto preliminar

O documento preliminar divide a solução desejada em sete etapas:

1. coleta e curadoria;
2. extração, limpeza e segmentação;
3. geração de embeddings e indexação vetorial;
4. interface do usuário;
5. recuperação semântica;
6. geração da resposta pelo LLM;
7. análise e classificação de interações.

Esse fluxo ajuda a delimitar capacidades, mas não determina como cada componente deve ser implementado. Parte dele ainda está no roadmap.

## Correspondência entre TCC e implementação

| Etapa prevista | Estado no software | Evidência atual |
| --- | --- | --- |
| Cadastro de fontes e procedência | Implementada | `knowledge_sources` e Admin de origens |
| Recepção e armazenamento privado de PDFs | Implementada | Upload Admin, API e `KnowledgeFileService` |
| Curadoria assistida e pendências | Implementada | Lotes, quarentena e revisão manual |
| Extração textual de PDF | Implementada para PDFs com texto | `PdfTextExtractor` e PrinsFrank PDF Parser |
| Normalização e detecção de seções | Implementadas | Serviços determinísticos de processamento |
| Chunking com página e seção | Implementado | `KnowledgeChunker` e `knowledge_chunks` |
| Embeddings | Não implementados | Campo preparado, sem geração vetorial |
| Indexação e recuperação vetorial | Não implementadas | pgvector disponível, sem pipeline de consulta |
| Integração com LLM | Não implementada | Sem provedor, prompt ou geração |
| Humanização de bula | Não implementada | Sem interface ou avaliação de resposta |
| Base estruturada de interações | Não implementada | Sem modelo/tabela específica de interações |
| Classificação leve/moderada/grave | Não implementada | Sem regra ou fonte integrada |
| Interface para usuário final/mobile | Não implementada | Interface atual é administrativa e web/PWA |
| Disclaimer nas respostas | Não aplicável ainda | Ainda não existem respostas geradas |
| Avaliação técnica e SUS | Não executadas | Protocolos estão apenas planejados no projeto |

## Evolução das decisões tecnológicas

O projeto preliminar cita Python, LangChain, ChromaDB, API GPT-4o ou equivalente e React Native/Flutter como possibilidades. Essas escolhas foram formuladas antes do desenvolvimento e não constituem requisitos do TCC.

A implementação existente utiliza:

- PHP 8.4 e Laravel 13 no backend;
- Livewire 4 e Tailwind CSS para o Admin web/PWA;
- PostgreSQL 17 com pgvector;
- Laravel Queue;
- Laravel Filesystem;
- PrinsFrank PDF Parser.

O capítulo de desenvolvimento deve concentrar-se na solução efetivamente construída. A justificativa técnica pode abordar:

- unificação da aplicação em um monólito modular;
- autenticação, validação, ORM, filas e filesystem fornecidos pelo ecossistema Laravel;
- redução de componentes operacionais na fundação do MVP;
- PostgreSQL como banco transacional e futuro armazenamento vetorial;
- rastreabilidade e consistência antes da integração com LLM;
- possibilidade de integrar outros serviços futuramente quando houver necessidade comprovada.

Não é necessário fazer uma comparação entre linguagens ou bancos se esse não for um objetivo da pesquisa. O relevante é mostrar como as decisões atendem aos requisitos, quais trade-offs foram assumidos e quais evidências demonstram o funcionamento da solução.

## Delimitação correta do protótipo atual

O software atual é a **fundação de ingestão e preparação da base de conhecimento** do BULA-IA. Ele já resolve procedência, armazenamento, integridade, curadoria assistida, processamento textual e rastreabilidade de chunks.

Ele ainda não cumpre integralmente o objetivo geral do TCC, pois não interpreta perguntas, não identifica interações e não produz respostas em linguagem acessível. Essa distinção deve aparecer em desenvolvimento, resultados, limitações e cronograma.

## Avaliação prevista no projeto

### Avaliação técnica futura

O documento propõe 20 pares de medicamentos com interações documentadas:

- 10 graves;
- 5 moderadas;
- 5 leves.

Métricas propostas:

- identificação correta da presença ou ausência de interação;
- classificação correta da gravidade;
- presença e adequação do disclaimer;
- correspondência entre citação e fonte recuperada.

Esse protocolo precisa definir também pares negativos, critérios de desempate, avaliador de referência, versão das fontes, cálculo das métricas e tratamento de respostas inconclusivas. “Taxa de acerto” não deve ser calculada antes de congelar o conjunto de teste.

### Avaliação de usabilidade futura

O projeto prevê SUS com 5 a 10 participantes não especialistas e avaliação qualitativa por ao menos um profissional de saúde. O tamanho amostral permite uma análise exploratória, sem generalização estatística.

Antes da coleta, devem ser definidos termo de consentimento, tarefas, critérios de recrutamento, tratamento dos dados, roteiro, cálculo SUS e aprovação institucional quando exigida.

## Ajustes necessários no texto final do TCC

- redigir a seção de tecnologias a partir da implementação real, sem tratá-la como subordinada às sugestões do projeto preliminar;
- separar arquitetura implementada de arquitetura futura;
- trocar “treinamento da IA” por termos precisos quando o método for RAG sem fine-tuning;
- não afirmar redução de alucinação como garantia; tratá-la como hipótese a ser medida;
- registrar que pgvector foi preparado, mas ainda não está sendo usado para recuperação;
- documentar a ausência atual de DrugBank e verificar licença de uso antes da integração;
- incluir a interface administrativa como resultado técnico da fase de curadoria;
- incluir segurança, procedência, concorrência e compensação de arquivos no capítulo técnico;
- revisar cronograma e fases conforme o estado real do desenvolvimento;
- manter explícito que o sistema é informacional e não realiza diagnóstico ou prescrição.
