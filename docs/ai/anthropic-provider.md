# Provedor de texto Anthropic (Messages API)

## Propósito
Adaptador nativo (não compatível-OpenAI) da Anthropic Messages API como driver `anthropic` da capacidade `text`, atrás dos contratos existentes (`TextGenerationProvider`). O pipeline RAG não sabe qual provedor é usado. Embeddings NÃO usam Claude: `AI_EMBEDDING_DRIVER` é independente. O frontend nunca fala com a Anthropic.

## Estado de validação
- **Implementado e testado com mocks** (`Http::fake`, recorder em memória): transporte, retries, mapeamento de erros, streaming, custos, limites, troca de provedor.
- **Validado contra a API real em 2026-11-04** (`claude-sonnet-5-5`, `anthropic-version: 2026-10-21`): `AnthropicLiveConnectivityTest` passou (resposta e `output_tokens > 0`, 1,5 s) e um *smoke* de ponta a ponta no banco de desenvolvimento (consulta de interações + pergunta livre) está registrado na seção "Validação real" abaixo. Fatos de protocolo e preços vêm da documentação oficial consultada em 2026-11-03.

## Arquivos
- `app/Services/Ai/Anthropic/`: `AnthropicClient` (HTTP, retry, logs), `AnthropicTextGenerationProvider` (`generate` + `stream`), `AnthropicResponseMapper`, `AnthropicSseParser`, `AnthropicUsageTracker` (custo, limites, registro).
- `app/Services/Ai/StreamingTextGenerationProvider.php`: interface opcional de streaming.
- `app/Services/Ai/AiUsageRecorder.php` (+ `EloquentAiUsageRecorder`, `Fake/InMemoryAiUsageRecorder`), `app/Data/Ai/AiUsageRecord.php`.
- `AiProviderException`: adicionados (retrocompatível) `AI_BUDGET_EXCEEDED`, `AI_INPUT_LIMIT_EXCEEDED` e campos opcionais `httpStatus`, `providerRequestId`.
- Tabela `ai_usage_logs` (migration `2026_11_03_235100`), model `AiUsageLog`, `AiUsageLogFactory`. Registro em `AiProviderManager` (`text` / `anthropic`).

## Configuração (`config/ai.php` → `text.drivers.anthropic`)
| Env | Padrão | Nota |
|---|---|---|
| `AI_TEXT_DRIVER` | vazio | defina `anthropic` para ativar; não vem ativo no `.env.example` |
| `ANTHROPIC_API_KEY` | - | sem chave => `AI_NOT_CONFIGURED` |
| `ANTHROPIC_MODEL` | vazio | sem padrão no código; IDs: `claude-fable-5-1`, `claude-opus-5-5`, `claude-sonnet-5-5`, `claude-haiku-5-5` |
| `ANTHROPIC_BASE_URL` | `https://api.anthropic.com` | |
| `ANTHROPIC_API_VERSION` | `2026-10-21` | header `anthropic-version` |
| `ANTHROPIC_MAX_TOKENS` | 2048 | `max_tokens` (usa `maxOutputTokens` da requisição quando houver) |
| `ANTHROPIC_TIMEOUT` / `ANTHROPIC_RETRIES` | 90 / 2 | |
| `ANTHROPIC_RETRY_BASE_MS`, `ANTHROPIC_RETRY_AFTER_MAX_SECONDS` | 500 / 30 | backoff e teto do retry-after |
| `ANTHROPIC_MAX_INPUT_TOKENS`, `ANTHROPIC_{DAILY,MONTHLY}_TOKEN_BUDGET`, `ANTHROPIC_{DAILY,MONTHLY}_USD_BUDGET` | vazio = sem limite | |

## Requisição
`POST {base_url}/v1/messages` com `x-api-key`, `anthropic-version`, JSON. Corpo: `model`, `max_tokens`, `system` (top-level), `messages: [{role: user}]`. `TextGenerationRequest::$temperature` é **ignorado** (na versão 2026-10-21 `temperature/top_p/top_k` foram removidos e retornam 400). Sem prefill, sem `thinking` por padrão. `metadata` da requisição nunca é enviada; `metadata['run_id']` só alimenta o log interno.

## Resposta
Concatena só blocos `type=text`. `stop_reason=max_tokens`: devolve o texto parcial com `metadata['truncated']=true` (decisão: não descartar conteúdo já cobrado; o consumidor decide). `refusal` => `AI_REJECTED`. Sem bloco de texto ou corpo inválido => `AI_INVALID_RESPONSE`. Metadados: `stop_reason`, `truncated`, `provider_request_id`, `correlation_id`.

