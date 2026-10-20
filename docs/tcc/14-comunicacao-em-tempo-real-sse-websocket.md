# Comunicação em tempo real: polling, SSE e WebSocket

Mapeamento no docx: Cap. 3, seção 3.3.3 (Tecnologias Planejadas) e 3.3.2 etapa 6 (Geração da resposta pela LLM; streaming é relevante para a interface). Fonte primária: `docs/realtime-options.md`.

## Objetivo do tópico

Registrar a análise de alternativas de transporte para atualizações do servidor ao navegador e a decisão adotada.

## 1. Fundamentação

- Polling: o cliente consulta periodicamente; simples, com latência limitada pelo intervalo.
- Server-Sent Events (SSE): fluxo unidirecional servidor para cliente sobre HTTP, com reconexão nativa.
- WebSocket: canal bidirecional persistente, exige infraestrutura própria.

[referência a incluir: especificações WHATWG (SSE) e RFC 6455 (WebSocket).]

## 2. Necessidades identificadas

Todas unidirecionais (servidor para cliente):

| Necessidade | Situação |
| --- | --- |
| Saúde de IA local e OCR no Admin | implementado, polling de 10 s |
| Progresso de reanálise/classificação | implementado, polling de 2 a 3 s |
| Progresso de processamento de documentos | futuro |
| Resposta da IA principal em streaming de tokens | futuro |
| Chat bidirecional/presença | não previsto |

## 3. Comparação resumida

| Critério | Polling | SSE | WebSocket (Reverb) |
| --- | --- | --- | --- |
| Infraestrutura adicional | nenhuma | nenhuma | processo persistente, porta, proxy |
| Hospedagem compartilhada | sim | sim, com limites | improvável |
| Bidirecional | não | não | sim |
| Streaming de tokens de LLM | não | adequado | possível, desnecessário |

## 4. Decisão

1. Agora: polling Livewire (implementado).
2. Próxima etapa recomendada: SSE para progresso de jobs e resposta da IA principal, com streams curtos (cerca de 60 s), reconexão com `Last-Event-ID`, limite de conexões por usuário e `X-Accel-Buffering: no`.
3. WebSocket/Reverb somente se surgirem requisitos bidirecionais, presença ou alto fan-out e o ambiente permitir processo persistente.

Critério de revisão: carga perceptível do polling ou necessidade de streaming de tokens.

## Evidências no repositório

- `docs/realtime-options.md`; `app/Http/Controllers/Admin/ServiceHealthController.php` (payload JSON que alimentaria o SSE)
- `resources/views/livewire/shared/analysis-run.blade.php`, componente `ServiceHealth`

## Resultados observados

Nenhum teste de latência ou carga comparando as opções. A decisão é de planejamento por critérios qualitativos; SSE ainda não foi implementado.

## Lacunas / a verificar

- Validar a viabilidade de SSE no ambiente de hospedagem alvo (limite de workers PHP-FPM e buffering do proxy).
- Medir o custo do polling com vários administradores simultâneos.
