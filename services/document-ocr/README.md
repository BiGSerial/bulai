# document-ocr

Serviço HTTP interno de OCR/document parsing do BULA-IA. CPU-only por padrão.

- `POST /v1/documents/parse` — multipart `file` (PDF). Retorna `engine`, `engine_version`, `pages[]` (`page`, `text`, `confidence`, `blocks[]` com `type`, `text`, `confidence`, `bbox`) e `processing.duration_ms`. Campos que o engine não fornece vêm `null`.
- `GET /health`.
- Autenticação opcional: `OCR_API_TOKEN` exige `Authorization: Bearer`.
- Limites: `OCR_MAX_PDF_BYTES`, `OCR_MAX_PAGES`, `OCR_RENDER_DPI`, `OCR_LANGUAGE`.

O contrato HTTP não depende do engine: implemente `app.engine.DocumentOcrEngine` e registre-o em `build_engine` (`OCR_ENGINE`).

```bash
python -m venv .venv && .venv/bin/pip install -e ".[test]" && .venv/bin/pytest
docker compose up -d --build document-ocr
```

GPU é opcional e não é suportada pela imagem padrão; para usá-la, troque `paddlepaddle` por `paddlepaddle-gpu` em uma imagem derivada com NVIDIA Container Toolkit.

## Produção

- `OCR_MAX_CONCURRENCY` (padrão `1`): quantos documentos entram ao mesmo tempo. Com `OCR_EXECUTOR=process` (padrão) o engine é sequencial de qualquer forma: valores maiores só mudam quantos pedidos esperam dentro do serviço em vez de na fila do PHP.
- `OCR_EXECUTOR` (padrão `process`): onde o engine roda. Ver "Processo filho" abaixo. `inline` mantém o engine no processo web (só para depuração/testes; `/status` fica lento durante um documento).
- Build de produção: `docker build --build-arg PREFETCH_MODELS=1 …` baixa os modelos do PaddleOCR na construção da imagem (o container não precisa de internet em execução). Em desenvolvimento (padrão `0`) eles são baixados no primeiro uso.

## Modelos, oneDNN e página-limite

- O oneDNN do Paddle 3.x quebra na CPU (`ConvertPirAttribute2RuntimeAttribute not support …`): `OCR_ENABLE_MKLDNN=0` (padrão) o desliga. Os modelos `medium` levam ~1 min por página sem oneDNN; o padrão agora são os leves `PP-OCRv5_mobile_det` + `latin_PP-OCRv5_mobile_rec` (`OCR_DET_MODEL`, `OCR_REC_MODEL`; vazios = o Paddle escolhe pelo idioma), 150 dpi (`OCR_RENDER_DPI`), `OCR_CPU_THREADS=2`.
- `python -m app.warmup` baixa esses modelos (usado no build de produção com `PREFETCH_MODELS=1`).
- `POST /v1/documents/parse` aceita o campo de formulário `page_limit` (1..`OCR_MAX_PAGES`): processa só as N primeiras páginas (usado para complementar capas).

## Processo filho e progresso por página

O PaddleOCR segura o GIL enquanto reconhece uma página. No mesmo processo do servidor web, uma resposta trivial de `/status` chegava a 1,5 s (mediana) e 4,4 s (pico) durante um documento de 11 páginas, e o painel do admin marcava o serviço como inacessível. Por isso o engine vive num **processo filho persistente** (`app/worker.py`, `multiprocessing` com contexto `spawn`):

- O filho é criado no arranque (`lifespan`), carrega o PaddleOCR uma vez (`engine.warmup()`) e fica à espera de pedidos numa fila. O pai só envia `(caminho do PDF, max_pages, page_limit)` e recebe, pela fila de resultados, uma mensagem por página concluída e o resultado final (ou o erro).
- `/status` e `/health` leem apenas memória do processo pai: nunca esperam o engine.
- Se o filho morrer no meio de um documento (segfault, OOM), o pedido em curso recebe **504** `ocr worker interrupted`, `failed` é incrementado e o **próximo pedido cria um filho novo** sem reiniciar o container. `status.worker` mostra `{"alive": bool, "restarts": int}`.
- O watchdog (`OCR_REQUEST_TIMEOUT`) passa a **matar só o filho** quando o documento mais antigo excede o limite; o servidor continua de pé e o pedido recebe 504. Em `OCR_EXECUTOR=inline` o comportamento antigo (sair com código 3) é mantido.
- Engines de teste: `OCR_ENGINE=pacote.modulo:Classe` constrói qualquer `DocumentOcrEngine` sem argumentos dentro do filho (ver `tests/fake_engine.py`). Um `engine` injetado em `create_app(..., engine=...)` roda sempre em processo (`InlineExecutor`), como antes.

## Ocupação, reinício e watchdog

Sem Docker socket: o reinício acontece dentro do processo e o Docker (`restart: unless-stopped`) religa o container quando ele sai.

- `GET /status` (sem autenticação): `{"status":"ok","busy":bool,"active":int,"queued":int,"current_seconds":float|null,"since":"<ISO8601 UTC>"|null,"completed":int,"failed":int,"uptime_seconds":float,"current":{...}|null,"worker":{"alive":bool,"restarts":int}}`. `active` = documentos em processamento; `queued` = aguardando o limite `OCR_MAX_CONCURRENCY`; `current_seconds`/`since` = o documento mais antigo em andamento. Contadores em memória (zeram quando o processo reinicia). Falha = erro do engine (422/500) ou filho morto (504).
- `current` (progresso do documento mais antigo em andamento; `null` quando ocioso): `{"correlation_id": "<X-Correlation-Id do pedido, ou UUID gerado>", "page": <última página concluída, 0 antes da primeira>, "total_pages": <int ou null até o PDF ser aberto>, "started_at": "<ISO8601 UTC>", "elapsed_seconds": float}`. O engine reporta `(0, total)` ao abrir o PDF e `(n, total)` após cada página; o cliente casa `correlation_id` com o documento que está processando.
- `POST /admin/restart` exige `Authorization: Bearer <OCR_API_TOKEN>` (401 se errado; **404 se o serviço não tem token configurado**). Responde `202 {"status":"restarting"}` e ~1 s depois encerra o processo com `os._exit(0)`.
- Watchdog: `OCR_REQUEST_TIMEOUT` (segundos, padrão `900`; `0` desativa). Uma thread verifica a cada 5 s o documento mais antigo em andamento; se exceder o limite, registra `ocr_watchdog_timeout` e mata o processo filho (modo `process`, pedido recebe 504) ou encerra o processo com código `3` (modo `inline`).
- Testes: `create_app(..., exit_fn=..., restart_delay=..., watchdog_interval=..., clock=..., executor_mode=...)` permitem injetar a saída, o relógio e o modo de execução.
- Limitações: se o cliente cancelar a requisição, o documento deixa de contar como ativo mesmo que o filho ainda esteja processando (o próximo pedido espera na fila até ele terminar). O primeiro pedido logo após um arranque ou reinício do filho espera o carregamento dos modelos (`OCR_WORKER_BOOT_TIMEOUT` não existe: o limite é 600 s fixos em `ProcessExecutor`).
