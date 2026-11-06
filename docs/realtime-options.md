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

- `GET /admin/knowledge/stream` (`KnowledgeStreamController`, nome `admin.knowledge.stream`, só ADMIN): `text/event-stream` com `retry: 3000`, evento `changed` quando algo muda e comentário `: ping` caso contrário. Payload JSON: `worker`, `jobs`, `active_items`, `stale`, `documents` (bulas com etapa em andamento: `id`, `title`, `step`, `summary`, `progress` `{page, total}` ou `null`) e `services` (por serviço: `state`, `busy`, `progress`, `hint`). A cada ~2 s calcula a "assinatura" de `QueueStatusService::snapshot()` + `ServiceHealthChecker::all()` **só com o que muda de fato** (estado do worker, jobs, itens com estado/percentual/rótulo/parado, bulas com etapa/progresso/parado, serviços com estado/ocupação/progresso/correlação/falhas): tempos decorridos, latências e "lido há N s" ficam fora, senão haveria um evento a cada ciclo. A página do OCR avançando, sim, dispara evento.
- **Bulas em processamento (06/11/2026):** `QueueStatusService::snapshot()['documents']` nasce de `ProcessingTimeline::active()` (etapas `running` da linha do tempo, ver `docs/technical-foundation.md`). Na etapa `ocr` o worker PHP não vê a página (a chamada é síncrona): o serviço casa `detail.correlation_id` do evento com `correlationId` da última leitura de saúde do OCR (`ServiceHealthChecker::ocr()`, do cache) e preenche `progress` e `summary` ("página 7 de 12 · 65 s"). Sem casamento, `progress` é nulo e o resumo é o da própria etapa ("32 de 80 trechos · lote 2 de 5"). `stale` = sem sinal há mais de `knowledge.processing.progress_stale_minutes` (o `updated_at` do evento ou, no OCR casado, a última resposta do serviço).
- Conexões curtas: `KNOWLEDGE_STREAM_SECONDS` (25), `KNOWLEDGE_STREAM_INTERVAL_MS` (2000) e no máximo `KNOWLEDGE_STREAM_MAX_PER_USER` (2) conexões por usuário (429 acima disso; contador no cache, liberado no `finally`). A sessão é liberada (`session()->save()`) antes do loop.
- Cliente: `resources/js/app.js` abre um único `EventSource` quando a página tem `data-realtime-url` (shell do admin) e dispara o evento de janela `bulai-realtime`; as telas Pendências e Fila e serviços chamam `$wire.$refresh()` nele. O `wire:poll` (5 s) permanece como reserva.
- Produção: location própria no Nginx (`fastcgi_buffering off`, `X-Accel-Buffering: no`). Cada stream ocupa um worker do FPM (`pm.max_children = 6`); com 2 conexões por usuário e poucos administradores é aceitável. **Se houver mais administradores simultâneos, criar um pool/socket FPM dedicado ao stream ou reduzir `KNOWLEDGE_STREAM_MAX_PER_USER`.** Fila, cache e sessão usam Redis (ver `docs/technical-foundation.md`); o stream ainda compara snapshots (Pub/Sub fica para quando houver fan-out alto).

## Sondagem de saúde em segundo plano (06/11/2026)

Problema medido: a sondagem dos serviços (`Http::timeout(3)`) rodava dentro da renderização das telas e do laço do SSE. Durante um OCR o serviço leva de 1,5 s a 4,4 s para responder a qualquer pedido (o PaddleOCR segura o interpretador); cada estouro virava "Inacessível" por 5 s de cache, e "ocupado" era confundido com "fora do ar". Imagens antigas sem `/status` dobravam o custo com o fallback para `/health`.

- **Quem sonda:** o comando `services:probe-health` (`App\Console\Commands\ProbeServiceHealth`), agendado em `routes/console.php` com `everyFiveSeconds()->onOneServer()->withoutOverlapping()`; o container `scheduler` (`schedule:work`) o executa. Cada leitura vai para o cache em `service-health:{serviço}` (1 dia), com `observed_at`.
- **Quem lê:** `ServiceHealthChecker::all()/localAi()/ocr()/embeddings()` devolvem a última leitura do cache; o painel, a Fila e serviços e o SSE nunca esperam por um serviço ocupado. Se a leitura tiver mais de `FRESH_SECONDS` (15 s: scheduler parado ou desenvolvimento sem ele) ou não existir, a sondagem é feita na hora, como antes. `refresh()` sonda os três e grava (usado pelo comando e pelos testes). `ServiceRestarter` apaga a chave ao reiniciar um serviço, forçando uma leitura nova.
- **Ocupado ≠ fora do ar:** cada leitura guarda `failures` (sondagens seguidas sem resposta). Até `FAILURES_BEFORE_UNREACHABLE` (3), o estado é `UNRESPONSIVE` (tom `warning`): "Ocupado, sem resposta há N s" quando a última leitura boa tinha `busy=true`, senão "Sem resposta"; modelo, ocupação e página do OCR da última leitura boa são preservados, e `responded_at` marca quando o serviço respondeu pela última vez. A partir da 3ª falha seguida: `UNREACHABLE` ("Inacessível", `danger`). Uma resposta zera o contador.
- **Progresso do OCR:** o `/status` do OCR devolve `current` (`correlation_id`, `page`, `total_pages`, `started_at`, `elapsed_seconds`) enquanto lê um documento. `ServiceHealth` expõe `progress` (`{page, total}`), `correlationId` e `hint()`: "Página 7 de 12 · 65 s" no OCR, "Processando há N s" na IA local/embeddings ocupados, "Lido há N s" quando o serviço está livre, "Última resposta há N s" quando não respondeu. O `correlation_id` é o mesmo `X-Correlation-Id` que o `HttpDocumentOcrClient` envia, o que permite ligar a página em curso ao documento em processamento.
- **Contrato JSON** (`GET /admin/knowledge/services/health` e `ServiceHealth::toArray()`): `service`, `state`, `label`, `hint`, `latency_ms`, `model`, `busy`, `active_requests`, `current_seconds`, `restarts`, `progress`, `correlation_id`, `observed_at`, `responded_at`, `failures`.
- Testes: `tests/Feature/Admin/ServiceHealthTest.php`.

## Limitações atuais

- Sem SSE conectado, o polling mostra mudanças com atraso de até 10 s (saúde) ou 5 s (progresso).
- A página do OCR na Fila tem a idade da leitura de saúde (até 5 s com o scheduler); a IA local e os embeddings não informam progresso dentro de uma chamada, só "processando há N s" e, nos embeddings, o lote em curso gravado pelo próprio worker.
- A leitura de saúde tem até 5 s de idade (intervalo do comando agendado); sem o scheduler, até 15 s, e a sondagem volta a acontecer na requisição.
- Imagens do OCR e da IA local anteriores a 27/10/2026 não têm `/status` (nem `busy`/`current`): o indicador funciona, mas sem ocupação nem página. Reconstrua as imagens (`docker compose build document-ocr local-ai`).
