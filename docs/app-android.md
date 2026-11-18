# Aplicativo Android nativo (APK)

## Propósito

Levar o BULA-IA para o celular como aplicativo instalado, com o que o PWA não consegue fazer de forma confiável: **alarme na hora exata de cada dose, mesmo sem internet, com o celular bloqueado e depois de reiniciar**, com "Tomei", "Pular" e "Adiar" na própria notificação, modo despertador em tela cheia, widget na tela inicial, atalhos no ícone, câmera para a receita, ditado por voz e download do resumo para o médico.

As telas são as mesmas do PWA (Laravel + Livewire), abertas num WebView. Não há uma segunda interface para manter: tudo o que muda no site aparece no aplicativo. A parte nativa (Kotlin) cuida só do que precisa do aparelho.

## Arquitetura

```
┌──────────────── Aplicativo (mobile/android) ────────────────┐        ┌────────── Servidor Laravel ──────────┐
│ MainActivity (WebView) ── window.BulaiNative ──┐            │        │                                      │
│   telas do PWA, sessão por cookie               │            │  HTTPS │ POST /aparelho (sessão + CSRF)       │
│                                                 ▼            │ ─────► │   → token do aparelho (uma vez)      │
│ PonteNativa ── Sincronizacao ── ApiClient (Bearer) ─────────┼──────► │ GET  /api/mobile/v1/agenda           │
│                     │               │                        │        │ POST /api/mobile/v1/doses            │
│                     ▼               ▼                        │        │ GET  /api/mobile/v1/avisos           │
│ AgendadorDeAlarmes (AlarmManager)  WorkManager               │        │ DELETE /api/mobile/v1/aparelho       │
│   └► AlarmeReceiver ► Notificacoes / AlarmeActivity          │        └──────────────────────────────────────┘
│        └► AcaoDaDoseReceiver (Tomei/Pular/Adiar) ► MarcarDoseWorker
│ ReinicioReceiver (boot, atualização, hora/fuso) · ProximaDoseWidget · atalhos
└──────────────────────────────────────────────────────────────┘
```

1. A pessoa entra na conta dentro do aplicativo, como no site.
2. `resources/js/nativo.js` percebe o `window.BulaiNative`, chama `POST /aparelho` com a sessão e entrega o token à parte nativa (`conectar`). O token nunca aparece na tela.
3. A parte nativa busca a agenda de **hoje e amanhã** e agenda um alarme exato para cada dose pendente com lembrete. Sincroniza a cada 30 minutos, ao abrir o aplicativo, depois de cada ação nas telas do tratamento e depois de cada marcação pelo alarme.
4. Na hora, o alarme toca com a agenda guardada no aparelho; não depende de rede. "Tomei"/"Pular" valem na hora no aparelho e vão ao servidor pelo WorkManager, que repete até a rede voltar, com o horário **real** do toque (`recorded_at`); remédios de programação dinâmica ("de 8 em 8 horas") contam a próxima dose a partir dele.
5. Avisos que não são de dose (cuidador, fim de tratamento, remédio ligado ao catálogo) chegam como notificação na sincronização.
6. Ao sair da conta (página de visitante com o aparelho ainda ligado), `nativo.js` chama `desconectar`: alarmes cancelados, dados locais apagados e o token revogado no servidor.

## Backend

| Item | Arquivo |
| --- | --- |
| Tabela `mobile_devices` (usuário, `installation_id`, `token_hash` sha256 único, aparelho, versão, `last_seen_at`) | `database/migrations/2026_11_17_213408_create_mobile_devices_table.php`, `app/Models/MobileDevice.php` |
| Emissão do token (sessão do WebView) | `POST /aparelho` → `App\Http\Controllers\MobileDeviceController` (throttle 10/min). Mesma instalação de novo = token novo, o antigo deixa de valer |
| Autenticação do aparelho | `App\Http\Middleware\AuthenticateMobileDevice` (`Bearer bulai_app_…`; conta desativada ou não aprovada → 401) |
| Agenda | `GET /api/mobile/v1/agenda` → `Api\Mobile\AgendaController@index` → `App\Services\Treatment\DeviceAgendaService` |
| Marcação | `POST /api/mobile/v1/doses` `{medication_id, scheduled_for, status: taken\|skipped, recorded_at?}` → `TreatmentScheduleService::mark()`; devolve a agenda nova. Janela de ±48 h; remédio de outra pessoa → 404 |
| Avisos | `GET /api/mobile/v1/avisos?desde=ISO` → `Api\Mobile\NoticeController` (não lidos das últimas 24 h, até 20, com o caminho da tela) |
| Desconectar | `DELETE /api/mobile/v1/aparelho` → `Api\Mobile\DeviceController` |
| Limite | `RateLimiter` `mobile-api`: 30/min por aparelho e 120/min por IP (`AppServiceProvider`) |
| Auditoria | `user_audit_events`: `mobile_device_connected`, `mobile_device_disconnected` |
| Revogação | `UserAccountService::revokeAccess()` apaga os aparelhos: troca de senha, "Encerrar outras sessões", desativação e redefinição de senha desconectam o aplicativo |

