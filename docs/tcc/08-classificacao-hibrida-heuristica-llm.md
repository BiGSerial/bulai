# Classificação híbrida na ingestão: heurística primeiro, IA local na faixa ambígua

Mapeamento no docx: Cap. 3, seções 3.3 (arquitetura), 3.3.2 (fluxo de processamento, etapas 1 e 2) e 3.4 (Fases 1 e 2). Complementa `03-ingestao-e-processamento.md`.

## Objetivo do tópico

Descrever como o sistema identifica a bula (nome, princípio ativo, tipo paciente/profissional) antes de associá-la ao acervo, combinando regras determinísticas, catálogo regulatório da ANVISA e, apenas quando necessário, um classificador local.

## 1. Fundamentação teórica

### 1.1 Sistemas híbridos e escalonamento de custo

Abordagens em cascata usam métodos baratos e previsíveis primeiro e recorrem a modelos caros ou probabilísticos somente em casos ambíguos. Em domínio sensível, isso reduz custo e superfície de erro. [referência a incluir: trabalhos sobre model cascades / human-in-the-loop.]

### 1.2 LLM como classificador auxiliar

Modelos de linguagem podem extrair campos estruturados de texto semiestruturado, mas podem produzir valores não sustentados pelo texto. [referência a incluir: Huang et al. (2023) sobre alucinação, já presente nas referências do projeto; extração de informação com LLMs.]

## 2. Camadas do pipeline

Separação de responsabilidades adotada:

| Camada | Função | Situação |
| --- | --- | --- |
| 1. Extração | PDF para texto, qualidade, OCR condicional | implementada |
| 2. Classificação de ingestão | heurística, catálogo ANVISA, IA local na faixa ambígua | implementada |
| 3. Recuperação (RAG) | embeddings, busca vetorial | planejada, não implementada |
| 4. IA principal | resposta ao usuário | planejada, não implementada |

O classificador local pertence somente à camada 2: não responde a usuários e não participa do RAG.

## 3. Fluxo de decisão

1. A heurística (`KnowledgeDocumentIdentificationService`, `AnvisaLeafletMetadataExtractor`) gera candidatos, evidências e um score.
2. O catálogo regulatório (`AnvisaRegulatoryCatalog`, importado por `ImportAnvisaMedicines`) valida a identificação.
3. Porta por score (`config/document_classification.php`):

| Condição | Decisão | IA chamada? |
| --- | --- | --- |
| score >= 85 | `ACCEPTED_HEURISTIC` | não |
| 40 <= score < 85 | consulta ao classificador local | sim |
| score < 40 | `MANUAL_REVIEW` | não |

4. Resposta da IA passa por validação estrita e regras de aceitação (ver `11-validacao-estrita-e-grounding.md`). Decisões possíveis: `AI_ACCEPTED`, `AI_SUGGESTED`, `AI_LOW_CONFIDENCE`, `AI_REJECTED`, `AI_UNAVAILABLE`, `AI_DISABLED`, `MANUAL_REVIEW`.
5. Em qualquer decisão diferente de `ACCEPTED_HEURISTIC`/`AI_ACCEPTED` o item permanece pendente para revisão humana.

Os limiares (85, 40 e confiança mínima 0,85) são parâmetros de configuração a calibrar, não constantes científicas.

## 4. Execução assíncrona

O upload identifica apenas com extração textual; para cada PDF pendente é despachado `ClassifyPendingDocument` (após commit), que reutiliza `KnowledgePendingItemReanalysisService::reanalyze` com análise profunda (OCR e classificação). O botão "Reanalisar" do Admin usa o mesmo caminho, porém de forma síncrona na requisição.

## 5. Contrato com o classificador

`DocumentClassificationRequest` envia somente score, candidatos, até 8 trechos de evidência de até 400 caracteres e campos ambíguos; nunca o PDF ou o texto completo. Temperatura 0.

## 6. Consulta sob demanda e decisão humana

- "Consultar IA agora" enfileira `ClassifyPendingDocument(forceAi: true)` (`KnowledgePendingItemReanalysisService::queueItem`), ignorando o limiar de pontuação (faixa 40 a 85). É recusada se o classificador não estiver configurado ou se o item já estiver em fila/processamento. O progresso aparece no polling de 3 s existente.
- Quando a regra automática conservadora descarta ou apenas sugere a resposta da IA, a decisão passa ao administrador por um quadro comparativo (ver `12-auditabilidade-e-explicabilidade.md` e `11-validacao-estrita-e-grounding.md`). A regra automática permanece inalterada para o processamento sem supervisão.
- Situação: implementado e coberto por testes (`KnowledgeAiSuggestionReviewTest`, caso forçado em `KnowledgeDocumentClassificationTest`).

## Evidências no repositório

- `app/Services/Knowledge/KnowledgeDocumentClassificationService.php`, `KnowledgeDocumentIdentificationService.php`, `KnowledgePendingItemReanalysisService.php`, `AnvisaRegulatoryCatalog.php`, `EloquentAnvisaRegulatoryCatalog.php`
- `app/Jobs/ClassifyPendingDocument.php`; `app/Console/Commands/ImportAnvisaMedicines.php`
- `app/Data/Ai/DocumentClassificationRequest.php`, `DocumentClassificationResponse.php`
- `config/document_classification.php`
- Testes: `tests/Feature/Knowledge/{KnowledgeDocumentClassificationTest,KnowledgeBackgroundClassificationTest,AnvisaMedicineCatalogTest}.php`

## Resultados observados

Observação de teste com n pequeno, sem valor estatístico: em 6 PDFs pendentes reais, o modelo local respondeu JSON válido em cerca de 8 a 14 s (CPU), mas as validações rejeitaram todas as respostas (conflito com a heurística, tipo divergente ou nada acrescentado). Em um caso (omeprazol sódico) a IA estava correta e a heurística errada (capturou "10mL de solução diluente"). O caso motivou a decisão humana por campo (seção 6). A correção automática pela IA, mesmo com grounding e confirmação regulatória, continua não implementada e é hipótese a avaliar.

## Lacunas / a verificar

- Sem corpus rotulado, precisão/recall e taxa de acordo heurística x IA x humano não foram medidos.
- Limiares 85/40/0,85 não foram calibrados.
- Verificar se o corpus real do ANVISA importado cobre os 30 medicamentos do MVP citados na seção 3.2.2 do docx.
- Registrar contagem de decisões por tipo em uma amostra maior.
- Sem medição de quanto a consulta forçada (fora da faixa 40 a 85) agrega ou prejudica; sem registro do uso da consulta sob demanda em amostra maior.
