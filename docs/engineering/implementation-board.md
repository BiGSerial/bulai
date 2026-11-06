# Quadro de execução

Estados: BACKLOG · READY · IN_PROGRESS · BLOCKED · IN_REVIEW · DONE. `DONE` exige evidência.

| ID | Agente | Tarefa | Dep. | Estado | Evidência / bloqueio |
| --- | --- | --- | --- | --- | --- |
| A-1 | A | Auditoria + docs de arquitetura | — | DONE | `docs/architecture/*`; baseline 354/354 testes |
| I-1 | I | Adaptador Anthropic + testes mock + custos | — | DONE (mocks) | 26 testes unit (25 ok, 1 skip live); NÃO validado contra API real; `docs/ai/anthropic-provider.md` |
| B-1 | B | `active_ingredients`, aliases, vínculo medicamento↔ativo | A-1 | DONE | 4 testes; dev: 1806 ingredientes, 21.605/39.416 medicamentos ligados |
| B-2 | B | Vínculo `drug_interactions`→ingrediente + comando de backfill | B-1 | DONE | `knowledge:build-ingredients`; 160.235/160.235 pares ligados |
| C-1 | C | Colunas de modelo de embedding + `KnowledgeIndexer` | A-1 | DONE | `knowledge:index-embeddings`; 7 testes (`tests/Feature/Retrieval`) |
| C-2 | C | `KnowledgeRetriever` lexical (FTS pt) | A-1 | DONE | idem; filtros por documento/seção, só READY e fonte ativa |
| C-3 | C | Recuperação vetorial/híbrida | C-1 | IN_REVIEW | implementada (RRF k=60) e testada com embeddings fake; sem modelo real não há resultado científico |
| D-1 | D | Resolver de medicamentos + lookup estruturado | B-2 | DONE | `InteractionLookup`, `PairEvidenceRetriever` |
| D-2 | D | Orquestração RAG + validação + persistência | C-2, D-1 | DONE | 8 testes (`tests/Feature/Interactions`); falta smoke real |
| E-1 | E | Rotas/UX PWA (busca, seleção, resultados, evidências) | D-2 | DONE | 9 testes `tests/Feature/Consulta`; NÃO verificado visualmente em navegador |
| E-2 | E | Histórico, privacidade, SW/offline | E-1 | DONE | histórico com exclusão+retenção+auditoria; SW v2 com atualização controlada e `/offline`; ícones só SVG |
| F-1 | F | Rate limit, validação, anti-injection, retenção | D-2 | IN_REVIEW | rate limit por usuário, lock anti-duplicação, tags escapadas, validador, purge diário; falta revisão de segurança dedicada |
| G-1 | G | Datasets, métricas, comandos `evaluation:*` | D-2 | DONE | `evaluation:build-datasets`, `evaluation:run`; 9 testes (Metrics, pipeline) |
| G-2 | G | Execução dos experimentos reais | G-1, integração | DONE (parcial) | 9 registros em `storage/app/evaluation/runs`; vetorial/híbrido NÃO executados (sem embeddings); Claude NÃO validado; sem profissional/SUS |
| H-1 | H | Health check do pipeline e tempos por etapa | D-2 | DONE | `bulai:pipeline-check`; timings por etapa em `interaction_pair_results.timings`; purge agendado |
| T-1 | — | Documentos TCC (`docs/tcc`) | G-2 | DONE | `docs/tcc/17`–`23`, README e registro atualizados; .docx não editado |
| X-1 | — | Serviço de embeddings (decisão do usuário) | — | DONE (iteração 2) | serviço `embeddings` + `bge-m3`; C-3 medido |
| X-2 | — | Teste real Anthropic (chave + autorização de custo) | — | DONE (iteração 2) | 4 chamadas reais, US$ 0,024 |
| X-3 | — | Revisão por profissional de saúde e SUS | — | BLOCKED | protocolos em `docs/tcc/22`; depende de pessoas e do comitê de ética |
| X-4 | — | Commit das alterações e repetição dos experimentos com código limpo | — | BLOCKED | aguarda autorização para commitar; registros atuais têm `dirty: true` |

## Iteração 2 (04/11/2026): bula humanizada, contexto de tratamento, analytics, embeddings

