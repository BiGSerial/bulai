# Estado atual (auditoria de 03/11/2026)

Auditoria do repositório na branch `feat/interacoes-ddinter` (commit base `9ea4265`). Suíte de base: **354 testes, 354 aprovados** (PostgreSQL 17 + pgvector local).

## Implementado e funcional

| Área | Situação | Onde |
| --- | --- | --- |
| Ingestão de bulas (PDF, lote, OCR, classificação heurística + LLM, revisão) | Funcional | `app/Services/Knowledge`, `app/Livewire/Admin/Knowledge` |
| Linha do tempo do processamento (atualizado em 06/11/2026) | Cada etapa (texto nativo, OCR, normalização, trechos, embeddings, vínculo) em `knowledge_processing_events`, visível na tela do documento; `ProcessingTimeline::active()` para a Fila/SSE | `App\Services\Knowledge\ProcessingTimeline`, `docs/technical-foundation.md` |
| Catálogo ANVISA | 39.416 registros em `anvisa_medicines` (10.574 sem princípio ativo preenchido) | `AnvisaMedicineCsvImporter` |
| Interações DDInter | 160.235 pares com **gravidade apenas** (major 26.914, moderate 96.675, minor 6.833, unknown 29.813), nomes em inglês | `drug_interactions`, `DrugInteractionCsvImporter` |
| Documentos/chunks | 17 documentos, 782 chunks, `embedding_status=PENDING`, coluna `embedding vector` sem dimensão | `knowledge_documents`, `knowledge_chunks` |
| Contratos de IA | `TextGenerationProvider`, `EmbeddingProvider`, `VisionProvider`, `DocumentClassificationProvider`, `AiProviderManager`, drivers `openai_compatible` e `fake` | `app/Services/Ai` |
| Auth e papéis | Login, primeiro acesso, `UserRole`, middleware `admin`, auditoria de usuário | `app/Http`, `routes/web.php` |
| PWA (casca) | `manifest.webmanifest` e `sw.js` que não cacheiam `/admin`, `/login` | `public/` |
| Infra | Compose: postgres (pgvector), redis, document-ocr, local-ai (llamafile Qwen2.5-1.5B) | `compose.yaml` |
| Saúde dos serviços (atualizado em 06/11/2026) | Sondada em segundo plano por `services:probe-health` (a cada 5 s, no scheduler) e lida do cache pelas telas e pelo SSE; distingue ocupado (`UNRESPONSIVE`, até 3 falhas seguidas) de inacessível e mostra a página do OCR em curso. Ver `docs/realtime-options.md` | `App\Services\Health\ServiceHealthChecker`, `App\Data\Health\ServiceHealth` |

## Ausente (verificado)

- Embeddings: nada gera, grava ou consulta vetores. **Não existe serviço de embeddings** (o `local-ai` expõe só `/v1/chat/completions`).
- Recuperação (lexical, vetorial, híbrida), RAG, análise de interações, resposta fundamentada.
- Relação medicamento → princípio ativo estruturada (só texto livre em `anvisa_medicines.active_ingredient`).
- Ponte de nomes PT↔EN: o catálogo ANVISA é em português ("VARFARINA SÓDICA") e o DDInter em inglês ("Warfarin"). Só 21.532 pares casam hoje por coincidência de grafia (captopril, atenolol...).
- Interface do usuário final, histórico de consultas, rotas públicas de consulta.
- Infraestrutura de avaliação (datasets, métricas, executor).
- Provedor Anthropic (em implementação pelo Agente I).

## Limitações herdadas que condicionam o projeto

1. O DDInter importado traz **gravidade, não texto**: a explicação e as evidências textuais só podem vir dos chunks das bulas (seções de interações) — por isso o RAG é necessário.
2. Corpus de bulas ainda pequeno (17 documentos): cobertura de recuperação será limitada e deve ser reportada como tal.
3. Licença do DDInter: verificar condições de uso antes de redistribuir/exibir dados em produção (registrado em `docs/tcc/15-ameacas-a-validade-e-limitacoes.md`).
4. Regras de projeto: `.ai/rules` não existe neste repositório.
