#!/usr/bin/env bash
# Testes do BULA-IA iOS.
#   - Núcleo (BulaiCore): `swift test` — roda no macOS e também no Linux (sem UIKit/SwiftUI).
#   - No macOS com Xcode: também compila o app e o widget para o simulador e roda os testes pelo esquema.
set -euo pipefail
cd "$(dirname "$0")/.."

echo "› swift test (BulaiCore)"
(cd BulaiCore && swift test)

if command -v xcodebuild >/dev/null 2>&1; then
    DESTINO="${BULAI_SIMULADOR:-platform=iOS Simulator,name=iPhone 16}"
    echo "› xcodebuild test ($DESTINO)"
    xcodebuild -project BulaIA.xcodeproj -scheme BulaIA -destination "$DESTINO" -derivedDataPath build/DerivedData test
else
    echo "› Xcode não encontrado: só o núcleo foi testado (app e widget precisam de macOS)."
fi
