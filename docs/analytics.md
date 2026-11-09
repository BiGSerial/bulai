# Analytics e auditoria das respostas da IA (área administrativa)

## Propósito
Sustentar a tese do TCC com dados: **tudo o que a IA respondeu ao paciente fica registrado no backend** (evidências completas, validação de cada afirmação, contexto de tratamento usado, custo, tokens e latência) e é **mensurável** em um painel administrativo e exportável para análise estatística. Os dados são anonimizados na interface: usuários aparecem só pelo id numérico; nome e e-mail nunca são lidos pelo serviço.

## Rotas (`routes/web.php`, grupo `admin`, middleware `auth` + `admin`)
| Rota | Nome | Componente | Conteúdo |
| --- | --- | --- | --- |
| `GET /admin/analytics` | `admin.analytics.index` | `App\Livewire\Admin\Analytics\Dashboard` | KPIs, distribuições, ancoragem, desempenho, consumo de IA; filtro de período (`?periodo=7|30|90|0`) |
| `GET /admin/analytics/consultas` | `admin.analytics.consultations.index` | `…\Consultations\Index` | lista paginada (filtro `?status=`) |
| `GET /admin/analytics/consultas/{uuid}` | `admin.analytics.consultations.show` | `…\Consultations\Show` | pipeline + cada par: veredito, gravidade, explicação, base estruturada, limitações, métricas, evidências completas |
| `GET /admin/analytics/perguntas` | `admin.analytics.questions.index` | `…\Questions\Index` | lista paginada (filtro `?status=`) |
| `GET /admin/analytics/perguntas/{uuid}` | `admin.analytics.questions.show` | `…\Questions\Show` | pergunta, resposta, contexto de tratamento, métricas, evidências |
| `GET /admin/analytics/bulas` | `admin.analytics.leaflets.index` | `…\Leaflets\Index` | lista paginada (filtro `?status=`) |
| `GET /admin/analytics/bulas/{uuid}` | `admin.analytics.leaflets.show` | `…\Leaflets\Show` | chave de cache, seções com afirmações, métricas, evidências |

Item de menu "Analytics" em `App\AdminNavigation::items()` (ícone `chart`).

## Tabelas e colunas lidas (`app/Services/Analytics/AnalyticsService.php`)
- `interaction_consultations`: `status`, `user_id` (só contagem distinta), `duration_ms`, `pipeline->>'retrieval_mode'`, `pipeline->>'text_provider'`, `created_at` (período).
- `interaction_pair_results` (join com a consulta para o período): `verdict`, `severity`, `explanation`, `evidences` (array JSON; `method` de cada evidência), `generation->>'status'|'claims_accepted'|'claims_rejected'`, `generation->'rejection_reasons'` (objeto motivo → n), `timings->>'lookup_ms'|'retrieval_ms'|'generation_ms'`.
- `medication_questions`: `status`, `user_id`, `duration_ms`, `generation` (idem), `treatment_context->>'consented'`, `treatment_context->'interactions'`.
- `medicine_leaflet_summaries`: `status`, `views`, `duration_ms`, `generation` (idem).
- `ai_usage_logs`: `provider`, `model`, `capability`, `status`, `error_code`, `input_tokens`, `output_tokens`, `estimated_cost_usd`, `latency_ms`, `created_at`. O consumo por usuário e por provedor, com cache, raciocínio e o `usage` original da API, tem aba própria: ver `docs/ai/consumo-por-usuario.md`.

Nunca lidos: `users.name`, `users.email`, texto de prompts (não existem no banco), alergias.

## Métricas e fórmulas
| Métrica | Fórmula |
| --- | --- |
| Taxa de ancoragem | `Σ claims_accepted / (Σ claims_accepted + Σ claims_rejected)`; `null` sem geração. Calculada para pares, perguntas e bulas humanizadas |
| Motivos de rejeição | soma por chave de `generation->rejection_reasons` (`jsonb_each_text`) — vocabulário do `ExplanationValidator` |
| Status da geração | contagem de `generation->>'status'` ∈ {generated, invalid, unavailable} |
| Vereditos / gravidade | contagem de `verdict` (`PairVerdict`) e `severity` (`InteractionSeverity`, só pares com base estruturada) |
| Duração (média, p50, p95) | `avg`, `percentile_cont(0.5)`, `percentile_cont(0.95)` sobre `duration_ms` (PostgreSQL) |
| Tempo por etapa | média de `timings->>'lookup_ms'`, `'retrieval_ms'`, `'generation_ms'` dos pares |
| Método das evidências | contagem do campo `method` de cada evidência (`lexical`, `vector`, `lexical+vector`) |
| Cache de bulas humanizadas | `cache_hits = Σ views − total` (cada resumo é gerado uma vez; demais exibições reusam o registro) |
| Custo estimado | `Σ estimated_cost_usd` (estimado na chamada pelo provedor, tabela em `config/ai.php`); tokens = `Σ input_tokens`, `Σ output_tokens` |
| Latência de IA | `avg(latency_ms)` e `percentile_cont(0.95)` |
| Série diária | últimos 30 dias por `to_char(created_at, 'YYYY-MM-DD')`, independente do filtro de período |
| Usuários distintos | `count(distinct user_id)` em consultas e em perguntas (o KPI mostra o maior dos dois) |

