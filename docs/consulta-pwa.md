# Consulta de interações (PWA do usuário final)

## Propósito
Interface mobile-first, separada do Admin, em que um usuário autenticado pesquisa medicamentos do catálogo ANVISA, monta uma lista de 2 a 6 itens e recebe, para cada par, o veredito, a gravidade (quando há base), os trechos de bula usados e as limitações. Tudo passa pelo backend Laravel: o navegador nunca conhece chaves nem fala com provedores de IA.

## Rotas (`routes/web.php`)
| Rota | Componente / view | Observação |
| --- | --- | --- |
| `GET /` | `welcome` (visitante) ou redirecionamento ao início do perfil | `User::homeRouteName()`: ADMIN → `admin.documents.index`; USER → `consulta.pesquisa` |
| `GET /consulta` | `App\Livewire\Consulta\Pesquisa` | busca com debounce, lista, rate limit |
| `GET /consulta/{consultation:uuid}` | `App\Livewire\Consulta\Resultado` | só o dono (404 para outros); `wire:poll.2s` enquanto não termina |
| `GET /historico` | `App\Livewire\Consulta\Historico` | consultas de interação e perguntas; exclusão individual/total, auditada (`consultations_deleted`, `questions_deleted`) |
| `GET /historico/perguntas/{question:uuid}` | `App\Livewire\Consulta\PerguntaRegistrada` | leitura de uma pergunta anterior (resposta, fontes e contexto de tratamento da época); só o dono (404 para outros) |
| `GET /perguntar[?medicamento={id}]` | `App\Livewire\Consulta\Perguntar` | pergunta livre sobre um medicamento, respondida só com trechos de bula + fatos do tratamento (`MedicationQuestionService`); pergunta e resposta são gravadas em `medication_questions` |
| `GET /perfil` | `App\Livewire\Consulta\Perfil` | consentimento de dados de saúde, alergias, receitas médicas e medicamentos em uso com posologia; apagável; auditado |
| `GET /offline` | `offline.blade.php` | tela pública usada pelo service worker |

## Fluxo de dados
`Pesquisa` → `MedicationSearchService` (catálogo) → `InteractionConsultationService::start` → job `RunInteractionConsultation` → `PairAnalyzer` por par → `interaction_consultations` + `interaction_pair_results`. Detalhes do pipeline: `docs/architecture/target-state.md`.

Execução (`BULAI_CONSULTATION_EXECUTION`): `after_response` (padrão; roda depois de responder, sem worker), `queue` (exige `php artisan queue:work`) ou `sync`. Consultas paradas por mais de `BULAI_CONSULTATION_STALE_AFTER_SECONDS` (300) viram `FAILED/CONSULTATION_TIMEOUT` ao serem abertas, com botão "Tentar novamente".

## Estados exibidos
`INTERACTION_FOUND` (gravidade da fonte estruturada + trechos + explicação validada, se houver), `NOT_FOUND_IN_SOURCES` ("não significa que seja segura"), `INSUFFICIENT_EVIDENCE`, `MEDICATION_NOT_IDENTIFIED`, `CONTRADICTORY_EVIDENCE`, `TECHNICAL_ERROR`. Cada evidência mostra documento, seção, página, fonte, versão (prefixo do `content_hash`), trecho, score e quais afirmações sustenta.

## Privacidade e retenção
Guarda-se apenas: usuário, lista de medicamentos (id/nome/ingredientes), resultado, configuração do pipeline. Não se guarda o texto da resposta bruta do LLM nem prompts. Retenção `BULAI_CONSULTATION_RETENTION_DAYS` (90); `consultations:purge-expired` roda diariamente às 03:30 (`routes/console.php`). Exclusões do usuário geram `UserAuditEvent` `consultations_deleted` (só a contagem). Limitação: não há tela de consentimento/política de privacidade formal (requisito institucional/LGPD a decidir).

## Limites e rate limit
`BULAI_CONSULTATION_MAX_MEDICINES` (6), `BULAI_CONSULTATION_RATE_LIMIT_PER_HOUR` (30 consultas/usuário), `BULAI_SEARCH_LIMIT_PER_MINUTE` (120 buscas/usuário), trava de 10 s contra envio duplicado.

## PWA
`public/manifest.webmanifest` (atalhos para `/consulta` e `/historico`), `public/sw.js` v2: páginas nunca são cacheadas (inclusive `/consulta`, `/historico`, `/livewire`); só `/build/*`, `/brand/*`, manifesto e `/offline`. A nova versão do service worker **não** assume sozinha: `resources/js/app.js` dispara `bulai-sw-update` e o usuário aceita em "Atualizar agora". Faixa "sem conexão" no shell. **A consulta exige conexão; não há modo offline de análise.**

Ícones: SVG (`any maskable`) + PNG 192/512 (`any` e `maskable`) e `apple-touch-icon` 180 px, gerados de `bulai-icone-app.svg` com `rsvg-convert` (container Alpine); o SW v3 pré-cacheia os PNG e o manifesto. Limitações conhecidas: layout validado por testes de renderização e leitura do HTML, **não** por inspeção visual em navegador/dispositivo; contraste e leitores de tela não foram auditados com ferramenta (foram usados `aria-*`, foco visível, rótulos e cores sempre acompanhadas de texto).

## Testes
`tests/Feature/Consulta/ConsultaPwaTest.php` (10), `tests/Feature/Interactions/InteractionAnalysisTest.php` (9), `tests/Feature/PwaAssetTest.php`.
```bash
php artisan test --compact tests/Feature/Consulta tests/Feature/Interactions
```

## Perfil do paciente, alergias e perguntas (04/11/2026)

