#!/usr/bin/env bash
# Compila o app (e o widget) para o simulador, sem assinatura. Requer macOS com Xcode 16 ou mais novo.
#   scripts/compilar.sh                       # iPhone 16 no simulador
#   BULAI_SIMULADOR="platform=iOS Simulator,name=iPhone 15" scripts/compilar.sh
set -euo pipefail
cd "$(dirname "$0")/.."
command -v xcodebuild >/dev/null || { echo "Precisa do Xcode (macOS)."; exit 1; }
DESTINO="${BULAI_SIMULADOR:-platform=iOS Simulator,name=iPhone 16}"
xcodebuild -project BulaIA.xcodeproj -scheme BulaIA -configuration Debug -destination "$DESTINO" \
    -derivedDataPath build/DerivedData CODE_SIGNING_ALLOWED=NO build
echo "App em build/DerivedData/Build/Products/Debug-iphonesimulator/BulaIA.app"