O filtro de período aplica `created_at >= hoje − N dias` (0 = tudo).

## Exportação para o TCC
```bash
php artisan analytics:export                      # JSON em storage/app/evaluation/analytics-<data>.json
php artisan analytics:export --format=csv         # uma linha por par e por pergunta
php artisan analytics:export --from=2026-11-01 --to=2026-11-30 --out=/tmp/analytics.json
```
JSON: `generated_at`, `code_version`, `overview` (mesmo agregado do painel), `pairs[]` e `questions[]` anonimizados (uuid, datas, status, veredito/gravidade, métricas de geração e tempos). CSV: colunas unificadas `kind, uuid, created_at, status, retrieval_mode, verdict, severity, position, has_explanation, evidence_count, generation_status, claims_accepted, claims_rejected, input_tokens, output_tokens, duration_ms, lookup_ms, retrieval_ms, generation_ms, treatment_*, error_code`.

## Componentes visuais
`x-knowledge-admin.bars` (distribuição em barras, largura em 12 faixas por classe, sem CSS inline), `x-knowledge-admin.meter` (razão 0–1), `x-knowledge-admin.evidences` (lista completa de trechos com afirmações sustentadas) e `x-knowledge-admin.generation-facts` (status, modelo, tokens, afirmações, recuperação, tempos). Gravidade usa cores de estado sempre acompanhadas de texto.

## Gráficos (Chart.js)
A visão geral usa **Chart.js 4** + **chartjs-plugin-datalabels** (dependências `chart.js` e `chartjs-plugin-datalabels` no `package.json`).
- `resources/js/charts.js`: componente Alpine `bulaiChart` (registrado em `alpine:init`, importado por `app.js`). O Chart.js é carregado por *import dinâmico* só quando há gráfico na página (chunk separado no build; o app do paciente não o baixa). Tipos: barras horizontais (`bar`), colunas (`column`, opcionalmente `stacked`), linha (`line`) e rosca (`doughnut`); rótulos de valor/percentual pelo datalabels; formatos `int`, `usd`, `ms`, `percent` em pt-BR; animação desligada com `prefers-reduced-motion`.
- `resources/views/components/knowledge-admin/chart.blade.php` (`<x-knowledge-admin.chart>`): recebe `items`+`labels`+`tones` (uma série) ou `categories`+`series` (várias). Fica sob `wire:ignore` com `wire:key` derivado do hash dos dados, então o gráfico é recriado quando o período muda. Uma tabela `sr-only` espelha os números para leitores de tela; sem dados mostra a mensagem `empty`.
- `AnalyticsService::timeline()` (também em `overview()['timeline']`): consultas, perguntas e bulas por dia, com zeros nos dias sem atividade.
- Gráficos da página: atividade diária (linha), afirmações aceitas × rejeitadas (barras empilhadas), vereditos/status/modos (rosca), gravidade, motivos de rejeição e resultado das perguntas (barras), tempo por tipo de resposta e por etapa, custo/chamadas por dia, chamadas por modelo e tokens.

**Alinhamento das tabelas:** `x-knowledge-admin.data-table` aplica a cada célula o alinhamento do cabeçalho da sua coluna (`'align' => 'right'` para números; coluna sem título = ações, à direita). Antes só a última célula ia à direita, deixando colunas numéricas do meio desalinhadas do cabeçalho.

## Fábricas de teste
`database/factories/Consultation/{InteractionConsultation,InteractionPairResult,MedicationQuestion,MedicineLeafletSummary}Factory` — usar `XFactory::new()->create()` (os modelos não declaram `HasFactory`).

## Testes
```bash
php artisan test --compact tests/Feature/Admin/AnalyticsAdminTest.php tests/Feature/Admin/AdminDesignSystemTest.php
```
Cobrem: acesso só de admin (403), agregações (taxa de ancoragem, percentis, vereditos, consumo de IA), filtro de período, detalhe de consulta com evidências sem expor nome/e-mail, detalhes de pergunta e bula, exportação JSON/CSV e formato inválido.

## Limitações
- Agregações dependem de funções do PostgreSQL (`percentile_cont`, `jsonb_each_text`, `jsonb_array_elements`); não rodam em SQLite.
- Custo é **estimado** pela tabela de preços configurada; não é a fatura do provedor.
- Os gráficos de consumo de IA (custo e chamadas por dia) ignoram o filtro de período (sempre 30 dias); a tabela "Série diária" continua disponível. A atividade diária (consultas/perguntas/bulas) respeita o período e, em "Todo o período", mostra os últimos 90 dias.
- O painel consulta o banco a cada renderização (sem cache); com volume grande, considerar materialização diária.
- Perguntas e consultas são apagadas pela retenção (`consultations:purge-expired`); métricas históricas devem ser exportadas antes da purga.

**Reformulação do admin (06/11/2026):** as quatro telas de Analytics compartilham a navegação por abas (Visão geral · Consultas · Perguntas · Bulas humanizadas); a visão geral mostra KPIs, três medidores de ancoragem, distribuições em duas colunas e tabelas densas de desempenho e consumo de IA; as listas são tabelas com ação "Abrir"; os detalhes de pergunta e bula humanizada usam abas (`aba` na URL): Contexto/Seções · Geração · Evidências.
