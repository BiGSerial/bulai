# Avisos e lembretes do tratamento

## Propósito

O paciente que registrou horários de dose (ver `docs/tratamento.md`) recebe um aviso no aplicativo quando chega a hora de um remédio, um aviso no último dia de um tratamento com data de término e um aviso quando a administração liga ao catálogo um remédio que ele cadastrou pelo nome. Tudo é opcional, por paciente, e nunca substitui a orientação médica (a diretriz do produto vai no e-mail).

## Tabelas e modelos

| Tabela | Uso |
| --- | --- |
| `notifications` (canal `database` do Laravel) | um registro por aviso: `type`, `data` (título, frase, `kind`, `route`, remédio/horário), `read_at` e **`dedupe_key` (única)** |
| `patient_profiles.timezone`, `reminders_enabled`, `reminders_email` | fuso dos horários e preferências do paciente |
| `patient_medications.dose_times`, `as_needed`, `reminders_enabled` | horários programados e se o remédio gera lembrete |
| `medication_dose_events` | "tomei"/"pulei": uma dose marcada não recebe lembrete |

Migrações: `2026_11_05_114449_create_notifications_table` (com `dedupe_key`) e `2026_11_05_114448_create_treatment_schedule_tables`.

## Serviço, comando e agendador

- `App\Services\Treatment\ReminderService::run(?CarbonImmutable $now)`: para cada paciente com consentimento e `reminders_enabled` (e ao menos um remédio com lembrete), monta a agenda do dia no fuso do paciente (`TreatmentScheduleService::agenda()`) e envia **um** lembrete por dose cujo estado é `due`, isto é, horário entre `horário − BULAI_REMINDER_LEAD_MINUTES` e `horário + BULAI_DOSE_LATE_AFTER_MINUTES`, sem marcação. A partir das 08:00 locais, envia o aviso de fim de tratamento para remédios com `ends_at` = hoje.
- Comando `php artisan treatment:send-reminders [--now=ISO8601]` (`App\Console\Commands\SendTreatmentRemindersCommand`); `--now` serve a testes e reprocessamentos.
- `routes/console.php`: `everyFiveMinutes()->onOneServer()->withoutOverlapping()`.

## Canais e deduplicação

- `App\Notifications\MedicationReminderNotification` (chave `dose:{patient_medication_id}:{scheduled_for UTC ISO}`) e `App\Notifications\TreatmentEndingNotification` (chave `end:{patient_medication_id}:{AAAA-MM-DD}`): canal `database` sempre; `mail` quando `reminders_email` do paciente estiver ligado. `App\Notifications\UnmatchedMedicineResolvedNotification` (sem chave) é enviada pela administração.
- `App\Notifications\Channels\DedupedDatabaseChannel` substitui o canal `database` (registrado no `AppServiceProvider`): notificações que implementam `HasDedupeKey` gravam a chave na coluna única; uma segunda gravação com a mesma chave é ignorada sem erro. O `ReminderService` ainda consulta a chave antes de enviar, para não disparar o e-mail duas vezes.

## Tela `/avisos` (`App\Livewire\Consulta\Avisos`)

Lista dos 30 avisos mais recentes com hora no fuso do paciente ("Hoje, 08:00"), não lidos em destaque com o selo "Novo", botão "Marcar todos como lidos", uma ação grande por aviso ("Ver remédios de hoje" → `/hoje`; "Ver meus remédios" → `/perfil`) que já marca como lido, e "Apagar". Bloco **Preferências** (só com consentimento de dados de saúde): "Receber lembretes de horário", "Também por e-mail" e "Horário da sua região" (dez fusos brasileiros; qualquer outro valor é recusado). Botão "Ativar avisos neste aparelho" pede a permissão do navegador e só aparece onde há suporte.

## Sino e avisos no navegador

