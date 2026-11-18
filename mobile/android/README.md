# BULA-IA para Android

Aplicativo nativo em Kotlin + Jetpack Compose sobre a API `/api/mobile/v1`, com alarmes exatos das doses, widget e atalhos.

```bash
scripts/preparar-ambiente.sh      # uma vez: JDK + Android SDK + local.properties
scripts/gerar-apk.sh              # APK de depuração em dist/
./gradlew testDebugUnitTest lintDebug
```

Arquitetura, API, etapas, assinatura e limitações: [`docs/app-android.md`](../../docs/app-android.md).
