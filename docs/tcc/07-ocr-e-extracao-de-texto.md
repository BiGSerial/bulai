# Extração de texto, avaliação de qualidade e OCR condicional

Mapeamento no projeto de pesquisa (docx): Cap. 3, seção 3.3.2 (etapa 2, "Pré-processamento: extração de texto dos PDFs") e 3.4 (Fase 2). Complementa `03-ingestao-e-processamento.md`.

## Objetivo do tópico

Registrar a fundamentação e a implementação da camada que converte o PDF da bula em texto confiável, decidindo de forma determinística quando a extração nativa é insuficiente e o reconhecimento óptico de caracteres (OCR) deve ser acionado.

## 1. Fundamentação teórica

### 1.1 Extração de texto de PDF

PDFs podem conter texto codificado (camada textual) ou apenas imagens de páginas digitalizadas. Bibliotecas de parsing recuperam a camada textual, mas podem produzir texto vazio, fragmentado ou com caracteres inválidos quando a codificação de fontes é atípica. [referência a incluir: especificação ISO 32000 / documentação do formato PDF.]

### 1.2 OCR

OCR converte imagens de texto em caracteres, normalmente em etapas de detecção de regiões, reconhecimento e, opcionalmente, análise de layout. Sua acurácia depende de resolução, ruído, idioma e engine. [referência a incluir: survey de OCR / artigo do engine PaddleOCR.]

### 1.3 Porta de qualidade (quality gate)

Em vez de executar OCR sempre (custo de CPU e tempo), adota-se uma avaliação prévia da extração nativa. A decisão é determinística e explicável, sem uso de LLM.

## 2. Decisão de projeto implementada

| Etapa | Descrição | Situação |
| --- | --- | --- |
| Extração nativa | `PdfTextExtractor` (implementação `PrinsFrankPdfTextExtractor`), páginas numeradas a partir de 1 | implementado e testado |
| Avaliação de qualidade | `HeuristicExtractionQualityEvaluator` produz `score` (0 a 1), `is_acceptable`, `reasons` e `metrics` | implementado e testado |
| OCR condicional | `HttpDocumentOcrClient` envia o PDF a `services/document-ocr` somente se a extração for reprovada | implementado; acurácia não medida |
| Escolha do melhor texto | se o texto do OCR pontuar menos que o nativo, mantém-se o nativo (`OCR_WORSE_THAN_TEXT`) | implementado |

### 2.1 Sinais de qualidade e penalidades

O score parte de 1,0 e subtrai penalidades fixas por motivo detectado. `NO_PAGES` e `NO_TEXT` reprovam sempre. O limiar padrão de aceitação é 0,6.

| Motivo | Penalidade | Limite padrão (`config/document_ocr.php`) |
| --- | --- | --- |
| `LOW_CHARACTER_COUNT` | 0,40 | mínimo 400 caracteres |
| `LOW_CHARACTERS_PER_PAGE` | 0,20 | mínimo 150 por página |
| `HIGH_EMPTY_PAGE_RATIO` | 0,30 | máximo 0,5 |
| `EXCESSIVE_LINE_FRAGMENTATION` | 0,25 | máximo 0,6 de linhas com até 3 caracteres |
| `HIGH_INVALID_CHARACTER_RATIO` | 0,40 | máximo 0,05 |
| `LOW_ALPHABETIC_RATIO` | 0,30 | mínimo 0,6 |
| `LOW_WORD_COUNT` | 0,25 | mínimo 60 palavras |

As penalidades e os limites são escolhas iniciais de engenharia, não valores calibrados cientificamente.

### 2.2 Serviço OCR

Serviço Python (FastAPI) em `services/document-ocr`, com `POST /v1/documents/parse` e `GET /health`, token opcional, limites de tamanho, MIME e páginas. O contrato HTTP independe do engine (`DocumentOcrEngine`); o engine inicial é PaddleOCR em CPU (renderização via `pypdfium2`). Em `compose.yaml`: 2 CPUs, 3 GB, porta publicada apenas em 127.0.0.1.

### 2.3 Política de falha

OCR indisponível com texto aproveitável mantém a extração nativa; sem texto aproveitável, o documento falha de forma controlada (`OCR_UNAVAILABLE`) e preserva os chunks anteriores. Códigos de erro: `OCR_DISABLED`, `OCR_NOT_CONFIGURED`, `OCR_INVALID_FILE`, `OCR_PAYLOAD_TOO_LARGE`, `OCR_UNAVAILABLE`, `OCR_TIMEOUT`, `OCR_RATE_LIMITED`, `OCR_REJECTED`, `OCR_INVALID_RESPONSE`. Mensagens nunca contêm URL, token ou texto do documento.

### 2.4 Rastreabilidade

`metadata.processing.extraction` registra método (`pdf_text` ou `ocr`), engine, versão, score antes/depois, motivo do fallback, código de erro, páginas e duração. Isso permite comparar métodos nos experimentos.

## Evidências no repositório

- `app/Services/Knowledge/DocumentTextExtractionService.php`, `HeuristicExtractionQualityEvaluator.php`, `HttpDocumentOcrClient.php`, `DocumentOcrException.php`
- `app/Data/Knowledge/DocumentExtractionResult.php`, `ExtractionQuality.php`
- `config/document_ocr.php`
- `services/document-ocr/` (app, Dockerfile, tests)
- `compose.yaml`; `docs/technical-foundation.md` (seção "OCR Fallback and AI Provider Layer")
- Testes: `tests/Unit/Services/Knowledge/{HeuristicExtractionQualityEvaluatorTest,HttpDocumentOcrClientTest,DocumentTextExtractionServiceTest}.php`, `services/document-ocr/tests/test_api.py`

## Resultados observados

- Testes automatizados cobrem avaliador, cliente HTTP e serviço de extração com engine falso.
- Nenhuma medição de acurácia do PaddleOCR ou de ganho do OCR sobre a extração nativa foi realizada.

## Lacunas / a verificar

- O engine PaddleOCR real não é exercitado por testes automatizados (apenas engine falso).
- Medir taxa de acionamento do OCR, CER/WER em amostra rotulada e tempo por página.
- Calibrar penalidades e limiares com PDFs reais (nativos e digitalizados).
- Não existe job dedicado de OCR; ele roda dentro do processamento e da classificação.