## Mapeamento de erros
| HTTP | Código | Retry |
|---|---|---|
| 400 | `AI_INVALID_REQUEST` (também ocorre ao atingir limite de gasto) | não |
| 401, 403 | `AI_AUTH_FAILED` | não |
| 402, 404, 413, demais 4xx | `AI_REJECTED` | não |
| 429 com `retry-after` (<= teto) | `AI_RATE_LIMITED` | sim, espera o retry-after |
| 429 sem `retry-after` | `AI_RATE_LIMITED` | **não** (provável teto de gasto) |
| 500/502/503/529 | `AI_UNAVAILABLE` | sim, backoff exponencial + jitter |
| 504 | `AI_TIMEOUT` | sim |
| timeout de leitura | `AI_TIMEOUT` | não (evita multiplicar 90 s) |
| falha de conexão | `AI_UNAVAILABLE` | sim |
Mensagens das exceções são só o código; nunca incluem prompt, resposta, URL ou chave.

## Visão (receita por foto)

`AnthropicVisionProvider` (`AI_VISION_DRIVER=anthropic`, `ai.vision.drivers.anthropic`) usa a mesma Messages API: `messages[0].content` = blocos `{"type":"image","source":{"type":"base64","media_type":...,"data":...}}` (um por `VisionImage`, só `image/jpeg|png|webp`, até `ai.max_image_bytes`) seguidos do bloco `text`; `system` vem de `VisionRequest::$metadata['system']`. Reaproveita `AnthropicClient` (retentativas, erros), `AnthropicResponseMapper` (refusal → `AI_REJECTED`, `max_tokens` → `truncated`) e `AnthropicUsageTracker` com `capability=vision` (orçamentos diários/mensais compartilhados com o texto; custo estimado pela mesma tabela). Configuração: as mesmas chaves `ANTHROPIC_*`; `AI_VISION_MODEL` opcional (vazio = `ANTHROPIC_MODEL`). Consumidor: `App\Services\Patient\PrescriptionExtractionService` (`docs/consulta-pwa.md` → "Receita por foto"). Testes: `tests/Unit/Services/Ai/AnthropicVisionProviderTest.php`.

## Streaming
`StreamingTextGenerationProvider::stream()` devolve um `Generator<string>` de fragmentos; `getReturn()` entrega a `TextGenerationResponse` final (uso: `input_tokens` de `message_start`, `output_tokens` acumulado de `message_delta`). Evento `error` no meio do stream (HTTP 200) vira `AiProviderException` (`overloaded_error`/`api_error` => UNAVAILABLE, `rate_limit_error` => RATE_LIMITED etc.); stream sem `message_stop` => `AI_INVALID_RESPONSE`. Não há retry depois que o stream começou.

## Custos e limites
- Preços em `ai.text.drivers.anthropic.pricing` (USD/MTok por modelo, referência 2026-11-03); valor nulo => custo não estimado (`estimated_cost_usd` nulo). Haiku está nulo (documentação só diz "a partir de 0,10/0,50"). Confirme os preços antes de depender dos valores.
- Entrada estimada = caracteres / `chars_per_token` (padrão 3); é heurística, não a contagem real do provedor.
- Orçamentos diário/mensal são checados antes da chamada contra a soma de `ai_usage_logs` (sucessos); `soma >= limite` => `AI_BUDGET_EXCEEDED` sem chamar a API. A última requisição pode ultrapassar levemente o limite. Entrada acima do limite => `AI_INPUT_LIMIT_EXCEEDED`.
- Cada requisição grava em `ai_usage_logs`: provider, model, run_id, capability, tokens, latência, status, error_code, http_status, provider_request_id, custo estimado, pricing_reference. Nunca prompt, resposta ou chave. Falha ao gravar só gera um aviso de log.

## Testes
- Sem banco: `php artisan test --compact --filter=Anthropic tests/Unit` (`AnthropicTextGenerationProviderTest`).
- Com banco (`RefreshDatabase`): `tests/Feature/Infrastructure/AnthropicUsageLogPersistenceTest.php`.
- Real (pulado por padrão): `ANTHROPIC_LIVE_TEST=1 ANTHROPIC_API_KEY=... ANTHROPIC_MODEL=... php artisan test --filter=AnthropicLiveConnectivityTest` (consome tokens reais).

## Validação real (05/11/2026)
Executada no banco de desenvolvimento com `claude-sonnet-5-5` e recuperação híbrida (bge-m3): conectividade ok (1,5 s); uma consulta de interações com evidência em bula (IBUPROFENO + paracetamol: 2.320/242 tokens, 2,9 s, modelo declarou `insufficient` → só trechos) e uma pergunta livre respondida com 4 afirmações validadas (omeprazol, reações adversas: 2.729/485 tokens, 3,6 s, US$ 0,0103). Total de 4 chamadas, US$ 0,024, todas `success`/HTTP 200 em `ai_usage_logs`. Tabela completa em `docs/evaluation.md` ("Validação real da IA principal").

Em produção, `app`/`worker` alcançam `api.anthropic.com` pela rede `egress` do `compose.prod.yaml` (as demais redes continuam internas); ver `docs/deployment/kinghost.md`.

## Limitações
Sem tool use, thinking, prompt caching nem contagem de tokens via API; validação real limitada a 4 chamadas (sem teste de carga, 429 ou streaming contra a API real); Haiku sem preço; limite de entrada é estimativa.
