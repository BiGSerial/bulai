# Aplicativo Android nativo (APK)

## Propósito

O BULA-IA instalado no celular como **aplicativo nativo**: telas em Kotlin + Jetpack Compose (Material 3), falando com o servidor por uma API REST própria (`/api/mobile/v1`), e o que só o aparelho faz bem: **alarme na hora exata de cada dose, mesmo sem internet, com o celular bloqueado e depois de reiniciar**, "Tomei", "Pular" e "Adiar" na notificação, modo despertador em tela cheia, widget e atalhos.

Não há WebView: as regras de negócio continuam no Laravel (os mesmos services que as telas Livewire usam) e o app só desenha o JSON.

## Etapas

| Etapa | Conteúdo | Situação |
| --- | --- | --- |
| 1. Fundação | API de conta; Entrar, Cadastro, Esqueci a senha, Primeiro acesso; menu inferior; Início; Remédios do dia (ontem/hoje/amanhã); Avisos (central, lembretes, alarmes do celular); WebView removido | **feita** |
| 2. Tratamento | Perfil (consentimento, alergias, receitas por texto, remédios, posologia), busca de remédios, Bulas | a fazer |
| 3. Consultas | Interações (pesquisa, resultado, histórico) e Perguntar (com ditado) | a fazer |
| 4. Restante | Receita por foto (câmera, envio, revisão), cuidadores, Minha conta, resumo PDF | a fazer |
| 5. Publicação | assinatura, R8, versionamento, testes instrumentados, política de privacidade e "Segurança dos dados" da Play Store | a fazer |

Enquanto uma tela não existe no app, avisos e atalhos que apontam para ela abrem o Início.

## Arquitetura

```
┌──────────────────────── Aplicativo (mobile/android) ────────────────────────┐        ┌──────── Servidor Laravel ────────┐
│ MainActivity (Compose) ─ AppBulai: sessão → Conta | Primeiro acesso | Paciente│        │ /api/mobile/v1 (JSON)            │
│   telas (ui/*) ─ ViewModel (StateFlow) ─ Repositórios ─ Retrofit/OkHttp ──────┼─HTTPS─►│  entrar, cadastro, esqueci-senha │
│                         │                   │                                │ Bearer │  eu, primeiro-acesso, aparelho   │
│                         ▼                   ▼                                │        │  inicio, dia, dia/doses          │
│                CacheEmDisco (última resposta)   Sessao (token no Keystore)   │        │  avisos (+ novos, lidos, …)      │
│                                                                               │        │  agenda, doses (alarmes)         │
│ Sincronizacao ─ SincronizarWorker / MarcarDoseWorker (ApiClient) ─────────────┼───────►│                                  │
│ AgendadorDeAlarmes (AlarmManager) ► AlarmeReceiver ► Notificacoes / AlarmeActivity      └──────────────────────────────────┘
│ ReinicioReceiver · ProximaDoseWidget · atalhos · VideoDeAbertura              │
└───────────────────────────────────────────────────────────────────────────────┘
```

1. **Entrar**: e-mail e senha em `POST /entrar` com o `installation_id` do aparelho; o servidor devolve o token `bulai_app_…` (o banco guarda só o sha256). O token fica cifrado com uma chave AES-GCM do Android Keystore (`dados/Cofre.kt`).
2. **Senha provisória**: com `must_change_password`, só `eu`, `primeiro-acesso` e `aparelho` respondem; o resto dá 403 `{code: "must_change_password"}`. O app mostra a tela de troca; a troca mantém este aparelho conectado e derruba as sessões do site e os outros aparelhos.
3. **Conta liberada**: `Sessao` liga a sincronização dos alarmes (`Sincronizacao.conectar`), que busca a agenda de hoje e amanhã e agenda um alarme exato por dose. Sincroniza a cada 30 minutos, ao abrir o app e depois de cada marcação ou mudança de lembrete feita nas telas.
4. **Telas**: cada tela abre com a última resposta guardada (`CacheEmDisco`) ou com o esqueleto de carregamento, e atualiza por cima. Puxar para baixo atualiza; voltar para a tela (ON_RESUME) também. Ações (Tomei, Pular, Desfazer, preferências, apagar aviso) mudam a tela na hora e se confirmam com o servidor; se falharem, voltam ao estado anterior e o snackbar explica. Tomei/Pular oferecem "Desfazer" no snackbar.
5. **Token recusado (401)** em qualquer chamada: o app cancela os alarmes, apaga os dados locais e volta para Entrar.
6. **Sair**: menu ⋮ › Sair da conta; avisa o servidor (`DELETE /aparelho`) e limpa o aparelho.

