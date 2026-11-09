# Modo cuidador com handshake

## Propósito

Permitir que um familiar ou cuidador (outra conta do BULA-IA) acompanhe o tratamento de um paciente — em geral uma pessoa idosa — **só com a aprovação explícita do paciente** e só no que ele permitir: receber aviso de dose atrasada, ver remédios e horários, marcar doses e mudar dose/horários. O paciente revoga quando quiser; quem cuida pode sair. Tudo fica registrado para o paciente consultar (LGPD).

## Fluxo (handshake)

1. **Convite** — o paciente abre *Quem cuida de mim* (`/cuidadores`) e toca em "Criar código de convite". Sai um código de **8 números** (mostrado como `1234 5678`), de **uso único**, válido por **48 horas**. "Enviar o convite" usa o compartilhamento do aparelho (`navigator.share`) ou copia o texto com o link `/cuidar?codigo=…`. Criar um código novo cancela o anterior (só um vale por vez). Exige o consentimento de saúde (`User::hasHealthConsent()`).
2. **Aceite** — quem cuida, logado, abre *Cuidar de alguém* (`/cuidar`; o link já traz o código) e toca em "Usar o código". O vínculo fica **PENDENTE**, o código deixa de valer e o paciente recebe um aviso no sino (e push) "Fulano quer acompanhar seus remédios". Nada é mostrado antes da aprovação.
3. **Aprovação** — só o paciente aprova, em `/cuidadores`, escolhendo as permissões (as três vêm marcadas). Também pode recusar.
4. **Uso** — quem cuida passa a ver o paciente em *Cuidar de alguém*, na Início ("Quem você acompanha") e no seletor do menu do avatar.
5. **Fim** — o paciente "Tira o acesso" (com confirmação) ou quem cuida "Deixa de acompanhar". Vale na hora: a próxima ação ou carregamento recebe 403.

Mensagens de código: formato errado ("O código tem 8 números…"), não encontrado, já usado/cancelado, vencido, código do próprio paciente, vínculo já existente/pendente. **Limite**: 5 tentativas erradas por conta em 15 minutos (`RateLimiter`, chave `caregiver-code:{user_id}`); depois, "Muitas tentativas… Espere N minutos". Acerto zera o contador.

## Permissões (por vínculo, editáveis depois em `/cuidadores`)

| Coluna | Rótulo para o paciente | Efeito |
| --- | --- | --- |
| `can_receive_alerts` | Receber aviso quando eu atrasar um remédio | aviso de dose atrasada (sino + Web Push) |
| `can_view_medications` | Ver meus remédios e horários | `/cuidando/{vinculo}/hoje` e `/cuidando/{vinculo}/remedios` |
| `can_manage_medications` | Marcar doses e mudar dose e horários dos meus remédios | "Tomei"/"Pular"/"Desfazer" e o formulário "Dose e horários" |

"Marcar e editar" **implica ver** (`CaregiverLinkService::normalizePermissions()` liga "ver" quando "marcar" está ligado; a policy também exige as duas). Cadastrar e tirar remédios, receitas e alergias continuam só com o paciente.

**Consentimento de saúde**: `CaregiverLink::allows()` exige vínculo `ACTIVE` + permissão + `health_consent_at` do paciente. Se o paciente nunca consentiu ou apagou os dados de saúde (o que revoga o consentimento), quem cuida não vê nada e não recebe avisos; a tela *Cuidar de alguém* explica o motivo. As telas `/cuidadores` explicam isso ao paciente.

## Autorização no servidor

- `App\Policies\PatientMedicationPolicy` — `view`, `markDose`, `update`: dono do remédio, ou quem cuida com vínculo ativo e as permissões (e consentimento).
- `App\Policies\CaregiverLinkPolicy` — `manage` (só o paciente: aprovar, recusar, permissões, revogar, histórico), `leave` (quem cuida), `viewPatient` e `managePatient`.
- `App\Livewire\Consulta\Concerns\AtuaComoCuidador` — o vínculo vem da rota e é **reconferido a cada requisição Livewire** (`#[Locked] vinculoId`), por isso revogar corta ações em telas já abertas.
- O serviço também confere dono/estado (`abort_unless … 403`) em aprovar/recusar/permissões/revogar/sair.

