#!/usr/bin/env bash
# Prepara a máquina (Linux/WSL, sem sudo) para gerar o APK do BULA-IA:
#   - JDK 17+ (usa o do sistema; sem ele, baixa o Temurin 21 para ~/.local/share/bulai-jdk);
#   - Android SDK em $ANDROID_HOME (padrão ~/Android/Sdk): cmdline-tools, platform-tools, plataforma e build-tools;
#   - aceita as licenças do SDK e grava local.properties (sdk.dir).
# Idempotente: rodar de novo só confere e completa o que falta. Uso: mobile/android/scripts/preparar-ambiente.sh
set -euo pipefail

ANDROID_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ANDROID_HOME="${ANDROID_HOME:-$HOME/Android/Sdk}"
CMDLINE_TOOLS_BUILD="${CMDLINE_TOOLS_BUILD:-16111833}"
PLATFORM="${ANDROID_PLATFORM:-platforms;android-37.0}"
BUILD_TOOLS="${ANDROID_BUILD_TOOLS:-build-tools;36.1.0}"
JDK_DIR="$HOME/.local/share/bulai-jdk"

passo() { printf '\n\033[1;34m==> %s\033[0m\n' "$1"; }

java_major() {
    "$1" -version 2>&1 | awk -F'"' '/version/ {split($2, v, "."); print (v[1] == "1" ? v[2] : v[1]); exit}'
}

passo "JDK"
JAVA_BIN=""
if [ -n "${JAVA_HOME:-}" ] && [ -x "$JAVA_HOME/bin/java" ]; then
    JAVA_BIN="$JAVA_HOME/bin/java"
elif command -v java >/dev/null 2>&1; then
    JAVA_BIN="$(command -v java)"
fi
if [ -z "$JAVA_BIN" ] || [ "$(java_major "$JAVA_BIN")" -lt 17 ]; then
    if [ ! -x "$JDK_DIR/bin/java" ]; then
        echo "JDK 17+ não encontrado; baixando Temurin 21 para $JDK_DIR"
        tmp="$(mktemp -d)"
        curl -fsSL -o "$tmp/jdk.tar.gz" "https://api.adoptium.net/v3/binary/latest/21/ga/linux/x64/jdk/hotspot/normal/eclipse"
        mkdir -p "$JDK_DIR"
        tar -xzf "$tmp/jdk.tar.gz" -C "$JDK_DIR" --strip-components=1
        rm -rf "$tmp"
    fi
    export JAVA_HOME="$JDK_DIR"
    JAVA_BIN="$JDK_DIR/bin/java"
fi
echo "Java $(java_major "$JAVA_BIN") em $JAVA_BIN"

passo "Android SDK em $ANDROID_HOME"
SDKMANAGER="$ANDROID_HOME/cmdline-tools/latest/bin/sdkmanager"
if [ ! -x "$SDKMANAGER" ]; then
    tmp="$(mktemp -d)"
    curl -fsSL -o "$tmp/clt.zip" "https://dl.google.com/android/repository/commandlinetools-linux-${CMDLINE_TOOLS_BUILD}_latest.zip"
    unzip -q "$tmp/clt.zip" -d "$tmp"
    mkdir -p "$ANDROID_HOME/cmdline-tools"
    rm -rf "$ANDROID_HOME/cmdline-tools/latest"
    mv "$tmp/cmdline-tools" "$ANDROID_HOME/cmdline-tools/latest"
    rm -rf "$tmp"
fi

yes | "$SDKMANAGER" --sdk_root="$ANDROID_HOME" --licenses >/dev/null 2>&1 || true
"$SDKMANAGER" --sdk_root="$ANDROID_HOME" --install "platform-tools" "$PLATFORM" "$BUILD_TOOLS" 2>&1 | grep -v '^\[' || true

passo "local.properties"
printf 'sdk.dir=%s\n' "$ANDROID_HOME" > "$ANDROID_DIR/local.properties"
cat "$ANDROID_DIR/local.properties"

cat <<EOF

Pronto. Para usar adb e o SDK no terminal, acrescente ao ~/.bashrc:
  export ANDROID_HOME="$ANDROID_HOME"
  export PATH="\$ANDROID_HOME/platform-tools:\$ANDROID_HOME/cmdline-tools/latest/bin:\$PATH"
${JAVA_HOME:+  export JAVA_HOME="$JAVA_HOME"}

Gerar o APK: mobile/android/scripts/gerar-apk.sh debug   (ou release)
EOF
