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
| Início (`/inicio`) | Saudação + data por extenso; chip "N avisos novos"; **cartão do dia** (fundo azul-marinho): anel de progresso "tomadas/total", frase "N de M doses tomadas" ou "Tudo tomado por hoje!", próxima dose ("Próximo: 14:00 · Losartana", "Atrasado: 08:00 · …", "Amanhã: …") e botão **"Marcar como tomado"** quando a próxima está na janela (Agora/Atrasado; ação `tomei` do próprio componente, só do dono); depois 5 cartões com ícone em círculo: Perguntar (laranja), Remédios de hoje (apoio = próxima dose; vermelho se atrasada), Fotografar receita, Meus remédios, Ver o que já perguntei; cartão `<x-consulta.instalar />` | Perguntar / Marcar como tomado |
| Remédios de hoje (`/hoje`, `Consulta\Tratamento\Hoje`) | Segmentos Ontem/Hoje/Amanhã (`irPara(dia)`), data por extenso; resumo com anel "tomadas" e "N de M horários marcados"; banner vermelho se há atrasados; grupos Manhã/Tarde/Noite (ícones sol/relógio/lua) em **linha do tempo vertical** (bolinha cinza = a tomar, laranja pulsando = agora, vermelha = atrasado, verde = tomei, cinza + nome riscado = pulei); por horário: hora em caixa, nome, dose, selo de estado (cor + texto), botões "Tomei" (verde) e "Pular" lado a lado com `flex-wrap`, "Desfazer" discreto quando marcado; seção "Só quando precisar" com os remédios `as_needed`; `wire:poll.60s` só na lista; estado vazio ilustrado com "Adicionar remédio", "Fotografar receita" e "Meus remédios" | Tomei |
| Meus remédios (`/perfil`) | Segmentos "Em uso · Receitas · Alergias" (Alpine, sem recarregar; `?aba=`); cartão por remédio com ícone, nome, posologia, horários como chips, selos "Sem horário fixo"/"Sem bula no catálogo"/"Tratamento encerrado"; botão "⋯" abre a **folha de ações** (`x-consulta.folha-acoes`): Ver a bula simples (só com catálogo), Informar dose e horários, Tirar da minha lista (com confirmação dentro da folha); formulário de posologia inline (dose, quando tomar → horários sugeridos, horários `type=time`, "Só quando precisar", "Lembrar nos horários") com "Mais detalhes" recolhido (datas, receita, observações); busca com "Você quis dizer…?" e "Não achei meu remédio: cadastrar assim mesmo" | Adicionar remédio |
| Conferir a receita (`/receitas/{id}/revisar`) | por item: candidato do catálogo ou selo "Não encontrado…", horários sugeridos editáveis, correção de dose/frequência | Confirmar e salvar |

Contexto de tratamento nas respostas (`TreatmentContextService`): remédios sem catálogo entram em `unresolved` como "Nome (sem bula cadastrada no BULA-IA)" e contam em `medications_count`; a IA recebe o fato "Não foi possível verificar interações com: …" e a tela mostra o mesmo. `Pesquisa` (verificar interações) e o detector de menções consideram só remédios ligados ao catálogo (`scopeMatched`).

## Toques por tarefa (a partir da Início)
| Tarefa | Toques |
| --- | --- |
| Marcar que tomou o remédio | Início → Marcar como tomado (1, quando a próxima dose está na janela) ou Remédios de hoje → Tomei (2) |
| Pular um horário | Remédios de hoje → Pular (2) |
| Desfazer | +1 |
| Ver o que tomar amanhã | Remédios de hoje → Amanhã (2) |
| Receita por foto até o programa pronto | Fotografar → Tirar foto → Ler esta receita → Confirmar (4; item fora do catálogo não acrescenta toque) |
| Cadastrar remédio que não está no catálogo | Meus remédios → Adicionar remédio → digitar → Cadastrar assim mesmo (4) → Salvar posologia (+1) |
| Ajustar um horário | Meus remédios → ⋯ → Informar dose e horários → mudar a hora → Salvar (5) |

## Programação dinâmica por intervalo (06/11/2026)