Formato da agenda (uma linha por horário; `key` = `{remédio}|{horário UTC}`):

```json
{
  "timezone": "America/Sao_Paulo", "reminders_enabled": true, "reminders_blocked_reason": null,
  "lead_minutes": 10, "late_after_minutes": 60, "unread_notices": 2,
  "doses": [{"key": "12|2026-11-05T11:00:00+00:00", "medication_id": 12, "name": "Losartana", "dosage": "1 comprimido",
             "time": "08:00", "scheduled_for": "2026-11-05T08:00:00-03:00", "status": "pending", "dynamic": false, "remind": true}]
}
```

`remind` é falso sem consentimento de dados de saúde (`reminders_blocked_reason = consent`), com "Receber lembretes de horário" desligado em Avisos (`disabled`), com o lembrete do remédio desligado ou em remédio "se precisar". As mesmas regras do `ReminderService`.

## Web (PWA)

- `resources/js/nativo.js` (importado em `app.js`): ligação da conta, sincronização após ações dos componentes `consulta.tratamento.hoje`, `consulta.inicio`, `consulta.perfil`, `consulta.receita.revisar` e `consulta.avisos`, `window.bulaiNativo` para as telas, `navigator.share` nativo e um `SpeechRecognition` sobre o reconhecimento de voz do Android (o `voz.js` funciona sem mudança).
- `<meta name="bulai-sessao" content="{id ou vazio}">` em `layouts/app`, `layouts/auth` e `welcome`: diz ao aplicativo quem está logado. Páginas sem a meta (offline, erro) não mexem no aparelho.
- `resources/js/pwa.js`: dentro do aplicativo o cartão "Instalar" nunca aparece.
- Tela **Avisos › Preferências**: bloco "Alarmes neste celular" (`resources/views/components/consulta/alarmes-do-celular.blade.php`), visível só no aplicativo: o que falta permitir (avisos, hora exata, economia de bateria), "Tocar como despertador", tempo do "Adiar", próximo alarme e "Testar o alarme agora".

Ponte `window.BulaiNative` (`mobile/android/.../web/PonteNativa.kt`), só responde em páginas do próprio endereço do BULA-IA:

| Método | Uso |
| --- | --- |
| `versao()`, `instalacao()`, `aparelho()`, `usuarioConectado()` | identificação para `POST /aparelho` |
| `conectar(token, usuario)`, `desconectar()`, `sincronizar()` | conta e agenda |
| `estado()` | JSON: `conectado`, `notificacoes`, `alarmesExatos`, `telaCheia`, `bateriaLivre`, `modoAlarme`, `adiarMinutos`, `proximoAlarme`, `versao` |
| `pedirPermissao(tipo)` | `notificacoes`, `alarmes`, `tela_cheia`, `bateria`, `aplicativo` |
| `definirModoAlarme(bool)`, `definirAdiamento(min)`, `testarAlarme()` | preferências do alarme |
| `compartilhar(titulo, texto, url)` | folha de compartilhamento do Android |
| `ditadoDisponivel()`, `ouvirIniciar(idioma)`, `ouvirParar()`, `ouvirCancelar()` | ditado; eventos voltam por `window.__bulaiVoz(tipo, dados)` |

O aplicativo avisa a página com o evento `bulai-nativo-mudou` ao voltar para o primeiro plano (por exemplo, depois de conceder uma permissão nas configurações).

## Aplicativo (mobile/android)

