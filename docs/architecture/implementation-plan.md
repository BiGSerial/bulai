# Plano de implementação

Fases e tarefas rastreadas em `docs/engineering/implementation-board.md`.

0. Auditoria e contratos. 1. Fundação farmacológica (ingredientes, resolução, segurança base). 2. Recuperação + análise RAG. 3. PWA integrado. 4. Instrumentos de avaliação. 5. Integração/estabilização. 6. Experimentos e evidências para o TCC.

Execução: o ambiente permite subagentes reais, mas compartilham o mesmo PostgreSQL de teste; por isso só o Agente I (sem banco) roda em paralelo. Os demais workstreams são executados em sequência pelo orquestrador, com os mesmos contratos. Nenhum push ou deploy é feito sem autorização.
