# Trabalhos futuros relacionados a OCR, classificação e IA

Mapeamento no docx: Cap. 3, seção 3.4 (Etapas de Desenvolvimento, Fases 2 a 5) e Cap. 4 (Cronograma). Complementa a lista geral de `05-qualidade-operacao-e-evolucao.md`.

## Objetivo do tópico

Priorizar a evolução das camadas descritas nos tópicos 07 a 15 e relacioná-las às fases do projeto de pesquisa.

## 1. Itens priorizados

| Prioridade | Item | Relação com o docx | Dependência |
| --- | --- | --- | --- |
| 1 | Corpus rotulado e avaliação de OCR e classificação (CER/WER, acurácia, decisões) | 3.5.1 | autorização de PDFs, gabarito |
| 2 | Calibração de limiares 85/40/0,85 e penalidades de qualidade | 3.4 Fase 2 | item 1 |
| 3 | Política de correção pela IA com grounding e `CONFIRMED` | 2.7.2 | item 1 |
| 4 | Comparar modelos locais (tamanho, quantização) e alternativas ao PaddleOCR | 3.3.3 | item 1 |
| 5 | Jobs dedicados `ProcessDocumentOcr` e `GenerateDocumentEmbeddings`; tornar "Reanalisar" assíncrono | 3.3.2 etapa 2 e 3 | fila |
| 6 | Embeddings versionados com `EmbeddingMetadata` e indexação vetorial (pgvector no Compose) | 3.3.2 etapa 3 | item 5 |
| 7 | Recuperação, geração com citação de fonte e IA principal via provedor de texto | 3.3.2 etapas 5 e 6; Fase 2 | item 6 |
| 8 | SSE para progresso de jobs e streaming de resposta | 3.3.2 etapa 6 | item 7 |
| 9 | Módulo de interações e gravidade | 3.3.2 etapa 7; Fase 3 | itens 6 e 7 |
| 10 | Avaliação técnica (20 pares) e usabilidade (SUS) | 3.5.1 e 3.5.2; Fase 5 | itens 7 a 9 |

## 2. Observação de alinhamento com o docx

O docx planeja Python, LangChain e ChromaDB (3.3.3). O repositório adota Laravel e PostgreSQL com pgvector, já presente no Compose, e provedores desacoplados próprios. Essa divergência deve ser justificada na redação final como decisão de engenharia, conforme `00-contexto-e-alinhamento-com-o-projeto.md`. Não há ainda interface mobile (Fase 4 do docx); existe PWA administrativo.

## Evidências no repositório

- `docs/technical-foundation.md`, `docs/realtime-options.md`, `compose.yaml` (imagem pgvector), `app/Data/Ai/EmbeddingMetadata.php`

## Resultados observados

Não se aplica (planejamento).

## Lacunas / a verificar

- Reconciliar o cronograma do docx (Cap. 4) com o estado atual: RAG, interações e avaliação ainda não iniciados no código.
