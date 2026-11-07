# 29. Perguntar confiável: detecção robusta, bula inteira como segunda fonte, evidência auditável e revisão humana

Data: 07/11/2026. Branch `feat/perguntar-confiavel`. Complementa as notas 24 (perguntas livres), 26 (tolerância a erros) e 28.

## 1. Sintoma e diagnóstico

Em produção o Perguntar "praticamente nunca detectava os medicamentos": "Naprix A" → "Não consegui ligar NAPRIX a uma bula"; "renitec" → "Encontrei o nome RENITEC, mas ainda não consigo ligar ele a uma bula"; "Quais os efeitos colaterais para naprix d?" → trechos de posologia e apresentação da bula do profissional, sem resposta.

Medições no banco de desenvolvimento (catálogo ANVISA de 39.416 registros):

| Medida | Valor |
| --- | --- |
| Medicamentos com princípio ativo ligado (`anvisa_medicine_ingredients`) | 22.051 (56 %) |
| Medicamentos com texto de ativo sem vínculo | 8.581 (2.478 textos distintos: dipirona, zolpidem, ambroxol, dexclorfeniramina, bromoprida…) |
| Medicamentos sem texto de ativo | 10.574 |
| "posso tomar losartana com dipirona?" (detector anterior) | `DIPIRONA` × 4 sem vínculo; losartana perdida |

Causas, em ordem de impacto:

1. **Catálogo sem vínculos em produção**: `knowledge:build-ingredients` nunca rodou após as importações; sem `anvisa_medicine_ingredients` o detector agrupava cada grafia do ativo como um grupo diferente ("dipirona", "dipirona sodica", "dipirona monoidratada" → 324 grupos) e descartava o termo como ambíguo, ou devolvia o mesmo remédio quatro vezes.
2. **Consulta ao catálogo com `limit(600)` ordenada por nome**: as centenas de "dipirona" expulsavam a "losartana" da resposta.
3. **Nomes em português que as regras não traduzem** para o DDInter (inglês): "dipirona" → metamizole nunca ligava, logo sem interação.
4. **Bulas processadas antes do vinculador** (sem metadados): sem `knowledge_document_ingredients`, mesmo com a bula cadastrada.
5. **Sufixo de linha de produto**: "naprix d" virava NAPRIX ("d" descartado como palavra curta).
6. **Trechos irrelevantes na tela**: quando o modelo não respondia, a tela mostrava os dois primeiros trechos recuperados, de qualquer seção.

## 2. Fluxo de resposta (novo)

```
pergunta → triagem → detecção (catálogo com ou sem vínculo) → bulas de cada remédio (MedicineLeafletFinder)
        → intenção da pergunta (QuestionIntentDetector) → evidências já extraídas + busca por trechos nas seções do assunto (bula do paciente primeiro)
        → 1ª geração (modelo julga; "insuficiente" nunca vira trecho na tela)
        → bula inteira (LeafletEvidenceService): trecho literal + seção + página + confiança, conferido contra o documento, gravado em knowledge_leaflet_facts
        → 2ª geração com a evidência
        → sem evidência: resposta honesta + pergunta na fila de revisão humana (medication_questions.review_status)
```

### 2.1 Detecção (`MedicationMentionDetector`)

- Consulta **agregada** ao catálogo: um grupo por nome × princípio ativo, com um registro representativo (preferindo um já ligado a ingredientes) e o tamanho do grupo; limite de 800 grupos, nunca de linhas, então nenhum termo expulsa outro.
- Chave de grupo: ids dos princípios ativos ligados; sem vínculo, os **componentes do ativo sem sais, hidratos e dose** (`IngredientNameNormalizer::base`: "losartana potássica 50mg" → "losartana"); sem ativo, o nome do produto. Grupos sem vínculo entram no grupo ligado com os mesmos componentes (`mergeUnlinked`).
- Regra de foco de um termo com vários grupos: nome de produto exato > todos os grupos partilham o ativo que o termo nomeia (vale o grupo só com esse ativo: "losartana" → losartana, não losartana + hidroclorotiazida) > até três grupos > ambíguo ("acido").
- Sufixo de linha de produto ("naprix d", "naprix a", "losartana hct"): testado como nome exato antes do nome sozinho.
- Ditado que parte o nome ("ome prazol"): a junção de palavras vizinhas entra como candidato.
- `guesses()`: quando nada é reconhecido, nomes parecidos (pg_trgm ≥ 0,3) viram chips "Você quis dizer…?" na tela; os excluídos pelo "Não era esse remédio" não voltam.

