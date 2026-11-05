# 25. Programação do tratamento, avisos ao paciente e remédios fora do catálogo

Nota redigida em 05/11/2026 a partir do código, dos testes e das execuções locais. Complementa o capítulo 24 (contexto de tratamento) e o capítulo 21 (PWA). Seções do docx relacionadas: 3.3.2 (etapas 4 e 5), 3.4 (Fase 4), 3.5.3.

## 1. Problema

Até a iteração anterior o paciente conseguia registrar receitas e remédios em uso, mas:

1. a confirmação de uma receita fotografada **bloqueava** qualquer item que o catálogo ANVISA não reconhecesse (grafia diferente, produto novo, manipulado, fitoterápico). O paciente ficava sem registrar o tratamento inteiro;
2. a posologia era texto livre ("de 8 em 8 horas") sem se transformar em **horários**; não havia agenda nem como marcar o que foi tomado;
3. não havia **avisos**: nem lembrete de dose, nem aviso de fim do tratamento, nem retorno quando a administração corrigia um cadastro.

Para o público-alvo (pessoas idosas, muitas vezes com vários remédios ao dia), a ausência de uma agenda simples é o que mais pesa na adesão ao tratamento.

## 2. Decisões de projeto

| Decisão | Justificativa |
| --- | --- |
| **Nunca impedir o registro** de um remédio por ele não constar no catálogo. O item entra pelo nome como foi escrito (`patient_medications.name_as_written`, `catalog_status = unmatched`) e gera uma pendência para a administração (`unmatched_medicine_reports`). | O tratamento do paciente é a fonte de verdade; o catálogo é apoio. A pendência fecha o ciclo: o administrador liga o nome ao produto certo e, a partir daí, o remédio ganha bula simples e entra no cruzamento de interações. |
| **Horários derivados por regra, nunca por IA** (`DoseScheduleParser`). "8/8h", "2x ao dia", "pela manhã e à noite", "ao deitar", "se precisar" viram listas de `HH:MM` a partir de uma tabela de horários padrão (`config('bulai.treatment.default_times')`), sempre ajustáveis pelo paciente. | Coerente com a diretriz do produto: o sistema não decide posologia; só organiza o que o prescritor escreveu. Determinismo torna o comportamento auditável e testável (20 casos de teste). |
| **Fuso horário do paciente** guardado no perfil (`patient_profiles.timezone`, padrão `America/Sao_Paulo`); o servidor opera em UTC e cada horário programado é convertido no momento de montar a agenda. | Evita o erro clássico de agenda deslocada em 3 horas; permite pacientes em outros fusos do Brasil. |
| **Agenda calculada, não materializada**: `TreatmentScheduleService::agenda()` gera as linhas do dia a partir dos remédios ativos e cruza com `medication_dose_events` (o que foi marcado como "tomei"/"pulei"). | Sem tabelas de ocorrências para manter em sincronia quando o paciente muda horários ou encerra um remédio. A única escrita é a marcação do paciente. |
| **Avisos pelo canal `database` do Laravel**, com `dedupe_key` única por dose (`dose:{medicação}:{horário UTC}`), e-mail opcional, e notificação do navegador apenas com o aplicativo aberto. | Entrega garantida dentro do app sem dependências novas. Web Push real exige chaves VAPID e a biblioteca `minishlink/web-push`, cuja adoção foi deixada como decisão do projeto (ver limitações). |
| **Sênior-primeiro** na tela "Remédios de hoje": uma linha por horário, hora em destaque, dois botões empilhados ("Tomei"/"Pular"), "Desfazer" em um toque. | Mesmo contrato das ondas anteriores (`docs/design/principios-de-ux.md`): poucos gestos, letras grandes, uma decisão por tela. |

## 3. Modelo de dados (migrações `2026_11_05_114448` e `2026_11_05_114449`)

- `patient_medications`: `medicine_id` passa a ser **opcional**; novas colunas `name_as_written`, `catalog_status` (`matched` | `unmatched`), `dose_times` (JSON com `HH:MM`), `as_needed`, `reminders_enabled`.
- `unmatched_medicine_reports`: paciente, medicação, nome escrito e normalizado, posologia de contexto, `status` (`OPEN` | `RESOLVED` | `DISMISSED`), medicamento resolvido, quem resolveu, nota e data.
- `medication_dose_events`: medicação, `scheduled_for` (UTC), `status` (`TAKEN` | `SKIPPED`), `recorded_at`; única por (medicação, horário).
- `patient_profiles`: `timezone`, `reminders_enabled`, `reminders_email`.
- `notifications`: tabela padrão do Laravel com `dedupe_key` única.