- `App\Livewire\Consulta\SinoDeAvisos` fica no cabeçalho do shell do paciente: contagem de não lidos (uma consulta) e `wire:poll.60s="verificar"` **só nesse fragmento**. Quando um aviso novo chega desde a última verificação, o componente dispara o evento `bulai-aviso` (título, frase, `tag`).
- `resources/js/app.js` escuta `bulai-aviso` e, com permissão concedida, chama `registration.showNotification()` do service worker (ou `new Notification()` onde não há registro, como em desenvolvimento). `public/sw.js` (`bulai-static-v4`) trata `notificationclick`: foca a janela do aplicativo e vai para `/hoje`.

## Deploy

Serviço `scheduler` novo em `compose.prod.yaml` (`php artisan schedule:work`, 256 MB), variáveis `BULAI_TREATMENT_TIMEZONE`, `BULAI_REMINDER_LEAD_MINUTES`, `BULAI_DOSE_LATE_AFTER_MINUTES`, `BULAI_REMINDER_QUEUE` em `app.env` e `MAIL_MAILER` para o e-mail opcional. Detalhes e verificação em `docs/deployment/kinghost.md` ("Agendador e lembretes"). Em desenvolvimento: `php artisan schedule:work` (ou `treatment:send-reminders` à mão).

## Testes

`tests/Feature/Consulta/AvisosTest.php`: janela e filtros do lembrete (consentimento, preferência, remédio sem lembrete, "se precisar", dose marcada), canais (e-mail só por opção), deduplicação real no banco (duas rodadas, uma linha; o canal ignora a repetição), aviso de fim de tratamento a partir das 08:00, comando com `--now`, registro no agendador, tela de avisos (ler, abrir, apagar, preferências, privacidade) e sino (contagem e evento `bulai-aviso`). `tests/Feature/PwaAssetTest.php` confere o `notificationclick` do service worker.

## Limitações

- **Sem Web Push**: o aviso do navegador só aparece com o aplicativo aberto (aba ou PWA em primeiro plano/segundo plano com a página viva). Push real exige chaves VAPID, a biblioteca `minishlink/web-push` (dependência a aprovar) e uma tabela de inscrições; é o próximo passo natural.
- O e-mail depende de `MAIL_MAILER` configurado no ambiente; em desenvolvimento (`log`) o conteúdo vai para o log.
- `BULAI_REMINDER_QUEUE` está reservada: hoje a entrega é síncrona dentro do agendador (volume pequeno, sem dependência do worker).
- A janela de lembrete é avaliada a cada 5 minutos; um lembrete pode chegar até 5 minutos depois do início da janela.
- Remédios com programação dinâmica ("de 8 em 8 horas"): o lembrete segue a agenda recalculada a partir do horário real da última dose tomada (ver `docs/tratamento.md`, "Programação dinâmica por intervalo").

## Web Push (07/11/2026)

O aviso do navegador só aparecia com o aplicativo aberto. Agora há Web Push real, **sem dependência nova**: autenticação VAPID (RFC 8292, JWT ES256 assinado com `openssl` em `App\Services\Push\WebPushSender`) e push **sem conteúdo**. O push vazio acorda o service worker (`public/sw.js`, evento `push`), que busca o texto em `GET /push/ultimo` (aviso não lido mais recente do próprio paciente, últimos 30 min) e o mostra; sem rede ou sessão, mostra um aviso genérico. Assim não se guardam chaves do aparelho nem há criptografia de payload.