- **Consentimento explícito** (`patient_profiles.health_consent_at`) antes de guardar alergias (`patient_allergies`) e medicamentos em uso (`patient_medications`). Sem consentimento nada é gravado nem usado nos alertas. "Apagar todos os meus dados de saúde" remove tudo e revoga o consentimento; consentimento e apagamento geram `UserAuditEvent` (`health_consent_given`, `health_data_deleted`, só contagens).
- **Alertas de alergia** (`AllergyChecker`): por substância (PT/EN, sem sais) e por classe (`database/data/ingredient_classes.csv`: penicilina, cefalosporina, AINE, sulfonamida, macrolídeo, quinolona, benzodiazepínico; **pendente de revisão farmacêutica**). Aparecem ao selecionar o medicamento e no topo do resultado. Reação cruzada entre classes diferentes não é modelada; o alerta nunca é "ausência de risco".
- **Receitas médicas e posologia** (`patient_prescriptions`; colunas `prescription_id`, `dosage`, `frequency`, `started_at`, `ends_at`, `notes` em `patient_medications`): o paciente registra quem prescreveu (nome obrigatório, CRM/data/observações opcionais) e, em cada medicamento em uso, a dose, a frequência, o período e a receita vinculada. Medicamento com `ends_at` no passado é tratamento encerrado (`PatientMedication::isActive()`) e sai do cruzamento. Remover a receita mantém o medicamento (vínculo vira nulo). Não há leitura de imagem/OCR da receita.
- **Contexto de tratamento** (`App\Services\Patient\TreatmentContextService`, determinístico, nunca LLM): para um medicamento perguntado, informa se está em uso (com posologia e prescritor), as interações DDInter com cada medicamento ativo em uso (gravidade `major`/`moderate`/`minor`/`unknown`, ordenadas da maior para a menor, `divergent` quando há variantes), duplicidade de princípio ativo, alergias cruzadas (`AllergyChecker`) e medicamentos não verificáveis (sem princípio ativo identificado). Sem consentimento devolve `consented: false` e nada mais. Exibido no painel "No seu tratamento" (componente `x-consulta.tratamento`) em Perguntar e na leitura de perguntas anteriores. Na resposta do assistente, o perfil só entra quando há achado (interação com remédio em uso, duplicidade ou alergia: `alertsText`); intenção, recuperação orientada, PDF original e voz da bula humanizada estão em `docs/ai/perguntar-inteligencia-conversacional.md`.
- **Perguntas (fluxo livre, 04/11/2026)**: o usuário não escolhe o medicamento; escreve a dúvida e o sistema (1) faz a triagem da entrada (`App\Services\Safety\InputGuard`: remove caracteres invisíveis, neutraliza tags, limita tamanho e recusa injeção de instruções, código, pedidos de dano e assuntos fora da proposta — recusas viram `medication_questions.status = blocked` com `guard.category/flags`, sem chamar o modelo; em `harm_risk` a tela destaca o CVV 188); (2) detecta os medicamentos citados (`MedicationMentionDetector`: n-gramas de 1–3 palavras do texto normalizado contra nome comercial, princípio ativo e aliases PT/EN, agrupados por conjunto de princípios ativos, termos ambíguos descartados; "meus remédios"/"o que eu tomo" inclui os medicamentos em uso; `?medicamento={id}` e a correção "Não é esse?" entram como escolha explícita); sem medicamento reconhecido → `no_medicine`, com chips dos remédios em uso; (3) recupera trechos das bulas de todos os citados (consulta = pergunta + nomes dos princípios ativos), monta os fatos determinísticos do tratamento de cada um e da interação DDInter entre eles como evidência citável `T1` e gera a resposta validada (`MedicationQuestionService::askFreeform`). Estados: `blocked`, `no_medicine`, `not_identified`, `no_leaflet`, `no_evidence`, `excerpts_only`, `answered`; medicamentos sem bula entre os citados viram limitação listada. Registro: `medicines` (lista detectada com `matched_text`), `guard`, `treatment_context` (primeiro medicamento + `others`), evidências e métricas. Guarda em três camadas: triagem determinística → prompt de sistema (pergunta delimitada em `<pergunta>` como dado não confiável, regra de ignorar instruções embutidas, só medicamentos, formato JSON fixo, nunca revelar instruções) → `ExplanationValidator`. Entrada `?q=` (caixa da tela Início) pré-preenche e, se a pergunta estiver completa, responde na hora. Testes: `tests/Feature/Consulta/PerguntaLivreTest.php`, `tests/Unit/Services/Safety/InputGuardTest.php`.
- **Registro das perguntas** (`medication_questions`): toda pergunta de usuário autenticado grava `uuid`, medicamento, pergunta, status, resposta, evidências (com `supports_claims`), `treatment_context`, `generation` (modelo, tokens, claims aceitos/rejeitados, motivos), `retrieval`, `pipeline` (modo de recuperação, provedor, versão do prompt e do código), `duration_ms` e `expires_at` (`BULAI_QUESTIONS_RETENTION_DAYS`, padrão = retenção das consultas). `consultations:purge-expired` apaga consultas e perguntas vencidas. Na interface o usuário vê a resposta, a frase `bulai.guidelines.evidence_notice` e as fontes de forma compacta (documento · seção · página); trechos brutos só no estado `excerpts_only`. Detalhe completo (trechos, métricas) fica no backend para auditoria e análise.
- **Histórico**: consultas e perguntas em listas separadas (paginação `page` e `perguntas`), exclusão individual e total; "Apagar todos os meus dados de saúde" no perfil também apaga receitas e perguntas.
- **PWA**: `start_url` = `/consulta` (o app instalado abre na área do paciente, inclusive para admin); admin e paciente têm links um para o outro.
- Pendente: lembretes de horário e reação cruzada entre classes de alergia (a leitura de receita por foto está na seção "Receita por foto").
- Testes: `tests/Feature/Consulta/PerfilEPerguntasTest.php` (13 testes: consentimento, privacidade, alergias, contexto de tratamento, citação de fatos do tratamento, registro e leitura de perguntas, receitas/posologia, histórico e purge).

## Bula humanizada (04/11/2026)

**Propósito.** Resumo em linguagem simples de um medicamento — para que serve, como usar, benefícios (prós), riscos e cuidados (contras), efeitos colaterais comuns, contraindicações ("não use se"), interações e quando procurar ajuda — gerado **somente** a partir das bulas cadastradas (RAG), validado afirmação a afirmação e cacheado. Nunca responde "de memória": sem princípio ativo identificado ou sem bula ligada, a página diz isso.

| Item | Onde |
| --- | --- |
| Rota | `GET /bula/{medicine}` (`consulta.bula`, id de `anvisa_medicines`; só usuários autenticados) |
| Componente | `App\Livewire\Consulta\Bula` + `resources/views/livewire/consulta/bula.blade.php` |
| Serviço | `App\Services\Leaflet\LeafletSummaryService` (`cached`, `isAvailable`, `forMedicine`) |
| Tabela | `medicine_leaflet_summaries` (modelo `App\Models\Consultation\MedicineLeafletSummary`) |
| Config | `config('bulai.leaflet')`: `prompt_version`, `max_output_tokens`, `evidence_per_section`, `sections` (chave, rótulo, consulta de recuperação e padrões de seção da bula) |
| Entradas | botão "Bula" em cada resultado e item selecionado da Pesquisa; chips de medicamento no Resultado; CTA "Perguntar sobre ele" leva a `/perguntar?medicamento={id}` |

**Pipeline.** (1) Documentos: bulas READY de fonte ativa ligadas aos princípios ativos (`knowledge_document_ingredients`); prefere `bula_paciente`, senão `bula_profissional`. (2) Recuperação por seção: `KnowledgeRetriever` no modo `bulai.retrieval.mode`, restrita aos documentos e aos padrões de seção configurados; sem resultado, repete sem padrões. Trechos deduplicados e numerados `E1..En`. (3) Um único prompt (sistema em pt-BR com a diretriz `bulai.guidelines.prompt_rule`, proibição de imperativo e de conhecimento externo; evidências em `<evidencia>` escapadas) pedindo JSON `{"sections": {"<chave>": [{"text", "evidence_ids"}]}}`, até 3 afirmações por seção, `purpose = humanized_leaflet`. (4) Validação seção a seção com `ExplanationValidator` (JSON estrito, citação existente, ancoragem lexical ≥ 0,4 nos trechos citados, sem orientação de suspender/trocar/ajustar dose). Afirmações reprovadas são descartadas e contadas em `generation.rejection_reasons`.

