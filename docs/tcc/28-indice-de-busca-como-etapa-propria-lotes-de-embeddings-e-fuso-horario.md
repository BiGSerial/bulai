# Nota 28 — Índice de busca como etapa própria, lotes de embeddings dimensionados para CPU e fuso horário

Data: 07/11/2026 · Branch `fix/indice-de-busca` · Seções do TCC: 3.3.2 (pipeline de ingestão, etapa de embeddings), 3.5.2 (observabilidade; complementa a nota 27), 3.5.3 (operação da base de conhecimento).

## 1. Problema observado em produção

Depois da release de 06/11 (nota 27), a linha do tempo mostrou com precisão onde o pipeline falhava: `Índice de busca (embeddings) · 36 trechos · bge-m3 · AI_TIMEOUT · 154 s`, em vários documentos. Outros documentos, processados antes de a indexação ser automática, estavam com o índice "Aguardando" e sem ação disponível na interface: a única opção era "Reprocessar", que refaz OCR, normalização e trechos.

| Medição / fato | Valor |
| --- | --- |
| Trechos por requisição ao serviço de embeddings | 16 (constante no comando e no job) |
| Como o serviço `embeddings` atende uma lista | O adaptador do supervisor (`services/local-ai/app/supervisor.py`, `embed_batch`) chama o llamafiler **um texto por vez** e concatena a resposta |
| Vazão medida no ambiente de desenvolvimento (2 vCPU, bge-m3 Q8) | 2.171 tokens em 17,7 s ≈ 123 tokens/s |
| Lote típico de bula (16 trechos × ~800 tokens) | ≈ 13 mil tokens ≈ 105 s em dev; mais lento na VPS |
| Limite no PHP | `AI_EMBEDDING_TIMEOUT` ausente no `app.env` da VPS → padrão do código, **60 s**; no dev, 120 s |
| Consequência de um lote estourado | Os 16 trechos ficavam sem vetor; após 3 lotes com falha a indexação parava; o documento ia para `embedding_status = FAILED` |

Diagnóstico: o tamanho do lote foi escolhido pensando em uma API de nuvem (que processa a lista em paralelo) e não no serviço local, cujo tempo cresce linearmente com o número e o tamanho dos textos. O limite de tempo era por requisição, independente do que ela carregava.

## 2. Decisões

### 2.1 Lotes limitados por quantidade **e** por caracteres; lote lento refeito trecho a trecho (`KnowledgeIndexer`)

- `ai.embedding.batch_size` (padrão 4) e `ai.embedding.batch_characters` (padrão 6.000) limitam cada requisição; um trecho maior que o orçamento vai sozinho. Com a vazão medida, uma requisição leva ~30-60 s em 2 vCPU, abaixo do novo limite de 180 s.
- Se uma requisição com mais de um trecho falhar por `AI_TIMEOUT` ou `AI_UNAVAILABLE`, os trechos são reenviados **um a um** (`split_batches` no resumo e na linha do tempo): um trecho lento não derruba os que cabiam no tempo. Um trecho que falha sozinho conta como lote com falha; três lotes com falha interrompem a execução (proteção contra serviço fora do ar).
- Limites subiram: `AI_EMBEDDING_TIMEOUT` 60/120 → 180 s (padrão do código e arquivos de ambiente); `LOCAL_AI_REQUEST_TIMEOUT` do serviço `embeddings` 120 → 180 s (por texto).

### 2.2 Índice de busca em job próprio (`IndexKnowledgeDocumentEmbeddings`)

- `ProcessKnowledgeDocument` termina no vínculo com o princípio ativo e **enfileira** o job do índice (fila única: roda logo em seguida). Efeitos: o tempo do OCR e o dos embeddings deixam de disputar o mesmo `timeout` de job; o índice pode ser refeito sem reprocessar o PDF; o job tem limite próprio (`KNOWLEDGE_INDEX_JOB_TIMEOUT_SECONDS`, 600 s), `ShouldBeUnique` por documento e `failed()` que marca `embedding_status = FAILED` e fecha a etapa `embed` com `INDEX_JOB_FAILED` (nunca fica "Gerando" para sempre).
- O job reinicia só a linha `embed` da linha do tempo (`ProcessingTimeline::resetStep`); as etapas de leitura, OCR e trechos da execução anterior permanecem visíveis.
- A situação do índice do documento é sempre recalculada ao fim (`KnowledgeIndexer::index(documentId:)` inclui o documento na atualização mesmo sem trechos pendentes).

### 2.3 Ações na administração

- Página do documento: alerta quando os trechos estão prontos e o índice está "Aguardando" (amarelo: "O índice de busca ainda não foi gerado") ou "Falhou" (vermelho: código e explicação em português, `KnowledgeProcessingEvent::errorLabel()`), com o botão "Gerar o índice de busca" / "Refazer só o índice de busca". Menu "Mais": "Refazer o índice de busca" para qualquer documento pronto.
- Lista de documentos: botão "Gerar N índices de busca pendentes" (documentos com `content_status = READY` e índice diferente de READY/PROCESSING), que enfileira um job por documento.
- O nome da ação Livewire evita a palavra "embeddings" no HTML (a interface fala "índice de busca"; teste existente garante).