## 4. Fluxos

### 4.1 Receita → tratamento (com e sem catálogo)

1. A foto é transcrita para JSON (capítulo 24, seção 7b) e cada item recebe candidatos do catálogo.
2. Na conferência, o paciente vê os horários sugeridos pelo parser e pode ajustá-los. Itens sem candidato aparecem com o selo "Não encontrado no catálogo: vamos conferir" e **não impedem** a confirmação.
3. `TreatmentScheduleService::register()` grava cada item: com `medicine_id` quando houver escolha; sem ele, pelo nome escrito, abrindo (ou reaproveitando) uma pendência `OPEN`.
4. O paciente é levado a "Remédios de hoje", já com a agenda montada.

### 4.2 Pendência do administrador

1. A tela "Remédios não encontrados" agrupa as pendências por nome normalizado (quantos pacientes, posologias de exemplo).
2. O administrador busca no catálogo e liga o nome ao produto. `TreatmentScheduleService::resolve()` atualiza **todas** as pendências do mesmo nome, funde com um registro já existente do mesmo paciente (preservando marcações) e fecha a pendência.
3. Cada paciente recebe o aviso "Seu remédio foi reconhecido". A partir daí a bula simples e o cruzamento de interações passam a valer para aquele remédio.

### 4.3 Agenda e lembretes

1. `agenda(usuário, dia)` monta as linhas do dia no fuso do paciente e classifica cada uma: `pending`, `due` (dentro da janela de aviso), `late` (passou de `late_after_minutes` sem marcação), `taken`, `skipped`.
2. O comando `treatment:send-reminders` roda a cada 5 minutos pelo agendador e envia um lembrete por dose dentro da janela, protegido pela `dedupe_key`.
3. No app, o sino do cabeçalho consulta os não lidos; com permissão concedida, o navegador mostra a notificação pelo service worker enquanto o app estiver aberto.

## 5. Evidências

- Testes da fundação: `tests/Unit/Services/Treatment/DoseScheduleParserTest.php` (20 casos de frequência) e `tests/Feature/Consulta/ProgramacaoDoTratamentoTest.php` (registro sem catálogo e pendência, resolução com fusão, agenda no fuso do paciente e marcações, privacidade).
- Testes das frentes: `RemediosDeHojeTest`, `ReceitaPorFotoTest` (atualizado: item sem catálogo é salvo e sinalizado), `AvisosTest`, `UnmatchedMedicinesAdminTest` (números na seção 7).
- Documentação técnica: `docs/tratamento.md`, `docs/notificacoes.md`, `docs/admin-remedios-nao-encontrados.md`.

## 6. Limitações e ameaças à validade

- O parser de frequência cobre as formas mais comuns em receitas brasileiras; expressões raras ("dias alternados", "semanal") ficam sem horário e são sinalizadas para o paciente definir à mão.
- Lembretes só chegam fora do app por e-mail (quando ativado). Notificação do navegador exige o app aberto; push real é trabalho futuro com dependência a aprovar.
- A resolução administrativa é manual: não há sugestão automática por similaridade além da busca do catálogo.
- Nenhum teste com pacientes idosos ou em dispositivos reais foi executado; a avaliação de usabilidade continua como protocolo (capítulo 22).

## 7. Números da iteração

| Medida | Valor |
| --- | --- |
| Casos do parser de frequência (`DoseScheduleParserTest`) | 20 |
| Testes da fundação do tratamento (`ProgramacaoDoTratamentoTest`) | 4 |
| Testes das frentes (`RemediosDeHojeTest` 3, `ReceitaPorFotoTest` 7, `AvisosTest` 8, `UnmatchedMedicinesAdminTest` 5) | 23 |
| Suíte completa em 05/11/2026 (sequencial, após a quinta onda) | 546 testes, 545 aprovados, 1 ignorado (chamada real à Anthropic, executada à parte), 8.111 asserções, ~56 s |
| Toques para marcar uma dose tomada (Início → Remédios de hoje → Tomei) | 2 |
| Toques da foto da receita ao programa de tratamento pronto | 4 (um item fora do catálogo não acrescenta toque) |
| Janela do lembrete | 10 min antes até 60 min depois do horário; agendador a cada 5 min |

A barra inferior do app passou a levar "Remédios" diretamente para "Remédios de hoje"; receitas e alergias ficam a um toque dali.