**Estados persistidos** (`status`): `generated` (todas as seções com ≥ 1 afirmação válida), `partial` (algumas), `invalid` (JSON malformado, nenhuma afirmação aceita ou saída truncada — nada é exibido), `unavailable` (provedor indisponível, geração desabilitada ou nenhum trecho recuperado). Colunas: `sections` (por chave: `label`, `claims[{text, evidence_chunk_ids}]`), `evidences` (trecho, documento, seção, página, versão, score, método e `supports_claims` como `chave#índice`), `generation` (modelo, tokens, aceitas/rejeitadas, motivos, `run_id`, `prompt_version`, `truncated`, `malformed`/`error_code`), `retrieval` (modo, duração e, por seção, candidatos e se os padrões funcionaram), `pipeline` (modo, provedores, versão do código, tipo e ids dos documentos), `duration_ms`, `views`.

**Cache e invalidação.** Chave única `ingredient_key` (ids ordenados dos princípios ativos) + `prompt_version` + `documents_fingerprint` (sha256 dos `content_hash` ordenados das bulas usadas). Produtos equivalentes (mesmos ativos) compartilham o resumo; reprocessar/acrescentar uma bula ou mudar o prompt gera um novo registro (os antigos ficam para auditoria). Geração concorrente é resolvida pela restrição única (reaproveita o registro vencedor). `views` conta só acessos com cache.

**Experiência.** Cache hit renderiza direto; sem cache, a página mostra "Preparando a bula humanizada…" e dispara `wire:init="gerar"`. Bloco "No seu tratamento" (`x-consulta.tratamento`, `TreatmentContextService`): em uso + posologia, alergias (alerta), interações com cada medicamento em uso com gravidade DDInter, duplicidade; sem consentimento, convite ao perfil. Cada seção mostra apenas "Fontes: documento · seção · página" (sem trechos brutos) e a frase `bulai.guidelines.evidence_notice`; seções sem base são listadas como ausentes. Gerações novas contam no mesmo limite horário das consultas (`bulai.consultation.rate_limit_per_hour`).

**Limitações.** A seleção de trechos por seção depende dos nomes de seção extraídos na ingestão (bulas com seções mal identificadas caem no fallback lexical); "benefícios" e "riscos" são leituras das seções de indicação/advertência, não avaliação clínica; o validador rejeita frases imperativas mesmo quando copiadas da bula ("não use se…"), por isso o prompt pede paráfrase ("a bula contraindica…"); não há regeneração manual pelo admin nem revisão farmacêutica do texto gerado.

**Testes.** `tests/Feature/Consulta/BulaHumanizadaTest.php` (6): sem ativo/sem bula, geração + persistência + cache + equivalência + invalidação por hash, `invalid`/`unavailable`/desabilitado, estados da página e bloco pessoal (alergia + interação maior), consentimento ausente + rate limit, links na Pesquisa.
```bash
php artisan test --compact tests/Feature/Consulta/BulaHumanizadaTest.php
```

## Redesign mobile (navegação em 4 destinos) — 04/11/2026

**Mapa de navegação** (barra inferior no celular, menu no cabeçalho em telas ≥ 640px): **Início** (`/inicio`, `Livewire\Consulta\Inicio`) · **Perguntar** (`/perguntar`) · **Remédios** (`/perfil`; também ativo em `consulta.receita.*`) · **Histórico** (`/historico`; também ativo em `consulta.pergunta` e `consulta.resultado`). Conta, área administrativa (admin) e Sair ficam no menu do avatar no cabeçalho. `User::homeRouteName()` e `/` levam USER a `consulta.inicio`; `manifest.webmanifest` tem `start_url` `/inicio` e atalhos Perguntar, Fotografar receita (`/receitas/nova`), Meus remédios e Verificar interações.

**Início**: pergunta rápida (formulário GET para `/perguntar?q=…`), três ações grandes (Fotografar receita — só aparece se a rota `consulta.receita.nova` existir —, Verificar interações, Meus remédios), resumo do tratamento (remédios em uso ativos e alergias; sem consentimento, convite ao perfil) e os 3 registros mais recentes (perguntas e consultas).

**Shell** (`components/consulta/shell.blade.php`): cabeçalho compacto com botão Voltar (quando a tela passa `:back`) ou ícone do app, título da tela no cabeçalho (as telas não repetem `<h1>`), avatar com menu (`role="menu"`, fecha com Esc/clique fora), faixas de offline e de nova versão, slot opcional `sticky` para a ação principal fixa acima da barra (usado em Verificar interações), rodapé com `config('bulai.guidelines.disclaimer')`. Alvos de toque ≥ 44px (barra: 64px), foco visível em todos os controles, `aria-current="page"`.

**Telas polidas**: Verificar interações (passos 1/2, chip "Usar os meus N remédios", CTA fixo com contagem), Interações/resultado (gravidade em destaque no topo do cartão), Histórico (segmentos Perguntas | Interações com `role="tablist"`, cartão inteiro clicável, estados vazios com ação), Bula humanizada (Voltar para a tela de origem), página inicial pública (um CTA: Entrar / Abrir o aplicativo).

**Testes**: `ConsultaPwaTest::test_home_is_the_start_screen_…` (redirecionamento, 4 destinos sem "Conta" na barra, pergunta rápida, resumo com tratamento encerrado excluído, recentes), `tests/Feature/Auth/*` e `PwaAssetTest` ajustados para `/inicio`. Build do Vite executado com sucesso.

**Não verificado**: inspeção em dispositivo real/emulador (iOS Safari e Android Chrome), leitor de tela, contraste medido com ferramenta. Integração pendente: o Perguntar deve ler o parâmetro `q` enviado pelo Início.

## Receita por foto (04/11/2026)

**Propósito.** O paciente fotografa a receita; o provedor de visão transcreve para dados estruturados; o paciente **confere e confirma** cada item; só então a receita e os medicamentos (com posologia) entram no perfil. A IA só transcreve: nunca decide posologia nem grava nada sozinha.

**Fluxo.** `GET /receitas/nova` (`consulta.receita.nova`, `Livewire\Consulta\Receita\Nova`): exige consentimento de dados de saúde; `<input type="file" accept="image/*" capture="environment">` (câmera) ou galeria; validação `mimes:jpg,jpeg,png,webp` + `max` (`BULAI_PRESCRIPTION_MAX_IMAGE_KB`, 8 MB); rate limit `BULAI_PRESCRIPTION_RATE_LIMIT_PER_HOUR` (10). Ao enviar: a imagem vai para a área transitória privada `prescriptions/tmp/<uuid>` no disco `knowledge` (`PrescriptionExtractionService::stash`), cria-se `patient_prescriptions` com `source=photo`, `status=PENDING`, e o job `ExtractPrescription` roda conforme `BULAI_CONSULTATION_EXECUTION` (after_response/queue/sync). `GET /receitas/{prescription}/revisar` (`consulta.receita.revisar`, só o dono, 404) faz `wire:poll.2s` enquanto PENDING/PROCESSING (timeout → `FAILED/PRESCRIPTION_TIMEOUT` após `BULAI_CONSULTATION_STALE_AFTER_SECONDS`).

