# Nota 27 — Observabilidade do pipeline de ingestão, progresso em tempo real e retaguarda do classificador

Data: 06/11/2026 · Branch `feat/experiencia-nativa` (onda 7, mesma PR #33) · Seções do TCC: 3.3.2 (pipeline de ingestão), 3.5.2 (observabilidade e saúde de serviços; complementa a nota 13), 3.5.3 (tempo real; complementa a nota 14), 2.4/3.3.3 (classificação híbrida; complementa as notas 08, 10 e 11).

## 1. Problema observado

O administrador pediu "uma visão direta do que acontece nos containers da IA, do OCR e dos embeddings, com progresso em tempo real", e relatou que o indicador de saúde "fica off com frequência" justamente quando o OCR ou a IA local estão em uso. Antes de projetar, o comportamento foi medido no ambiente de desenvolvimento (containers de `compose.yaml`, PDF de 11 páginas):

| Medição | Antes |
| --- | --- |
| OCR do PDF (PaddleOCR, CPU, 2 vCPU) | 108 s (≈ 10 s por página) |
| Resposta de `/status` do OCR enquanto processa | mediana 1,5 s, p90 3,9 s, pico 4,4 s (par de pedidos feito pelo painel) |
| Limite da sondagem do painel | 3 s por pedido; resultado em cache por 5 s |
| `/status` da IA local durante uma geração | ≈ 1 ms (o supervisor em Python é multi-thread) |
| Imagens em execução no ambiente de desenvolvimento | OCR de 18/10 (sem `/status`, respondia 404) e IA local de 15/10 (sem contadores de ocupação) |

Diagnóstico: (i) o PaddleOCR segura o interpretador Python no mesmo processo do servidor web (`run_in_threadpool` não libera o GIL o bastante), então qualquer resposta HTTP do container fica lenta durante o OCR; (ii) a sondagem corria dentro da renderização da página e do laço SSE, com 3 s de limite, e tratava "demorou" como "fora do ar"; (iii) o documento em processamento só tinha dois estados visíveis (`PROCESSING` → `READY`/`FAILED`): extração nativa, OCR, chunking, embeddings e vínculo com o princípio ativo aconteciam só no log de arquivo.

## 2. Decisões

### 2.1 OCR em processo filho persistente, com progresso por página (`services/document-ocr`)

- O motor roda em um processo filho de longa duração (`multiprocessing`, contexto `spawn`), iniciado no `lifespan`, que carrega os modelos uma vez e recebe pedidos por fila (`app/worker.py`, `ProcessExecutor`). O processo web só lê memória própria em `/status` e `/health`.
- O laço de páginas (`engines/paddle.py`) reporta `(página, total)` após cada página; `ActivityTracker.snapshot()` expõe `current = {correlation_id, page, total_pages, started_at, elapsed_seconds}` ou `null`. O `correlation_id` é o mesmo que o cliente PHP envia em `X-Correlation-Id`, o que permite casar a página atual com o documento em processamento.
- Filho morto ou travado: o pedido em curso recebe 504 e o próximo pedido recria o filho; o watchdog mata só o filho em vez de derrubar o servidor. `OCR_EXECUTOR=inline` mantém o motor no processo (testes e depuração).
- Aceitação medida após o rebuild: `/status` durante o OCR com p50 16 ms, p90 18 ms, p100 27 ms em 115 amostras; `current.page` avançou de 0 a 10 de 11 e voltou a `null` ao fim. O tempo total do OCR não mudou de forma relevante (121 s, variação de CPU).

### 2.2 Sondagem de saúde em segundo plano e "ocupado ≠ fora do ar" (`app/Services/Health`)

- Comando `services:probe-health` agendado a cada 5 s (`everyFiveSeconds()->onOneServer()->withoutOverlapping()`, executado pelo container `scheduler`) sonda os três serviços e grava no cache com `observed_at`. Painel, Fila e SSE leem o cache e nunca bloqueiam; se a leitura tiver mais de 15 s (scheduler parado), a sondagem inline de antes é usada.
- Falhas consecutivas são contadas por serviço. Até duas falhas: estado `UNRESPONSIVE` (tom de aviso), rótulo "Ocupado, sem resposta há N s" quando a última leitura boa estava ocupada, preservando modelo, ocupação e progresso. Três falhas seguidas: `UNREACHABLE`, como antes.
- `ServiceHealth` ganha `progress` (`{page, total}`), `correlationId`, `observedAt`, `respondedAt`, `failures` e `hint()` ("Página 7 de 12 · 65 s", "Processando há N s", "Lido há N s").

### 2.3 Linha do tempo do processamento (`knowledge_processing_events`)

- Tabela com uma linha por etapa e execução: `step` (`read_native`, `ocr`, `normalize`, `chunk`, `embed`, `link`), `state` (`running|done|failed|skipped`), início, fim, duração e `detail` (JSON com qualidade, páginas, caracteres, trechos, lote atual, modelo, `correlation_id` do OCR, código de erro).
- `ProcessingTimeline` (`start/progress/finish/fail/skip/reset/for/active`) nunca lança: falha ao gravar vira `warning` no log e o processamento segue. Cada nova execução apaga a anterior (`reset`).
- Emissão no `KnowledgeDocumentProcessor`, em `DocumentTextExtractionService::extract()` (marcos `native_done`, `ocr_started`, `ocr_done`, `ocr_failed`, com o `correlation_id`), no `KnowledgeIndexer::index(documentId:)` (progresso por lote de embeddings) e no job `ProcessKnowledgeDocument` (vínculo com o princípio ativo).
- Página do documento: seção "Linha do tempo do processamento" (uma linha por etapa, estado, duração, resumo em português), `wire:poll.3s` enquanto houver etapa em andamento e atualização pelo evento `bulai-realtime`.
- Fila e serviços: seção "Documentos em processamento" com etapa atual, resumo e barra de progresso; o passo `ocr` em andamento é casado com `current.correlation_id` do `/status` do OCR para mostrar "página X de Y". O evento SSE `changed` passa a carregar `documents` e, por serviço, `progress` e `hint`.

### 2.4 Claude como retaguarda do classificador local (`app/Services/Ai`)

- Novo driver `anthropic` na capacidade `classifier` (`AnthropicDocumentClassificationProvider`): mesmo prompt de sistema e mesmo payload do provedor local (candidatos e trechos curtos, nunca o PDF), saída forçada por JSON Schema (`output_config.format`), validação estrita local (`DocumentClassificationResponse::fromModelOutput`), uso e custo registrados em `ai_usage_logs` com os orçamentos diários/mensais já existentes.
- Encadeamento (`FallbackDocumentClassificationProvider`), ativado por `DOCUMENT_CLASSIFIER_FALLBACK_DRIVER=anthropic`: o Claude só é chamado quando o provedor local lança exceção (indisponível, timeout, resposta inválida), responde com confiança abaixo de `ai_min_confidence` ou não resolve o princípio ativo quando ele está em aberto. O motivo fica em `metadata.fallback` e aparece na revisão ("A IA local não conseguiu (motivo); a resposta veio do Claude").
- Invariante preservada (notas 08 e 11): a resposta do Claude passa pelas mesmas checagens de respaldo no texto, conflito com a heurística e confirmação regulatória; ela nunca promove uma bula sozinha (teste: resposta do Claude sem confirmação do catálogo vira `AI_SUGGESTED`).

## 3. Mapeamento para o texto do TCC

- **3.3.2 Pipeline de ingestão**: a linha do tempo materializa as etapas que o texto já descreve (extração nativa → OCR condicional → normalização → seções/chunks → embeddings → vínculo), agora auditáveis por documento e por execução.
- **3.5.2 Observabilidade**: separar "vivo" de "ocupado" e tirar a sondagem do caminho da requisição são decisões de engenharia defensáveis com as medições da seção 1; a tabela "antes/depois" do OCR é evidência quantitativa.
- **3.5.3 Tempo real**: mantém a escolha SSE + polling de reserva (nota 14); o que muda é o conteúdo do evento (documentos, progresso, dica), não o transporte.
- **Classificação híbrida**: a retaguarda em nuvem é a terceira camada (heurística → IA local → Claude), acionada por critérios explícitos e com custo controlado; reforça o argumento de "IA local por padrão, nuvem só quando necessário".

## 4. Evidências

| Evidência | Onde |
| --- | --- |
| OCR em processo filho, `current`, 504 sem derrubar o servidor, watchdog do filho | `services/document-ocr/tests/test_api.py` (23 testes) |
| Estados `UNRESPONSIVE`/`UNREACHABLE`, cache, sondagem inline, `progress`/`hint`, comando agendado | `tests/Feature/Admin/ServiceHealthTest.php` (9) |
| Linha do tempo por etapa, OCR com `correlation_id`, falha, reset, `active()`, tela | `tests/Feature/Knowledge/KnowledgeProcessingTimelineTest.php` (6) |
| Fila com documentos em processamento e página do OCR casada; SSE com `documents` e `hint` | `tests/Feature/Admin/QueueStatusTest.php` e teste do stream |
| Retaguarda: requisição e uso registrado, resposta fora do contrato, três gatilhos, falha dupla, revisão, timeout do job | `tests/Feature/Ai/AnthropicClassificationFallbackTest.php` (5), `KnowledgeDocumentClassificationTest` |

## 5. Limitações e ameaças à validade

- Nenhuma chamada real à API da Anthropic foi feita (só `Http::fake`); o formato de saída estruturada segue a documentação da versão `2026-10-21` e precisa de um teste manual com chave real antes de ativar em produção.
- A página atual do OCR não chega ao worker PHP (a chamada é síncrona): vem do `/status` do serviço, por isso depende do `scheduler` ativo e tem atraso de até 5 s. A IA local e os embeddings não têm progresso interno a uma chamada; só "processando há N s" e lotes.
- As medições da seção 1 são de um único PDF em um único ambiente; servem para justificar a decisão, não como benchmark.
- O rebuild da imagem do OCR é obrigatório para o progresso por página; imagens anteriores continuam funcionando (campo `current` ausente → sem página, sem erro).

## 6. Números da suíte

| Momento | Resultado |
| --- | --- |
| Antes da onda 7 (commit `70c6039`) | 578 testes, 577 aprovados, 1 ignorado |
| Depois da onda 7 | 599 testes, 598 aprovados, 1 ignorado (chamada real à Anthropic), 15.762 asserções; mais 23 testes do serviço de OCR em pytest |
