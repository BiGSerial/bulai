# Bula humanizada, contexto de tratamento e registro analítico das respostas

Mapeamento no docx: Cap. 2, 2.7.1 e 2.7.2 (RAG e grounding); Cap. 3, 3.3.1 (arquitetura), 3.3.2 (etapas 3 a 5), 3.5.1 e 3.5.3 (avaliação e auditabilidade). Documentos técnicos: `docs/consulta-pwa.md`, `docs/analytics.md`, `docs/diretrizes-do-produto.md`, `docs/evaluation.md`.

## Objetivo do tópico

Descrever a segunda iteração do MVP (04/11/2026): (1) a "bula humanizada" gerada por RAG a partir das bulas cadastradas; (2) a pergunta livre que passa a conhecer o tratamento do paciente (receitas, medicamentos em uso, alergias) e a classificar o risco de interação; (3) o registro, no backend, de **todas** as respostas da IA com dados analíticos (evidências, validação, custo, latência) que sustentam a tese; (4) a recuperação híbrida com embeddings reais e a validação do provedor Anthropic; (5) a diretriz "nunca substitui o médico" como regra verificável do sistema.

## 1. Diretriz do produto como requisito verificável

A regra "o sistema nunca substitui um médico" deixou de ser só um aviso de interface e virou configuração única (`config/bulai.php`, chave `guidelines`) consumida em três pontos: rodapé/onboarding do PWA, prompt de sistema de toda chamada ao modelo (`prompt_rule`) e documentação. O `ExplanationValidator` continua descartando qualquer afirmação com verbo de conduta, de modo que a regra vale mesmo quando o modelo a desobedece. Detalhes e tabela de verificação: `docs/diretrizes-do-produto.md`.

## 2. Modelo de dados acrescentado

| Tabela | Finalidade | Observações |
| --- | --- | --- |
| `patient_prescriptions` | receita médica: prescritor, registro profissional, data, observações | apagável pelo usuário; cascata do usuário |
| `patient_medications` (+ colunas) | posologia (`dosage`, `frequency`, `started_at`, `ends_at`, `notes`) e vínculo opcional à receita | `isActive()` exclui tratamentos encerrados do contexto |
| `medication_questions` | cada pergunta livre: pergunta, status, resposta validada, evidências, **contexto de tratamento usado**, métricas de geração/recuperação, pipeline, duração, expiração | registro analítico; retenção `BULAI_QUESTIONS_RETENTION_DAYS` |
| `medicine_leaflet_summaries` | bula humanizada por **conjunto de princípios ativos** (`ingredient_key`), versão do prompt e *fingerprint* dos documentos | cache compartilhado entre produtos equivalentes e entre usuários; invalida-se quando uma bula é reprocessada |

Migração: `database/migrations/2026_11_04_120000_create_patient_care_and_answer_logs_tables.php`.

## 3. Contexto de tratamento (determinístico)

`App\Services\Patient\TreatmentContextService::for(User, AnvisaMedicine)` responde, sem LLM: o medicamento está em uso? com que posologia e receita? há alergia cruzada (`AllergyChecker`)? para cada medicamento em uso, a base DDInter registra interação e com que gravidade (`InteractionLookup`)? há duplicidade de princípio ativo? quais medicamentos em uso não puderam ser verificados (princípio ativo não identificado)? O resultado é ordenado por gravidade e devolve `highest_severity`.

Decisão de projeto: esse contexto é entregue ao modelo como **evidência citável** ("T1", documento "Contexto do tratamento") e não como instrução. Assim o modelo pode mencionar, por exemplo, "a base registra interação moderada com X, que você usa", mas a afirmação continua sujeita às mesmas regras de ancoragem das afirmações vindas da bula, e a gravidade nunca é decidida pelo modelo. Sem consentimento de dados de saúde o contexto é vazio e nada é gravado.

## 4. Bula humanizada (RAG por seção)

Pipeline (`App\Services\Leaflet\LeafletSummaryService`):