| ID | Frente | Tarefa | Dep. | Estado | Evidência / bloqueio |
| --- | --- | --- | --- | --- | --- |
| F-0 | Arquiteto | Fundação: migração `2026_11_04_120000` (receitas, posologia, `medication_questions`, `medicine_leaflet_summaries`), modelos, `config('bulai.guidelines/questions/leaflet')`, `TreatmentContextService`, diretriz no shell e na página inicial | — | DONE | migração aplicada em dev; `docs/diretrizes-do-produto.md` |
| F-A | A | Pergunta com contexto de tratamento (risco classificado), registro de perguntas, receitas/posologia, histórico de perguntas, expurgo | F-0 | DONE |  `PerfilEPerguntasTest` |
| F-B | B | Bula humanizada (RAG por seção, validação, cache, "Para você") em `/bula/{medicine}` | F-0 | DONE |  `BulaHumanizadaTest` (6 testes) |
| F-C | C | Analytics/auditoria admin (`/admin/analytics`, detalhes, `analytics:export`) | F-0 | DONE |  `AnalyticsAdminTest` (6), `docs/analytics.md` |
| F-D | D | Embeddings locais + modo híbrido, smoke real Anthropic, ícones PNG do PWA, docs de deploy | — | DONE | `bge-m3` 782/782 chunks; `…025153-retrieval-modes-bge-m3`; 4 chamadas reais (US$ 0,024); `PwaAssetTest` (4); deploy tests 81/81 |
| F-R | Arquiteto | Achados do ultrareview: reaproveitamento de importação por sha256, job idempotente, recarga indevida do SW na primeira visita, aviso de atualização no admin | — | DONE | `DrugInteractionImportTest` (10 testes) |
| F-T | Arquiteto | Capítulo `docs/tcc/24`, README/registro, `atividades-implementadas`, `technical-foundation`, `23` seções 3/3a | F-A..F-D | DONE | ver arquivos |
| F-0b | Arquiteto | Fundação da 2ª onda: `InputGuard`/`GuardVerdict` (19 testes), migração `2026_11_04_150000` (perguntas com vários medicamentos e triagem; receita por foto), `config('bulai.guard/prescription')` | — | DONE | `InputGuardTest` |
| F-E | E | Pergunta livre sem seleção (detector de medicamentos, guarda em três camadas, vários medicamentos por pergunta) | F-0b | DONE |  `PerguntaLivreTest` (6), `PerfilEPerguntasTest` |
| F-F | F | Receita por foto (provedor de visão Anthropic, extração JSON validada, revisão e confirmação pelo usuário, perfil com segmentos) | F-0b | DONE |  `ReceitaPorFotoTest` (6), `AnthropicVisionProviderTest` (4) |
| F-G | G | Redesign mobile: Início, navegação em 4 destinos, CTAs fixos, polimento das telas | — | DONE |  `ConsultaPwaTest`, `PwaAssetTest`, `npm run build` ok |
| F-0c | Arquiteto | Fundação da 3ª onda: tokens de identidade visual derivados do logotipo (`brand` azul-marinho, `accent` laranja, `surface` creme) em `resources/css/app.css` | — | DONE | `npm run build` ok |
| F-H | H | Redesign da administração: barra lateral em grupos, visão geral com toda a informação da aplicação, relatórios gerados sob demanda (`admin_reports`, `reports:generate`), polimento das listas | F-0c | DONE |  `ReportsAdminTest` (4), `KnowledgeAdminTest`, `docs/relatorios.md`, `docs/admin-design-system.md` |
| F-I | I | Gerenciamento de usuários finalizado, scaffold de login (esqueci/redefinir senha, lembrar-me, bloqueio progressivo, sair de outros dispositivos), Argon2id com re-hash no login | — | DONE |  `PasswordHashingTest` (2), `PasswordResetTest` (5), `UserManagementAdminTest`, `docs/usuarios-e-autenticacao.md` |
| F-J | J | Identidade visual no app do paciente, página pública, offline, manifesto; guia `docs/design/identidade-visual.md` com contraste AA | F-0c | DONE |  `docs/design/identidade-visual.md`; contraste AA verificado; CSS 67,6 kB (12,1 kB gzip) |
| F-0d | Arquiteto | Contrato de UX (`docs/design/principios-de-ux.md`): uma tela/uma ação, sem duplicação, três níveis, listas enxutas, feedback e polling restrito, checklist por tela | — | DONE | documento |
| F-K | K | Redesign do fluxo de ingestão do admin (revisão de pendência, pendências, importações, adicionar documentos, documentos, origens) + componentes `toast`/`page-header` | F-0d | DONE |  168/168 em `tests/Feature/Knowledge*`; revisão com uma ação primária, comparativo só do que diverge, cartão de conclusão com próxima pendência |
| F-L | L | Redesign de operação, análises e pessoas (visão geral, fila, catálogo, interações, analytics, relatórios, usuários, conta, primeiro acesso) + cache dos agregados de interações | F-0d | DONE |  99/100 no escopo; cache dos agregados de interações; componentes `ops-*` |
| F-M | M | Redesign sênior-primeiro do app do paciente (texto 18px+, alvos ≥56px, 3 destinos, 4 botões na Início, fluxos em ≤3 toques, falar/ouvir progressivo, vocabulário simples, página /sobre) e telas de entrada | F-0d | DONE |  65 testes; Início com 4 botões, 3 destinos, Falar/Ouvir, `/sobre`; checklist e toques por tarefa em `docs/consulta-pwa.md` |

