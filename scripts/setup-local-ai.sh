#!/usr/bin/env bash
# Bootstrap do classificador local: instala o modelo, sobe o container, aguarda READY e faz uma requisição de teste.
# Uso: ./scripts/setup-local-ai.sh [--force]
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"
[ -f .env ] && { set -a; . ./.env; set +a; }
PORT="${LOCAL_AI_HOST_PORT:-8088}"
WAIT_SECONDS="${LOCAL_AI_SETUP_WAIT_SECONDS:-600}"

for cmd in docker curl; do
    command -v "$cmd" >/dev/null || { echo "Dependência ausente: $cmd" >&2; exit 2; }
done
docker compose version >/dev/null 2>&1 || { echo "docker compose é necessário." >&2; exit 2; }

./scripts/install-local-model.sh "$@"
docker compose up -d --build local-ai

echo "Aguardando o modelo carregar (até ${WAIT_SECONDS}s)..."
deadline=$((SECONDS + WAIT_SECONDS))
while :; do
    status="$(curl -fsS "http://127.0.0.1:$PORT/status" 2>/dev/null | sed -n 's/.*"status": *"\([A-Z_]*\)".*/\1/p' || true)"
    [ "$status" = "READY" ] && break
    [ "$SECONDS" -ge "$deadline" ] && { echo "Timeout; último estado: ${status:-indisponível}" >&2; exit 1; }
    sleep 5
done

echo "Serviço READY. Requisição de teste:"
curl -fsS "http://127.0.0.1:$PORT/v1/chat/completions" -H 'Content-Type: application/json' \
    -d '{"model":"local","max_tokens":20,"temperature":0,"messages":[{"role":"user","content":"Responda apenas: ok"}]}'
echo
