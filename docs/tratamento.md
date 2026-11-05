# Programação do tratamento (app do paciente)

## Propósito
Transformar a receita (fotografada ou cadastrada à mão) em um **programa de tratamento** que o paciente consegue seguir: cada remédio com dose, frequência e horários do dia; a tela "Remédios de hoje" com "Tomei"/"Pular" em um toque; e o registro do que foi marcado. Remédio que o catálogo ANVISA não reconhece **não bloqueia** o cadastro: entra pelo nome escrito, sinalizado para o paciente, e vira pendência para os administradores ligarem ao catálogo (`docs/admin-remedios-nao-encontrados.md`). Lembretes e avisos: `docs/notificacoes.md`.

Diretriz mantida: o app programa e lembra; **não decide posologia**. Horários sugeridos vêm de regras determinísticas sobre o texto da receita e o paciente sempre pode ajustá-los.

## Tabelas e colunas (migração `2026_11_05_114448_create_treatment_schedule_tables`)
| Tabela | Colunas novas | Uso |
| --- | --- | --- |
| `patient_medications` | `medicine_id` **nulo** permitido; `name_as_written` (120); `catalog_status` (`matched`/`unmatched`, índice); `dose_times` jsonb (lista "HH:MM"); `as_needed` bool; `reminders_enabled` bool (padrão true) | remédio em uso, com ou sem catálogo; unicidade `(user_id, medicine_id)` continua (vários nulos são permitidos no PostgreSQL); sem catálogo a unicidade é por `(user_id, name_as_written)` garantida no serviço |
| `unmatched_medicine_reports` | `user_id`, `patient_medication_id`, `name_as_written`, `normalized_name`, `dosage`, `frequency`, `status` (`OPEN`/`RESOLVED`/`DISMISSED`), `resolved_medicine_id`, `resolved_by`, `resolution_note`, `resolved_at` | pendência dos administradores; cascata ao apagar o remédio ou o usuário |
| `medication_dose_events` | `user_id`, `patient_medication_id`, `scheduled_for` (UTC), `status` (`TAKEN`/`SKIPPED`), `recorded_at`; único `(patient_medication_id, scheduled_for)` | o que o paciente marcou em cada horário |
| `patient_profiles` | `timezone` (padrão `America/Sao_Paulo`), `reminders_enabled`, `reminders_email` | fuso dos horários e preferências de lembrete |

Modelos: `PatientMedication` (`isMatched()`, `displayName()`, `scheduledTimes()`, `scopeMatched()`, `doseEvents()`, `unmatchedReport()`), `UnmatchedMedicineReport` (`scopeOpen()`), `MedicationDoseEvent`, `PatientProfile`, `User::treatmentTimezone()`.

## Parser de horários (`App\Services\Treatment\DoseScheduleParser`)
Determinístico, sem IA. Entrada: texto de "quando tomar"; saída: `{times: [...], as_needed: bool, rule}` ou `null` (não programável).

| Texto da receita | Horários sugeridos | Regra |
| --- | --- | --- |
| "de 8 em 8 horas", "8/8h" | 06:00, 14:00, 22:00 | intervalo que divide o dia → tabela `config('bulai.treatment.default_times')` |
| "12/12 horas", "2x ao dia", "duas vezes ao dia" | 08:00, 20:00 | idem |
| "a cada 6 horas" | 00:00, 06:00, 12:00, 18:00 | idem |
| "a cada 5 horas" | 04:00, 08:00, 13:00, 18:00, 23:00 | intervalo que não divide o dia → passos a partir das 08:00 |
| "1x ao dia", "diariamente" | 08:00 | contagem |
| "pela manhã e à noite" | 08:00, 20:00 | períodos citados (jejum 06:00, manhã 08:00, almoço 12:00, tarde 16:00, noite 20:00, ao deitar 22:00) |
| "uma vez ao dia, à noite" | 20:00 | contagem 1 + período |
| "se necessário", "SOS" | nenhum, `as_needed = true` | sem horário fixo |
| "1x por semana", "conforme orientação" | `null` | não programável; o paciente define à mão |

`defaultTimes(n)`: tabela para 1, 2, 3, 4 e 6 doses; outros valores distribuem uniformemente a partir das 08:00.

## Serviço (`App\Services\Treatment\TreatmentScheduleService`)
- `register(User, dados)`: cria/atualiza o remédio. Com `medicine_id` válido → `updateOrCreate` por `(user_id, medicine_id)` e fecha pendências abertas daquele registro. Sem `medicine_id` → procura pelo `name_as_written` do mesmo usuário (atualiza em vez de duplicar), grava `catalog_status = unmatched` e abre (ou mantém) uma `UnmatchedMedicineReport` `OPEN`. Horários: os informados em `dose_times` (limpos por `cleanTimes()`, até 12) ou os sugeridos pelo parser; `ends_at` vem de `ends_at` ou de `duration` ("7 dias", "2 semanas", "1 mês").
- `resolve(report, medicine, admin, nota)`: liga ao catálogo; se o paciente já tinha aquele medicamento do catálogo, funde (posologia/horários/eventos migram, duplicado é apagado).
- `agenda(User, dia, agora)`: uma linha por horário de cada remédio ativo no dia (`started_at ≤ dia ≤ ends_at`), no fuso do paciente, com estado `pending` / `due` (a partir de `reminder_lead_minutes` antes) / `late` (após `late_after_minutes`) / `taken` / `skipped`. `scheduled_for` é gravado em UTC.
- `nextDose(User)`: próxima dose sem marcação (hoje ou amanhã). `mark()`/`unmark()`: idempotentes; 404 se o remédio não for do usuário.

