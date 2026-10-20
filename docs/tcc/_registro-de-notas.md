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
