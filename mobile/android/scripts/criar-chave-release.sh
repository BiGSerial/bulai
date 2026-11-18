#!/usr/bin/env bash
# Cria (uma única vez) a chave que assina a versão de publicação e o keystore.properties que o Gradle lê.
# GUARDE o arquivo .jks e as senhas fora do repositório (cofre de senhas): sem eles não é possível publicar atualizações
# do aplicativo — o Android recusa um APK novo assinado com outra chave. Os dois ficam fora do Git (.gitignore).
set -euo pipefail

ANDROID_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CHAVE="$ANDROID_DIR/bulai-release.jks"
cd "$ANDROID_DIR"

if [ -f "$CHAVE" ] || [ -f keystore.properties ]; then
    echo "Já existe $CHAVE ou keystore.properties; nada foi alterado." >&2
    exit 1
fi
if [ -z "${JAVA_HOME:-}" ] && [ -x "$HOME/.local/share/bulai-jdk/bin/java" ]; then
    export JAVA_HOME="$HOME/.local/share/bulai-jdk"
fi
KEYTOOL="${JAVA_HOME:+$JAVA_HOME/bin/}keytool"

read -r -s -p "Senha da chave (mínimo 8 caracteres): " SENHA
echo
read -r -s -p "Repita a senha: " CONFIRMA
echo
if [ "$SENHA" != "$CONFIRMA" ] || [ "${#SENHA}" -lt 8 ]; then
    echo "As senhas não conferem ou são curtas demais." >&2
    exit 1
fi

"$KEYTOOL" -genkeypair -v -keystore "$CHAVE" -alias bulai -keyalg RSA -keysize 4096 -validity 10000 \
    -storepass "$SENHA" -keypass "$SENHA" -dname "CN=BULA-IA, O=BULA-IA, C=BR"

umask 077
cat > keystore.properties <<PROPS
storeFile=bulai-release.jks
storePassword=$SENHA
keyAlias=bulai
keyPassword=$SENHA
PROPS

echo
echo "Chave criada em $CHAVE e keystore.properties gravado."
echo "Impressão digital SHA-256 (para App Links / assetlinks.json no futuro):"
"$KEYTOOL" -list -v -keystore "$CHAVE" -alias bulai -storepass "$SENHA" | grep -m1 'SHA256'