## Iteração 3 (05/11/2026): programação do tratamento, avisos e remédios fora do catálogo

| ID | Frente | Tarefa | Dep. | Estado | Evidência / bloqueio |
| --- | --- | --- | --- | --- | --- |
| F-0e | Arquiteto | Fundação: migrações `2026_11_05_114448` (remédio sem catálogo, horários de dose, pendências, eventos de dose, fuso/lembretes no perfil) e `2026_11_05_114449` (avisos com `dedupe_key`), modelos, `DoseScheduleParser`, `TreatmentScheduleService`, `config('bulai.treatment')`, rotas e stubs de `/hoje`, `/avisos` e `/admin/knowledge/unmatched-medicines` | — | DONE | `DoseScheduleParserTest` (20), `ProgramacaoDoTratamentoTest` (4) |
| F-N | N | Paciente: receita/perfil aceitam remédio fora do catálogo (sinalizado), tela "Remédios de hoje" com Tomei/Pular, horários editáveis, Início com próxima dose, contexto de tratamento com remédios sem catálogo | F-0e | DONE | `ReceitaPorFotoTest` (7), `RemediosDeHojeTest` (3); toques por tarefa em `docs/consulta-pwa.md`; `docs/tratamento.md` |
| F-O | O | Avisos: lembretes de dose (comando agendado, canal `database` + e-mail opcional, dedupe), fim de tratamento, sino no shell, tela `/avisos`, notificação no navegador com o app aberto, preferências | F-0e | DONE | `AvisosTest` (8); serviço `scheduler` no `compose.prod.yaml`; `docs/notificacoes.md` |
| F-P | P | Admin: pendência "Remédios não encontrados" (listar, ligar ao catálogo, descartar), aviso ao paciente ao resolver, contagem na visão geral e no menu | F-0e | DONE | `UnmatchedMedicinesAdminTest` (5); `docs/admin-remedios-nao-encontrados.md` |
| F-Q | Arquiteto | Integração: aba "Remédios" da barra inferior → `/hoje`, suíte completa, capítulo `docs/tcc/25`, instruções de release, commit e PR | F-N..F-P | DONE | 546 testes, 545 aprovados, 1 ignorado, 8.111 asserções (05/11/2026) |

## Iteração 4 (06/11/2026): experiência nativa, conversa com contexto, tolerância a erros e landing page