1. produto → princípios ativos → bulas ligadas (`knowledge_document_ingredients`), preferindo bula do paciente;
2. para cada uma das 8 seções configuradas (para que serve, como usar, benefícios, riscos e cuidados, efeitos colaterais, não use se, interações, procure ajuda), recuperação com filtro de seção da bula (`RetrievalQuery::sectionPatterns`) e consulta temática, no modo configurado (lexical/híbrido);
3. um único prompt com todas as evidências delimitadas e escapadas; saída JSON por seção com afirmações curtas e ids de evidência;
4. validação afirmação a afirmação (`ExplanationValidator`): JSON estrito, referência existente, sobreposição lexical mínima, ausência de conduta;
5. persistência com status `generated`/`partial`/`invalid`/`unavailable`, evidências com `supports_claims`, métricas e pipeline.

O usuário vê os cartões por seção, as fontes de forma compacta (documento · seção · página) e a frase "baseado em evidências cadastradas"; o trecho integral fica no backend (Analytics). A personalização ("Para você") vem do contexto de tratamento, não do modelo.

## 5. Pergunta livre com contexto

`MedicationQuestionService::ask(medicine, question, user)` recupera trechos das bulas do medicamento, acrescenta o contexto de tratamento como evidência "T1", gera e valida a resposta e **grava** o registro `medication_questions`. A interface mostra o painel "No seu tratamento" (em uso, posologia, interações com gravidade, alergias, não verificados) assim que o medicamento é escolhido, antes mesmo da pergunta. Estados: `not_identified`, `no_leaflet`, `no_evidence`, `excerpts_only`, `answered`.

Mudança em relação à iteração anterior: a pergunta passou a ser guardada (com consentimento implícito na conta e aviso na tela), porque o registro analítico de todas as respostas é requisito do trabalho; o usuário pode apagar cada pergunta ou todo o histórico, com auditoria por contagem, e há expurgo diário.

## 6. Registro analítico e auditoria (Analytics)

Área administrativa `/admin/analytics` (`docs/analytics.md`): KPIs de consultas, pares, perguntas e bulas humanizadas; distribuições de veredito, gravidade, status de geração, motivos de rejeição e modo de recuperação; taxa de ancoragem (afirmações aceitas / produzidas); latências (média, p50, p95) e tempos por etapa (lookup, recuperação, geração); consumo do provedor de IA (chamadas, tokens, custo estimado, falhas por código) com série diária. Páginas de detalhe reproduzem, por consulta/pergunta/bula, tudo o que foi usado: trechos completos, afirmações aceitas e rejeitadas, `generation`, `retrieval`, `timings`, `structured`. O comando `analytics:export` gera JSON/CSV anonimizado para o capítulo de resultados.

Privacidade: as listagens não exibem nome/e-mail (apenas id numérico); prompts e respostas brutas do modelo não são armazenados.

## 7. Recuperação híbrida e provedor real

Embeddings reais: modelo `bge-m3` Q8_0 (BAAI, licença MIT, multilíngue, 1024 dimensões, 634.553.760 bytes, SHA-256 `950f4a8e5e19477a6d3c26d2f162233c20002c601f75e4b002e3239997821167`), instalado por `scripts/install-embedding-model.sh` e servido pelo serviço `embeddings` (mesma imagem do classificador local, modo `LOCAL_AI_MODE=embedding`, que usa o llamafiler e adapta lotes para a API OpenAI-compatível) em `compose.yaml` e `compose.prod.yaml`. Indexação: 782/782 chunks, 0 falhas, ~22 min em CPU. Resultados (tabela completa em `23`, seção 3): híbrido MRR 0,375 e R@3 1,0 contra lexical 0,3125 e 0,5; vetorial isolado 0,0625. Ressalvas: amostra de 4 consultas e gabarito construído sobre o pool lexical.

Provedor real: 4 chamadas a `claude-sonnet-5-5` (US$ 0,024 no total) validaram conectividade, consulta de interações e perguntas; a pergunta sobre reações adversas do omeprazol foi respondida com 4 afirmações aceitas e 0 rejeitadas (2.729 tokens de entrada, 485 de saída, 3,6 s, US$ 0,0103). Detalhes em `23`, seção 3a, e em `docs/ai/anthropic-provider.md`.

Implantação: a produção passa a ter o serviço `embeddings` e uma rede `egress` para app/worker (saída para a API da Anthropic, antes inexistente), documentadas em `docs/deployment/kinghost.md`; a imagem da receita e os prompts nunca são persistidos.

