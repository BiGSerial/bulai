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
