# API de estatística e avaliação (`/api/v1`)

## Propósito

Dar a um script ou a um agente externo (por exemplo, o agente de desenvolvimento que avalia as respostas) acesso de leitura aos números do painel Analytics, ao consumo de IA e ao rastreio de cada resposta do Perguntar, e permitir avaliar o Perguntar com perguntas reais, pontuadas pela mesma régua do `evaluation:conversation`. Tudo por Bearer Token com escopo, validade e revogação imediata.

## Tokens

- Emissão e revogação: **Admin › Análises › Acesso à API** (`/admin/api`, `App\Livewire\Admin\ApiTokens\Index`). O token aparece uma única vez; o banco guarda só `sha256(token)` em `ingestion_tokens` (mesma tabela e middleware dos tokens de ingestão, `App\Http\Middleware\AuthenticateIngestionToken`).
- Permissões (`ingestion_tokens.scope`):
  - `api:read`: leitura (`/me`, `/stats/*`, `/questions*`).
  - `api:evaluate`: leitura **e** `POST /evaluation/ask` (consome IA de verdade).
- Validade: quantidade + unidade (minutos, horas ou dias), de 1 minuto a 90 dias. Ao fim do prazo o token deixa de funcionar sozinho; a tela mostra quando expira e quanto falta. Para dar acesso só durante uma sessão de avaliação, emita por minutos ou horas. Expirado ou revogado: `401 {"message": "Invalid, expired or revoked token."}`.
- Auditoria: emissão e revogação entram em `user_audit_events` (`api_token_issued`, `api_token_revoked`) na trilha do admin que agiu.
- Terminal (emergência ou sem acesso ao painel):
  - `php artisan tokens:revoke --list` lista os tokens ativos;
  - `php artisan tokens:revoke {id}` revoga na hora;
  - `php artisan knowledge:issue-token "nome" --scope=api:read --expires="+30 days"` emite pelo terminal.
- Tokens `knowledge:ingest` não abrem `/api/v1`, e a tela não lista nem revoga esses tokens.

## Limites

- Leitura: 60 requisições por minuto por token e 120 por IP (`api-read`).
- Avaliação: 6 por minuto e 300 por dia por token, e 12 por minuto por IP (`api-evaluate`), porque cada chamada passa pelo modelo.
- Excedeu: `429`.

## Endpoints

Todos respondem JSON. Os períodos usam `from` e `to` no formato `AAAA-MM-DD` (dia inteiro, fuso da aplicação); sem `from`, valem os últimos 30 dias.

| Método e rota | Escopo | O que devolve |
|---|---|---|
| `GET /api/v1/me` | read | Token (id, nome, escopo, validade), `evaluation_user_id` e fuso. |
| `GET /api/v1/stats/overview` | read | O mesmo de `AnalyticsService::overview`: consultas, pares, perguntas (ancoragem, recusas, duração), bulas, IA (chamadas, tokens, custo, latência, por modelo, falhas, diário), **cobertura em três dimensões** (`coverage`: registro no DDInter, bulas dos dois remédios, evidência para explicar; ver `docs/analytics.md`) e série diária. |
| `GET /api/v1/stats/ai-usage?provider=` | read | Por provedor e modelo, médias por usuário, consumo sem usuário e uma linha por usuário (até 100), **só com `user_id`**, sem nome nem e-mail. |
| `GET /api/v1/stats/knowledge` | read | Tamanho da base **deste ambiente** (`KnowledgeInventoryService`): documentos por situação e tipo, prontos e vinculados a princípio ativo, trechos e embeddings; cada bula pronta com os seus princípios ativos; princípios ativos com bula; produtos do catálogo da ANVISA (identificados, com bula de algum ativo, com bula de todos); interações do DDInter (total, ligadas ao catálogo, por gravidade, com bula de um lado, entre princípios ativos com bula e a gravidade delas); bulas dos EUA por situação e origem, trechos por situação da tradução e a lista das prontas. É a fonte dos números de relatório: eles precisam vir do ambiente que responde, não da máquina de desenvolvimento. |
| `GET /api/v1/questions?status=&user_id=&per_page=` | read | Perguntas, das mais novas às mais antigas (paginado, até 100 por página): pergunta, resposta, status, intenção principal, suficiência, se leu a bula inteira ou o PDF original, afirmações aceitas e recusadas, revisão. |
| `GET /api/v1/questions/{uuid}` | read | Registro completo para auditoria: `medicines`, `guard`, `treatment_context`, `generation`, `retrieval` (classificação, suficiência, `treatment_check`, `leaflet_fallback`), `pipeline` e `evidences`. |
| `POST /api/v1/evaluation/ask` | evaluate | Roda a pergunta no fluxo completo (`MedicationQuestionService::askFreeform`) e pontua. |

### `POST /api/v1/evaluation/ask`

O corpo da requisição tem estes campos:

