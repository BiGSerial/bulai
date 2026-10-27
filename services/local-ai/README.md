# local-ai

Supervisor (somente biblioteca padrão) que gerencia o subprocesso do llamafile e expõe, na porta pública `8080`, o estado do modelo e um proxy restrito (`POST /v1/chat/completions`, `GET /v1/models`).

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

Observação: como o timeout de socket do proxy (120 s) é menor que o do watchdog (240 s), o watchdog só age em respostas que ficam pingando sem concluir.

## Testes

```bash
pip install pytest && cd services/local-ai && pytest
```