## API `/api/mobile/v1`

Respostas em JSON com `Cache-Control: no-store`; erros no formato do Laravel (`message`, e `errors` por campo no 422). Rotas em `routes/api.php`, controladores em `app/Http/Controllers/Api/Mobile`.

| Método e caminho | Uso | Observação |
| --- | --- | --- |
| `POST /entrar` | `{email, password, installation_id, device_name?, app_version?}` → `{token, user}` | throttle `mobile-login` (5/min e 20/15 min por e-mail + IP, resposta 429 em JSON). Mesmas mensagens do site: a de cadastro pendente/recusado/desativado só aparece para quem acertou a senha. Entrar de novo na mesma instalação troca o token |
| `POST /cadastro` | `{name, email, password, password_confirmation, terms}` → 201 `{message}` | throttle `mobile-register`; conta nasce aguardando aprovação, sem token |
| `POST /esqueci-senha` | `{email}` → `{message}` | throttle `mobile-password-reset`; mesma resposta exista ou não o e-mail |
| `GET /eu` | `{user}` | também com senha provisória pendente |
| `POST /primeiro-acesso` | `{password, password_confirmation}` → `{user}` | senha nova diferente da provisória |
| `DELETE /aparelho` | desconecta este aparelho | auditado |
| `GET /inicio` | saudação, data, consentimento, próxima dose, progresso do dia, avisos não lidos, pessoas cuidadas | `App\Services\Treatment\PatientDayService::home` |
| `GET /dia?dia=-1\|0\|1` | resumo e doses do dia (com `period` manhã/tarde/noite, `adjusted_from` dos remédios dinâmicos) e remédios "quando precisar" | `PatientDayService::day` |
| `POST /dia/doses` | `{medication_id, scheduled_for, status: taken\|skipped, dia}` → dia atualizado | remédio de outra pessoa → 404 |
| `DELETE /dia/doses` | `{medication_id, scheduled_for, dia}` → dia atualizado | desfaz a marcação |
| `GET /avisos` | central: `unread`, preferências (`reminders`, `email`, `timezone`), fusos, até 30 avisos com `when`, `action`, `read` e `destination` `{route, params}` | `params` sempre objeto JSON |
| `POST /avisos/{id}/abrir` | marca como lido e devolve `destination` | |
| `POST /avisos/lidos`, `DELETE /avisos`, `DELETE /avisos/{id}` | marcar todos como lidos, apagar todos, apagar um | devolvem a central |
| `PUT /avisos/preferencias` | `{reminders?, email?, timezone?}` | só com consentimento; fuso só da lista do Brasil |
| `GET /avisos/novos?desde=ISO` | avisos não lidos novos (até 24 h, 20 itens) para notificações do sistema | usado pelo `SincronizarWorker` |
| `GET /agenda`, `POST /doses` | agenda dos alarmes e marcação pela notificação/despertador/widget (`recorded_at` = hora real do toque, janela de 48 h) | `DeviceAgendaService` |

Autenticação: `App\Http\Middleware\AuthenticateMobileDevice` (Bearer; conta desativada ou não aprovada → 401; senha provisória → 403 fora das rotas `:primeiro-acesso`). Limite geral `mobile-api`: 120/min por aparelho e 300/min por IP. Auditoria: `mobile_device_connected` / `mobile_device_disconnected`. `UserAccountService::revokeAccess()` apaga os aparelhos (encerrar sessões, desativação, redefinição de senha); na troca de senha feita pelo app, o aparelho que trocou continua (`$currentMobileDeviceId`).

Mudanças de apoio no backend: `TreatmentScheduleService::annotateAdjustments()` (antes `Hoje::anotarAjustes`, que agora delega) e `PatientProfile::TIMEZONES` (antes só em `Avisos`).

## Aplicativo (mobile/android)