| ID | Frente | Tarefa | Dep. | Estado | Evidência / bloqueio |
| --- | --- | --- | --- | --- | --- |
| F-0f | Arquiteto | Fundação: `pg_trgm` + índices de trigramas (`2026_11_06_011348`), `FuzzyMedicineMatcher`, busca do catálogo com fallback tolerante (`fuzzy`/`matched`), detector de menções com correção de digitação (`fuzzy`/`corrected`), módulos `resources/js/{voz,pwa}.js` importados pelo `app.js` | — | DONE | `BuscaToleranteAErrosTest` (2); `PerguntaLivreTest`, `PerfilEPerguntasTest`, `ConsultaPwaTest` continuam verdes |
| F-R | A | Perguntar como conversa (contexto das últimas trocas no bloco de fatos, remédios herdados), "Entendemos X como Y" com troca/remoção, ditado por voz robusto (`voz.js`: contínuo, interino, reinício, erros claros) | F-0f | DONE | `PerguntaLivreTest` 9 (+3); contexto como evidência `conversation_context`, nunca instrução |
| F-S | B | PWA instalável (`beforeinstallprompt`, cartão `x-consulta.instalar`, instruções iOS, manifesto e SW revisados), sensação nativa (safe areas, view transitions, toque), guardas globais de layout e `ConsultaDesignSystemTest` | F-0f | DONE | `PwaAssetTest` 6, `ConsultaDesignSystemTest` 3; `sw.js` v5; seção 9 de `docs/design/principios-de-ux.md` |
| F-T | C | Telas do paciente redesenhadas (Início com cartão do dia, Hoje com linha do tempo, Perfil em segmentos com folha de ações, Bula, Pesquisa com "Você quis dizer", Histórico, Avisos, Receita, Sobre) sem quebra a 360–400 px | F-0f | DONE | 66 testes em `tests/Feature/Consulta`; 9 componentes novos (`botao`, `cartao`, `icone`, `selo`, `vazio`, `anel-progresso`, `secao`, `folha-acoes`, `segmentos`) |
| F-U | D | Landing page pública de qualidade comercial (`welcome.blade.php`, componentes `landing/*`, SEO, FAQ, instalação), `LandingPageTest` | — | DONE | `LandingPageTest` 3 (62 asserções); `docs/design/landing-page.md` |
| F-V | Arquiteto | Integração: violações de layout apontadas por B aplicadas nas telas, suíte completa, `docs/tcc/26`, release, commit e PR | F-R..F-U | DONE | 562 testes, 561 aprovados, 1 ignorado, 11.064 asserções (06/11/2026) |
| F-W | Arquiteto | Programação dinâmica por intervalo: migração `2026_11_06_015003` (`interval_minutes`, `schedule_mode`), parser com `interval_hours`, `register()`/`agenda()`/`mark()` recalculando a cadeia a partir do horário real da última dose tomada | F-0e | DONE | `ProgramacaoDoTratamentoTest` (+1, cadeia recalculada; pular não desloca; desfazer restaura) |
| F-X | E | Interface da programação dinâmica: Hoje ("Tomou às", "Ajustado", intervalo), Início ("ajustado"), Perfil e Revisar com escolha "Contar a partir de quando eu tomar" × "Horários fixos" | F-W | DONE | `RemediosDeHojeTest` (+1), `ReceitaPorFotoTest` (modo por item); `docs/tratamento.md` seção "Programação dinâmica por intervalo" |

## Iteração 5 (06/11/2026): área administrativa profissional

| ID | Frente | Tarefa | Dep. | Estado | Evidência / bloqueio |
| --- | --- | --- | --- | --- | --- |
| F-Y0 | Arquiteto | Diagnóstico das áreas em branco (`main` em `grid flex-1` sem `content-start`) e correção no shell; contrato de componentes densos (`toolbar`, `data-table`, `row-actions`, `tabs`, `callout`, `workflow`, `kpis`) | — | DONE | commit `2157a51` |
| F-Y | F | Shell com trilha de navegação, painel "Ajuda desta tela" (`AdminHelp` para as 31 rotas: para que serve, quando usar, passo a passo, telas relacionadas), busca global Ctrl+K (telas, documentos, catálogo, usuários), menu com descrições, Visão geral reorganizada (KPIs, fluxo das bulas, atenção, atividade recente) | F-Y0 | DONE | `AdminHelpAndSearchTest` (3), `AdminDesignSystemTest` (+regra de densidade); `docs/admin-design-system.md` reescrito |
| F-Z | G | Base de conhecimento em tabelas densas ordenáveis com barra de filtros única (Documentos, Importações, Pendências, Remédios não encontrados, Origens, Catálogo, Interações), detalhes com fatos + abas, envio compacto | F-Y | DONE | `KnowledgeDocumentAdminTest` (+2); 52 testes das telas |
| F-AA | H | Fila e serviços (KPIs + tabela), Analytics (abas, KPIs, tabelas), Relatórios (tabelas), Usuários (tabela ordenável, detalhe em abas) | F-Y | DONE | `QueueStatusTest` (+1), `UserManagementAdminTest` (+2), `AnalyticsAdminTest` (+1) |
| F-AB | Arquiteto | Integração: suíte completa, docs, commit e PR | F-Y..F-AA | DONE | 574 testes, 573 aprovados, 1 ignorado, 15.317 asserções (06/11/2026) |
| F-AC | I | Perguntar como chat amigável: balões, "digitando…", falas em português simples por situação (só partes, nada na bula, sem bula, sem remédio, bloqueada), sugestões de reformulação em chips, detalhes técnicos recolhidos em "Ver na bula" | F-R | DONE | `PerguntaLivreTest` (+4, helper sem jargão); 578 testes, 577 aprovados, 1 ignorado (06/11/2026) |