### 2.4 Fuso horário America/Sao_Paulo

- `APP_TIMEZONE` (config `app.timezone`, antes fixo em UTC) e `TZ` em todos os containers (app, worker, scheduler, OCR, IA local, embeddings, PostgreSQL com `PGTZ`), nos dois arquivos compose; `php.ini` de produção coerente.
- Trade-off registrado: datas antigas foram gravadas em UTC em colunas sem fuso e passam a ser lidas como horário de Brasília (3 h adiantadas). Não houve conversão retroativa (dados do MVP). O fuso do paciente (programação do tratamento) é independente.
- Defeito latente encontrado pela suíte ao mudar o fuso: `TreatmentScheduleService` gravava `scheduled_for`/`recorded_at` convertidos para UTC, mas o Eloquent formata o Carbon no fuso da instância e **lê** colunas `timestamp` no fuso do app; com `APP_TIMEZONE=UTC` coincidia, em Brasília deslocava 3 h (dose marcada aparecia "atrasada", lembrete repetido). Corrigido com `stored()` (fuso do app em toda gravação e comparação); os seis testes do tratamento passam em UTC e em Brasília.

## 3. Mapeamento para o texto do TCC

- **3.3.2 Pipeline**: a etapa de embeddings vira unidade de trabalho própria, reexecutável, com parâmetros dimensionados pela vazão do serviço local (argumento: "IA local por padrão" exige calibrar lotes para CPU).
- **3.5.2 Observabilidade**: a linha do tempo da nota 27 foi o instrumento que localizou a falha; esta nota fecha o ciclo medir → corrigir → oferecer ação ao operador.
- **Limitações**: a vazão foi medida em um único ambiente; os padrões (4 trechos / 6.000 caracteres / 180 s) são conservadores e configuráveis.

## 4. Evidências

| Evidência | Onde |
| --- | --- |
| Lotes por caracteres, retomada trecho a trecho, trecho que falha sozinho, rótulo do erro | `tests/Feature/Knowledge/KnowledgeSearchIndexTest.php` (6 testes) |
| Job reinicia só a etapa `embed`, marca FAILED sem provedor ou quando morre, ignora documento sem trechos | idem |
| Página do documento (gerar/refazer), lista (ação em massa só para prontos sem índice) | idem |
| Embeddings ao fim do processamento (agora via job enfileirado) | `KnowledgeProcessingTimelineTest`, `KnowledgeDocumentProcessingTest::test_job_runs_the_processor_and_command_dispatches_the_job` |
| Compose de produção válido com `TZ`/`PGTZ`/`APP_TIMEZONE` | `docker compose -f compose.prod.yaml config` (CI de infraestrutura) |
| Agenda, lembretes e marcações corretos em UTC e em Brasília | `tests/Feature/Consulta/{AvisosTest,ProgramacaoDoTratamentoTest,RemediosDeHojeTest}` (rodados com `APP_TIMEZONE=UTC` e `America/Sao_Paulo`) |

## 5. Números da suíte

| Momento | Resultado |
| --- | --- |
| Antes (release de 06/11) | 599 testes, 598 aprovados, 1 ignorado |
| Depois | 606 testes, 605 aprovados, 1 ignorado, 15.962 asserções (07/11/2026) |

## 6. Complemento (07/11): vínculo bula ↔ princípio ativo como ação própria

O teste em produção depois do índice mostrou um segundo elo faltando: o Perguntar parte do nome comercial, vai ao catálogo ANVISA (`anvisa_medicine_ingredients`) e daí às bulas ligadas ao princípio ativo (`knowledge_document_ingredients`). Bulas processadas antes do vinculador existir não tinham esse vínculo, e o vinculador só usava registro, metadado de princípio ativo e título. Decisões: (i) nova estratégia `catalog` no `KnowledgeDocumentIngredientLinker` (nome do produto normalizado → catálogo → ingredientes já ligados; confiança 0,6; só quando as anteriores não resolvem), (ii) ação "Vincular princípios ativos" por documento (síncrona, etapa `link` na linha do tempo) e em massa (`LinkKnowledgeDocumentIngredients`), com alerta na página quando a bula pronta não tem vínculo, (iii) dependência operacional explícita: `knowledge:build-ingredients` precisa rodar após importar o catálogo ANVISA e o DDInter. Evidência: `tests/Feature/Knowledge/KnowledgeIngredientLinkActionsTest.php` (3). Limitação: produtos ausentes do catálogo ANVISA continuam sem vínculo até o reprocessamento identificar o princípio ativo.