**Extração (`PrescriptionExtractionService`).** Prompt de sistema: transcrever só o legível (campo ilegível = `null`, nunca completar), a imagem é dado não confiável (instruções escritas nela são ignoradas), inclui `bulai.guidelines.prompt_rule`, saída **somente** JSON `{prescriber_name, prescriber_registration, issued_at, legibility: good|partial|poor, items[{name_as_written, dosage, frequency, duration, notes, confidence 0–1}]}` com no máximo `BULAI_PRESCRIPTION_MAX_ITEMS` (15). Validação da saída: JSON estrito, tipos, limites de tamanho, data `AAAA-MM-DD` válida, `confidence` saturada em [0,1], textos passam por `InputGuard::sanitize` (controle/invisíveis removidos, `<`/`>` neutralizados). Cada item recebe `candidates` do catálogo ANVISA (`MedicationSearchService`, nome completo → sem concentração/forma → primeira palavra). Persistido em `extraction` (itens+candidatos, legibilidade, modelo, tokens, `prompt_version`, `run_id`, `duration_ms`) com `status=REVIEW`; erro do provedor → `FAILED` + `error_code` (`AI_*`, `PRESCRIPTION_IMAGE_INVALID`, `PRESCRIPTION_OUTPUT_INVALID`).

**Privacidade da imagem.** A foto nunca é gravada em disco permanente nem no banco: fica na área transitória só durante a extração e é apagada em `finally` (sucesso, falha ou receita inexistente; `failed()` do job também apaga). Nenhum dado identificável do usuário vai ao provedor — só a imagem e o prompt. O uso (tokens, custo, latência) fica em `ai_usage_logs` com `capability=vision`.

**Revisão (`Revisar`).** Cabeçalho editável (prescritor obrigatório, registro, data ≤ hoje); por item: nome como escrito, candidato selecionado (chips com as opções + busca livre), dose, frequência, duração, observações, selo "confira" quando `confidence < 0,6`, "não cadastrar este". Confirmar: `patient_medications` via `updateOrCreate(user, medicine)` com `prescription_id`, dose, frequência, `started_at` = data da receita ou hoje, `ends_at` = início + duração quando interpretável ("7 dias", "2 semanas", "1 mês"; contínuo = null), `notes` = duração + observações; receita `CONFIRMED` + `confirmed_at` + contagens `confirmed_items/skipped_items`. Descartar apaga a receita. Perfil: abas Remédios | Receitas | Alergias (`?aba=`), CTA "Fotografar receita", receitas mostram origem (foto/à mão), estado (em leitura, aguardando conferência, falhou) e itens vinculados.

**Provedor de visão.** `AI_VISION_DRIVER=anthropic` (`AnthropicVisionProvider`, bloco `image` base64 + texto, `system` via metadata) reaproveita `ANTHROPIC_API_KEY`, orçamentos e preços; `AI_VISION_MODEL` opcional. Custo por receita (Sonnet 5.5, foto ~1 MP ≈ 1.300–1.600 tokens de imagem + ~600 de prompt; saída ~200–400): ≈ US$ 0,006–0,009.

**Testes.** `tests/Feature/Consulta/ReceitaPorFotoTest.php` (6), `tests/Unit/Services/Ai/AnthropicVisionProviderTest.php` (4).

**Limitações.** Receitas manuscritas muito ilegíveis geram `legibility=poor`/itens com baixa confiança (o fluxo pede conferência, não corrige); a duração só vira `ends_at` em formatos simples; não há leitura de várias páginas/fotos por receita; sem validação farmacêutica do mapeamento nome→catálogo (o paciente escolhe entre candidatos); o rate limit é por usuário, não global.

## Identidade visual aplicada (05/11/2026)

Tokens da marca em `resources/css/app.css` (`brand` azul-marinho `#12344D`, `accent` laranja `#D9661F`, `surface` creme `#F7F4EE`), derivados do logotipo. No app do paciente: fundo `surface-50`, cabeçalho com símbolo da marca, barra inferior com item ativo em `brand-700` e traço `accent-500`, botão primário `brand-700`, **uma** ação de destaque por tela em `accent-600` (Perguntar, Fotografar receita, Entrar), gravidade clínica mantida nas cores semânticas com texto. `theme_color` `#12344d` e `background_color` `#f7f4ee` no manifesto; tela offline com as cores da marca. Contrastes verificados e regras de uso: `docs/design/identidade-visual.md`.

## Redesign sênior-primeiro (quarta onda, 05/11/2026)

Direção do usuário: grande parte do público é idosa; menus e tarefas precisam ser simples, com poucos toques e fácil entendimento. Contrato aplicado: `docs/design/principios-de-ux.md`. Lógica PHP intacta; só apresentação, textos e propriedades de exibição.

**Regras aplicadas a todas as telas**: texto base 18 px (`text-lg leading-relaxed` no shell), títulos 24–28 px, nada abaixo de 16 px em texto informativo; botões ≥ 56 px com texto 18–20 px, **uma ação por linha**; contraste ≥ 7:1 nos textos principais (`brand-900`/`slate-900` sobre branco); nenhum gesto além do toque; confirmações sempre com dois botões empilhados ("Sim, apagar" / "Não"); vocabulário "remédio", "substância", "bula simples"; rodapé de uma linha ("O BULA-IA não substitui seu médico ou farmacêutico." + "Saiba mais" → `/sobre`, rota `consulta.sobre`, view estática `resources/views/consulta/sobre.blade.php`) e aviso completo na primeira abertura (cartão "Entendi", lembrado em `localStorage` `bulai-aviso-visto`).

**Navegação**: barra inferior com três destinos (Início, Perguntar, Remédios; ícones 28 px, rótulo 16 px, altura 76 px). Histórico sai da barra e fica na Início ("Ver o que já perguntei") e no menu da conta; cabeçalho mínimo (título + Voltar grande); avatar só na Início. Componentes novos: `x-consulta.toast` (aviso breve após ação), `x-consulta.risco` (uma frase de risco em linguagem comum, derivada do contexto determinístico: alergia > interação maior/moderada > duplicidade), `x-consulta.tratamento` com `somente-relevante`.

