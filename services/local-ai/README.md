# local-ai

Supervisor (somente biblioteca padrão) que gerencia o subprocesso do llamafile e expõe, na porta pública `8080`, o estado do modelo e um proxy restrito (`POST /v1/chat/completions`, `GET /v1/models`).

## Modos

- `LOCAL_AI_MODE=chat` (padrão): servidor clássico do llamafile (`--server --nobrowser`), classificador da ingestão.
- `LOCAL_AI_MODE=embedding`: servidor **llamafiler** (`--server --v2 -l host:porta`), usado pelo serviço `embeddings` do compose para o RAG vetorial/híbrido. Proxy restrito a `POST /v1/embeddings` e `GET /v1/models`. Observações descobertas na validação com llamafile 0.9.3:
  - `--embedding` no servidor clássico **não** liga um servidor: dispara a CLI de embeddings (`embedding_cli`) e sai; por isso o modo usa `--v2`.
  - o llamafiler não tem `/health` (o supervisor sonda `GET /v1/models` e faz um `POST /v1/embeddings` de teste) e rejeita `--pooling` (o pooling vem dos metadados do GGUF; bge-m3 = CLS, vetores já normalizados).
  - o llamafiler só aceita `input` **string**; o supervisor adapta o formato OpenAI com `input` em lista (lotes do `knowledge:index-embeddings`) fazendo N chamadas sequenciais e devolvendo um único objeto com `data[].index` e `usage` somados. `model` na resposta é o `LOCAL_AI_MODEL_NAME` (é o nome gravado em cada chunk); o nome interno vai em `upstream_model`.
  - corpo máximo padrão 1 MiB (`LOCAL_AI_MAX_BODY_BYTES`), contexto recomendado 2048 (chunks ≤ 2.600 caracteres).

## Endpoints próprios

- `GET /health`: 200 só quando `READY`, senão 503 (campos `status`, `detail`, `model`, `since`).
- `GET /status` (sem autenticação): `status`, `detail`, `model`, `since` (epoch s) e, de ocupação: `busy` (bool), `active_requests` (int, requisições em voo em `/v1/chat/completions`), `current_seconds` (float|null, a mais antiga), `completed`, `failed` (resposta 5xx ou erro de upstream), `restarts` (reinícios do llamafile: admin, watchdog e quedas), `uptime_seconds`. Contadores em memória.
- `POST /admin/restart`: exige `Authorization: Bearer <LOCAL_AI_ADMIN_TOKEN>`; sem a variável definida responde 404, token errado 401. Reinicia só o subprocesso do llamafile (o container continua): estado `MODEL_LOADING`, `restarts` +1, volta a `READY` quando o modelo carregar. Responde `202 {"status":"restarting"}`. Requisições em voo falham com 504 controlado. A rota não passa pelo proxy (`ALLOWED_PROXY` não muda).

## Variáveis

| Variável | Padrão | Função |
| --- | --- | --- |
| `LOCAL_AI_ADMIN_TOKEN` | vazio | Habilita `/admin/restart`. |
| `LOCAL_AI_HANG_TIMEOUT` | `240` | Watchdog: segundos de uma requisição em voo antes de reiniciar o llamafile (log `local_ai_watchdog_restart`); `0` desativa. Verificação a cada 5 s. |
| `LOCAL_AI_REQUEST_TIMEOUT` | `120` | Timeout de socket do proxy até o llamafile. |
| `LOCAL_AI_MODE` | `chat` | `chat` ou `embedding` (ver "Modos"). |
| `LOCAL_AI_MAX_BODY_BYTES` | `262144` (chat) / `1048576` (embedding) | Tamanho máximo do corpo aceito pelo proxy. |

Observação: como o timeout de socket do proxy (120 s) é menor que o do watchdog (240 s), o watchdog só age em respostas que ficam pingando sem concluir.

## Testes

```bash
pip install pytest && cd services/local-ai && pytest
```
