#!/usr/bin/env bash
# Baixa e instala o modelo GGUF de EMBEDDINGS do RAG vetorial (nunca versionado no Git).
# Uso: ./scripts/install-embedding-model.sh [--force]
# Exit codes: 0 ok · 1 erro genérico/download · 2 uso/configuração inválida · 3 checksum inválido
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
[ -f "$ROOT/.env" ] && { set -a; . "$ROOT/.env"; set +a; }

# Modelo padrão: BAAI bge-m3 (MIT), multilíngue (português incluído), 1024 dimensões, contexto 8192, quantização
# Q8_0 (~635 MB, ~0,8 GB de RAM em CPU). Servido pelo llamafiler (serviço `embeddings`) na API /v1/embeddings.
MODEL_NAME="${EMBEDDINGS_MODEL_NAME:-bge-m3}"
MODEL_URL="${EMBEDDINGS_MODEL_URL:-https://huggingface.co/gpustack/bge-m3-GGUF/resolve/main/bge-m3-Q8_0.gguf}"
MODEL_FILENAME="${EMBEDDINGS_MODEL_FILENAME:-bge-m3-Q8_0.gguf}"
MODEL_SHA256="${EMBEDDINGS_MODEL_SHA256:-950f4a8e5e19477a6d3c26d2f162233c20002c601f75e4b002e3239997821167}"
MODEL_QUANTIZATION="${EMBEDDINGS_MODEL_QUANTIZATION:-Q8_0}"
MODEL_DIR="${EMBEDDINGS_MODEL_DIR:-${LOCAL_AI_MODEL_DIR:-$ROOT/services/local-ai/models}}"
MAX_ATTEMPTS="${EMBEDDINGS_DOWNLOAD_ATTEMPTS:-3}"
MANIFEST="$MODEL_DIR/embedding-model.json"

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
    echo "EMBEDDINGS_MODEL_URL deve usar https." >&2
    exit 2
fi
[[ "$MODEL_FILENAME" =~ ^[A-Za-z0-9._-]+\.gguf$ ]] || { echo "EMBEDDINGS_MODEL_FILENAME inválido." >&2; exit 2; }
[[ "$MODEL_SHA256" =~ ^[0-9a-fA-F]{64}$ ]] || { echo "EMBEDDINGS_MODEL_SHA256 deve ter 64 hex." >&2; exit 2; }
command -v curl >/dev/null || { echo "curl é necessário." >&2; exit 2; }
command -v sha256sum >/dev/null || { echo "sha256sum é necessário." >&2; exit 2; }

TARGET="$MODEL_DIR/$MODEL_FILENAME"
PART="$TARGET.part"
mkdir -p "$MODEL_DIR"

sha_of() { sha256sum "$1" | cut -d' ' -f1; }

# Fonte registrada sem query string (nunca grava tokens).
write_manifest() {
    printf '{\n  "model": "%s",\n  "filename": "%s",\n  "source": "%s",\n  "sha256": "%s",\n  "quantization": "%s",\n  "dimensions": 1024,\n  "installed_at": "%s"\n}\n' \
        "$MODEL_NAME" "$MODEL_FILENAME" "${MODEL_URL%%\?*}" "$1" "$MODEL_QUANTIZATION" "$(date -u +%Y-%m-%dT%H:%M:%SZ)" > "$MANIFEST"
}

if [ -f "$TARGET" ] && [ "$FORCE" -eq 0 ]; then
    actual_sha="$(sha_of "$TARGET")"
    if [ "$(echo "$actual_sha" | tr 'A-F' 'a-f')" = "$(echo "$MODEL_SHA256" | tr 'A-F' 'a-f')" ]; then
        [ -f "$MANIFEST" ] || write_manifest "$actual_sha"
        echo "Modelo de embeddings já instalado e íntegro: $TARGET"
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
    if curl --fail --location --proto "$PROTO" --proto-redir "$PROTO" --connect-timeout 20 --max-time 3600 \
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

actual_sha="$(sha_of "$PART")"
if [ "$(echo "$actual_sha" | tr 'A-F' 'a-f')" != "$(echo "$MODEL_SHA256" | tr 'A-F' 'a-f')" ]; then
    echo "SHA-256 inválido (esperado $MODEL_SHA256, obtido $actual_sha). Arquivo descartado." >&2
    exit 3
fi

mv -f "$PART" "$TARGET"
trap - EXIT
write_manifest "$actual_sha"

echo "Modelo de embeddings instalado: $TARGET"