## Iteração 6 (06/11/2026): observabilidade do pipeline, progresso em tempo real e retaguarda do classificador

| ID | Frente | Tarefa | Dep. | Estado | Evidência / bloqueio |
| --- | --- | --- | --- | --- | --- |
| G-0 | Arquiteto | Medição do sintoma "indicador off durante o OCR" (108 s por 11 páginas; `/status` com mediana 1,5 s e pico 4,4 s sob OCR; imagens locais de 15–18/10 sem `/status`); contratos: `current` no `/status` do OCR, campos novos de `ServiceHealth`, tabela `knowledge_processing_events`, gatilhos da retaguarda | — | DONE | `docs/tcc/27` seção 1; commit `53b3470` (alterações pré-existentes validadas: vínculo + índice ao fim do job, princípio ativo criado a partir da bula, tipos de bula distintos) |
| G-P | P | OCR em processo filho persistente (`app/worker.py`), progresso por página em `/status.current`, 504 sem derrubar o servidor, watchdog mata só o filho, `OCR_EXECUTOR=process|inline` | G-0 | DONE | `services/document-ocr/tests/test_api.py` (23); aceitação no container: `/status` p100 27 ms durante o OCR (antes 4,4 s) |
| G-H | H | Sondagem em segundo plano (`services:probe-health` a cada 5 s, cache com `observed_at`, inline só se a leitura tiver > 15 s), estado `UNRESPONSIVE` antes de `UNREACHABLE` (3 falhas), `progress`/`hint` em `ServiceHealth`, painel e Fila | G-0 | DONE | `ServiceHealthTest` (9); `docs/realtime-options.md` |
| G-C | C | Claude como retaguarda do classificador: driver `anthropic` (saída estruturada por JSON Schema), `FallbackDocumentClassificationProvider` (exceção, confiança baixa, princípio ativo ausente), `DOCUMENT_CLASSIFIER_FALLBACK_DRIVER`, revisão explica o motivo, timeout do job | G-0 | DONE | `AnthropicClassificationFallbackTest` (5), `KnowledgeDocumentClassificationTest`; `docs/tcc/08` §5.1; sem chamada real |
| G-T | T | Linha do tempo do processamento (`knowledge_processing_events`, `ProcessingTimeline`, emissão em extração/OCR/normalização/trechos/embeddings/vínculo, página do documento com `wire:poll` + SSE) | G-0 | DONE | `KnowledgeProcessingTimelineTest` (6); `docs/technical-foundation.md` |
| G-J | J | Fila e serviços com "Bulas em processamento" (etapa, página X de Y casada por `correlation_id`, sem sinal), SSE com `documents`/`progress`/`hint`, KPI "Em andamento" | G-H, G-T | DONE | `QueueStatusTest` (+3) |
| G-Z | Arquiteto | Integração: Pint, suíte completa, build, docs (nota 27, release, atividades), commit e PR #33 | G-P..G-J | DONE | 599 testes, 598 aprovados, 1 ignorado, 15.762 asserções (06/11/2026); OCR: 23 testes em pytest |