| Pasta / arquivo | O que faz |
| --- | --- |
| `MainActivity.kt` | WebView do BULA-IA: só o endereço configurado abre dentro (outros links vão para o navegador/WhatsApp/telefone); câmera e galeria no campo de arquivo (foto da receita via `FileProvider`); microfone para a página; downloads com o cookie da sessão (resumo em PDF); voltar do Android = voltar da página; respeita o tamanho de letra do sistema; tela própria sem internet; abertura por `bulai://abrir/{caminho}` |
| `abertura/VideoDeAbertura.kt`, `res/raw/abertura.mp4` | vídeo de abertura (marca Vixforge) só ao entrar pelo ícone: 3 s do martelo na forja + 1 s parado na logo, depois sai com fade para o login ou o app, que carregou por baixo. Não toca ao voltar do segundo plano, ao girar a tela, por notificação, atalho, widget ou link, nem com "remover animações" ligado. A tela de abertura do sistema segura até o primeiro quadro; se o vídeo falhar ou travar, o app segue (limite de 6 s). Vídeo mudo, não pausa a música |
| `web/PonteNativa.kt`, `web/Ditado.kt` | ponte JavaScript e reconhecimento de voz |
| `dados/` | `Preferencias` (estado local, fora de backup), `Agenda`/`Dose` (JSON do servidor), `ApiClient` (HttpURLConnection) |
| `alarmes/AgendadorDeAlarmes.kt` | regras de quando cada dose toca e o AlarmManager: dose pendente com lembrete toca no horário; a que passou há até 15 min sem tocar (celular desligado) toca logo; adiada toca no novo horário; uma dose que já tocou não toca de novo; modo despertador usa `setAlarmClock`, o normal `setExactAndAllowWhileIdle`, e sem a permissão de hora exata o aproximado |
| `alarmes/Notificacoes.kt` | canais "Hora do remédio", "Despertador do remédio" (som de alarme, ignora o Não perturbe) e "Avisos"; na tela bloqueada a notificação mostra só "Hora do remédio" |
| `alarmes/AlarmeActivity.kt` | despertador em tela cheia por cima da tela bloqueada: horário e nome grandes, botões de 72–80 dp, som em repetição e vibração por até 2 minutos |
| `alarmes/AcaoDaDoseReceiver.kt`, `AlarmeReceiver.kt`, `ReinicioReceiver.kt` | ações da dose, disparo do alarme e remontagem depois de reiniciar, atualizar o app ou mudar hora/fuso |
| `sync/` | `Sincronizacao` (conectar, desconectar, agenda), `SincronizarWorker` (a cada 30 min e sob demanda), `MarcarDoseWorker` (envio com repetição) |
| `widget/ProximaDoseWidget.kt` | "Próximo remédio" na tela inicial; o botão "Tomei" só aparece a partir de 1 hora antes do horário |
| `res/xml/shortcuts.xml` | atalhos do ícone: Remédios de hoje, Perguntar, Fotografar receita, Ver interações |

Versões: AGP 9.5.1 (Kotlin embutido), Gradle 9.9.1 (wrapper), `compileSdk 37`, `targetSdk 36`, `minSdk 26` (Android 8). Dependências: AndroidX core-ktx, activity-ktx, work-runtime-ktx e core-splashscreen; testes com JUnit 4 e org.json.

Permissões: internet; notificações; `SCHEDULE_EXACT_ALARM` (o Android 13+ pede que a pessoa libere "Alarmes e lembretes"); `USE_FULL_SCREEN_INTENT` (Android 14+ pede liberação para o modo despertador); vibração; iniciar com o aparelho; pedido para ignorar a economia de bateria; microfone (ditado); gravação em Downloads só no Android 8 e 9. Nenhuma permissão de câmera: a foto é tirada pelo aplicativo de câmera do aparelho.

## Ambiente e geração do APK

Uma vez por máquina (Linux/WSL, sem sudo):

```bash
mobile/android/scripts/preparar-ambiente.sh
```

Usa o JDK do sistema se for 17 ou mais novo (senão baixa o Temurin 21 para `~/.local/share/bulai-jdk`), instala o Android SDK em `~/Android/Sdk` (cmdline-tools, platform-tools, `platforms;android-37.0`, `build-tools;36.1.0`), aceita as licenças e grava `mobile/android/local.properties`.

Gerar:

```bash
mobile/android/scripts/gerar-apk.sh                                 # debug → produção (https://bulai.vixforge.com.br)
mobile/android/scripts/gerar-apk.sh debug http://192.168.0.10:8000  # debug → servidor de desenvolvimento na rede local
mobile/android/scripts/criar-chave-release.sh                       # uma vez: chave de assinatura + keystore.properties
mobile/android/scripts/gerar-apk.sh release                         # versão de publicação assinada
```

O APK vai para `mobile/android/dist/bulai-{versão}-{tipo}.apk` (fora do Git). A versão de depuração tem o pacote `br.com.vixforge.bulai.debug`, aceita HTTP (para o `php artisan serve`) e pode ser instalada ao lado da de publicação. No emulador (WSL + emulador do Windows/Orca), use o túnel do adb: `adb.exe reverse tcp:8000 tcp:8000`, `PHP_CLI_SERVER_WORKERS=6 php artisan serve --host=0.0.0.0` e `gerar-apk.sh debug http://localhost:8000`. Pelo `http://10.0.2.2:8000` (NAT do emulador + repasse do WSL) pedidos em paralelo falham ao acaso e a página fica sem CSS. Não deixe o adb do WSL rodando ao mesmo tempo que o do Windows: os dois disputam a porta 5037 e o Orca deixa de ver o emulador. No celular, use o IP da máquina na rede e `php artisan serve --host=0.0.0.0`.