## Tabelas (migrações `2026_11_09_020126_create_caregiver_links_table`, `2026_11_09_020127_create_caregiver_audit_events_table`)

| Tabela | Colunas | Uso |
| --- | --- | --- |
| `caregiver_links` | `patient_id`, `caregiver_id` (nulo enquanto convite), `status` (`INVITED`/`PENDING`/`ACTIVE`/`REVOKED`/`LEFT`/`CANCELED`), `invite_code_hash` (HMAC-SHA256 com `APP_KEY`, único, mantido depois do uso para dizer "já usado"), `invite_code` (cifrado, apagado ao usar/cancelar), `invite_expires_at`, as três permissões, `accepted_at`, `approved_at`, `ended_at`, `ended_by` | o vínculo do convite à revogação (a mesma linha) |
| `caregiver_audit_events` | `caregiver_link_id`, `patient_id`, `actor_id`, `event`, `details` jsonb, `ip`, `created_at` | trilha só de inclusão |

Eventos: `invite_created`, `invite_canceled`, `invite_accepted`, `approved`, `rejected`, `permissions_changed` (com `before`), `revoked`, `left`, `dose_marked` (remédio, horário, status), `dose_unmarked`, `medication_updated` (campos alterados). `CaregiverAuditEvent::describe()` gera a frase do histórico ("Ana marcou Losartana das 08:00 como tomado"). O paciente vê o histórico por vínculo (ativos e encerrados) em `/cuidadores` → "Ver o histórico". As marcações de quem cuida são gravadas em `medication_dose_events` com o `user_id` do paciente (a agenda dele não muda); quem marcou fica na auditoria.

Modelos/fábricas: `CaregiverLink` (estados `invited()`, `pending()`, `withPermissions()`), `CaregiverAuditEvent`.

## Rotas e telas (área do paciente, mesmas middlewares)

| Rota | Componente | O que faz |
| --- | --- | --- |
| `GET /cuidadores` (`consulta.cuidadores`) | `Consulta\Cuidado\QuemCuidaDeMim` | convite, pedidos pendentes (aprovar/recusar com permissões), pessoas que cuidam (permissões, histórico, tirar o acesso), acessos encerrados |
| `GET /cuidar` (`consulta.cuidar`) | `Consulta\Cuidado\CuidarDeAlguem` | digitar o código (`?codigo=` pré-preenche), pedidos aguardando, pessoas acompanhadas (o que foi liberado, aviso de consentimento), sair |
| `GET /cuidando/{vinculo}/hoje` (`consulta.cuidando.hoje`) | `Consulta\Tratamento\Hoje` (a mesma tela de "Remédios de hoje") | agenda do paciente; botões de marcar só com permissão |
| `GET /cuidando/{vinculo}/remedios` (`consulta.cuidando.remedios`) | `Consulta\Cuidado\Remedios` | lista de remédios do paciente e "Mudar dose e horários" (com permissão) |

**Troca de perfil e de quem são os dados**: o menu do avatar (`x-consulta.shell`) ganhou "Ver os remédios de: Eu / Cuidando de Fulano" (só vínculos com "ver" e consentimento), mais "Quem cuida de mim" e "Cuidar de alguém". Nas telas `/cuidando/...` o shell mostra, presa ao topo junto do cabeçalho, a faixa laranja "Você está vendo os remédios de Fulano" com "Voltar para os meus"; essas telas também exibem o menu do avatar. A Início lista "Quem você acompanha". O formulário "Dose e horários" foi extraído para `x-consulta.form-posologia` + trait `Concerns\EditaPosologia`, usados por Meus remédios e pela tela do cuidador (sem mudança visual em Meus remédios).

## Alerta de dose atrasada para quem cuida