```json
{
  "question": "Dipirona serve para dor de cabeça?",
  "scenario": "indicacao",
  "medicine_ids": [],
  "conversation": [],
  "expects": {"facts": true, "asks": false}
}
```

- `conversation`: turnos anteriores, até 6, para avaliar acompanhamento. Copie o `next_conversation_turn` da resposta anterior.
- `expects`: aceita `facts`, `asks`, `limit`, `follow_up`, `no_raw`, `honest_absence` (booleanos) e `medicines` (número). São as expectativas que `ConversationEvaluator::score()` usa.

A resposta traz:

- `uuid`, `status`, `answer`, `message`, `urgency` e `limitations`;
- `evaluation`: o turno resumido (fontes, geração, se leu a bula, tempo) mais `score.scores` com `relevancia`, `ancoragem`, `seguranca`, `clareza` e `naturalidade` (`true`, `false` ou `null` quando não se aplica), e `score.notes`;
- `retrieval` e `generation` completos;
- `next_conversation_turn`.

Como cada chamada passa pelo modelo, ela pode levar dezenas de segundos. Use um timeout de cliente de pelo menos 120 s.

Exemplo:

```bash
curl -s https://<host>/api/v1/evaluation/ask \
  -H "Authorization: Bearer $BULAI_TOKEN" -H "Content-Type: application/json" \
  -d '{"question":"Posso tomar ibuprofeno?","expects":{"facts":true,"asks":true,"limit":true}}'
```

## Conta de avaliação

As perguntas de `evaluation/ask` (e do `evaluation:conversation`) rodam como a conta técnica `avaliacao-bulai@example.test` (`App\Services\Evaluation\EvaluationAccount`):

- a conta é inativa, com senha aleatória que ninguém conhece, e não entra no sistema;
- tem consentimento de saúde, para o fluxo completo rodar;
- não tem remédios nem alergias cadastrados.

O consumo de IA fica atribuído a ela, com origem `api:evaluation`. Para separar o que é teste do que é uso real, filtre por `evaluation_user_id` (de `/me`): em `/questions?user_id=` e nas linhas por usuário de `/stats/ai-usage`.

## Privacidade

A exposição é a mesma das telas de Analytics do admin:

- o autor de cada pergunta aparece só pelo id interno;
- nome e e-mail nunca saem pela API;
- o texto das perguntas e o `treatment_context` são dados de saúde pseudonimizados.

Por isso:

- emita tokens com a menor validade que resolver;
- prefira `api:read` quando não for avaliar;
- revogue ao terminar.

## Arquivos

- Rotas: `routes/api.php` (grupo `v1`).
- Inventário da base: `app/Services/Analytics/KnowledgeInventoryService.php`.
- Controllers: `app/Http/Controllers/Api/V1/StatsController.php`, `QuestionController.php`, `EvaluationController.php`.
- Token: `app/Models/Knowledge/IngestionToken.php` (escopos `api:*`, `isActive()`, `revoke()`) e `app/Http/Middleware/AuthenticateIngestionToken.php` (aceita vários escopos).
- Limites: `app/Providers/AppServiceProvider.php` (`api-read`, `api-evaluate`).
- Admin: `app/Livewire/Admin/ApiTokens/Index.php` e `resources/views/livewire/admin/api-tokens/index.blade.php`.
- Comando: `app/Console/Commands/RevokeTokenCommand.php` (`tokens:revoke`).
- Avaliação: `ConversationEvaluator::turn()` (resumo do turno, compartilhado com o comando) e `EvaluationAccount`.

## Testes

- `tests/Feature/Api/ApiAccessTest.php`: token válido, expirado, revogado e de outro escopo; leitura contra avaliação; estatísticas sem nome nem e-mail; inventário da base (bulas, catálogo, interações entre princípios ativos com bula, bulas dos EUA); rastreio das perguntas; avaliação pelo fluxo real com o provedor roteirizado; validação; limite por token; `tokens:revoke`.
- `tests/Feature/Admin/ApiTokensAdminTest.php`: só admin acessa; token mostrado uma vez, só o hash no banco; revogação imediata e auditoria; a tela não aceita outros escopos nem mexe em tokens de ingestão.

## Clientes automatizados

O site fica atrás do Cloudflare, que bloqueia a assinatura padrão de clientes como `Python-urllib` (erro 1010, `403`, sem chegar ao servidor). Envie um `User-Agent` próprio (ex.: `BULAI-Avaliacao/1.0`); o `curl` passa sem ajuste.

## Limitações

- A avaliação é síncrona, uma pergunta por chamada. Para os oito cenários de uma vez, continue usando `php artisan evaluation:conversation`.
- A pontuação é heurística, a mesma do comando. Ela não substitui a leitura humana nem a validação profissional.
- A conta de avaliação não tem perfil de tratamento, então os alertas de interação com remédios em uso e de alergia não são exercitados pela API.
- As perguntas de avaliação entram nos totais do painel Analytics. Separe-as pelo `evaluation_user_id`.