A tela de login usa o modo compacto do `layouts/auth` (`@section('compact', '1')`): logo, espaçamentos e cartão de cadastro menores para caber inteira na tela do celular sem rolagem (conferido no emulador de 411×866 px).

Instalar: `~/Android/Sdk/platform-tools/adb install -r mobile/android/dist/bulai-1.0.0-debug.apk` (celular com depuração USB ligada) ou enviar o arquivo para o celular e abrir (permitir "fontes desconhecidas").

**A chave de publicação (`bulai-release.jks`) e as senhas precisam ficar guardadas fora do repositório.** Sem elas não dá para atualizar o aplicativo nos aparelhos: o Android recusa um APK assinado com outra chave.

## Deploy do servidor

Rodar a migração (`mobile_devices`) e publicar o build do front (`nativo.js`). Sem variáveis novas.

### Vídeo de abertura

O arquivo `res/raw/abertura.mp4` (720×1280, H.264, sem áudio, ~730 KB) foi gerado do vídeo original da marca (5 s) com ffmpeg:

```bash
ffmpeg -i original.mp4 -an -vf "setpts=PTS*3/5.0347,fps=30,tpad=stop_mode=clone:stop_duration=1,format=yuv420p" \
  -c:v libx264 -profile:v main -preset slow -crf 26 -movflags +faststart abertura.mp4
```

Ou seja, acelerado para 3 s e com o último quadro congelado por 1 s. Para trocar o vídeo, gere de novo com o mesmo comando (5.0347 é a duração do original). Se as bordas do novo vídeo tiverem outra cor, ajuste `res/drawable/fundo_abertura.xml`: em telas mais altas que 9:16 ele preenche as faixas acima e abaixo.

## Testes

- `tests/Feature/Consulta/AppAndroidTest.php`: ligação pela sessão (só o hash fica no banco, reconectar troca o token, auditoria), agenda de hoje e amanhã só do próprio paciente com `remind` e marcações, bloqueio sem consentimento ou com lembretes desligados, marcação pela notificação (horário real, remarcação, 404 de remédio alheio, janela e validação), avisos depois de `desde`, tokens inválidos, conta desativada, revogação ao encerrar sessões, desconexão pelo aparelho e a meta `bulai-sessao` + bloco de alarmes na tela Avisos.
- `mobile/android/app/src/test/.../AgendadorDeAlarmesTest.kt` (`./gradlew testDebugUnitTest`): leitura da agenda e as regras de quando cada dose toca (futura, marcada no servidor ou no aparelho, sem lembrete, atrasada há pouco, já tocada, adiada, adiada e depois marcada).
- `mobile/android/app/src/test/.../VideoDeAberturaTest.kt`: quando o vídeo de abertura toca (só pelo ícone, tela nova, animações ligadas).
- Rodados também: `./gradlew lintDebug` e `assembleRelease` (R8 mantém os métodos da ponte JavaScript).

## Limitações

- **Testado só em parte no emulador** (Orca/Windows, ver dicas acima): instalação, abertura em vídeo, login cabendo na tela. O fluxo de alarmes depende do backend novo publicado. Antes de distribuir, validar num celular: login, alarme de teste, alarme com tela bloqueada, Tomei/Adiar/Pular pela notificação e pelo despertador, reiniciar o celular com uma dose marcada para logo depois, widget, foto da receita, ditado, download do resumo e sair da conta.
- **Sem push do servidor (FCM)**: avisos que não são de dose (cuidador, fim de tratamento) chegam na sincronização, a cada 30 minutos ou ao abrir o aplicativo. Push imediato exige um projeto Firebase (`google-services.json`) e o envio pelo servidor; é o próximo passo natural. O alarme da dose não depende disso.
- Fabricantes com economia de bateria agressiva (Xiaomi, Samsung, Huawei etc.) podem segurar alarmes mesmo com as permissões; por isso a tela pede para liberar a economia de bateria. Para a Play Store, `REQUEST_IGNORE_BATTERY_OPTIMIZATIONS` e `USE_FULL_SCREEN_INTENT` passam por análise de política; para distribuição direta do APK não há restrição.
- Uma marcação feita pelo celular sem rede só aparece no site depois que o envio der certo; até lá, o aparelho já não toca aquela dose.
- Uma conta logada em mais de um celular recebe o alarme em todos.
- Sem App Links: links `https://bulai.vixforge.com.br/...` abertos fora do aplicativo vão para o navegador. Exigiria publicar `/.well-known/assetlinks.json` com a impressão digital da chave de publicação (o script de criação da chave já mostra a impressão).
- O widget mostra "amanhã" pelo fuso do celular, não pelo fuso escolhido em Avisos (só difere se os dois forem diferentes).
- O WebView do Android precisa estar atualizado (Play Store › Android System WebView) para o service worker e o restante do PWA funcionarem como no Chrome.
