# Pipeline implementado e desvios em relação ao projeto

Mapeamento no docx: Cap. 3, seções 3.3.2 (Fluxo de Processamento), 3.3.3 (Tecnologias Planejadas) e 3.4 (Etapas de Desenvolvimento). Estado do código em 04/11/2026.

## Objetivo do tópico

Descrever o fluxo efetivamente construído, etapa a etapa, e declarar com clareza onde ele difere do plano do projeto, para que o texto final do TCC descreva o sistema real e justifique as mudanças.

## 1. Fluxo implementado (consulta do usuário)

| # | Etapa do projeto (3.3.2) | Como ficou | Onde |
| --- | --- | --- | --- |
| 1 | Coleta e curadoria | Bulas PDF da ANVISA ingeridas pelo Admin (lote, OCR condicional, classificação heurística + IA, revisão humana); catálogo ANVISA (CSV) e **DDInter** (CSV) importados por comando/Admin | `app/Services/Knowledge`, `knowledge:import-drug-interactions` |
| 2 | Pré-processamento | Extração de texto, normalização, segmentação por seção e *chunking* com página e seção preservadas | `KnowledgeChunker`, `KnowledgeSectionDetector` |
| 3 | Indexação vetorial | `knowledge:index-embeddings` grava vetor + modelo + dimensão + hash por chunk em **PostgreSQL/pgvector**; paralelamente há índice lexical (tsvector `portuguese`) | `app/Services/Retrieval/KnowledgeIndexer.php` |
| 4 | Interface | PWA Livewire: busca por nome comercial/princípio ativo, seleção de 2 a 6 medicamentos | `app/Livewire/Consulta` |
| 5 | Recuperação | Por par, trechos das bulas de cada fármaco que citam o outro (lexical, vetorial ou híbrido RRF) | `PairEvidenceRetriever`, `PostgresKnowledgeRetriever` |
| 6 | Geração | LLM explica **apenas** os trechos recuperados, em JSON; saída validada; sem recomendação de conduta | `ExplanationGenerator`, `ExplanationValidator` |
| 7 | Módulo de interações | Consulta estruturada ao DDInter por princípio ativo resolvido; gravidade vem da fonte | `InteractionLookup` |

Diferença conceitual relevante: no projeto, a etapa 7 era posterior à geração. No sistema construído a **base estruturada é a autoridade sobre existência e gravidade** e a geração fica subordinada a ela e às evidências (regra de domínio). Isso reduz o risco de alucinação de interações (seção 2.7.2 do projeto) ao custo de depender da cobertura do DDInter.

## 2. Desvios declarados

| Item do projeto | Implementado | Motivo / consequência |
| --- | --- | --- |
| DrugBank 6.0 | **DDInter** (CSV; ~160 mil pares com gravidade, sem texto) | DrugBank exige licença para uso além do acadêmico e distribuição restrita; o DDInter já estava importado. O DDInter traz gravidade, **não** descrição: o texto explicativo vem das bulas. [conferir termos de licença do DDInter antes de publicar dados] |
| ChromaDB | PostgreSQL 17 + pgvector | uma só base transacional (usuários, consultas, documentos, vetores); sem serviço extra na VPS |
| LangChain / Python | Laravel 13 / PHP 8.4, contratos próprios | backend único já existente; orquestração é pequena e testável |
| OpenAI GPT-4o | Provedor desacoplado: `anthropic` (Claude), `openai_compatible`, `fake` e llamafile local | ver `10-...` e `20-integracao-anthropic.md` |
| React Native / Flutter | **PWA** (Livewire + Tailwind), mobile-first | sem lojas de aplicativos; mesmo código do Admin |
| 30 medicamentos | catálogo ANVISA completo (39.416 registros), com 21,8 mil ligados a princípio ativo; **17 bulas** indexáveis | a cobertura de *bulas* continua pequena (veja `15-...`) |
| Gravidade Leve/Moderada/Grave | `minor`/`moderate`/`major` do DDInter, exibidas como Menor/Moderada/Maior, mais `unknown` ("gravidade não classificada pela fonte") | equivalência de rótulos é uma **suposição** do autor; `unknown` é frequente (29.813 pares) |
| Embeddings | implementados, **não medidos**: não há serviço de embeddings disponível | recuperação lexical é o único modo com resultado real |

## 3. Garantias de projeto (invariantes)

1. A IA generativa não decide existência nem gravidade. 2. "Não encontrada nas fontes" nunca é exibido como "seguro". 3. Toda afirmação textual exibida referencia ao menos um trecho recuperado e passa por verificação automática de ancoragem. 4. Trechos de bulas são tratados como dados não confiáveis. 5. Não há recomendação de suspender, trocar ou ajustar dose. 6. Estados inconclusivos são explícitos (`INSUFFICIENT_EVIDENCE`, `MEDICATION_NOT_IDENTIFIED`, `CONTRADICTORY_EVIDENCE`, `TECHNICAL_ERROR`).

## 4. O que ainda não existe

Serviço de embeddings; validação por profissional de saúde; SUS com usuários; contas por autocadastro; mecanismo de consentimento LGPD; ícones PNG do PWA. Ver `15-ameacas-a-validade-e-limitacoes.md` e `22-...`.