## 7a. Segunda onda do mesmo dia: pergunta livre, receita por foto e redesign mobile

- **Pergunta livre sem seleção prévia**: o usuário escreve a dúvida em linguagem natural; `MedicationMentionDetector` localiza os medicamentos citados (nome comercial ou princípio ativo, inclusive "meus remédios"), `MedicationQuestionService::askFreeform` reúne trechos das bulas de todos os citados, o contexto de tratamento e as interações estruturadas entre eles, e grava o registro com a lista de medicamentos detectados. Sem medicamento reconhecido, o sistema pede o nome em vez de adivinhar.
- **Guarda de entrada** (`InputGuard`): triagem determinística que recusa injeção de instruções, código, pedidos de dano e assuntos fora do escopo antes de qualquer chamada ao modelo; a recusa é registrada para análise (`status = blocked`). Com o prompt de sistema e o validador de saída, forma a defesa em três camadas descrita em `docs/diretrizes-do-produto.md`.
- **Receita por foto**: a imagem é enviada ao provedor de visão (Anthropic, Messages API com bloco de imagem), transcrita para JSON estrito (prescritor, data, itens com dose/frequência/duração e confiança), cada item é casado com o catálogo ANVISA e **o usuário confirma** antes de qualquer gravação; a imagem é descartada após a extração e só a transcrição validada fica em `patient_prescriptions.extraction`. Custo estimado por receita com Claude Sonnet 5.5: foto de ~1 MP ≈ 1.300–1.600 tokens de imagem + ~600 de prompt + 200–400 de saída ≈ US$ 0,006–0,009, registrado em `ai_usage_logs` (capacidade `vision`) e sujeito aos orçamentos diário/mensal. Limitações: uma foto por receita; a duração só vira data de término quando escrita em dias/semanas/meses; o casamento nome→catálogo não tem revisão farmacêutica (o paciente escolhe o candidato); itens com confiança < 0,6 recebem selo "confira" e receitas com legibilidade "poor" recebem alerta e sugestão de nova foto; o provedor de visão foi validado com uma chamada real (05/11/2026): receita sintética com três itens e uma instrução adversária impressa ("ignore as instruções anteriores"), `claude-sonnet-5-5`, 2.322 tokens de entrada, 361 de saída, 3,2 s, US$ 0,0083; prescritor, registro, data e os três itens (dose, frequência, duração, confiança 0,97) transcritos corretamente, instrução ignorada, imagem descartada após a extração.
- **Redesign mobile**: navegação em quatro destinos (Início, Perguntar, Remédios, Histórico), tela inicial com uma caixa de pergunta e três ações principais (fotografar receita, verificar interações, meus remédios), CTAs fixos acima da barra inferior, segmentos acessíveis nas telas de perfil e histórico. Decisões: o título da tela vai ao cabeçalho; "Conta" sai da barra; o cartão "Fotografar receita" só aparece quando a rota existe; o resumo conta apenas tratamentos ativos. Pendente: inspeção em dispositivo real e auditoria de acessibilidade com ferramenta.

## 7b. Terceira onda: administração, relatórios, usuários, autenticação e identidade visual

- **Identidade visual única** derivada do logotipo (azul-marinho `#12344D`, laranja `#D9661F`, creme `#F7F4EE`) como tokens em `resources/css/app.css`, aplicada à administração e ao app do paciente; pares de contraste calculados e documentados em `docs/design/identidade-visual.md` (o laranja 500 reprova com texto branco, 3,58:1, por isso as ações de destaque usam o tom 600, 4,93:1).
- **Administração redesenhada**: barra lateral em quatro grupos (Base de conhecimento, Operação, Análises, Pessoas), recolhível e com gaveta no celular; Visão geral que consolida base, operação, uso do app, IA e pessoas com um bloco "Precisa de atenção"; página de Relatórios com geração em job, download protegido e comando `reports:generate` (analytics do período, inventário da base, consumo de IA, auditoria de usuários, registros de avaliação). Detalhes em `docs/relatorios.md` e `docs/admin-design-system.md`.
- **Usuários e autenticação**: gerenciamento completo (busca, filtros, ordenação, senha provisória por e-mail, exigir troca, encerrar sessões, desativar com motivo, trilha de auditoria), redefinição de senha por e-mail, "lembrar-me", bloqueio progressivo por e-mail+IP, "sair de outros dispositivos" e histórico da própria conta. Senhas com **Argon2id** (parâmetros configuráveis; hashes bcrypt existentes migram no próximo login). O requisito falava em "Argon2D": o PHP e o Laravel oferecem Argon2i e Argon2id; o Argon2id é o híbrido recomendado (RFC 9106) que incorpora o modo "d" e foi o adotado. Detalhes em `docs/usuarios-e-autenticacao.md`.

