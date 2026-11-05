# Registro de notas acadêmicas

Tabela para continuidade: acrescente uma linha a cada nova implementação ou nota. Origem deve citar commit, arquivo ou teste.

| Data | Tópico | Arquivo | Origem |
| --- | --- | --- | --- |
| 2026-10-18 | Extração de texto, qualidade e OCR condicional | 07-ocr-e-extracao-de-texto.md | `docs/technical-foundation.md` (OCR Fallback); `HeuristicExtractionQualityEvaluator`; testes Unit/Services/Knowledge |
| 2026-10-18 | Classificação híbrida heurística + IA local | 08-classificacao-hibrida-heuristica-llm.md | `KnowledgeDocumentClassificationService`; `config/document_classification.php`; `KnowledgeDocumentClassificationTest`; observação com 6 PDFs (relato do desenvolvedor, sem commit) |
| 2026-10-18 | LLM local, quantização, llamafile | 09-llms-locais-quantizacao-llamafile.md | `services/local-ai`; `scripts/install-local-model.sh`; `test_supervisor.py` |
| 2026-10-18 | Provedores de IA desacoplados | 10-arquitetura-de-provedores-de-ia-desacoplados.md | `app/Services/Ai`; `config/ai.php`; `AiProviderLayerTest` |
| 2026-10-18 | Validação estrita e grounding | 11-validacao-estrita-e-grounding.md | `DocumentClassificationResponse`; `KnowledgeDocumentClassificationService`; testes correspondentes |
| 2026-10-18 | Auditabilidade e explicabilidade | 12-auditabilidade-e-explicabilidade.md | `AutomaticAnalysisReport`; `KnowledgeBackgroundClassificationTest` |
| 2026-10-18 | Observabilidade e saúde de serviços | 13-observabilidade-e-saude-de-servicos.md | `ServiceHealthChecker`; `ServiceHealthTest` |
| 2026-10-18 | SSE x WebSocket x polling | 14-comunicacao-em-tempo-real-sse-websocket.md | `docs/realtime-options.md` |
| 2026-10-18 | Ameaças à validade e limitações | 15-ameacas-a-validade-e-limitacoes.md | `docs/technical-foundation.md` (Known Limitations); relato dos 6 PDFs |
| 2026-10-18 | Trabalhos futuros de OCR/IA | 16-trabalhos-futuros.md | docx 3.4 e Cap. 4; `docs/technical-foundation.md` |
| 2026-10-18 | Decisão humana sobre sugestão da IA, quadro comparativo, consulta sob demanda, achado do omeprazol | 08, 11, 12, 15 (seções adicionadas) | `docs/technical-foundation.md` (Human decision on the AI suggestion); `KnowledgeAiSuggestionReviewService`; `AutomaticAnalysisReport::comparison`; `KnowledgeAiSuggestionReviewTest`; observação n=1 (sem commit) |
| 2026-10-20 | Gestão de usuários: senha provisória, travas de administrador, auditoria e endurecimento do login | 01, 02, 04, 05, 06 (trechos atualizados) | `docs/technical-foundation.md` (Gestão de usuários e endurecimento do login); `UserAccountService`; `UserManagementAdminTest`, `AccountTest`, `LivewirePersistentMiddlewareTest`; branch `feat/tela-de-usuarios` (sem commit) |
| 2026-10-20 | Primeiro acesso: troca da senha provisória em tela própria, boas-vindas por perfil e menu do admin como fonte única | 01, 02, 04, 05, 06 (trechos atualizados) | `docs/technical-foundation.md` (Gestão de usuários e endurecimento do login → Primeiro acesso); `FirstAccessController`; `App\AdminNavigation`; `FirstAccessTest`; branch `feat/tela-de-usuarios` (sem commit) |
| 2026-11-04 | Pipeline implementado e desvios do projeto | 17-pipeline-implementado-e-desvios-do-projeto.md | `app/Services/Interactions`, `app/Services/Retrieval`, `docs/architecture/*` |
| 2026-11-04 | Catálogo de princípios ativos e ponte PT↔EN | 18-catalogo-farmacologico-e-resolucao-de-ingredientes.md | `ActiveIngredientCatalogBuilder`, `ActiveIngredientCatalogTest`, `knowledge:build-ingredients` |
| 2026-11-04 | Recuperação, RAG e controle de alucinação | 19-recuperacao-rag-e-controle-de-alucinacao.md | `PostgresKnowledgeRetriever`, `ExplanationValidator`, `KnowledgeRetrievalTest`, `InteractionAnalysisTest` |
| 2026-11-04 | Integração Anthropic (somente mocks) | 20-integracao-anthropic.md | `docs/ai/anthropic-provider.md`, `AnthropicTextGenerationProviderTest` |
| 2026-11-04 | PWA, privacidade e segurança | 21-pwa-privacidade-e-seguranca-do-usuario-final.md | `docs/consulta-pwa.md`, `ConsultaPwaTest`, `PwaAssetTest` |
| 2026-11-04 | Protocolos de validação profissional e SUS (não executados) | 22-protocolos-de-validacao-profissional-e-usabilidade.md | `database/evaluation/interactions.protocol30.v1.json` |
| 2026-11-04 | Resultados experimentais | 23-resultados-experimentais.md | `storage/app/evaluation/runs/*.json`, `docs/evaluation.md` |
| 2026-11-04 | Bula humanizada, contexto de tratamento, registro analítico das respostas, analytics, embeddings reais e diretriz do produto | 24-bula-humanizada-contexto-de-tratamento-e-analytics.md | `TreatmentContextService`, `LeafletSummaryService`, `MedicationQuestionService`, `AnalyticsService`, `docs/analytics.md`, `docs/diretrizes-do-produto.md`, `docs/evaluation.md`; testes `PerfilEPerguntasTest`, `BulaHumanizadaTest`, `AnalyticsAdminTest` |
| 2026-11-05 | Programação do tratamento (horários por regra, agenda no fuso do paciente, Tomei/Pular), avisos (lembretes de dose, fim de tratamento, remédio reconhecido) e cadastro de remédios fora do catálogo com pendência para a administração | 25-programacao-do-tratamento-avisos-e-remedios-fora-do-catalogo.md | `DoseScheduleParser`, `TreatmentScheduleService`, `ReminderService`, `docs/tratamento.md`, `docs/notificacoes.md`, `docs/admin-remedios-nao-encontrados.md`; testes `DoseScheduleParserTest`, `ProgramacaoDoTratamentoTest`, `RemediosDeHojeTest`, `AvisosTest`, `UnmatchedMedicinesAdminTest` |
