#!/usr/bin/env bash
# Baixa e instala o modelo GGUF do classificador local (nunca versionado no Git).
# Uso: ./scripts/install-local-model.sh [--force]
# Exit codes: 0 ok · 1 erro genérico/download · 2 uso/configuração inválida · 3 checksum inválido
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
[ -f "$ROOT/.env" ] && { set -a; . "$ROOT/.env"; set +a; }

# Modelo padrão: Qwen2.5-1.5B-Instruct Q4_K_M (Apache-2.0) — multilíngue (inclui português), segue instruções
# curtas e gera JSON, ~1,1 GB, adequado a CPU com ~3 GB de RAM livres.
MODEL_NAME="${LOCAL_AI_MODEL_NAME:-qwen2.5-1.5b-instruct}"
MODEL_URL="${LOCAL_AI_MODEL_URL:-https://huggingface.co/Qwen/Qwen2.5-1.5B-Instruct-GGUF/resolve/main/qwen2.5-1.5b-instruct-q4_k_m.gguf}"
MODEL_FILENAME="${LOCAL_AI_MODEL_FILENAME:-qwen2.5-1.5b-instruct-q4_k_m.gguf}"
MODEL_SHA256="${LOCAL_AI_MODEL_SHA256:-6a1a2eb6d15622bf3c96857206351ba97e1af16c30d7a74ee38970e434e9407e}"
MODEL_QUANTIZATION="${LOCAL_AI_MODEL_QUANTIZATION:-Q4_K_M}"
MODEL_DIR="${LOCAL_AI_MODEL_DIR:-$ROOT/services/local-ai/models}"
MAX_ATTEMPTS="${LOCAL_AI_DOWNLOAD_ATTEMPTS:-3}"

FORCE=0
for arg in "$@"; do
    case "$arg" in
        --force) FORCE=1 ;;
        -h|--help) sed -n '2,5p' "$0"; exit 0 ;;
        *) echo "Argumento desconhecido: $arg" >&2; exit 2 ;;
    esac
done

# http só é aceito para loopback (espelho local/testes).
PROTO='=https'
if [[ "$MODEL_URL" == http://127.0.0.1* || "$MODEL_URL" == http://localhost* ]]; then
    PROTO='=http,https'
elif [[ "$MODEL_URL" != https://* ]]; then
    echo "LOCAL_AI_MODEL_URL deve usar https." >&2
    exit 2
fi
[[ "$MODEL_FILENAME" =~ ^[A-Za-z0-9._-]+\.gguf$ ]] || { echo "LOCAL_AI_MODEL_FILENAME inválido." >&2; exit 2; }
[[ "$MODEL_SHA256" =~ ^[0-9a-fA-F]{64}$ ]] || { echo "LOCAL_AI_MODEL_SHA256 deve ter 64 hex." >&2; exit 2; }
command -v curl >/dev/null || { echo "curl é necessário." >&2; exit 2; }
command -v sha256sum >/dev/null || { echo "sha256sum é necessário." >&2; exit 2; }

TARGET="$MODEL_DIR/$MODEL_FILENAME"
PART="$TARGET.part"
mkdir -p "$MODEL_DIR"

sha_of() { sha256sum "$1" | cut -d' ' -f1; }

if [ -f "$TARGET" ] && [ "$FORCE" -eq 0 ]; then
    if [ "$(sha_of "$TARGET" | tr 'A-F' 'a-f')" = "$(echo "$MODEL_SHA256" | tr 'A-F' 'a-f')" ]; then
        echo "Modelo já instalado e íntegro: $TARGET"
        exit 0
    fi
    echo "Modelo existente com checksum divergente; use --force para baixar novamente." >&2
    exit 3
fi

cleanup() { rm -f "$PART"; }
trap cleanup EXIT

attempt=1
while :; do
    rm -f "$PART"
    echo "Baixando $MODEL_FILENAME (tentativa $attempt/$MAX_ATTEMPTS)..."
    if curl --fail --location --proto "$PROTO" --proto-redir '=https,http' --connect-timeout 20 --max-time 3600 \
        --progress-bar --output "$PART" "$MODEL_URL"; then
        break
    fi
    if [ "$attempt" -ge "$MAX_ATTEMPTS" ]; then
        echo "Falha no download após $MAX_ATTEMPTS tentativas." >&2
        exit 1
    fi
    attempt=$((attempt + 1))
    sleep $((attempt * 3))
done

# Tamanho esperado, quando o servidor informa (best-effort, via HEAD).
expected_size="$(curl -fsSIL --proto "$PROTO" --connect-timeout 20 "$MODEL_URL" 2>/dev/null | tr -d '\r' | awk 'tolower($1)=="content-length:" {v=$2} END {print v}')"
actual_size="$(stat -c %s "$PART")"
if [[ "$expected_size" =~ ^[0-9]+$ ]] && [ "$expected_size" -gt 1000000 ] && [ "$expected_size" -ne "$actual_size" ]; then
    echo "Tamanho divergente: esperado $expected_size, obtido $actual_size." >&2
    exit 1
fi

actual_sha="$(sha_of "$PART")"
if [ "$(echo "$actual_sha" | tr 'A-F' 'a-f')" != "$(echo "$MODEL_SHA256" | tr 'A-F' 'a-f')" ]; then
    echo "SHA-256 inválido (esperado $MODEL_SHA256, obtido $actual_sha). Arquivo descartado." >&2
    exit 3
fi

mv -f "$PART" "$TARGET"
trap - EXIT

# Fonte registrada sem query string (nunca grava tokens).
cat > "$MODEL_DIR/model.json" <<JSON
{
  "model": "$MODEL_NAME",
  "filename": "$MODEL_FILENAME",
  "source": "${MODEL_URL%%\?*}",
  "sha256": "$actual_sha",
  "quantization": "$MODEL_QUANTIZATION",
  "installed_at": "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
}
JSON

echo "Modelo instalado: $TARGET"
