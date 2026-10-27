# Respostas em tempo real do backend: polling, SSE ou WebSocket

Decisão de arquitetura (planejamento). Estado atual: **polling** implementado; SSE recomendado como próxima etapa; WebSocket não recomendado agora.

## Necessidades identificadas

| Necessidade | Direção | Frequência | Situação |
| --- | --- | --- | --- |
| Saúde da IA local e do OCR no Admin | servidor → cliente | a cada poucos segundos | implementado (polling 10 s) |
| Progresso de reanálise/classificação de pendências | servidor → cliente | 1–2 s durante o processamento | implementado (polling 2 s, `wire:poll`) |
| Progresso de processamento de documentos (OCR, chunks) | servidor → cliente | 1–2 s | futuro |
| Resposta da IA principal (tokens em streaming) | servidor → cliente | contínuo, por pergunta | futuro |
| Chat com interação bidirecional/presença | ambos | contínuo | não previsto |

Todas as necessidades atuais e previstas são **unidirecionais** (o cliente só reage a eventos do servidor).

## Opções

| Critério | Polling (Livewire) | SSE (Server-Sent Events) | WebSocket (Laravel Reverb) |
| --- | --- | --- | --- |
| Infra adicional | nenhuma | nenhuma (HTTP comum) | processo persistente + porta + supervisor + proxy WS |
| Compatível com hospedagem compartilhada/KingHost | sim | sim, com limites | improvável |
| Latência | até o intervalo | baixa | baixa |
| Custo por cliente ocioso | requisição periódica | 1 conexão aberta (ocupa 1 worker PHP-FPM) | 1 conexão leve (processo assíncrono) |
| Bidirecional | não | não (cliente usa POST à parte) | sim |
| Reconexão/`Last-Event-ID` | n/a | nativa do navegador | manual/biblioteca |
| Passa por proxies/CDN | sim | sim, exige `proxy_buffering off` | exige configuração de upgrade |
| Streaming de tokens de LLM | não | **ideal** (via `fetch` + stream; `EventSource` é só GET) | possível, desnecessário |

## Recomendação

1. **Agora (feito):** polling Livewire para saúde (10 s) e progresso (2 s). Simples, robusto e sem infraestrutura nova. O mesmo payload está disponível em `GET /admin/knowledge/services/health` (JSON), que será a fonte do SSE.
2. **Próxima etapa — SSE** para progresso de jobs e para a resposta da IA principal:
   - `response()->eventStream()` do Laravel, autenticado pela sessão do Admin;
   - streams curtos (≈ 60 s) com reconexão automática e `Last-Event-ID`, para não prender workers PHP-FPM;
   - limite de conexões simultâneas por usuário e `X-Accel-Buffering: no` no proxy;
   - eventos no mesmo formato JSON do endpoint atual, para trocar o transporte sem mudar a UI.
3. **WebSocket/Reverb apenas se** surgirem requisitos bidirecionais, presença ou fan-out alto (muitos usuários simultâneos) **e** o ambiente permitir um processo persistente. Até lá, o custo operacional não se justifica.

Critério de revisão: se o polling de saúde/progresso passar a gerar carga perceptível (muitos admins simultâneos) ou se a IA principal exigir streaming de tokens, implementar o item 2.

## Implementação do SSE (feito)

- `GET /admin/knowledge/stream` (`KnowledgeStreamController`, nome `admin.knowledge.stream`, só ADMIN): `text/event-stream` com `retry: 3000`, evento `changed` (resumo JSON: worker, contagem de jobs, itens ativos/parados, estado e `busy` dos serviços) quando algo muda e comentário `: ping` caso contrário. A cada ~2 s calcula a "assinatura" de `QueueStatusService::snapshot()` + `ServiceHealthChecker::all()`.
- Conexões curtas: `KNOWLEDGE_STREAM_SECONDS` (25), `KNOWLEDGE_STREAM_INTERVAL_MS` (2000) e no máximo `KNOWLEDGE_STREAM_MAX_PER_USER` (2) conexões por usuário (429 acima disso; contador no cache, liberado no `finally`). A sessão é liberada (`session()->save()`) antes do loop.
- Cliente: `resources/js/app.js` abre um único `EventSource` quando a página tem `data-realtime-url` (shell do admin) e dispara o evento de janela `bulai-realtime`; as telas Pendências e Fila e serviços chamam `$wire.$refresh()` nele. O `wire:poll` (5 s) permanece como reserva.
- Produção: location própria no Nginx (`fastcgi_buffering off`, `X-Accel-Buffering: no`). Cada stream ocupa um worker do FPM (`pm.max_children = 6`); com 2 conexões por usuário e poucos administradores é aceitável. **Se houver mais administradores simultâneos, criar um pool/socket FPM dedicado ao stream ou reduzir `KNOWLEDGE_STREAM_MAX_PER_USER`.** Fila, cache e sessão usam Redis (ver `docs/technical-foundation.md`); o stream ainda compara snapshots (Pub/Sub fica para quando houver fan-out alto).

## Limitações atuais

- Sem SSE conectado, o polling mostra mudanças com atraso de até 10 s (saúde) ou 5 s (progresso).
- O estado da IA local é consultado a cada 5 s no máximo (cache), mesmo com vários administradores abertos.
