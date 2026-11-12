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
| `GET /api/v1/audit/*` | read | Auditoria da aplicação para o TCC: ver a seção **Auditoria** abaixo. |
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
- `pair_interactions`: o resultado da base estruturada para cada par citado na pergunta (`severity`: `major|moderate|minor|unknown`, `null` = o DDInter não registra interação, `duplicate` = mesmo princípio ativo, `unverified` = não foi possível consultar porque um dos remédios não tem princípio ativo ligado);
- `retrieval.unresolved_names`: nomes que a interpretação apontou e a detecção não resolveu no catálogo (a resposta e `limitations` avisam que a interação não foi verificada);
- `retrieval` e `generation` completos;
- `next_conversation_turn`.

Como cada chamada passa pelo modelo, ela pode levar dezenas de segundos. Use um timeout de cliente de pelo menos 120 s.

Exemplo:

```bash
curl -s https://<host>/api/v1/evaluation/ask \
  -H "Authorization: Bearer $BULAI_TOKEN" -H "Content-Type: application/json" \
  -d '{"question":"Posso tomar ibuprofeno?","expects":{"facts":true,"asks":true,"limit":true}}'
```

## Auditoria (`/api/v1/audit/*`)

Medem a aplicação inteira para os dados do TCC: tempos, quantidades, embeddings, qualidade e coerência do que está gravado, leitura dos PDFs e legibilidade. Só leitura, escopo `api:read`, sem nome nem e-mail. Cada bloco sai separado e todos juntos no dossiê.

| Rota | O que responde |
|---|---|
| `GET /audit/tcc?from=&to=` | **Dossiê**: ambiente, tamanho da base, uso (consultas, perguntas, bulas, pares, IA), ancoragem com intervalo de Wilson, tempos da ingestão, embeddings, qualidade dos dados, legibilidade e o quadro de experimentos. `from`/`to` filtram só o uso; sem `from`, todo o período. |
| `GET /audit/pipeline` | Tempo por etapa da ingestão (`knowledge_processing_events`): execuções, falhas, média, p50, p95 e máximo; tempo por documento; últimas falhas. |
| `GET /audit/embeddings?sample=40&live=0` | Os vetores estão corretos? Cobertura, modelo e dimensão únicos, vetor nulo, hash do texto igual ao da indexação, vetores iguais para textos diferentes, normas, e **coerência**: o vizinho mais próximo de um trecho é do mesmo documento? (com IC de Wilson) e similaridade dentro × entre documentos. Com `live=1` reembeda uma amostra (até 12 trechos) pelo provedor e compara por cosseno com o vetor gravado (`faithful` = menor cosseno ≥ 0,99); essa opção chama o serviço de embeddings. |
| `GET /audit/quality?verify_files=0` | 24 regras de integridade e coerência, em quatro grupos: documentos, trechos, catálogo/interações e respostas gravadas. Cada regra traz `violations`, `checked`, severidade (`error`, `warning`, `info`) e até 5 exemplos. Traz também estatísticas dos trechos e a **fidelidade das citações** (o trecho citado ainda existe e o texto exibido está dentro dele, nas últimas 200 respostas). `verify_files=1` recalcula o SHA-256 de cada PDF. |
| `GET /audit/documents` | Cada documento com o PDF armazenado, tamanho (confere com o registro), hash e quantidade de trechos. |
| `GET /audit/documents/{id}/pdf?text=0&page=` | **Lê o PDF guardado**: hash confere?, método de extração (texto nativo ou OCR), qualidade, páginas, caracteres por página, páginas vazias e **cobertura do índice** (fração das palavras de cada trecho que aparece nas páginas que ele declara; trechos abaixo de 80%; páginas fora do intervalo). Com `text=1` devolve o texto por página (até 6.000 caracteres cada; `page=N` escolhe uma). O arquivo nunca sai do servidor. |
| `GET /audit/readability?limit=200` | Índice de Flesch pt (Martins et al., 1996) das respostas e dos trechos que as sustentam, com mediana, faixas e proporção de respostas mais fáceis que a fonte (IC de Wilson). Substitui o script não versionado da seção 3.5.5 do TCC. |
| `GET /audit/evaluations?task=` | Os registros de `evaluation:run` (identificação, interações, recuperação, geração, baseline) e o quadro de experimentos com o mais recente de cada um; as listas por item vêm só contadas. |