### 2.2 Bulas de um medicamento (`MedicineLeafletFinder`)

1. Princípio ativo ligado → documentos ligados. Sem vínculo, o catálogo é **ligado na hora** com os mesmos aliases do construtor (`salt_stripped`, `alias`) e, se não houver alias, com a **tradução por IA** (`IngredientTranslationService`, método `ai_translation`, confiança 0,8). O vínculo é gravado: interações DDInter, contexto do tratamento e Bula simples passam a funcionar.
2. Pelo nome: bulas prontas cujo produto (metadado ou título antes de " — ") ou princípio ativo declarado é igual ao do medicamento, comparados sem sais/dose ("RENITEC®" → renitec; "maleato de enalapril" = "enalapril"). Nunca escolhe bula "parecida".

`LeafletSummaryService` (Bula simples) e `Bula` usam o mesmo localizador; sem princípio ativo, o cache do resumo é por nome do produto (`ingredient_key = p:<nome>`).

### 2.3 Intenção e seção (`QuestionIntentDetector`)

Palavras-chave por seção em `config/bulai.php` (`leaflet.sections.*.keywords`): efeitos colaterais (efeito, reação, sono, tontura, dor de cabeça…), como usar (dose, jejum, manhã, esqueci…), interações (junto, cerveja, café, leite… e ≥ 2 medicamentos), não use se, riscos, para que serve, procure ajuda, benefícios. A busca por trechos roda primeiro restrita às seções do assunto na **bula do paciente**, depois em todas as bulas, depois sem restrição; `retrieval.section_matched` registra se algum trecho veio da seção adequada.

### 2.4 Bula inteira como segunda fonte (`LeafletEvidenceService`)

Quando a 1ª geração não responde ("insuficiente", inválida ou sem trecho), a bula inteira (trechos com id, seção e página; até `BULAI_LEAFLET_FALLBACK_MAX_CHARACTERS`, 150.000) vai ao modelo com a pergunta e o assunto esperado. O modelo devolve `{found, items[{chunk, excerpt, summary, confidence}]}`. Cada item só é aceito se o `chunk` pertence ao documento, o `excerpt` (30–700 caracteres) está **literalmente** dentro desse trecho e a confiança ≥ `BULAI_LEAFLET_FALLBACK_MIN_CONFIDENCE` (0,5). Aceitos viram `knowledge_leaflet_facts`:

| Campo | Conteúdo |
| --- | --- |
| `document_id`, `chunk_id`, `medicine_id` | bula, trecho de origem, medicamento perguntado |
| `topic`, `section`, `page_start` | assunto da pergunta, seção e página do trecho |
| `excerpt`, `summary`, `confidence` | trecho literal, frase simples do modelo, confiança |
| `extraction_method`, `review_status` | `pdf_fallback_ai`; `pending` → `verified` / `rejected` (admin) |
| `document_sha256`, `document_version`, `model`, `run_id`, `question`, `extracted_at` | versão do PDF e do conteúdo, modelo, rastreio |

Nas próximas perguntas do mesmo assunto, as evidências não rejeitadas entram **antes** da busca por trechos (`stored()`), sem reler a bula. A bula do paciente é lida primeiro; se não responder, a do profissional (no máximo duas leituras por medicamento e por pergunta). Custo típico: uma bula de 30–110 mil caracteres ≈ 10–35 mil tokens de entrada no Sonnet.

### 2.5 Resposta honesta, humana e auditável

- Prompt da resposta (`interaction-explanation-v2`): fala com a pessoa ("você"), resposta direta primeiro, frases curtas, termos da bula mantidos e explicados entre parênteses, sem listas; validação afirmação a afirmação inalterada.
- Tela: frase de abertura quando a bula inteira foi consultada ("Encontrei na bula de NAPRIX a parte sobre efeitos colaterais mais comuns.") e, para efeitos colaterais, "Os efeitos relatados podem variar de pessoa para pessoa"; **Fonte: bula do paciente de X, parte “Reações adversas”, página 12**; "De onde tirei isso" mostra só os trechos que sustentam alguma afirmação.
- Sem evidência: "Procurei na bula inteira de X, mas não localizei uma informação suficientemente clara sobre … Prefiro não completar a resposta com suposições. O que encontrei fala de … Isso não quer dizer que seja seguro." + orientação a farmacêutico/médico + "Registrei sua pergunta para a equipe buscar mais fontes". Trechos de outra seção nunca aparecem; `EXCERPTS_ONLY` só quando o modelo não pôde ser consultado e os trechos vêm da seção adequada.
- `NOT_IDENTIFIED` fala como "conheço o X, mas ainda não tenho a bula dele" (o vínculo deixou de ser um conceito para o paciente).