| Tela | Decisões | Ação primária | Sem duplicação | Três níveis | Feedback | Polling restrito | Mobile 400 px | Linguagem | Acessibilidade |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Início | saudação pelo primeiro nome + 4 botões grandes empilhados (Perguntar, Fotografar receita, Meus remédios, Ver o que já perguntei); sem caixa de texto, contadores ou recentes | Perguntar (laranja) | sim | sim | links `wire:navigate` | n/a | sim | sim | `aria-label`, foco 4 px |
| Perguntar | um campo grande (placeholder em linguagem comum), chips dos remédios em uso, "Falar a pergunta" (Web Speech API, só se suportado), botão Perguntar fixo; resposta: texto 20–24 px → "Ouvir a resposta" (speechSynthesis, progressivo) → UMA frase de risco → "De onde veio esta resposta?" recolhido → "Entendemos que você perguntou sobre…" recolhido com correção e tratamento | Perguntar | sim | sim | `wire:loading` no botão | n/a | sim | sem jargão | `aria-pressed`, `aria-live` |
| Pergunta anterior | mesma leitura da resposta; CTA fixo "Perguntar de novo" | Perguntar de novo | sim | sim | — | n/a | sim | sim | sim |
| Verificar interações | um campo, lista dos escolhidos com "Tirar", alerta de alergia inline, resultados com "+ Adicionar" e link "Ver a bula simples", CTA fixo | Verificar interações | sim | sim | `wire:loading` | n/a | sim | sim | `aria-live` |
| Interações (resultado) | faixa colorida + UMA frase em linguagem comum por par; "O que a bula diz" e "Entenda esta análise" recolhidos; progresso em bloco com `wire:poll.2s` próprio | Repetir / Tentar novamente | sim | sim | sim | sim | sim | sim | `role=status/alert` |
| Bula simples | CTA "Perguntar sobre este remédio", frase de risco, seções com faixa colorida e até 3 frases em 20 px, "De onde veio?" recolhido, esqueleto no carregamento, "No seu tratamento" recolhido | Perguntar | sim | sim | `wire:init` | n/a | sim | sim | `aria-labelledby` |
| Meus remédios | 3 abas grandes; lista com nome 20 px e posologia 18 px; "+ Adicionar remédio" abre a busca; "Opções" por item (bula, dose/horário, tirar); receitas e alergias idem; formulários só após o toque | Adicionar remédio | sim | sim | `wire:loading` | n/a | sim | sim | `role=tablist` |
| Fotografar receita | uma frase + botão enorme "Tirar foto da receita" + "Escolher da galeria" | Tirar foto | sim | sim | `wire:loading` na foto | n/a | sim | sim | `label` + `sr-only input` |
| Conferir a receita | "Está tudo certo?"; um cartão por item (nome 24 px, dose/horário, remédio do catálogo em destaque, "Está certo?" com "Não, trocar" / "Corrigir dose" / "Não cadastrar"); CTA fixo "Confirmar e salvar" | Confirmar | sim | sim | `wire:loading` | `wire:poll.2s` só no bloco "Lendo" | sim | sim | `aria-expanded` |
| O que já perguntei | duas abas grandes, cartões altos, "Apagar" com dois botões empilhados | Abrir item | sim | sim | `wire:loading.attr` | n/a | sim | sim | `role=tab` |
| Sobre | aviso completo + como funciona, CTA "Voltar ao início" | Voltar | sim | sim | — | n/a | sim | sim | sim |
| Entrar / Esqueci / Redefinir | um cartão, campos 56 px, uma ação laranja, link secundário discreto, rodapé de uma linha | Entrar | sim | sim | nativo | n/a | sim | sim | `aria-describedby` |
| Página pública | título em linguagem simples, um CTA, 4 cartões de benefício, limites | Entrar | sim | sim | — | n/a | sim | sim | sim |

## Remédios de hoje (programação do tratamento)

Tela `/hoje` (`App\Livewire\Consulta\Tratamento\Hoje`): cada horário programado dos remédios ativos do dia, agrupado em Manhã/Tarde/Noite, com estado em uma palavra (A tomar · Agora · Atrasado · Tomei · Pulei) e os botões empilhados "✓ Tomei" e "Pular este horário" (um toque; "Desfazer" quando marcado). Ontem/Amanhã para conferir ou corrigir. A Início mostra no botão "Remédios de hoje" a próxima dose ("Próximo: 14:00 · Losartana"; em vermelho quando atrasada). Horários vêm da receita (parser determinístico) e são editáveis em "Informar dose e horários" no perfil. Remédio fora do catálogo é cadastrado pelo nome, com o selo "Não encontrado no catálogo: vamos conferir". Detalhes, tabelas, parser e limitações: `docs/tratamento.md`.

| Tela | Resumo | Ação primária | Sem duplicação | Três níveis | Feedback | Polling | Mobile | Linguagem | Acessibilidade |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Remédios de hoje | hora 30 px + nome 20 px + dose; selo cor+texto; Tomei verde 64 px; Pular; Desfazer; vazio com Fotografar/Meus remédios | Tomei | sim | sim (dia → lista → Meus remédios) | `wire:loading` no botão + toast | `wire:poll.60s` só na lista | sim | "Tomei", "Pular", "Atrasado" | `aria-label` por linha, `aria-live` na lista, foco 4 px |
| Início (5 botões) | Perguntar, Remédios de hoje (próxima dose), Fotografar receita, Meus remédios, Ver o que já perguntei | Perguntar | sim | sim | links `wire:navigate` | n/a | sim | sim | idem |
| Conferir a receita | item com catálogo ou selo "Não encontrado"; horários sugeridos editáveis; nunca bloqueia | Confirmar e salvar | sim | sim | `wire:loading` | só durante a leitura | sim | "vamos conferir" | rótulos `sr-only` nos horários |

**Toques por tarefa** (a partir da Início): perguntar = Perguntar → digitar/falar → Perguntar (3); ouvir a resposta = +1; fotografar receita = Fotografar → Tirar foto → Ler esta receita → Confirmar (4, sendo um a câmera do aparelho); adicionar remédio à mão = Meus remédios → Adicionar remédio → digitar → Adicionar (4; fora do catálogo: Cadastrar assim mesmo, 4); marcar que tomou = Remédios de hoje → Tomei (2); ver uma pergunta anterior = Ver o que já perguntei → item (2); apagar um item = +2 (Apagar → Sim, apagar).

**Limitações**: Falar/Ouvir dependem do suporte do navegador (Chrome/Android e Safari/iOS modernos; sem suporte os botões não aparecem); nenhuma verificação em dispositivo real nem com usuários idosos; o conteúdo das respostas (até 4 frases) vem do validador existente, sem reescrita para leitura fácil.

## Conversa com contexto, correção de digitação e ditado por voz (iteração 5)

**Propósito.** Três reclamações de uso: o sistema não entendia a pergunta seguinte ("e com café?"), não entendia nomes digitados errado e o ditado por voz "hora funcionava, hora não".

**Arquivos.** `app/Livewire/Consulta/Perguntar.php`, `resources/views/livewire/consulta/perguntar.blade.php`, `app/Services/Patient/MedicationQuestionService.php`, `resources/views/components/consulta/resposta.blade.php`, `resources/js/voz.js` (importado por `app.js`). Fundação da tolerância a erros: `App\Services\Interactions\FuzzyMedicineMatcher` (pg_trgm), `MedicationSearchService` e `MedicationMentionDetector` (`fuzzy`, `corrected`).

