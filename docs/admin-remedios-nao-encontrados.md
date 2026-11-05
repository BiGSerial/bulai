# Remédios não encontrados (pendência da administração)

## Propósito

O paciente pode registrar um remédio da receita mesmo quando o catálogo ANVISA não o reconhece (`patient_medications.medicine_id` nulo, `catalog_status = unmatched`, nome em `name_as_written`). O remédio aparece no tratamento dele normalmente (horários, lembretes), mas sem bula simples nem cruzamento de interações. Cada cadastro assim abre uma pendência em `unmatched_medicine_reports` para os administradores ligarem o nome ao medicamento certo do catálogo. Ao ligar, o remédio do paciente passa a ter `medicine_id`, e o paciente recebe um aviso.

## Fluxo

1. **Paciente** confirma uma receita por foto ou cadastra um remédio pelo nome → `App\Services\Treatment\TreatmentScheduleService::register()` grava o remédio sem `medicine_id` e abre (ou reaproveita) uma pendência `OPEN` com `normalized_name` (`AnvisaCatalogNormalizer::text`).
2. **Administração** vê a contagem em "Precisa de atenção" na visão geral e no menu "Remédios não encontrados" (`/admin/knowledge/unmatched-medicines`).
3. **Ligar ao catálogo**: o admin busca no catálogo e escolhe o medicamento. `TreatmentScheduleService::resolve()` roda para **todas** as pendências abertas com o mesmo `normalized_name` (vários pacientes de uma vez): liga o remédio, funde com um registro do catálogo que o paciente já tivesse (posologia, horários e marcações migram; o duplicado some) e fecha a pendência (`RESOLVED`, `resolved_medicine_id`, `resolved_by`, `resolution_note`). Cada paciente recebe `App\Notifications\UnmatchedMedicineResolvedNotification` (canal `database`), uma por remédio.
4. **Descartar**: fecha as pendências do nome como `DISMISSED` (com `resolved_by` e nota padrão). O remédio continua no tratamento do paciente só pelo nome. Pela situação "Descartadas" dá para revisar; ligar depois exige reabrir pelo banco (sem tela para isso ainda).

## Tabela `unmatched_medicine_reports`

| Coluna | Uso |
| --- | --- |
| `user_id`, `patient_medication_id` | paciente e remédio (apagados em cascata com eles) |
| `name_as_written`, `normalized_name` | nome como o paciente escreveu e forma normalizada usada no agrupamento |
| `dosage`, `frequency` | contexto para o admin reconhecer o produto |
| `status` | `OPEN`, `RESOLVED`, `DISMISSED` |
| `resolved_medicine_id`, `resolved_by`, `resolution_note`, `resolved_at` | desfecho |

Migração: `2026_11_05_114448_create_treatment_schedule_tables.php`. Modelo: `App\Models\UnmatchedMedicineReport` (scope `open()`).

## Tela (`App\Livewire\Admin\Knowledge\UnmatchedMedicines\Index`)

- Filtros em uma linha: busca por nome (normalizada) e situação (Abertas · Ligadas ao catálogo · Descartadas), com contagem de nomes e total de pendências abertas.
- **Uma linha por nome** (`ops-row`): primeiro nome escrito, apoio "N pacientes · desde dd/mm/aaaa · até duas posologias de exemplo", um selo (`Sem catálogo` / `Ligado a X` / `Descartado`) e, nas abertas, a ação primária **Ligar ao catálogo** e **Descartar** (discreto, com `confirm-modal`).
- Painel inline ao ligar: busca no catálogo (`MedicationSearchService`, pré-preenchida com o nome sem concentração/forma, ex.: "amoxil 500" → "amoxil"), candidatos com princípio ativo, detentor e selo de identificação, observação opcional e botão **Ligar** por candidato. Toast (`notify`) ao concluir.
- Nas situações resolvida/descartada a linha mostra quem decidiu e quando.
- Defesa em profundidade: `boot()` exige administrador além do middleware da rota.

## Visão geral e menu

- `OverviewService::attention()`: "remédios de pacientes sem correspondência no catálogo" (total de pendências abertas, tom `warning`, link para a tela). Só aparece quando > 0.
- `AdminNavigation`: item "Remédios não encontrados" logo após "Pendências" (ícone `flag`).

## Testes

`tests/Feature/Admin/UnmatchedMedicinesAdminTest.php` (5 testes): acesso só para admin e posição no menu; agrupamento por nome com contagem de pacientes, posologias e busca; ligar resolve todas as pendências do nome, funde duplicado, registra admin/nota e avisa cada paciente (sem avisar quem não foi afetado); recusa medicamento inexistente e descarte mantém o remédio pelo nome; visão geral mostra a atenção. Rode com `php artisan test --compact tests/Feature/Admin/UnmatchedMedicinesAdminTest.php`. O serviço de resolução é coberto em `tests/Feature/Consulta/ProgramacaoDoTratamentoTest.php`.

## Limitações

- O agrupamento é pelo nome normalizado exato ("amoxil 500" e "amoxil 500mg" são linhas diferentes); não há sugestão automática do catálogo na lista, só a busca pré-preenchida no painel.
- Não há reabertura de pendência descartada pela interface.
- Nenhum relatório inclui as pendências (o gerador de relatórios tem tipos fixos; adicionar exigiria um tipo novo).
- Aviso ao paciente só no canal `database` (sino/tela de avisos do app); e-mail fica a cargo das preferências de lembrete.
