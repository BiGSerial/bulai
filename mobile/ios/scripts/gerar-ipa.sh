#!/usr/bin/env bash
# Gera um IPA assinado em dist/ (arquivo .xcarchive + exportação). Requer macOS, Xcode e uma conta Apple no Xcode.
#
#   BULAI_TEAM=ABCDE12345 scripts/gerar-ipa.sh                 # instalação em aparelhos registrados (debugging)
#   BULAI_TEAM=ABCDE12345 scripts/gerar-ipa.sh release-testing # Ad Hoc (conta paga, aparelhos registrados)
#   BULAI_TEAM=ABCDE12345 scripts/gerar-ipa.sh app-store-connect  # TestFlight/App Store (conta paga; não envia nada)
#
# A equipe e o identificador também podem vir de Config/Local.xcconfig. Nenhum certificado fica no repositório:
# a assinatura automática do Xcode usa o que está no Keychain do Mac.
set -euo pipefail
cd "$(dirname "$0")/.."
command -v xcodebuild >/dev/null || { echo "Precisa do Xcode (macOS)."; exit 1; }
METODO="${1:-debugging}"
VERSAO=$(grep -E '^MARKETING_VERSION' Config/Base.xcconfig | awk '{print $3}')
mkdir -p dist build
AJUSTES=(-allowProvisioningUpdates)
[ -n "${BULAI_TEAM:-}" ] && AJUSTES+=("DEVELOPMENT_TEAM=$BULAI_TEAM")

xcodebuild -project BulaIA.xcodeproj -scheme BulaIA -configuration Release -destination "generic/platform=iOS" \
    -archivePath build/BulaIA.xcarchive "${AJUSTES[@]}" archive

OPCOES=build/ExportOptions.plist
cat > "$OPCOES" <<PLIST
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>method</key><string>$METODO</string>
    <key>signingStyle</key><string>automatic</string>
    ${BULAI_TEAM:+<key>teamID</key><string>$BULAI_TEAM</string>}
    <key>destination</key><string>export</string>
</dict>
</plist>
PLIST

xcodebuild -exportArchive -archivePath build/BulaIA.xcarchive -exportOptionsPlist "$OPCOES" -exportPath build/exportado -allowProvisioningUpdates
mv build/exportado/*.ipa "dist/bulai-$VERSAO-$METODO.ipa"
echo "IPA: dist/bulai-$VERSAO-$METODO.ipa"