| Pasta / arquivo | O que faz |
| --- | --- |
| `MainActivity.kt` | única Activity das telas; splash do sistema, vídeo de abertura e `bulai://abrir/{caminho}` (notificações, widget, atalhos) |
| `BulaiApp.kt` | `Container` com as dependências (sessão, API, repositórios, cache) |
| `dados/api/` | `BulaiApi` (Retrofit), `Modelos` (kotlinx.serialization), `Rede` (OkHttp com token, 401 → sessão encerrada, tradução dos erros para mensagens) |
| `dados/Sessao.kt`, `Cofre.kt`, `Preferencias.kt` | estado da conta (Visitante, TrocarSenha, Conectado), token cifrado no Keystore, preferências locais fora de backup |
| `dados/Repositorios.kt`, `CacheEmDisco.kt` | conta, tratamento e avisos; última resposta de cada tela em `noBackupFilesDir/telas` |
| `ui/tema/` | cores da marca (as do site), modo escuro, tipografia grande (corpo 18 sp, respeita a escala de fonte do sistema) |
| `ui/componentes/` | botões de 56–60 dp, campos com erro por campo e mostrar senha, cartões, faixa de erro com "Tentar de novo", estado vazio, esqueletos com brilho |
| `ui/conta/`, `ui/inicio/`, `ui/dia/`, `ui/avisos/` | telas e ViewModels |
| `ui/navegacao/` | `AppBulai` (sessão → navegação), menu inferior com contador de avisos, snackbar com "Desfazer", rotas e tradução de `destination`/caminho para tela |
| `ui/sistema/Permissoes.kt`, `alarmes/AjustesDoCelular.kt` | permissões dos alarmes (notificações, hora exata, tela cheia, bateria) e preferências do despertador |
| `alarmes/`, `sync/`, `widget/` | alarmes exatos, notificações com ações, despertador em tela cheia, sincronização em segundo plano e widget (sem mudança de regra) |
| `abertura/VideoDeAbertura.kt`, `res/raw/abertura.mp4` | vídeo de abertura só ao entrar pelo ícone (ver abaixo) |
| `res/drawable/marca_logo.xml` (+ `drawable-night`) | logo do site convertido para vetor, com o texto claro no modo escuro |

Detalhes de experiência:

- **Carregamento**: esqueletos com as proporções reais da tela na primeira abertura; depois, os dados guardados aparecem na hora e a atualização é silenciosa (indicador de puxar para atualizar).
- **Remédios do dia**: abas Ontem/Hoje/Amanhã com deslize lateral (`HorizontalPager`), cada dia buscado quando aparece; linha do tempo por período; "Tomei" com vibração de confirmação.
- **Acessibilidade**: títulos marcados como cabeçalho, estado das doses lido pelo TalkBack, erros de campo anunciados, "Apagar aviso" como ação de acessibilidade (além do arrastar), alvos de toque ≥ 48 dp, letra grande.
- **Telas largas**: conteúdo limitado a 520–640 dp e centralizado.
- **Autopreenchimento**: e-mail e senhas marcados para o gerenciador de senhas.

Versões: AGP 9.5.1, Kotlin 2.4.21 (plugins Compose e serialization), Gradle 9.9.1, `compileSdk 37`, `targetSdk 36`, `minSdk 26`. Dependências: AndroidX core-ktx, activity-compose, work-runtime-ktx, core-splashscreen, browser, navigation-compose 2.10.2, lifecycle 2.11.0; Compose BOM 2026.09.00 (Material 3 e ícones); kotlinx-coroutines 1.11.0; kotlinx-serialization 1.13.0; Retrofit 3.0.0 + conversor kotlinx; OkHttp 5.5.1. Testes: JUnit 4, org.json, coroutines-test, MockWebServer.

Permissões: internet; notificações; `SCHEDULE_EXACT_ALARM`; `USE_FULL_SCREEN_INTENT`; vibração; iniciar com o aparelho; pedido para ignorar a economia de bateria. Câmera, microfone e downloads voltam nas etapas que os usam.

### Vídeo de abertura

`res/raw/abertura.mp4` (720×1280, H.264, sem áudio, ~730 KB): 3 s do martelo na forja e 1 s parado na logo, depois fade para as telas, que carregaram por baixo. Só ao entrar pelo ícone; não toca ao voltar do segundo plano, ao girar a tela, por notificação, atalho, widget ou link, nem com "remover animações" ligado. A tela de abertura do sistema segura até o primeiro quadro; se o vídeo falhar ou travar, o app segue (limite de 6 s). Não pede foco de áudio. As faixas acima e abaixo do vídeo (telas mais altas que 9:16) usam o mesmo cinza das bordas.

Gerado do vídeo original da marca (5 s):

```bash
ffmpeg -i original.mp4 -an -vf "setpts=PTS*3/5.0347,fps=30,tpad=stop_mode=clone:stop_duration=1,format=yuv420p" \
  -c:v libx264 -profile:v main -preset slow -crf 26 -movflags +faststart abertura.mp4
```

## Ambiente e geração do APK

