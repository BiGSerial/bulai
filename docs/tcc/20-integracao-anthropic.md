# Integração nativa com a API da Anthropic (Claude)

Mapeamento no docx: Cap. 3, 3.3.1 e 3.3.3 (ponto "API de LLM ... ou equivalente"); Cap. 2, 2.6.3. Documento técnico completo: `docs/ai/anthropic-provider.md`.

## Objetivo do tópico

Registrar a integração do provedor de geração e **distinguir com precisão o que foi testado com simulações do que foi validado contra a API real** (esta última validação **não foi realizada**: não havia chave nem autorização de custo).

## 1. Decisão

O projeto previa "OpenAI GPT-4o ou equivalente". Adotou-se um adaptador **nativo** da Messages API da Anthropic (`POST /v1/messages`), e não um modo de compatibilidade OpenAI, porque cabeçalhos (`x-api-key`, `anthropic-version`), formato de resposta (blocos de conteúdo), erros e *streaming* (eventos nomeados) diferem. O adaptador implementa o contrato existente `TextGenerationProvider`; o pipeline não sabe qual provedor está ativo (`AI_TEXT_DRIVER`). Embeddings **não** usam Claude (`AI_EMBEDDING_DRIVER` independente).

## 2. Fatos verificados na documentação oficial (03/11/2026)

- Versão de API vigente `2026-10-21`; nessa versão `temperature`, `top_p` e `top_k` foram **removidos** (enviá-los retorna 400). O adaptador não os envia.
- IDs de modelo: `claude-fable-5-1`, `claude-opus-5-5`, `claude-sonnet-5-5`, `claude-haiku-5-5`. Nenhum modelo é padrão: sem `ANTHROPIC_MODEL` o driver responde `AI_NOT_CONFIGURED`.
- Erros: 400/401/402/403/404/413/429/500/504/529 com corpo `{"type":"error","error":{"type","message"},"request_id"}`; 429 de teto de gasto não traz `retry-after`.

## 3. O que foi implementado

Cliente HTTP com *retry* exponencial + *jitter*; *retry-after* respeitado até um teto; 429 sem *retry-after* não é reintentado; mapeamento para `AiProviderException` (mensagens só com código, nunca prompt/chave/URL); `stop_reason=max_tokens` devolvido como truncado; `refusal` → rejeitado; *streaming* SSE (`StreamingTextGenerationProvider`) com erro no meio do fluxo; registro por requisição em `ai_usage_logs` (provedor, modelo, run id, tokens, latência, status, tipo de erro, custo estimado); limites configuráveis de entrada e de orçamento diário/mensal; tabela de preços **configurável** (valor nulo → custo não estimado).

## 4. Estado de validação

| Item | Estado |
| --- | --- |
| Transporte, erros, retries, streaming, custos, limites, troca de provedor | **testado com simulações** (`Http::fake`); 26 testes sem banco + 1 de persistência |
| Autenticação real, formato real das respostas, SSE real, custos reais | **NÃO validado** |
| Teste de conectividade real | existe, **pulado** por padrão (`ANTHROPIC_LIVE_TEST=1` + chave + modelo) |
| Qualidade das explicações com Claude | **não medida** |

Os testes automáticos garantem coerência com o **contrato documentado**; só uma chamada real revela divergências. Esta distinção deve ser mantida no texto do TCC.

## 5. Uso no pipeline e comparação entre provedores

`ExplanationGenerator` envia apenas o contexto necessário (nomes, gravidade fornecida, até 4 trechos). Cada execução de avaliação registra o driver e o modelo de geração (`EvaluationRunRecorder`), de modo que o **mesmo** pipeline possa ser executado com provedores diferentes e os resultados não sejam misturados (arquivos distintos, parâmetros e modelos identificados). Hoje só há resultados com o modelo local pequeno (`23-...`).

## 6. Limitações e próximos passos

Sem *tool use*, *prompt caching*, contagem de tokens pela API nem *structured outputs* (`output_config.format`), que poderiam eliminar saídas JSON malformadas observadas no modelo local. Preço do Haiku não configurado. Recomenda-se, com autorização de custo, rodar o teste real e repetir `evaluation:run generation` com Claude.