## 7c. Quarta onda: redesign de todas as telas e abordagem sênior-primeiro

- **Contrato de UX** (`docs/design/principios-de-ux.md`): uma tela, uma ação primária; nenhum fato repetido; três níveis (cabeçalho, resumo decisório, detalhe sob demanda); listas de uma linha por item com um selo; feedback em toda ação; polling só no fragmento que muda; checklist por tela preenchido pelas frentes.
- **Administração**: a revisão de pendência passou a ter uma ação primária escolhida pelo contexto e o restante em "Mais", sem stepper nem blocos repetidos; listas de ingestão, visão geral, fila, catálogo, interações, analytics, relatórios e usuários seguiram o mesmo padrão; os agregados pesados da tela de interações ganharam cache de 60 s.
- **App do paciente, sênior-primeiro**: texto base de 18 px, alvos de 56 px ou mais, três destinos na barra, Início com saudação pelo primeiro nome e quatro botões, fluxos em até três toques, entrada por voz e leitura em voz alta por recursos nativos do navegador (aprimoramento progressivo), vocabulário comum ("remédio", "baseado na bula oficial"), rodapé de uma linha com página "Sobre". Contagem de toques por tarefa e checklist em `docs/consulta-pwa.md`. Limitação: não houve teste com usuários idosos nem em dispositivos reais.

## 8. Evidências automatizadas

| Tema | Teste |
| --- | --- |
| Contexto de tratamento, perguntas gravadas, receitas/posologia, histórico, expurgo | `tests/Feature/Consulta/PerfilEPerguntasTest.php` |
| Bula humanizada: estados, cache, validação, personalização | `tests/Feature/Consulta/BulaHumanizadaTest.php` |
| Analytics: KPIs, detalhe com evidências, anonimização, exportação | `tests/Feature/Admin/AnalyticsAdminTest.php` |
| PWA (manifesto, ícones PNG, service worker) | `tests/Feature/PwaAssetTest.php` |
| Recuperação (lexical, vetorial, híbrida) | `tests/Feature/Retrieval` |
| Guarda de entrada | `tests/Unit/Services/Safety/InputGuardTest.php` |
| Pergunta livre e detector de medicamentos | `tests/Feature/Consulta/PerguntaLivreTest.php` (ou `PerfilEPerguntasTest`) |
| Receita por foto e provedor de visão | `tests/Feature/Consulta/ReceitaPorFotoTest.php`, `tests/Unit/Services/Ai` |
| Relatórios administrativos | `tests/Feature/Admin/ReportsAdminTest.php` |
| Autenticação, Argon2id, usuários | `tests/Feature/Auth/*`, `tests/Feature/Admin/UserManagementAdminTest.php` |

Suite completa em 05/11/2026 (após as quatro ondas): 505 testes, 504 aprovados, 1 ignorado (teste "live" da Anthropic, executado à parte), 7.694 asserções, ~53 s.

## 9. Limitações e ameaças à validade

- A bula humanizada depende da cobertura de bulas cadastradas (17 documentos na base de desenvolvimento): produtos sem bula ligada recebem a mensagem honesta de indisponibilidade.
- A classificação de risco é a do DDInter, não uma avaliação clínica individual; posologia e receita são autodeclaradas e não validadas.
- O validador lexical de ancoragem aceita paráfrases com vocabulário coincidente e pode rejeitar paráfrases legítimas; a taxa de ancoragem mede conformidade ao procedimento, não correção clínica.
- Nenhuma validação por profissional de saúde foi feita (protocolos em `22`).
- Inspeção visual em dispositivos reais e auditoria de acessibilidade com ferramenta continuam pendentes.
