# BULA-IA para iOS

Aplicativo nativo em Swift + SwiftUI (MVVM, `async/await`) sobre a mesma API `/api/mobile/v1` do Android: lembretes locais das doses com Tomei/Adiar/Pular, widget "Próximo remédio", atalhos, câmera, QR Code e voz.

```bash
cp Config/Local.xcconfig.example Config/Local.xcconfig   # equipe e identificador (fora do Git)
scripts/testar.sh       # swift test no núcleo (também no Linux) + xcodebuild test no macOS
scripts/compilar.sh     # app e widget para o simulador (macOS + Xcode 16)
scripts/gerar-ipa.sh    # IPA assinado em dist/ (BULAI_TEAM=<Team ID>)
```

- `BulaiCore/`: núcleo testável (modelos, rede, sessão, lembretes, ViewModels) — `cd BulaiCore && swift test`.
- `BulaIA/`, `Widget/`: telas SwiftUI e widget. `Config/`: xcconfigs, Info.plist, entitlements.

Arquitetura, telas, diferenças em relação ao Android, assinatura, instalação no iPhone e pendências: [`docs/app-ios.md`](../../docs/app-ios.md).
