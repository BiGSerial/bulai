#!/usr/bin/env bash
# Gera o APK do BULA-IA.
#   scripts/gerar-apk.sh                 → debug apontando para produção (https://bulai.vixforge.com.br)
#   scripts/gerar-apk.sh debug http://10.0.2.2:8000   → debug para o `php artisan serve` (emulador)
#   scripts/gerar-apk.sh release         → versão de publicação, assinada com keystore.properties
# O APK final é copiado para mobile/android/dist/.
set -euo pipefail

ANDROID_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TIPO="${1:-debug}"
URL="${2:-}"
cd "$ANDROID_DIR"

if [ ! -f local.properties ]; then
    echo "Ambiente ainda não preparado: rode scripts/preparar-ambiente.sh" >&2
    exit 1
fi
if [ -z "${JAVA_HOME:-}" ] && [ -x "$HOME/.local/share/bulai-jdk/bin/java" ]; then
    export JAVA_HOME="$HOME/.local/share/bulai-jdk"
fi

ARGS=(--console=plain)
[ -n "$URL" ] && ARGS+=("-PbulaiBaseUrl=$URL")

case "$TIPO" in
    debug)
        ./gradlew "${ARGS[@]}" testDebugUnitTest assembleDebug
        ORIGEM="app/build/outputs/apk/debug/app-debug.apk"
        ;;
    release)
        if [ ! -f keystore.properties ]; then
            echo "Sem keystore.properties: rode scripts/criar-chave-release.sh (uma vez) antes de gerar a versão de publicação." >&2
            exit 1
        fi
        ./gradlew "${ARGS[@]}" testReleaseUnitTest assembleRelease
        ORIGEM="app/build/outputs/apk/release/app-release.apk"
        ;;
    *)
        echo "Uso: $0 [debug|release] [url-do-bulai]" >&2
        exit 1
        ;;
esac

VERSAO="$(git -c versionsort.suffix=- tag --list 'android-v*' --sort=-v:refname | head -1 | sed 's/^android-v//')"
VERSAO="${VERSAO:-1.0.0}"
mkdir -p dist
DESTINO="dist/bulai-${VERSAO}-${TIPO}.apk"
cp "$ORIGEM" "$DESTINO"
echo
echo "APK pronto: $ANDROID_DIR/$DESTINO"
echo "Instalar num celular ligado por USB (depuração ativada): \$ANDROID_HOME/platform-tools/adb install -r $DESTINO"
