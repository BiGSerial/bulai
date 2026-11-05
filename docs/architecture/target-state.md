# Arquitetura-alvo do MVP

```
Usuário (PWA Livewire)
  │  busca/seleção de medicamentos            consulta ─► InteractionAnalysisService
  ▼                                                          │
MedicationSearchService ──► anvisa_medicines                  ├─ IngredientResolver ─► active_ingredients (+aliases PT/EN)
                                                              ├─ InteractionLookup  ─► drug_interactions (gravidade estruturada)
                                                              ├─ KnowledgeRetriever ─► knowledge_chunks (lexical | vetorial | híbrido)
                                                              ├─ EvidenceContextBuilder (dados não confiáveis, delimitados)
                                                              ├─ TextGenerationProvider (anthropic | openai_compatible | fake)
                                                              └─ ResponseValidator (estrutura + referências + gravidade imposta)
                                                                   ▼
                                       interaction_consultations / interaction_pair_results (rastreável, retenção)
```

## Regras de domínio (invariantes)

1. **Existência e gravidade vêm do motor estruturado** (`drug_interactions`), nunca do LLM. O LLM só explica evidências recuperadas.
2. Veredito por par (`PairVerdict`): `INTERACTION_FOUND`, `NOT_FOUND_IN_SOURCES`, `INSUFFICIENT_EVIDENCE`, `MEDICATION_NOT_IDENTIFIED`, `CONTRADICTORY_EVIDENCE`, `TECHNICAL_ERROR`. "Não encontrada" jamais significa "segura".
3. Toda afirmação textual exibida referencia ≥1 chunk recuperado; sem referência válida o texto é descartado e o par vira inconclusivo/ somente-gravidade.
4. Trechos de bulas são dados não confiáveis (mitigação de prompt injection): delimitados, nunca instruções.
5. Nenhuma recomendação de suspender, substituir ou ajustar dose.

## Decisões (ADRs resumidos)

- **ADR-1 – Reutilizar tabelas existentes.** `drug_interactions`, `knowledge_sources/documents/chunks`, `anvisa_medicines` permanecem; entram `active_ingredients`, `active_ingredient_aliases`, `anvisa_medicine_ingredients`, consultas e resultados por par.
- **ADR-2 – Ponte PT↔EN por aliases + remoção de sais.** Normalizador remove sais/hidratos ("cloridrato de", "sódica"...), acentos e caixa; aliases curados (CSV versionado, `origin=curated`, pendente de revisão farmacêutica) cobrem divergências (varfarina→warfarin). Métodos e confiança ficam gravados em `anvisa_medicine_ingredients`.
- **ADR-3 – Recuperação lexical primeiro.** Não há modelo de embeddings disponível; FTS `portuguese` do PostgreSQL é o baseline funcional. Vetorial/híbrido são implementados sobre `EmbeddingProvider` e só são avaliados com modelo real. Colunas `embedding_model`/`embedding_dimensions` impedem misturar vetores incompatíveis; consultas filtram por modelo.
- **ADR-4 – Sem índice ANN agora.** Corpus de ~10³ chunks: varredura sequencial é suficiente; índice HNSW exige dimensão fixa e será criado por migração quando o modelo for escolhido.
- **ADR-5 – Frontend Livewire 4 + Alpine** na mesma aplicação, rotas `/` e `/consulta/*` separadas de `/admin`. Frontend nunca fala com a Anthropic.
- **ADR-6 – Avaliação por comandos Artisan** e arquivos JSON versionados (dataset, execução, métricas), sem dependência da UI.
- **ADR-7 – Privacidade.** Histórico por usuário autenticado, só lista de medicamentos + resultado; retenção configurável com comando de expurgo; exclusão pelo usuário; sem PII em logs.