Leitura dos resultados:
- `error` quebra uma garantia do sistema (ex.: bula pronta sem trechos, vetor de modelo misto, PDF ausente); `warning` merece olhar; `info` é descritivo.
- A coerência dos embeddings é um **indicador**, não prova de correção: bulas têm estrutura parecida, então a separação entre documentos é pequena mesmo com vetores íntegros. A prova de fidelidade ao texto atual é o `live=1`.
- A cobertura do PDF mede presença de palavras, não preservação de sentido, e depende da extração (nativa ou OCR) do próprio PDF.
- Os números valem para o ambiente consultado; leia `environment` no dossiê ao citar um resultado.
- **Bloco que falha não derruba a resposta** (`App\Services\Audit\AuditSection`): cada bloco do dossiê, cada grupo do `/audit/quality` e cada parte do `/audit/embeddings` roda isolado (num savepoint); se o banco daquele ambiente recusar uma consulta ou um dado vier fora do formato, o bloco sai como `{"error": {"exception", "message"}}`, a exceção vai para o log e o resto responde normalmente. No `/audit/quality`, `summary.failed_groups` lista os grupos que não rodaram (eles ficam fora da contagem). Motivo: em 11/11/2026 `/audit/tcc`, `/audit/quality` e `/audit/embeddings` davam 500 em produção e respondiam em desenvolvimento.
- **Conexão derrubada no meio da auditoria** (12/11/2026, produção): `/audit/tcc` voltou com `no connection to the server` no bloco de embeddings e `There is already an active transaction` em todos os seguintes; `/audit/readability` deu 500 uma vez e passou na repetição. O `dmesg` da VPS mostrou `Memory cgroup out of memory: Killed process … (postgres)` às 01:03:02, 01:03:20 e 01:03:31, os mesmos horários das chamadas: o contêiner do PostgreSQL (limite de memória bem menor que os 7,9 GB da máquina) matava o processo da consulta, com 565–677 MB, e o servidor derrubava as conexões. A suspeita é a checagem de vetores idênticos, que agrupava pelo vetor inteiro (1.024 floats); agora agrupa por `md5(embedding::text)` com `work_mem` de 8 MB (a causa exata não foi isolada no servidor). Para o relatório não cair junto, `AuditSection` tenta o bloco **mais uma vez** depois de reabrir a conexão (só para erros de conexão perdida) e, se ainda falhar, desfaz a transação pendente/reabre a conexão antes do bloco seguinte. No `/audit/embeddings` cada verificação roda isolada: as que não rodaram saem em `checks_not_run` (`{exception, message}`) e o `summary` conta só as que rodaram. Testes: `tests/Unit/Services/Audit/AuditSectionTest.php`, `tests/Feature/Api/AuditApiTest.php`. Infraestrutura: o limite do contêiner do PostgreSQL passou de 768 MB para 1,5 GB (`compose.prod.yaml`, `mem_limit` e `memswap_limit`), valendo após `bulai-deploy` recriar o serviço; os parâmetros do PostgreSQL (`shared_buffers`, `work_mem`) não mudaram.

Correções feitas a partir da primeira auditoria do ambiente de desenvolvimento (10/11/2026):
- **Trechos minúsculos e repetidos** (rodapés como "RDC 73/2016 -", títulos quebrados como "FUNCIONA?"): `App\Services\Knowledge\ChunkCleaner`, usado pelo `KnowledgeChunker` (documentos novos) e por `php artisan knowledge:repair-chunks [--dry-run] [--document=]` (existentes). Descarta trecho sem texto útil e repetição exata de trecho curto; funde fragmento de título ao trecho seguinte. O trecho que absorve mantém id e seção, então citações a ele continuam válidas. Limites em `config/knowledge.php` (`chunking.min_characters`, `chunking.duplicate_max_characters`). Resultado no ambiente: 862 → 762 trechos, 38 reindexados.
- **`token_count`**: agora gravado no processamento e pelo reparo, como **estimativa** (caracteres ÷ 4; `metadata.token_count_method = "chars/4"`), não a contagem do tokenizador do modelo.
- **Vínculo bula ↔ princípio ativo**: bula sem metadados e com título genérico é ligada pelo nome do produto nas primeiras linhas (método `content`, confiança 0,5, só se o catálogo já conhecer o nome); abreviação de 3 letras no metadado (ex.: "IBP") não cria mais princípio ativo.
- **Auditoria**: a regra "respondida sem evidência" misturava `and`/`or` sem parênteses e contava perguntas de outros status; corrigida. Pares do DDInter repetidos, trechos repetidos que o PDF realmente traz duas vezes e vetores de textos que só diferem em espaços passaram a `info`.
- Depois de `knowledge:repair-chunks`, rode `php artisan knowledge:index-embeddings`: o texto mudou e o hash antigo marca o trecho como defasado.

Arquivos: `app/Services/Audit/*` (`EmbeddingAuditService`, `DataQualityAuditService`, `PdfAuditService`, `PipelineTimingService`, `ReadabilityService`, `EvaluationRunsService`, `TccDossierService`, `AuditCheck`), `app/Http/Controllers/Api/V1/AuditController.php`, `routes/api.php` e `Metrics::wilson()`. Testes: `tests/Feature/Api/AuditApiTest.php`.

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