Config `config('bulai.treatment')`: `default_timezone`, `default_times`, `reminder_lead_minutes` (10), `late_after_minutes` (60), `reminder_queue`.

## Fluxo receita → tratamento
1. Foto lida (`PrescriptionExtractionService`) → `Receita\Revisar` carrega os itens já com **horários sugeridos** (`DoseScheduleParser`) e com o primeiro candidato do catálogo, se houver.
2. O paciente confere: pode trocar o remédio do catálogo, **"Nenhum destes: manter o nome escrito"** (`manterPeloNome`), corrigir dose/frequência (os horários são refeitos ao mudar "quando tomar"), tirar/adicionar horários, ou "Não cadastrar este".
3. **Confirmar nunca é bloqueado por item sem catálogo.** Cada item ativo passa por `register()`; os sem catálogo entram com o selo "Não encontrado no catálogo: vamos conferir" e abrem a pendência. A receita guarda `confirmed_items`, `skipped_items` e `unmatched_items`.
4. Redireciona para `/hoje` com a mensagem de quantos entraram e quantos ainda serão conferidos pela equipe.

Cadastro à mão (`Perfil`): a busca que não encontra mostra "Não achei meu remédio: cadastrar assim mesmo" (`cadastrarPeloNome`) → `register()` sem catálogo → abre a posologia para dose/horários.

## Telas
| Tela | O que mostra | Ação primária |
| --- | --- | --- |
| Início (`/inicio`) | 5 botões: Perguntar, **Remédios de hoje** (apoio = próxima dose: "Próximo: 14:00 · Losartana", "Atrasado: 08:00 · …" em vermelho, "Amanhã: …" ou "Veja os horários…"), Fotografar receita, Meus remédios, Ver o que já perguntei | Perguntar |
| Remédios de hoje (`/hoje`, `Consulta\Tratamento\Hoje`) | Ontem/Hoje/Amanhã; "N de M horários marcados"; grupos Manhã/Tarde/Noite; por horário: hora em 30 px, nome, dose, selo de estado (A tomar / Agora / Atrasado / Tomei / Pulei, cor + texto); botões empilhados "✓ Tomei" (verde, 64 px) e "Pular este horário"; "Desfazer" quando marcado; `wire:poll.60s` só na lista; estado vazio com Fotografar receita e Meus remédios | Tomei |
| Meus remédios (`/perfil`) | lista com `displayName()`, selo "Não encontrado no catálogo: vamos conferir", "às 08:00, 20:00" ou "só quando precisar"; "Ver a bula simples" só com catálogo; posologia com horários (`type=time`, Tirar/Adicionar), "Só quando precisar", "Lembrar nos horários"; botão "Ver remédios de hoje" | Adicionar remédio |
| Conferir a receita (`/receitas/{id}/revisar`) | por item: candidato do catálogo ou selo "Não encontrado…", horários sugeridos editáveis, correção de dose/frequência | Confirmar e salvar |

Contexto de tratamento nas respostas (`TreatmentContextService`): remédios sem catálogo entram em `unresolved` como "Nome (sem bula cadastrada no BULA-IA)" e contam em `medications_count`; a IA recebe o fato "Não foi possível verificar interações com: …" e a tela mostra o mesmo. `Pesquisa` (verificar interações) e o detector de menções consideram só remédios ligados ao catálogo (`scopeMatched`).

## Toques por tarefa (a partir da Início)
| Tarefa | Toques |
| --- | --- |
| Marcar que tomou o remédio | Remédios de hoje → Tomei (2) |
| Pular um horário | Remédios de hoje → Pular (2) |
| Desfazer | +1 |
| Ver o que tomar amanhã | Remédios de hoje → Amanhã (2) |
| Receita por foto até o programa pronto | Fotografar → Tirar foto → Ler esta receita → Confirmar (4; item fora do catálogo não acrescenta toque) |
| Cadastrar remédio que não está no catálogo | Meus remédios → Adicionar remédio → digitar → Cadastrar assim mesmo (4) → Salvar posologia (+1) |
| Ajustar um horário | Meus remédios → Opções → Informar dose e horários → mudar a hora → Salvar (5) |

## Testes
`tests/Unit/Services/Treatment/DoseScheduleParserTest` (20), `tests/Feature/Consulta/ProgramacaoDoTratamentoTest` (4: registro sem catálogo e pendência, resolução/fusão pelo admin, agenda no fuso do paciente, privacidade), `tests/Feature/Consulta/ReceitaPorFotoTest` (7: confirmação com item fora do catálogo, manter pelo nome, pular), `tests/Feature/Consulta/RemediosDeHojeTest` (3: agenda por período e marcações, privacidade/vazios/Início, cadastro pelo nome no perfil e contexto). Rodar: `php artisan test --compact tests/Feature/Consulta tests/Unit/Services/Treatment`.

## Limitações
- Sem teste com usuários idosos nem em aparelhos reais; o relógio do estado "Agora/Atrasado" é o do servidor convertido ao fuso do perfil (o paciente não edita o fuso pela interface ainda).
- Frequências semanais, dias alternados ou "conforme orientação" não geram horários: o paciente define à mão.
- Horários de "Remédios de hoje" só existem para remédios com `dose_times`; remédio "só quando precisar" não aparece na agenda.
- A agenda não considera a hora exata de `started_at` (dia inteiro) nem pausas de tratamento.