**Conversa.**
- A tela guarda as trocas desta sessão em `$conversa` (até 6, versão enxuta: pergunta, resposta validada, remédios, uuid). As anteriores ficam recolhidas em "Perguntas anteriores (N)"; a atual aparece como balão (pergunta à direita, resposta à esquerda). "Começar outra conversa" limpa tudo. A conversa vive só na tela (estado Livewire); cada troca continua registrada em `medication_questions`.
- `MedicationQuestionService::askFreeform(User, string, array $forcedMedicineIds = [], array $conversation = [], array $excludedMedicineIds = [])`. `conversation` = últimas 3 trocas `{question, answer, medicine_ids}`. Quando a nova pergunta não cita remédio, os `medicine_ids` da última troca são reaproveitados e marcados `matched_text = 'conversa'`.
- `conversationText()` monta o resumo ("Conversa anterior (apenas para entender a pergunta atual; não é fonte de fatos sobre o remédio): Paciente perguntou: "…" Resposta dada: "…"", 180 caracteres por campo, 600 no total). Ele entra junto aos fatos do tratamento na evidência **T1** do prompt (`ExplanationGenerator::answer()`), nunca no system prompt nem dentro de `<pergunta>`. No registro, vira uma evidência à parte (`document_type = conversation_context`, `chunk_id = -1`, não citável) e `generation.conversation_turns` / `generation.conversation_context` dizem quantas trocas foram usadas. A tela mostra "Leva em conta o que você perguntou antes nesta conversa".

**Correção de digitação.** Cada remédio detectado por semelhança aparece como "Entendemos “lozartana” como LOSARTANA. Não é isso?" com "Trocar o remédio" (busca no catálogo, fluxo `corrigir`) e "Não era esse remédio" (`remover`: repete a mesma pergunta excluindo o id; com dois remédios, "Tirar" mantém só o outro). A busca da correção também mostra "parecido com “…”" nos resultados tolerantes.

**Ditado por voz (`voz.js`).** Componente Alpine global `ditado({ aplicar, confirmar, texto })`: `continuous`, `interimResults` (texto parcial em cinza), `pt-BR`; acrescenta ao que já está escrito; reinicia sozinho quando o navegador encerra por silêncio (até 6 vezes) enquanto o usuário não tocar em "Parar"; mensagens por erro (`not-allowed`, `no-speech`, `network`, `audio-capture`, `language-not-supported`); para ao navegar (`livewire:navigating`) ou ao esconder a aba; sincroniza com o Livewire uma vez, 700 ms depois do último trecho (`$wire.set`), não a cada palavra. Sem API (Firefox, alguns WebViews): botão oculto e dica "use o microfone do teclado do celular". Enter envia a pergunta.

**Testes.** `tests/Feature/Consulta/PerguntaLivreTest.php`: `test_a_follow_up_without_a_medicine_name_reuses_the_previous_medicines_and_gives_the_model_the_conversation`, `test_misspelled_medicines_are_understood_shown_as_a_correction_and_can_be_removed`, `test_the_question_screen_offers_dictation_with_a_keyboard_fallback_hint`; fundação em `BuscaToleranteAErrosTest`.

**Limitações.** O resumo da conversa entra na mesma evidência T1 do tratamento: uma afirmação do modelo pode ficar "ancorada" no texto de uma pergunta anterior do paciente (texto do usuário), por isso o rótulo deixa explícito que não é fonte de fatos e as respostas anteriores já passaram pelo validador. A conversa não sobrevive a recarregar a página (o histórico completo continua em "Ver o que já perguntei"). Tolerância a erros cobre trocas/omissões de letras (semelhança ≥ 0,5); letras trocadas de lugar ("dipirnoa") ficam abaixo do limite. O ditado depende do suporte do navegador e não foi testado em aparelhos reais.

## Instalação do PWA, sensação nativa e regras de layout mobile (iteração 5)

**Problema relatado**: a opção de instalar não aparecia; telas estouravam a largura do celular; a navegação não parecia um aplicativo.

### Instalação

- `resources/js/pwa.js` captura `beforeinstallprompt` (Chrome/Edge, Android e desktop), guarda o evento e avisa a interface pelo evento `bulai-pode-instalar` com o estado `{installed, prompt, ios, dismissed}`. `window.bulaiInstalar()` abre o diálogo nativo e registra a escolha; `appinstalled` esconde tudo e mostra um aviso. "Agora não" é lembrado por 14 dias (`localStorage`, com `try/catch`).
- Safari no iPhone/iPad **nunca** dispara o evento: o cartão mostra o passo a passo "Compartilhar → Adicionar à Tela de Início → Adicionar", com ícones. Chrome/Firefox para iOS não instalam PWA (o cartão não aparece neles).
- Componente `<x-consulta.instalar />` (cartão sênior-primeiro, sem props): aparece na Início quando instalável e não instalado. No menu da conta do shell há "Instalar o aplicativo" (`window.bulaiInstalarAgora()`): instala na hora ou, no iPhone, limpa a dispensa e leva à Início para ver o passo a passo.
- `public/manifest.webmanifest`: `id`, `start_url` `/inicio?source=pwa` (permite medir aberturas pelo ícone), `display_override`, `orientation: portrait`, `prefer_related_applications: false`, ícones PNG 192/512 `any` e 512 `maskable`, atalhos com ícone (Remédios de hoje, Perguntar, Fotografar receita, Meus remédios).
- `public/sw.js` (`bulai-static-v5`): handler de `fetch` (critério de instalabilidade do Chrome), ícone SVG pré-cacheado, navegação sempre pela rede com `/offline` como reserva. Política de cache e atualização controlada (`SKIP_WAITING`) mantidas.
- `layouts/app.blade.php`: `theme-color` claro/escuro, `apple-mobile-web-app-status-bar-style=default`, `application-name`, `format-detection=telephone=no`.

**O que ainda depende do navegador**: o Chrome só oferece a instalação em HTTPS, com manifesto e service worker válidos **e** após algum engajamento (segunda visita ou 30 s de uso); em teste local (`http://localhost`) o prompt aparece, mas em IP da rede não. O iOS não tem prompt. Sem o evento, o cartão não aparece no Android (não há como forçar).

### Sensação nativa (sem bibliotecas)

`resources/css/app.css`: sem realce azul ao tocar, sem "elástico" na rolagem (`overscroll-behavior-y`), sem zoom por duplo toque (`touch-action: manipulation`), transição de 150 ms entre telas com View Transitions + `wire:navigate` (desligada com `prefers-reduced-motion`), barra de progresso da navegação na cor de destaque, `.app-screen` com `100dvh`, resposta tátil (botões e links "afundam" 2% ao toque), `user-select: none` em botões e cabeçalho respeitando `safe-area-inset-top` quando instalado. `resources/js/pwa.js` fecha menus ao navegar (`bulai-navegando`) e volta ao topo da nova tela.

### Regras de layout mobile (fiscal `tests/Feature/Consulta/ConsultaDesignSystemTest.php`)

Varre `resources/views/livewire/consulta/**`, `resources/views/components/consulta/**` e `welcome.blade.php`. Falha com: `style=` inline; `whitespace-nowrap` sem `truncate`/`overflow-hidden`; `grid-cols-3+` sem prefixo responsivo; `w-64+`, `w-[…]`, `min-w-[…]` sem `max-w-…`; `text-[…]` acima de 2rem fora de `h1`/`h2`; `fixed inset-x-0` sem filho com `max-w-…`; `<table>` fora de `overflow-x-auto`; jargão (chunk, OCR, embedding, LLM, token, prompt…). Exceção consciente: atributo `data-mobile-ok="motivo"` na linha (ex.: seletor de 3 abas curtas). Guardas globais em `app.css`: `img/svg/video { max-width: 100% }`, `overflow-wrap: anywhere` em textos, `min-width: 0` nos filhos diretos do conteúdo e `overflow-x: clip` na casca.

