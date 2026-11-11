# Reavaliação pós-correções em produção, 11/11/2026 — RELATÓRIO PARCIAL (bloqueado)

| | |
|---|---|
| Execução | 11/11/2026, a partir das 13:50 (UTC−3) |
| Ambiente | Produção, `https://bulai.vixforge.com.br` (`app_env=production`, recuperação `hybrid`, texto `anthropic`, embeddings `openai_compatible`), via token `api:evaluate` (id 3, conta de avaliação id 8) |
| Código | `main` = `14e61bc`. **Implantado em produção: `947ea4e` (PR #59), informado pelo responsável** (a API não expõe o SHA). A correção dos duplicados (PR #60, `9ffc20b`) **não está implantada**. |
| Status | **A correção da PR #60 não foi testada em produção, porque ainda não foi implantada.** A sondagem mede o estado da PR #59: o defeito persiste, como esperado sem a #60. |

## 1. Auditoria inicial

Fonte: `GET /api/v1/stats/knowledge` às 13:50:05 (comparação com 02:57 do relatório de 11/11 anterior).

| Medida | Anterior (02:57) | Agora (13:50) |
|---|---|---|
| Bulas prontas / total | 82 | 91 (1 `FAILED`; 92 no total) |
| Trechos / com embedding | 5.652 / 5.652 | 6.699 / 6.699 |
| Princípios ativos (total) / com bula | n/d / 57 | 1.851 / 62 |
| Catálogo ANVISA: registros / identificados | 39.416 / 25.596 | 39.416 / 25.682 |
| Interações DDInter / ligadas | 234.981 / 234.333 | 234.981 / 234.333 |
| Interações entre dois com bula (graves) | 613 | 733 (39 graves) |
| Bulas dos EUA prontas | 26 | 64 |

A base mudou (9 bulas e 38 bulas FDA a mais): **qualquer comparação com a rodada anterior deixa de ser controlada**.

**Consolidação dos duplicados: não aplicada (o código que a faz, PR #60, não está implantado).** O inventário atual de produção ainda lista como princípios ativos separados, com bula: `Ibuprofeno` (bulas 20, 26, 27), `Besilato De Anlodipino` (28, 33, 68, 71), `Losartana Potássica` (5, 25, ao lado de `Losartan`), além de `Dipirona` e `Dipirona Monoidratada`. A PR #60 depende de `knowledge:build-ingredients` para fundi-los. Não há como saber, pela API, se a migração/consolidação foi executada; o estado dos dados indica que não.

Datasets originais preservados (SHA-256 do repositório = SHA-256 gravado nos registros de produção):

| Arquivo | SHA-256 |
|---|---|
| `interactions.v2.json` | `62d2ed86ce1a058e4d8b2ad83d3dcf3e614838b7abf1b1ce4a24b9d8b7905ba4` |
| `interactions.protocol30.v2.json` | `fa993eeddb401a1e8ce3ccf159ddf4a7aeb12b4f4a1f65386c16fd108398de70` |
| `identification.v2.json` (ajuste) | `a5f54b221e1aea631479cbc390c898bd431ca9692a2e97573bfa286ef8edf546` |
| `identification.heldout.json` (reservado) | `d3519d0ae8b078120ef18e6aa5fc7d99861fe6f016228733a4b6af4552bc9331` |
| `retrieval.subjects.v1.json` | `5e2b54ee712c66178c32cacbeb39cba4122356344bf1a1065df6efe4f8766f69` |

Os registros `evaluation:run` em produção (`GET /api/v1/audit/evaluations`) são todos de 11/11 às 08:20–08:38, **anteriores à PR #60 (11:06)**. Nenhum deles mede o estado pós-correção.

## 2. Testes automatizados (ambiente local, PostgreSQL local; NÃO equivalem a produção)

`php artisan test tests/Feature/Knowledge tests/Feature/Evaluation tests/Feature/Api`: **201 testes, 198 aprovados, 3 falhos, 0 ignorados** (1.111 asserções).

Os 3 falhos são testes de página Blade (`DrugInteractionImportTest::test_page_is_admin_only_and_shows_totals`, `KnowledgePendingReviewFlowTest::test_navigation_exposes_the_anvisa_catalog_page_and_explains_each_item`, `KnowledgeProcessingTimelineTest::test_document_page_shows_the_timeline_and_refreshes_while_a_step_is_running`) que quebram por `ViteManifestNotFoundException` (assets não compilados aqui). Não envolvem lógica; falta rodá-los após `npm run build`. Os testes de `ActiveIngredientCatalogTest` (inclui fusão do duplicado e idempotência) e `EvaluationPipelineTest` passaram. As demais suítes (`tests/Unit`, restante de `tests/Feature`) não foram executadas nesta rodada.

## 3. Interações (experimento prioritário) — NÃO reexecutado

`evaluation:run interactions` roda no servidor (Artisan); não há acesso a ele por token de API, e reconstruir o catálogo em produção exige autorização, análise de impacto e backup. **Matriz de confusão, precisão, recall, F1 e IC 95% pós-correção: não medidos.** Valores anteriores permanecem como a última medição (TP 1.319, TN 58, FP 0, FN 81, abstenções 25, recall 94,2%).

Sondagem pelo fluxo real (`POST /evaluation/ask`, 6 perguntas, 13:5x; respostas em `avaliacao-producao-pos-correcoes-2026-11-11.sonda-interacoes.json`). Isto é uma sondagem qualitativa de 6 pares, não substitui o conjunto de 1.483:

| Par (grave no DDInter) | Status | Resultado |
|---|---|---|
| Ibuprofeno + varfarina | answered | "A base de interações consultada não registra interação" — **defeito persiste** |
| Ibuprofeno + ácido acetilsalicílico | answered | "não registra interação entre esses dois remédios" — **defeito persiste** |
| Ibuprofeno + rivaroxabana | answered | "não registra interação" — **defeito persiste** (antes era abstenção) |
| Anlodipino + carbamazepina | answered | "Não há orientação específica sobre tomar anlodipino junto com carbamazepina" — sem a interação grave |
| Anlodipino + fenobarbital | no_evidence | sem a interação grave |
| Anlodipino + itraconazol | needs_info | "o anlodipino não aparece na lista de exemplos" — sem a interação grave |

Em nenhum dos 6 pares a gravidade da fonte foi apresentada. Os 3 casos de ibuprofeno seguem exatamente o modo de falha descrito (ausência de interação registrada por erro de vínculo). **Critério de segurança não atendido; a correção não pode ser declarada validada.**

Causa: produção roda `947ea4e` (PR #59), sem o código da PR #60. Os dados ainda têm `Ibuprofeno`/`Besilato De Anlodipino` separados dos canônicos do DDInter. Mesmo após o deploy da #60, a fusão só ocorre com `knowledge:build-ingredients`.

## 4–8. Não executados nesta rodada

| Experimento | Situação | Impedimento |
|---|---|---|
| 4. Identificação (ajuste e reservado) | Não executado | `evaluation:run` exige shell no servidor |
| 5. Recuperação (369 consultas) | Não executado | idem |
| 6. Bateria de 234 perguntas | Não executada | Aguardando decisão: rodá-la antes da consolidação mede o estado "com defeito" e consome ~US$ de IA e ~42 min; recomendo rodar depois da consolidação |
| 7. Comparação de modelos | Não executada | `evaluation:run generation --provider` exige servidor |
| 8. Legibilidade | Medida (só leitura) | `GET /audit/readability?limit=1000`, 13:50: 272 pares; mediana resposta 58,3, trechos 29,3; resposta mais fácil em 98,2% (IC 95,8–99,2); faixas das respostas: fácil 186, difícil 61, muito fácil 21, muito difícil 4. População maior que a anterior (269 pares), sem mudança metodológica; resultado praticamente igual |

## 9. Comparação consolidada (parcial)

| Métrica | Antes | Depois | Interpretação |
|---|---|---|---|
| Identificação correta (reservado) | 93,6% | não medido | sem acesso ao `evaluation:run` |
| Falsos negativos | 81 | não medido | idem |
| Interações graves sem alerta | 7 | **≥ 3 persistem** (sondagem de 6 pares; não é a medida do conjunto) | Defeito não corrigido nos dados de produção |
| Hit@3 híbrido | 40,1% | não medido | |
| Afirmações aceitas | 55,6% | não medido | |
| Respostas mais legíveis | 98,1% | 98,2% (272 pares) | Estável; legibilidade ≠ compreensão ou segurança |

## 10. Pendências e plano proposto (aguardando autorização)

1. Implantar `main` (PR #60 e #61) em produção, com autorização.
2. Confirmar o backup recuperável e fazer a análise de impacto de `php artisan knowledge:build-ingredients` (primeiro com o modo de simulação, se existir; ele funde `Ibuprofeno`, `Besilato De Anlodipino`, `Losartana Potássica`, etc.). **Não foi executado.**
3. Após a consolidação: `evaluation:run interactions` (1.483 pares e protocolo de 30), identificação (ajuste/reservado), recuperação (369 consultas) e geração, com os mesmos datasets; depois a bateria de 234 perguntas com `scripts/evaluation/bateria.py`.
4. Rodar os 3 testes de página após `npm run build`.

## Recomendações para o TCC

Não substituir nenhum número do TCC por resultados desta rodada: não há medição pós-correção. Manter o resultado histórico (7 interações graves sem alerta; 81 falsos negativos) e, se a consolidação não for aplicada a tempo, registrar na Discussão que a correção existe no código (PR #60, testes locais) mas não foi verificada em produção, com a sondagem de 6 pares como evidência de que o defeito persiste nos dados.

## Comandos executados

`git pull origin main`; `GET /api/v1/{me,stats/knowledge,audit/tcc,audit/evaluations,audit/quality,audit/readability?limit=1000}`; 6× `POST /api/v1/evaluation/ask`; `composer install`; `php artisan test --compact tests/Feature/Knowledge tests/Feature/Evaluation tests/Feature/Api`. Nenhuma alteração em produção além das 6 perguntas de avaliação (gravadas na conta de avaliação id 8).

## Atualização: após deploy de `14e61bc` e `knowledge:build-ingredients` (11/11/2026, ~15:10–15:25)

Informado pelo responsável: deploy de `14e61bcbc26979f859dd4a58cded5350288e35ac` e `knowledge:build-ingredients` executados (a saída do comando não foi fornecida). Mudanças verificadas no inventário (`GET /stats/knowledge`, 15:09):

| Medida | Antes da consolidação (13:50) | Depois (15:09) |
|---|---|---|
| Princípios ativos (total) / com bula | 1.851 / 62 | 1.846 / 60 |
| Ativos com bula (duplicados) | `Ibuprofeno`, `Besilato De Anlodipino`, `Losartana Potássica`, `Dipirona Monoidratada` | `Ibuprofen`, `Amlodipine`, `Losartan` (canônicos do DDInter); `Dipirona` única |
| Interações entre dois ativos com bula / graves | 733 / 39 | 814 / 44 |
| Interações que envolvem ativo com bula | 25.688 | 27.003 |

A consolidação ocorreu para os 4 casos citados. Remanescem nomes em português sem canônico no DDInter (`Etexilato De Dabigatrana`, `Latanaprosta`, `Gliclazida`, `Ciprofibrato`…), que não são duplicados.

Sondagem repetida (mesmas 6 perguntas; `...sonda-interacoes-pos-consolidacao.json`). **Fluxo "Perguntar" livre, não o `InteractionEvaluator`; não substitui o conjunto de 1.483 pares.**

| Par | Antes | Depois |
|---|---|---|
| Ibuprofeno + varfarina | "não registra interação" | Alerta de risco de sangramento, citando a bula dos EUA da varfarina e a bula do ibuprofeno. **Não cita o registro do DDInter nem a gravidade.** |
| Ibuprofeno + AAS | "não registra interação" | Risco de úlcera e sangramento; **sem gravidade da fonte** |
| Ibuprofeno + rivaroxabana | "não registra interação" | **"Existe registro de interação de gravidade maior"** (DDInter) |
| Anlodipino + carbamazepina | sem a interação | **Persiste**: "não há orientação específica sobre esse par" |
| Anlodipino + fenobarbital | `no_evidence` | **Persiste** |
| Anlodipino + itraconazol | `needs_info` | **Persiste** |

Leitura: o erro de "ausência de interação registrada" para o ibuprofeno deixou de aparecer nessas 3 respostas, e a rivaroxabana passou a trazer a gravidade do DDInter. Os 3 casos de anlodipino continuam sem a interação grave, apesar de `Amlodipine` agora ser canônico e ter bula; a causa não foi investigada (pode ser o caminho do Perguntar livre, que usa as bulas, e não o verificador de pares). **O critério de segurança dos 7 casos graves não está confirmado**: depende de `evaluation:run interactions` no servidor. Ainda não executado.

Comandos pendentes, na VPS: `bulai-deploy artisan evaluation:run interactions` com `database/evaluation/interactions.v2.json` e `interactions.protocol30.v2.json` (conferir `evaluation:run --help` para as opções e o rótulo `prod-`), identificação (ajuste e reservado), recuperação por assunto e geração.