### 2.6 Revisão humana (`admin.knowledge.unanswered.index`)

`medication_questions.review_status` (`pending` → `resolved` / `dismissed`, `review_reason` = `no_leaflet` | `not_identified` | `no_evidence`, `reviewed_by`, `reviewed_at`). A tela "Perguntas sem resposta" agrupa por remédio e motivo, com exemplos e a ação sugerida (cadastrar a bula, ligar o princípio ativo, buscar bula mais completa); "Resolvida" fecha o grupo. Entra na Visão geral como item de atenção.

### 2.7 Interações: tradução de princípios ativos por IA (`IngredientTranslationService`)

Os nomes que aliases curados e regras ortográficas não ligaram ao DDInter são traduzidos em lotes de 40 para a INN em inglês; a IA **só propõe**: o nome devolvido precisa existir no catálogo (nome canônico ou alias não gerado por IA), vira alias `origin = ai`, `pending_review`, e os medicamentos são ligados por ele. Roda ao fim de `knowledge:build-ingredients` (`--no-ai` desliga), no job `BuildActiveIngredientCatalog` (enfileirado automaticamente ao fim de cada importação do catálogo ANVISA e do DDInter) e, na hora da pergunta, pelo localizador. `IngredientNameNormalizer` ganhou sais que faltavam ("hemitartarato de zolpidem") e descarta dose ("50mg").

## 3. Arquivos

`app/Services/Patient/{MedicationMentionDetector,MedicationQuestionService,QuestionIntentDetector}.php`, `app/Services/Knowledge/{MedicineLeafletFinder,LeafletEvidenceService,IngredientTranslationService,ActiveIngredientCatalogBuilder,IngredientNameNormalizer}.php`, `app/Jobs/BuildActiveIngredientCatalog.php` (+ `ImportAnvisaCatalog`, `ImportDrugInteractions`), `app/Models/Knowledge/KnowledgeLeafletFact.php`, `app/Models/Consultation/MedicationQuestion.php`, `app/Livewire/Consulta/{Perguntar,Bula}.php`, `app/Livewire/Admin/Knowledge/UnansweredQuestions/Index.php`, `app/Livewire/Admin/Documents/Show.php` (aba "Evidências extraídas", revisão), `app/Services/Leaflet/LeafletSummaryService.php`, `app/Services/Interactions/ExplanationGenerator.php`, `config/bulai.php` (`questions.leaflet_fallback`, `ingredients.ai_translation`, `leaflet.sections.*.keywords`, `generation.prompt_version = interaction-explanation-v2`), `config/knowledge.php` (`ingredients.job_timeout_seconds`), migrações `2026_11_07_120000_create_knowledge_leaflet_facts_table` e `2026_11_07_120100_add_review_to_medication_questions`.

## 4. Testes

`tests/Feature/Consulta/PerguntarConfiavelTest.php` (7): detecção sem vínculos, sufixos e ditado; "você quis dizer"; vínculo na hora e bula pelo nome (Perguntar e Bula simples); bula inteira com evidência gravada, reaproveitada e rejeitável; resposta honesta + fila de revisão + `EXCERPTS_ONLY` só com seção adequada; job após importação; tradução por IA (construtor e localizador). `PerguntaLivreTest` e `PerfilEPerguntasTest` atualizados (fonte com seção/página, bula inteira lida quando a busca não acha, "página" deixou de ser jargão).

## 5. Limitações e próximos passos

- A bula inteira é lida por pergunta (não na ingestão); documentos acima do limite de caracteres são truncados (registrado em `retrieval.leaflet_fallback.documents[].truncated`). Uma análise proativa na ingestão (ficha por seção) reaproveitaria o mesmo validador.
- Aliases propostos pela IA (`origin = ai`) e evidências extraídas ficam `pending_review`; não há ainda tela própria de aliases (contagem em `IngredientTranslationService::pendingCount()`).
- Em produção, o catálogo só ganha vínculos depois de `knowledge:build-ingredients` (ou do job após uma importação nova); até lá o localizador liga medicamento a medicamento, a cada pergunta.
