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