Uma vez por máquina (Linux/WSL, sem sudo):

```bash
mobile/android/scripts/preparar-ambiente.sh
```

Usa o JDK do sistema se for 17 ou mais novo (senão baixa o Temurin 21 para `~/.local/share/bulai-jdk`), instala o Android SDK em `~/Android/Sdk` e grava `mobile/android/local.properties`.

```bash
mobile/android/scripts/gerar-apk.sh                                 # debug → produção (https://bulai.vixforge.com.br)
mobile/android/scripts/gerar-apk.sh debug http://192.168.0.10:8000  # debug → servidor de desenvolvimento na rede local
mobile/android/scripts/criar-chave-release.sh                       # uma vez: chave de assinatura + keystore.properties
mobile/android/scripts/gerar-apk.sh release                         # versão de publicação assinada
```

O APK vai para `mobile/android/dist/bulai-{versão}-{tipo}.apk` (fora do Git). A versão de depuração tem o pacote `br.com.vixforge.bulai.debug` e aceita HTTP. **A chave de publicação e as senhas ficam fora do repositório**: sem elas não dá para atualizar o app nos aparelhos.

### Emulador (WSL + emulador do Windows/Orca)

- Túnel do adb: `adb.exe reverse tcp:8000 tcp:8000`, servidor com `PHP_CLI_SERVER_WORKERS=6 php artisan serve --host=0.0.0.0 --port=8000` e APK com `gerar-apk.sh debug http://localhost:8000` (pelo `10.0.2.2` pedidos em paralelo falham ao acaso).
- Não deixe o adb do WSL rodando junto com o do Windows: os dois disputam a porta 5037 e o Orca deixa de ver o emulador.
- Para testar sem mexer no banco de desenvolvimento, use um banco à parte: `CREATE DATABASE bulai_app_demo`, `DB_DATABASE=bulai_app_demo php artisan migrate` e suba o servidor com o mesmo `DB_DATABASE`.

## Deploy do servidor

Migração `mobile_devices` e as rotas novas de `routes/api.php`; sem variáveis novas e sem build de front.

## Testes

- `tests/Feature/Consulta/AppAndroidTest.php`: entrar (só o hash no banco, entrar de novo troca o token, auditoria, e-mail sem diferença de maiúsculas), senha errada e conta bloqueada, limite de tentativas em JSON, cadastro aguardando aprovação, "esqueci a senha" com a mesma resposta, senha provisória (403 fora do primeiro acesso, troca mantém o aparelho e derruba os outros), Início, dia com ontem/amanhã e marcar/desfazer, central de avisos (abrir, lidos, apagar, preferências, aviso de outra pessoa → 404), agenda dos alarmes, marcação pela notificação, avisos novos, tokens inválidos e revogação.
- `mobile/android/app/src/test/`: `AgendadorDeAlarmesTest` (regras dos alarmes), `VideoDeAberturaTest` (quando o vídeo toca), `RedeTest` (cabeçalhos, leitura do JSON, 422 por campo, 401 encerra a sessão, 403 de senha provisória, 429, sem rede; com MockWebServer), `NavegacaoEDiaTest` (aviso/caminho → tela; marcação otimista recalcula o resumo).
- Rodados também: `./gradlew lintDebug` e o fluxo no emulador: entrar, Início, Tomei, Remédios do dia, Avisos (lista, lembretes, alarmes do celular), alarme de teste com Tomei/Adiar/Pular, abrir por `bulai://abrir/avisos`, sair, senha provisória.

## Limitações

- Etapas 2 a 5 por fazer (perfil, bulas, interações, perguntar, receita por foto, cuidadores, minha conta, publicação). Atalhos de Perguntar, Receita e Interações voltam com as telas.
- O trabalho em segundo plano (`SincronizarWorker`, `MarcarDoseWorker`) ainda usa o `ApiClient` (HttpURLConnection) da primeira versão; as telas usam Retrofit/OkHttp.
- **Sem push do servidor (FCM)**: avisos que não são de dose chegam na sincronização (30 min ou ao abrir o app).
- Fabricantes com economia de bateria agressiva podem segurar alarmes; a tela Avisos pede para liberar. Na Play Store, `REQUEST_IGNORE_BATTERY_OPTIMIZATIONS` e `USE_FULL_SCREEN_INTENT` passam por análise de política.
- Uma conta logada em mais de um celular recebe o alarme em todos.
- O widget mostra "amanhã" pelo fuso do celular, não pelo fuso escolhido em Avisos.
