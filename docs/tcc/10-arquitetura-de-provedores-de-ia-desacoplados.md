# Arquitetura de provedores de IA desacoplados por capacidade

Mapeamento no docx: Cap. 3, seção 3.3.1 (Justificativa da Escolha Arquitetural) e 3.3.3 (Tecnologias Planejadas, ponto "API de LLM ... ou equivalente"). Complementa `01-visao-geral-e-arquitetura.md`.

## Objetivo do tópico

Explicar como a aplicação isola o uso de modelos de IA atrás de contratos, permitindo trocar fornecedor ou engine sem alterar a regra de negócio.

## 1. Fundamentação

Princípios de inversão de dependência e arquitetura hexagonal (ports and adapters) motivam interfaces estáveis na camada de aplicação e adaptadores específicos por fornecedor. [referência a incluir: Martin, princípios SOLID; Cockburn, arquitetura hexagonal.] O docx prevê "OpenAI GPT-4o ou equivalente"; o desacoplamento sustenta o "ou equivalente" e a opção por modelos locais.

## 2. Estrutura implementada

`Aplicação -> contratos -> AiProviderManager -> drivers -> API externa/local`

| Capacidade | Contrato | Drivers | Uso atual |
| --- | --- | --- | --- |
| Texto | `TextGenerationProvider` | `openai_compatible`, `fake` | nenhum fluxo de negócio (preparado para a IA principal) |
| Embedding | `EmbeddingProvider` | `openai_compatible`, `fake` | nenhum (preparado para RAG) |
| Visão | `VisionProvider` | `openai_compatible`, `fake` | nenhum |
| Classificador | `DocumentClassificationProvider` | `llamafile`, `openai_compatible`, `fake` | classificação na ingestão |

- Cada capacidade resolve seu driver de forma independente (`AI_TEXT_DRIVER`, `AI_EMBEDDING_DRIVER`, `AI_VISION_DRIVER`, `DOCUMENT_CLASSIFIER_DRIVER`). Driver ausente ou desconhecido gera `AI_NOT_CONFIGURED`.
- Novos drivers entram por `AiProviderManager::extend()` sem alterar consumidores.
- DTOs próprios (`app/Data/Ai`) isolam a aplicação do JSON do fornecedor; o JSON bruto não sai de `app/Services/Ai/OpenAiCompatible`.
- Erros controlados (`AiProviderException`): `AI_RATE_LIMITED`, `AI_TIMEOUT`, `AI_UNAVAILABLE`, `AI_AUTH_FAILED`, `AI_REJECTED`, `AI_INVALID_RESPONSE`, `AI_INVALID_REQUEST`, `AI_NOT_CONFIGURED`, com correlation id; chaves, URLs e prompts não aparecem em mensagens.
- Telemetria: logs `ai_request_completed` e `ai_request_failed` com provider, capacidade, operação, modelo, latência, tokens e sucesso, sem conteúdo ou segredos. Custo monetário não é calculado.
- `EmbeddingMetadata` (provider, modelo, dimensões, data, hash do conteúdo) define a validade de um embedding e detecta vetores obsoletos; ainda não é persistido.
- Chamadas longas devem ocorrer em jobs, nunca em controllers ou componentes Livewire.
- Limites de entrada: `AI_MAX_INPUT_CHARACTERS` (200.000) e `AI_MAX_IMAGE_BYTES` (10 MiB); timeouts e retries por capacidade.

## 3. Driver fake

Determinístico, usado em testes e desenvolvimento offline, evitando chamadas reais a modelos nos testes automatizados.

## Situação

| Item | Situação |
| --- | --- |
| Contratos, manager, DTOs, erros, driver `openai_compatible` e `fake` | implementados e testados |
| Driver `llamafile` do classificador | implementado e testado; usado na ingestão |
| Texto, embedding e visão em fluxos de negócio | planejados |
| Persistência de metadados de embedding | planejada |

## Evidências no repositório

- `app/Services/Ai/` (`AiProviderManager.php`, `AiProviderException.php`, contratos, `OpenAiCompatible/`, `Fake/`)
- `app/Data/Ai/`; `config/ai.php`
- Testes: `tests/Unit/Services/Ai/AiProviderLayerTest.php`, `LlamafileClassificationProviderTest.php`, `DocumentClassificationResponseTest.php`

## Resultados observados

Apenas verificação por testes automatizados com driver fake e respostas simuladas; nenhum teste chama modelo ou API externa real. Os drivers `openai_compatible` de texto, embedding e visão não foram exercitados contra um fornecedor real.

## Lacunas / a verificar

- Validar o driver `openai_compatible` contra pelo menos um fornecedor real e contra um servidor local alternativo.
- Definir política de custo e de privacidade para uso de API externa na IA principal (dados do usuário).
