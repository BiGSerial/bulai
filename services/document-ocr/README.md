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

- `OCR_MAX_CONCURRENCY` (padrão `1`): quantos documentos são processados ao mesmo tempo; o OCR roda fora do *event loop*, então `/health` responde durante um documento longo.
- Build de produção: `docker build --build-arg PREFETCH_MODELS=1 …` baixa os modelos do PaddleOCR na construção da imagem (o container não precisa de internet em execução). Em desenvolvimento (padrão `0`) eles são baixados no primeiro uso.

## Modelos, oneDNN e página-limite

- O oneDNN do Paddle 3.x quebra na CPU (`ConvertPirAttribute2RuntimeAttribute not support …`): `OCR_ENABLE_MKLDNN=0` (padrão) o desliga. Os modelos `medium` levam ~1 min por página sem oneDNN; o padrão agora são os leves `PP-OCRv5_mobile_det` + `latin_PP-OCRv5_mobile_rec` (`OCR_DET_MODEL`, `OCR_REC_MODEL`; vazios = o Paddle escolhe pelo idioma), 150 dpi (`OCR_RENDER_DPI`), `OCR_CPU_THREADS=2`.
- `python -m app.warmup` baixa esses modelos (usado no build de produção com `PREFETCH_MODELS=1`).
- `POST /v1/documents/parse` aceita o campo de formulário `page_limit` (1..`OCR_MAX_PAGES`): processa só as N primeiras páginas (usado para complementar capas).

## Ocupação, reinício e watchdog

Sem Docker socket: o reinício acontece dentro do processo e o Docker (`restart: unless-stopped`) religa o container quando ele sai.

- `GET /status` (sem autenticação): `{"status":"ok","busy":bool,"active":int,"queued":int,"current_seconds":float|null,"since":"<ISO8601 UTC>"|null,"completed":int,"failed":int,"uptime_seconds":float}`. `active` = documentos em processamento; `queued` = aguardando o limite `OCR_MAX_CONCURRENCY`; `current_seconds`/`since` = o documento mais antigo em andamento. Contadores em memória (zeram quando o processo reinicia). Falha = erro do engine (422/500).
- `POST /admin/restart` exige `Authorization: Bearer <OCR_API_TOKEN>` (401 se errado; **404 se o serviço não tem token configurado**). Responde `202 {"status":"restarting"}` e ~1 s depois encerra o processo com `os._exit(0)`.
- Watchdog: `OCR_REQUEST_TIMEOUT` (segundos, padrão `900`; `0` desativa). Uma thread verifica a cada 5 s o documento mais antigo em andamento; se exceder o limite, registra `ocr_watchdog_timeout` e encerra o processo com código `3`.
- Testes: `create_app(..., exit_fn=..., restart_delay=..., watchdog_interval=..., clock=...)` permitem injetar a saída e o relógio.
- Limitação: se o cliente cancelar a requisição, o documento deixa de contar como ativo mesmo que a thread do engine ainda esteja rodando.
