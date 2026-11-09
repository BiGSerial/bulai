# Consumo de IA por usuário e por provedor

## Propósito

Saber quanto cada pessoa gasta de IA, com o número exato que a API do provedor devolveu em cada chamada, e manter
cada provedor separado. Se o texto trocar de Anthropic para OpenAI (ou outro serviço no padrão OpenAI), o consumo
novo aparece com o nome do novo provedor e o detalhe de uso dele, sem misturar com o histórico anterior. É a base para
planos de preço no aplicativo.

## O que é gravado (`ai_usage_logs`)

Uma linha por chamada, com sucesso ou falha. Colunas adicionadas em
`2026_11_08_223121_add_user_and_provider_details_to_ai_usage_logs_table`:

| Coluna | Conteúdo |
| --- | --- |
| `user_id` | Quem gerou a chamada (FK `users`, `nullOnDelete`). Nulo para avaliações, comandos e rotinas. |
| `origin` | De onde veio: `livewire:<componente>`, `route:<rota>` ou `artisan:<comando>`. |
| `response_model` | Modelo que a API diz ter respondido (pode ser uma versão datada do pedido em `model`). |
| `input_tokens` | **Entrada total** processada, inclusive a parte lida ou gravada em cache. |
| `cache_read_tokens` / `cache_write_tokens` | Parte da entrada lida do cache / gravada no cache. |
| `output_tokens` | Saída cobrada, inclusive o raciocínio interno. |
| `reasoning_tokens` | Parte da saída gasta em raciocínio, quando o provedor informa (a OpenAI informa, a Anthropic não separa). |
| `provider_usage` | O objeto `usage` da resposta **exatamente como a API devolveu** (jsonb). |

As colunas que já existiam continuam: `provider`, `model`, `capability`, `estimated_cost_usd`, `pricing_reference`,
`latency_ms`, `status`, `error_code`, `http_status`, `provider_request_id`, `run_id`. Prompt e resposta nunca são gravados.

### Como cada provedor é lido (`App\Data\Ai\TokenUsage`)

- **Anthropic** (`fromAnthropic`): `input_tokens` da API conta só a entrada fora do cache, então a entrada total é
  `input_tokens + cache_creation_input_tokens + cache_read_input_tokens`. No streaming, o `usage` do `message_start` é
  completado pelo do `message_delta`.
- **Padrão OpenAI** (`fromOpenAi`): `prompt_tokens` já inclui `prompt_tokens_details.cached_tokens`;
  `completion_tokens` inclui `completion_tokens_details.reasoning_tokens`. Embeddings gravam 0 de saída.

### Nome do provedor

- Anthropic: `anthropic`.
- Drivers `openai_compatible`: `openai` quando `base_url` é `api.openai.com` (ou Azure OpenAI), senão o rótulo de
  `AI_TEXT_PROVIDER` / `AI_EMBEDDING_PROVIDER` / `AI_VISION_PROVIDER` / `AI_CLASSIFIER_PROVIDER` (ex.: `openrouter`),
  senão `openai_compatible`. A IA local do classificador grava `llamafile`.

Antes desta mudança só as chamadas da Anthropic iam para a tabela; agora as dos drivers no padrão OpenAI também vão.

## Custo (`App\Services\Ai\AiCostEstimator`)

Custo estimado na hora da chamada, em USD, pela tabela `pricing` do driver em `config/ai.php` (USD por milhão de tokens):

```
custo = (entrada − cache lido − cache gravado) × input + cache lido × cache_read + cache gravado × cache_write + saída × output
```

- Anthropic (`$anthropicPricing`, referência 2026-11-08): Sonnet 5.5 2,00 / 2,50 / 0,20 / 10,00; Opus 5.5 4,00 / 5,00 / 0,20 / 20,00;
  Fable 5.1 10,00 / 12,50 / 0,25 / 50,00 (entrada / gravação no cache de 5 min / leitura do cache / saída). Haiku sem preço.
- Drivers no padrão OpenAI: `pricing.models` começa vazio. Ao trocar para a OpenAI, preencha os preços do modelo
  (`input`, `cache_read`, `output`) e `AI_TEXT_PRICING_REFERENCE` com a data da tabela consultada.
- Se a chamada usou uma parte sem preço configurado (por exemplo, cache sem `cache_read`), o custo fica nulo: os tokens
  são gravados, o custo não é inventado. O painel mostra essas chamadas como "sem preço".

## Atribuição ao usuário (`App\Services\Ai\AiUsageAttribution`)

- `AttributeAiUsageToUser` (grupo `web`) guarda o usuário e a tela no `Context` oculto do Laravel. O Context acompanha
  os jobs despachados na requisição, então o resumo de bula feito na fila continua atribuído a quem pediu.
- O recorder usa o usuário autenticado e, fora de uma requisição (worker), o do Context.
- A origem é o componente Livewire em execução, senão a do Context. Comandos Artisan gravam `artisan:<comando>`
  (`AppServiceProvider`); `queue:*` e `schedule:*` ficam de fora para cada job manter a origem de quem o despachou.
- Chamadas anteriores a esta mudança não têm usuário e não há como recuperá-lo.

## Telas

- `GET /admin/analytics/consumo-ia` (`admin.analytics.ai-usage.index`, `App\Livewire\Admin\Analytics\AiUsage\Index`):
  filtros de período e provedor; indicadores (custo, usuários com uso, custo médio e mediano por usuário, tokens por
  usuário, custo por chamada, consumo sem usuário); um bloco por provedor com entrada, cache, saída, raciocínio e
  modelos; ranking de usuários por custo.
- `GET /admin/analytics/consumo-ia/usuarios/{user}` (`admin.analytics.ai-usage.show`): totais do usuário, por provedor
  e modelo, por origem, por dia e cada chamada com o JSON de `provider_usage` e o id da requisição no provedor.
- Consultas em `App\Services\Analytics\AiConsumptionService`. As médias por usuário só consideram chamadas com usuário.
- Relatório CSV `ai_usage` (Relatórios) agora sai por dia × usuário × provedor × modelo × capacidade, com as colunas de cache e raciocínio.

## Testes

- `tests/Unit/Services/Ai/AiCostEstimatorTest.php`: leitura do `usage` da Anthropic e da OpenAI, custo por parte e custo nulo sem preço.
- `tests/Feature/Ai/AiUsageByUserTest.php`: chamada real (HTTP simulado) da Anthropic e da OpenAI gravando usuário,
  cache, raciocínio, JSON original e custo; falha gravada como `openai`; atribuição pelo Context fora da requisição; origem de comandos.
- `tests/Feature/Admin/AiUsageAdminTest.php`: acesso só de admin, provedores separados, médias, ranking e detalhe com o JSON.

## Limitações

- O custo é estimado pela tabela configurada, não pela fatura do provedor. Para conferir com a fatura, use o
  `provider_request_id` e o `provider_usage` de cada chamada.
- O orçamento diário/mensal da Anthropic (`limits.*_tokens`) soma `input_tokens + output_tokens`; com a entrada total,
  a leitura de cache passa a contar nesse limite de tokens (o limite em USD já usa o preço reduzido).
- A gravação no cache de 1 hora (2x) não é usada pelo aplicativo; se for adotada, a tabela precisa de um preço próprio.
- Classes novas do Tailwind nas telas exigem `npm run build` para aparecer no ambiente compilado.