**Testes**: `tests/Feature/PwaAssetTest.php` (manifesto instalável, metas, fluxo de instalação, service worker, página offline, marca) e `ConsultaDesignSystemTest` (3 testes). **Limitações**: sem teste em aparelho real; o prompt do Chrome não é controlável pelo site; splash screen do iOS não configurada (usa fundo + ícone).

## Telas do paciente redesenhadas (iteração 5)

**Problema relatado**: "as páginas estão muito pobres e pouco intuitivas na versão aplicação e ela quebra o tamanho do mobile". Todas as telas do paciente (exceto Perguntar, tratada na seção de conversa) foram refeitas com uma mesma linguagem visual de aplicativo nativo, mantendo o contrato sênior-primeiro (`docs/design/principios-de-ux.md`) e passando no fiscal de layout `ConsultaDesignSystemTest`.

### Componentes novos (`resources/views/components/consulta/`)

| Componente | Uso |
| --- | --- |
| `x-consulta.botao` | Botão/link grande (`size` sm/md/lg = 48/56/64 px; `variant` primary/accent/secondary/soft/success/danger/quiet; `loadingTarget`/`loading`). Texto quebra em vez de estourar (sem `whitespace-nowrap`). Substitui `x-knowledge-admin.button` nas telas do paciente. |
| `x-consulta.cartao` | Cartão com `tone` (neutral/brand/accent/warning/danger/success), `min-w-0`. |
| `x-consulta.icone` | Ícone de linha (Heroicons) em círculo colorido (`name`, `tone`, `size`; `plain` = só o SVG). Mapa de ~30 nomes (pergunta, relogio, camera, remedios, historico, bula, sino, alerta, check, mais, lupa, pilula, coracao, receita, apagar, seta, fechar, editar, ouvir, escudo, info, sol, lua, calendario, desfazer, usuario). |
| `x-consulta.selo` | Selo de situação (cor + texto, `break-words`). |
| `x-consulta.vazio` | Estado vazio ilustrado (SVG inline) com título, texto e ações no slot. |
| `x-consulta.anel-progresso` | Anel SVG "feitos/total" com `aria-label`; cor acompanha o avanço. |
| `x-consulta.secao` | Acordeão (`details`) com ícone, título, resumo e seta animada; `open` por padrão opcional. |
| `x-consulta.folha-acoes` | Bottom sheet (Alpine): sobe do rodapé no celular, centralizado no desktop; Esc/toque fora/Fechar; `x-trap.noscroll`. O pai declara `x-data="{ folha: false }"`. |
| `x-consulta.segmentos` | Controle segmentado (2 ou 3 opções) em modo Alpine (`modelo`) ou servidor (`acao` + `ativo`). |

`x-consulta.severity` ganhou `size="lg"` (selo grande), `x-consulta.risco` e `x-consulta.tratamento` ganharam ícone e `break-words`.

### Por tela

| Tela | O que mudou |
| --- | --- |
| Início | Data por extenso; chip de avisos não lidos; cartão do dia (anel de progresso, próxima dose, "Marcar como tomado" em 1 toque quando na janela); ações como cartões com ícone em círculo (Perguntar em laranja); `<x-consulta.instalar />`. |
| Remédios de hoje | Segmentos Ontem/Hoje/Amanhã; resumo com anel; banner de atrasados; linha do tempo vertical por período com bolinhas de estado; Tomei/Pular lado a lado com `flex-wrap`; seção "Só quando precisar"; vazio ilustrado com "Adicionar remédio". |
| Meus remédios | Segmentos Em uso/Receitas/Alergias; cartões com chips de horários e selos; folha de ações "⋯" (bula, horários, tirar com confirmação); posologia com "Mais detalhes" recolhido; "Você quis dizer…?" na busca; vazios ilustrados; consentimento com lista de garantias. |
| Bula simples | Capa azul-marinho com nome, substância e "Para que serve" em destaque; botão "Ouvir a bula simples" (`speechSynthesis`, pt-BR; para ao navegar); demais seções em acordeão com ícone e cor por tipo (duas primeiras abertas); "Perguntar sobre este remédio" fixo embaixo. |
| Verificar interações | Campo com ícone de busca; selecionados como chips removíveis (nome leva à bula); "Você quis dizer…?" quando a busca tolerante entrou em ação; resultados como cartões; CTA fixo. |
| Resultado | Remédios como chips; alergia em cartão com ícone; progresso com `<progress>` durante a análise; gravidade como selo grande; "O que a bula diz" e "Entenda esta análise" em acordeão. |
| O que já perguntei | Segmentos Perguntas/Interações; cartões com ícone, data relativa ("Hoje, 14:00", "Ontem, …"), selo de situação; confirmação de apagar inline; vazios ilustrados. |
| Pergunta anterior | Mesmo padrão; "Sobre" em acordeão com botões para a bula. |
| Avisos | Ícone por tipo (lembrete/fim de tratamento/remédio reconhecido), destaque dos não lidos, ações lado a lado com `flex-wrap`, preferências em cartão. |
| Fotografar / Conferir a receita | Barra de etapas 1 Foto → 2 Conferir → 3 Pronto (a etapa concluída fica verde); itens numerados; selo "Leitura incerta"; "Você quis dizer…?" na busca de correção; tela de confirmação com "Pronto!". |
| Sobre | Capa azul-marinho com o aviso e lista com ícones. |

### Checklist de UX (seção 8 dos princípios), por tela

| Critério | Início | Hoje | Meus remédios | Bula | Interações/Resultado | Histórico/Pergunta | Avisos | Receita | Sobre |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Ação primária única, visível sem rolar | Perguntar (ou Marcar como tomado) | Tomei | Adicionar remédio | Perguntar (fixo) | Verificar (fixo) / Repetir | Perguntar de novo (fixo) | Marcar todos como lidos | Ler / Confirmar (fixo) | Voltar (fixo) |
| Sem duplicação | ✓ | ✓ (estado = selo + bolinha, mesma cor) | ✓ | ✓ ("Para que serve" só na capa) | ✓ | ✓ | ✓ | ✓ | ✓ |
| Três níveis (cabeçalho → resumo → detalhe recolhido) | ✓ | ✓ | ✓ ("Mais detalhes") | ✓ (acordeões) | ✓ (acordeões) | ✓ | ✓ | ✓ ("Quem receitou") | ✓ |
| Lista enxuta (1 linha, 1 selo, 1 ação) | ✓ | ✓ | ✓ (ações na folha) | n/a | ✓ | ✓ | ✓ | ✓ | n/a |
| Feedback (`wire:loading`, toast) | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | n/a |
| Polling restrito | n/a | lista (60 s) | n/a | n/a | bloco da análise (2 s) | n/a | n/a | bloco da leitura (2 s) | n/a |
| Mobile 360–400 px sem rolagem horizontal | ✓ (`min-w-0`, `break-words`, `flex-wrap`; fiscal verde) | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Linguagem (verbos, sem jargão) | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Acessibilidade (rótulos, foco, `aria-*`, contraste AA) | ✓ (`aria-label` no anel) | ✓ (`aria-label` por dose, `aria-live`) | ✓ (`role=dialog` na folha, `x-trap`) | ✓ (`aria-pressed` no Ouvir) | ✓ | ✓ | ✓ | ✓ (`aria-current="step"`) | ✓ |