- **Tabela** `push_subscriptions` (usuário, endpoint, hash, falhas). Endpoints que respondem 404/410 são apagados; 10 falhas seguidas também.
- **Canal** `App\Notifications\Channels\WebPushChannel`: acrescentado às vias de `MedicationReminderNotification`, `TreatmentEndingNotification` e `UnmatchedMedicineResolvedNotification` só quando há chaves VAPID e aparelho inscrito; nunca derruba a notificação (o aviso continua no aplicativo e no e-mail).
- **Rotas** (área do paciente): `POST/DELETE /push/inscrever`, `GET /push/ultimo`. JS: `window.bulaiPush` (`resources/js/app.js`). Tela Avisos: "Receber avisos também com o aplicativo fechado".
- **Chaves**: `sudo bulai-deploy artisan webpush:generate-keys` imprime `WEBPUSH_PUBLIC_KEY`, `WEBPUSH_PRIVATE_KEY` (PEM em base64, **segredo**) e `WEBPUSH_SUBJECT`; só no `/srv/bulai/shared/env/app.env`, depois `sudo bulai-deploy activate`. Sem as três variáveis o botão não aparece e tudo segue como antes. Trocar a chave pública invalida as inscrições.
- **Ativação nos aparelhos**: o service worker mudou (`bulai-static-v6`); quem já instalou precisa tocar em "Atualizar" (faixa azul) uma vez e depois ativar os avisos de novo na tela Avisos. No iPhone o Web Push só funciona com o app instalado na tela inicial (iOS 16.4+).
- **Limitações**: a entrega depende do serviço de push do navegador (FCM, Apple, Mozilla); o lembrete sai pelo agendador a cada 5 minutos (`treatment:send-reminders`), então precisa do `scheduler` rodando; push vazio exibe o aviso só se o aparelho consegue buscar `/push/ultimo` (sessão válida), senão mostra o texto genérico.
- Testes: `tests/Feature/Consulta/WebPushTest.php` (JWT verificado com a chave pública, push vazio com TTL/Urgency, limpeza de endpoints, canal, rotas, tela e service worker).

## Correções de interface (07/11/2026)

- **Balão quebrando o layout no PWA**: `break-words` não reduz a largura mínima de itens de grid/flex; uma palavra longa alargava a coluna e o `overflow-x: clip` cortava o balão. `.app-screen` agora força `min-width: 0` nos filhos de grid/flex e `overflow-wrap: anywhere` nos textos; a coluna do chat usa `minmax(0, 1fr)`.
- **Microfone sem pedir permissão**: o nginx de produção enviava `Permissions-Policy: microphone=()`, que proíbe o recurso na página inteira (o navegador nem exibe o pedido). Passou a `microphone=(self)` em `docker/production/nginx/default.conf` (imagem `bulai-web`). Quem negou antes precisa liberar o microfone nas configurações do site.

### Celular estreito (07/11/2026, segunda passada)

Capturas de um Android com tela de ~370 px (fonte grande) mostraram o nome do remédio quebrando no meio da palavra ("DIPIRON/A") e o chat usando só ~70 % da largura. Ajustes: cartão da dose em duas linhas (horário e situação em cima, nome e dose com a largura toda embaixo; trilho da linha do tempo mais estreito); balão do assistente sem avatar e sem margem direita no celular (o avatar volta a partir de `sm`); margens laterais da tela de 1 rem para 0,75 rem; caixa de pergunta compacta (1 linha que cresce até ~6, botões `min-h-12`), devolvendo altura às respostas; título do cabeçalho em até duas linhas em vez de cortado; abaixo de 24 rem a tipografia grande (`text-xl`/`2xl`/`3xl`) diminui um degrau e `px-5` vira `px-4`. Não foi possível conferir em aparelho real: validar nas três telas das capturas (Remédios de hoje, Perguntar, uma resposta longa).

## Modo cuidador: aviso de dose atrasada (09/11/2026)

`treatment:send-reminders` também roda `App\Services\Caregiving\CaregiverAlertService`: quem cuida com a permissão "receber avisos" recebe `CaregiverLateDoseNotification` (sino + Web Push, chave `cuidador:{vínculo}:{remédio}:{horário UTC}`) quando uma dose do paciente fica atrasada (`late_after_minutes`), dentro da janela `BULAI_CAREGIVER_ALERT_WINDOW_MINUTES` (padrão 120). O paciente recebe `CaregiverRequestNotification` quando alguém usa o código do convite. Avisos com `route_params` abrem a tela do paciente certo em `Avisos::abrir()` e em `GET /push/ultimo`. Detalhes, permissões e testes: `docs/cuidador.md`.