**Regra.** Quando a frequência é por intervalo ("de 8 em 8 horas", "8/8h", "a cada 6 horas"), o remédio guarda `interval_minutes` e entra no modo `schedule_mode = interval`: os horários **não são fixos**. A agenda projeta a cadeia a partir do horário **real** em que a última dose foi marcada como "Tomei" (`medication_dose_events.recorded_at`), somando o intervalo. O paciente pode trocar para `fixed` (horários iguais todos os dias) em Meus remédios ou ao revisar a receita; frequências por contagem ("2x ao dia", "pela manhã e à noite") continuam fixas.

**Exemplo** (de 8 em 8 horas, começa em 05/11, primeiro horário 06:00):

| O que aconteceu | Agenda de 05/11 | Agenda de 06/11 |
| --- | --- | --- |
| Nada marcado | 06:00 · 14:00 · 22:00 | 06:00 · 14:00 · 22:00 |
| "Tomei" a das 06:00 às **09:30** | 06:00 (tomou às 09:30) · **17:30** (ajustado) | 01:30 · 09:30 · 17:30 |
| "Pular" a das 17:30 | 06:00 · 17:30 (pulei) | 01:30 · 09:30 · 17:30 (pular não desloca) |
| "Tomei" a das 01:30 de 06/11 às **03:00** | — | 01:30 (tomou às 03:00) · **11:00** · **19:00** |
| "Desfazer" a marcação das 01:30 | — | 01:30 · 09:30 · 17:30 |

- **Tomei** desloca a cadeia: a próxima dose é `recorded_at + intervalo`. **Pular** não desloca (a seguinte é contada do horário programado que foi pulado). **Desfazer** devolve a projeção anterior.
- Doses esquecidas antes de uma marcação continuam visíveis como atrasadas; a cadeia só é recontada **depois** da marcação.
- Antes da primeira marcação a cadeia nasce no primeiro horário de `dose_times` do dia de início (ou 08:00) e avança em intervalos; por isso, no modo dinâmico, só o primeiro horário é guardado.
- Cada linha de `agenda()` traz `dynamic` (bool) e `taken_at` (horário real da marcação, no fuso do paciente). `mark()` aceita `recordedAt` para testes.
- **Telas**: Remédios de hoje mostra "a cada 8 h", "Tomou às 09:30" quando difere do programado e o selo "Ajustado" (com "contado a partir das 09:30, quando você tomou a anterior") nas doses recontadas; a Início acrescenta "· ajustado" à próxima dose; Meus remédios e Revisar receita oferecem os cartões "Contar a partir de quando eu tomar (recomendado)" e "Horários fixos todos os dias", e no modo dinâmico só pedem o "Primeiro horário do dia".
- **Lembretes** (`treatment:send-reminders`) usam a mesma `agenda()`, portanto seguem a programação recalculada; a `dedupe_key` leva o horário projetado.
- **Limitações**: a cadeia é recalculada por remédio, sem considerar outros remédios; intervalos que não dividem 24 h ("de 5 em 5 horas") deslocam de dia para dia por desenho; não há limite de doses por dia além do que o intervalo permite; o paciente não edita o intervalo em horas na tela (vem da frequência escrita).

## Testes
`tests/Unit/Services/Treatment/DoseScheduleParserTest` (20), `tests/Feature/Consulta/ProgramacaoDoTratamentoTest` (4: registro sem catálogo e pendência, resolução/fusão pelo admin, agenda no fuso do paciente, privacidade), `tests/Feature/Consulta/ReceitaPorFotoTest` (7: confirmação com item fora do catálogo, manter pelo nome, pular), `tests/Feature/Consulta/RemediosDeHojeTest` (3: agenda por período e marcações, privacidade/vazios/Início com cartão do dia e "Marcar como tomado", cadastro pelo nome no perfil e contexto). Rodar: `php artisan test --compact tests/Feature/Consulta tests/Unit/Services/Treatment`.

## Limitações
- Sem teste com usuários idosos nem em aparelhos reais; o relógio do estado "Agora/Atrasado" é o do servidor convertido ao fuso do perfil (o paciente não edita o fuso pela interface ainda).
- Frequências semanais, dias alternados ou "conforme orientação" não geram horários: o paciente define à mão.
- Horários de "Remédios de hoje" só existem para remédios com `dose_times`; remédio "só quando precisar" aparece numa seção à parte, sem marcação de dose.
- A agenda não considera a hora exata de `started_at` (dia inteiro) nem pausas de tratamento.