**Testes** (`DB_DATABASE=… php artisan test tests/Feature/Consulta`): `ConsultaPwaTest` (Início com 3 destinos e ações grandes; busca com "Você quis dizer…?"), `RemediosDeHojeTest` (cartão do dia com progresso, "Marcar como tomado" só do dono, `irPara`, estado vazio com "Adicionar remédio"), `PerfilEPerguntasTest` (três segmentos renderizados, posologia, receitas), `ReceitaPorFotoTest`, `AvisosTest`, `BulaHumanizadaTest`, `ConsultaDesignSystemTest` (fiscal de layout). **Limitações**: sem teste em aparelhos reais nem com pessoas idosas; "Ouvir a bula" depende de `speechSynthesis` do navegador (voz pt-BR nem sempre instalada); a folha de ações usa `x-trap` do Alpine Focus embutido no Livewire.

### Programação dinâmica por intervalo (06/11/2026)

Remédios "de tantas em tantas horas" deixam de ter horários fixos: depois de "Tomei", a próxima dose é contada a partir do horário real da marcação (regra, exemplos e limitações em `docs/tratamento.md`). Na interface: Remédios de hoje mostra o intervalo, "Tomou às HH:MM" e o selo "Ajustado" com a explicação; a Início acrescenta "· ajustado" à próxima dose; Meus remédios e Revisar receita oferecem a escolha "Contar a partir de quando eu tomar (recomendado)" / "Horários fixos todos os dias" e, no modo dinâmico, pedem só o primeiro horário do dia. Testes: `RemediosDeHojeTest::test_interval_medicines_show_the_real_time_and_the_recalculated_doses_and_the_mode_can_be_chosen`, `ReceitaPorFotoTest` (item "de 8 em 8 horas" confirma em modo dinâmico).

## Perguntar: chat amigável (iteração 5)

**Propósito.** Quando a bula não responde, a tela fala com a pessoa em vez de mostrar um relatório ("Limitações", "evidências", "seção · página"). A conversa é feita de balões: a pergunta à direita, o BULA-IA à esquerda com avatar e nome; "digitando…" enquanto procura; rolagem automática para o último balão; a caixa de texto, o ditado e o botão Perguntar ficam fixos embaixo.

**Arquivos.** `app/Livewire/Consulta/Perguntar.php` (`fala()`, `falaCurta()`, `listar()`, `reformulacoes()` — texto por situação, determinístico, sem IA), `resources/views/livewire/consulta/perguntar.blade.php`, componente novo `resources/views/components/consulta/balao.blade.php`; `resources/views/components/consulta/resposta.blade.php` (página do histórico) alinhado ao mesmo tom; `MedicationQuestionService::askFreeform()` passou a devolver `missing` (`unidentified`, `without_leaflet`) com os nomes em falta, sem mudar os campos existentes (`limitations`/`evidences` continuam no registro e no Analytics).

**O que o balão diz.**

| Situação | Texto | Próximo passo |
| --- | --- | --- |
| Respondida | a resposta; "Li isso na bula de X." (+ perfil, + conversa anterior) | Ouvir; "Ver na bula" recolhido com as partes usadas |
| Só partes da bula | "Não consegui montar uma resposta curta com segurança, mas achei na bula de X estas partes que falam disso:" + até 2 partes de ~300 caracteres | 3 reformulações em chips ("X dá sono?", "Como devo tomar X?", "X pode ser tomado com comida?", "Quais os efeitos mais comuns de X?", sem repetir a pergunta atual) |
| Nada na bula | "Procurei na bula de X e não achei nada sobre isso. Isso não quer dizer que seja “não”…" | reformulações + "pergunte ao seu farmacêutico" |
| Sem bula | "Ainda não tenho a bula de X comigo. Posso ajudar com outro remédio, ou você confere na bula que vem na caixa." | chips com outros remédios em uso; "Não é esse remédio?" |
| Nome sem bula ligada | "Encontrei o nome X, mas ainda não consigo ligar ele a uma bula…" | busca "Não é esse remédio?" |
| Sem remédio na pergunta | "Sobre qual remédio você quer saber? Escreva o nome como está na caixa…" | chips dos remédios em uso |
| Bloqueada | mensagem de segurança (e 188 quando há risco) | — |

Correção de digitação ("Entendemos 'lozartana' como Losartana. Não é isso?") é uma linha dentro do balão com "Trocar" / "Não era esse remédio". Remédios sem bula numa pergunta com vários viram uma frase ("Ainda não tenho a bula de Y comigo."). O contexto do tratamento (alergia, interação) continua como uma única frase de risco (`x-consulta.risco`) abaixo do balão; os detalhes (partes da bula, o que foi considerado, link do histórico) ficam em "Ver na bula" / "Mais detalhes".

**Testes.** `PerguntaLivreTest` (+4: só partes da bula, nada na bula, sem bula, falas determinísticas; nenhum balão mostra "Limitações", "seção", "página", "catálogo", "evidência" ou "Princípio ativo") e `PerfilEPerguntasTest` (textos). **Limitações.** As reformulações são modelos fixos por nome de remédio; a rolagem automática e o "digitando…" não foram verificados em aparelho real.

## Modo cuidador (09/11/2026)

Rotas novas: `/cuidadores` (Quem cuida de mim: convite, aprovação, permissões, histórico, tirar o acesso), `/cuidar` (Cuidar de alguém: usar o código, pessoas acompanhadas), `/cuidando/{vinculo}/hoje` e `/cuidando/{vinculo}/remedios` (dados do paciente cuidado). O menu do avatar ganhou o seletor "Ver os remédios de: Eu / Cuidando de Fulano" e os itens "Quem cuida de mim" e "Cuidar de alguém"; o shell aceita `cuidando` (vínculo) e mostra a faixa fixa "Você está vendo os remédios de Fulano · Voltar para os meus"; a Início lista "Quem você acompanha". Componentes novos: `x-consulta.form-posologia`, `x-consulta.cuidado-permissoes`, `x-consulta.cuidado-historico`. Detalhes: `docs/cuidador.md`.

## Envio da pergunta e rolagem (09/11/2026)

- Ao enviar (botão ou Enter), o texto vira na hora o balão da pessoa (`data-testid="pergunta-pendente"`, evento `pergunta-enviada`), com o "digitando…" abaixo. Antes, o balão só aparecia junto com a resposta.
- Quando a resposta chega, a tela rola até o **começo** da fala do BULA-IA (`scrollIntoView({ block: 'start' })`, com `scroll-mt-24` para o cabeçalho). Antes, a tela parava na pergunta e a pessoa precisava rolar para ler a resposta.
- Teste: `ConversaHumanizadaTest::test_the_sent_question_shows_at_once_and_the_screen_scrolls_to_the_start_of_the_answer`.