`App\Services\Caregiving\CaregiverAlertService::run()` roda dentro do comando existente `treatment:send-reminders` (agendador, a cada 5 min; nada novo no `routes/console.php`). Para cada vínculo `ACTIVE` com `can_receive_alerts`, quem cuida com conta ativa e paciente com consentimento: monta a agenda do paciente **uma vez** (`TreatmentScheduleService::agenda()`, no fuso dele, com a programação dinâmica) e, para cada dose `late` sem marcação de remédio com lembrete ligado, que virou atrasada há no máximo `BULAI_CAREGIVER_ALERT_WINDOW_MINUTES` (padrão 120), envia `App\Notifications\CaregiverLateDoseNotification`:

- canais: `database` (sino/`/avisos`, com `SinoDeAvisos` anunciando ao navegador) + `WebPushChannel` quando há VAPID e aparelho inscrito — o mesmo caminho dos lembretes do paciente;
- deduplicação: `dedupe_key = cuidador:{vínculo}:{remédio}:{horário UTC}` (uma vez por vínculo e dose);
- dados: título "Remédio atrasado: Maria", frase "Losartana das 08:00 (1 comprimido) ainda não foi marcado por Maria.", `route = consulta.cuidando.hoje` + `route_params`. `Avisos::abrir()` e `GET /push/ultimo` passaram a usar `route_params`, então o toque no aviso/push abre os remédios do paciente certo (e a tela reconfere o vínculo).
- logo após a meia-noite também olha a agenda de ontem (dose das 23:30 que atrasa à 00:30).

A preferência "Receber lembretes" do paciente **não** desliga os avisos a quem cuida (são do vínculo); "Lembrar nos horários" desligado num remédio também o tira do alerta ao cuidador. O pedido de vínculo gera `CaregiverRequestNotification` ao paciente (sino + push, rota `consulta.cuidadores`).

Comando: `php artisan treatment:send-reminders [--now=ISO8601]` imprime também "Modo cuidador: N paciente(s) acompanhado(s), M aviso(s) de dose atrasada enviados a quem cuida.".

## Testes

`tests/Feature/Consulta/ModoCuidadorTest.php` (22 com o provedor de dados): código de 8 números, 48 h, uso único, link que pré-preenche, códigos errado/usado/vencido, convite novo cancela o anterior, código próprio; limite de 5 tentativas por pessoa; convite exige consentimento; só o paciente aprova (cuidador e estranho recebem 403) e escolhe permissões; recusar e editar permissões (marcar implica ver) com auditoria; matriz da `PatientMedicationPolicy` (status × permissões × consentimento, dono e estranho); cuidador com permissão marca dose e edita remédio com auditoria e o paciente vê o histórico; sem permissão de marcar → 403 em `tomei` e `editarPosologia`; sem "ver" → 403 nas rotas; revogar corta ação numa tela já aberta, sair do vínculo; sem consentimento → 403 e explicação; troca de perfil mostra os dados certos, a faixa de quem são os dados e o seletor; alerta de atraso só para quem tem a permissão, uma vez, fora da janela nada, abre a tela certa pelo aviso e pelo push; dose marcada não gera alerta.

Rodar: `php artisan test --compact tests/Feature/Consulta/ModoCuidadorTest.php`.

## Limitações e decisões

- Não há pedido iniciado pelo cuidador: exigiria localizar o paciente por e-mail/telefone (risco de enumeração de contas). O fluxo é sempre convite do paciente → código → aprovação.
- O código é numérico (fácil de digitar e ditar por telefone); a força vem do uso único, das 48 h e do limite por conta. Não há limite por IP além do login.
- Quem cuida não cadastra nem apaga remédios, receitas ou alergias, não vê perguntas/consultas do paciente e não usa Perguntar/receita por foto em nome dele.
- O aviso a quem cuida não vai por e-mail (só aplicativo e push).
- Acessar `/cuidando/{id}` de um vínculo alheio responde 403 (não 404); o id do vínculo não revela dados.
- Sem teste com idosos reais; textos e alvos de toque seguem o padrão do PWA.
