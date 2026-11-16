# Auditoria científica e rodada final de testes do BULA-IA, 16/11/2026

| | |
|---|---|
| Execução | 16/11/2026, 19:10–20:40 (UTC−3) |
| Ambiente | Produção, `https://bulai.vixforge.com.br`, token `api:evaluate` (id 70, conta de avaliação id 8). Só leituras (`GET`) e 32 perguntas de avaliação (`POST /evaluation/ask`). Nenhuma mutação. |
| Código em produção | `0a59cc1` (deploy de 13/11, 13:07 UTC). `main` = `6a54572` (só documentação a mais). |
| Base no momento | 104 documentos (102 prontos, 2 com falha), 7.363 trechos, todos com *embedding*; 1.846 princípios ativos, 66 com bula; 234.981 interações do DDInter; 64 bulas dos EUA prontas |
| Artigo auditado | `docs/tcc/tcc-bula-ia-texto-final.md` e `Word/TCC_BULA-IA_artigo_2026-11-12.docx` da `main` (PR #71) |
| Evidências | Este arquivo; `auditoria-cientifica-2026-11-16.independente.json` (32 respostas analisadas); `database/evaluation/freeform.independent.v1.json`; `scripts/evaluation/independente.py` e `analisar_independente.py` |

## 1. Resumo executivo

- **O que as evidências sustentam:** a integração com o DDInter funciona no caminho de pares e, desde 13/11, também na pergunta livre: na rodada nova, **29 de 29** pares rotulados tiveram a gravidade igual à do DDInter (IC 95%: 88,3%–100%), inclusive com genéricos, sais, grafias alternativas, erros de digitação, três medicamentos e histórico de conversa; **60 de 60** medicamentos reais foram reconhecidos e o único nome inventado foi declarado como não reconhecido. Todos os intervalos de confiança do artigo se reproduzem.
- **O que não sustentam como está escrito:** (a) o teste de 1.483 pares **não** exercita o reconhecimento do nome digitado (começa pelo número de registro); (b) a recuperação é medida **só dentro das bulas do próprio princípio ativo**; (c) a mudança de 369 para 362 consultas vem sobretudo de **mudança no avaliador** (PR #60), não só da base; (d) há afirmações sobre OCR, LGPD e "bula dos EUA traduzida" que são só parcialmente confirmadas.
- **Defeitos reais encontrados hoje (seção 5):** fármaco ausente do DDInter (dipirona) é comunicado como "não há interação registrada"; uma interação grave recebeu como resposta só uma pergunta; em 14 de 24 respostas com interação registrada a gravidade aparece apenas no texto fixo das limitações; uma pergunta conceitual foi reconhecida como o produto "TESTE SISTEMA" do catálogo; o OCR nunca concluiu em produção; a bula profissional do Marevan falhou.
- **Nada disso é validação clínica.** O gabarito é o DDInter, consultado pelo próprio sistema; nenhuma resposta foi revisada por profissional de saúde.

## 2. Experimentos antigos auditados (seção 1 da tarefa)

Fonte: `GET /api/v1/audit/evaluations` (12 registros) e histórico de implantação (GitHub Actions, horário de **conclusão** do deploy).

| Registro | Experimento | Início (BRT) | *Commit* em produção | Hash do conjunto confere | Modelos / parâmetros |
|---|---|---|---|---|---|
| 5a135fd9, 6ada6bd2 | Identificação ajuste / reservado (antes) | 11/11 08:20 | `947ea4e` | sim (`a5f54b22…`, `d3519d0a…`) | lexical, semente 42 |
| 0d46ca9e | Interações 1.483 (antes) | 11/11 08:20 | `947ea4e` | sim (`62d2ed86…`) | lexical |
| 7cdf5716 | Protocolo 30 (antes) | 11/11 08:22 | `947ea4e` | sim (`fa993eed…`) | híbrida |
| 87d8519f | Recuperação por assunto (antes) | 11/11 08:22 | `947ea4e` | sim (`5e2b54ee…`) | lexical, vetorial, híbrida; BGE-M3 |
| 9d8c430b | Sem recuperação | 11/11 08:33 | `947ea4e` | sim (`62d2ed86…`) | Claude Sonnet 5.5, 120 pares, semente 42 |
| 26e00d60, c3356ab6 | Identificação (depois) | 11/11 22:50 | `14e61bc` | sim | lexical |
| 1264b525 | Interações 1.483 (depois) | 11/11 22:50 | `14e61bc` | sim | lexical |
| 8601ffa5 | Interações 1.483 (depois) | 12/11 00:58:41 | `037adea` (deploy concluído 00:58:12) | sim | lexical |
| 67c9b04b | Protocolo 30 (depois) | 13/11 12:39 | `0a59cc1` | sim | híbrida |
| 5923700e | Recuperação por assunto (depois) | 13/11 12:41 | `0a59cc1` | sim | três modos |

- O `ExplanationGenerator` e o *prompt* `interaction-explanation-v3` não mudam desde 10/11: modelo e *prompt* da explicação foram constantes em todas as rodadas. O campo `code.version` dos registros é `null`; a versão vem do histórico de implantação.
- **Intervalos de confiança:** os 15 citados no artigo foram recalculados (Wilson, 95%) e conferem todos.
- **Coerência artigo × registros:** os números do artigo conferem com os registros. Ressalvas na seção 6.

**Pontos específicos pedidos:**

1. **24 regras da auditoria de dados** (`GET /audit/quality`): 19 sem ocorrência; 1 **aviso** com ocorrência (648 das 234.981 interações do DDInter sem os dois princípios ativos resolvidos); 4 **informativas** com ocorrência, que não são falha: 39 trechos sem seção, 26 documentos com trechos repetidos, 8.243 pares com mais de um registro no DDInter (variantes como "(topical)"), 13.734 registros do catálogo sem princípio ativo. Zero erros. A regra "interação sem gravidade" tem 0 ocorrências porque `unknown` é um valor de gravidade, não ausência.
2. **1.424 interações × 666 classificadas:** no gabarito, 758 dos 1.424 pares com interação têm gravidade `unknown` no DDInter (registrada sem classificação). O avaliador exclui `unknown` da concordância de gravidade (`InteractionEvaluator`, linha 76). 110 + 482 + 74 = 666. Os 59 restantes não têm registro (negativos).
3. **199 respondidas × 177 da análise de fontes:** populações diferentes. O denominador de 177 (`scripts/evaluation/analisar.py`) = perguntas dos grupos bula brasileira, marca e conversa **com fontes** e com assunto mapeado a seção = **170 respondidas + 7 pedidos de esclarecimento** (`needs_info`). Das 199 respondidas, ficam fora: 12 de marca e 12 de conversa (assunto sem seção mapeada), 3 sem bula brasileira e 2 de urgência. O artigo chama os 177 de "respostas", mas 7 não são.
4. **Conjuntos antes e depois das correções:** todas as rodadas de produção, antes e depois, usaram a **mesma v2** (hashes idênticos): a comparação antes × depois é controlada quanto ao conjunto. A diferença v1 → v2 (feita antes das rodadas de produção) é o DDInter de 8 → 14 grupos: mesmos 1.483 pares e 59 consultas, mas **486 pares mudaram de rótulo** (negativos 545 → 59); no protocolo, 24 dos 30 pares foram sorteados de novo.

## 3. Rodada nova e independente (seções 2 e 3 da tarefa)

**Conjunto:** `database/evaluation/freeform.independent.v1.json` (SHA-256 `da527c18956f9eac410566499cec7a2b9001669348bd35157d8caaab0af29f8c`), 32 itens, criado hoje sem alterar os anteriores. Pares rotulados = rótulos de `interactions.v2.json` (DDInter, 14 grupos; `gold_ref` = id do par), sorteados com semente 20261116 **entre produtos não envolvidos nas correções**, e citados por nomes **diferentes dos do conjunto de ajuste** (genérico, sal, grafia alternativa, erro de digitação, marca). Itens sem par rotulado avaliam comportamento. **Limitação:** os 55 produtos de origem também estão no conjunto de ajuste de identificação; a independência está nos nomes e nos pares, não nos fármacos. O DDInter é referência de integração, não validação clínica.

**Execução:** `python3 scripts/evaluation/independente.py <TOKEN> saida.json` (16/11, 19:15–19:28), uma pergunta por vez, 10,5 s entre elas, com o registro completo de cada uma (`GET /questions/{uuid}`). Análise: `python3 scripts/evaluation/analisar_independente.py saida.json analise.json`.

| Estágio | Resultado |
|---|---|
| **Identificação** | 60 de 60 medicamentos reais reconhecidos (IC 95%: 94,0%–100%), incluindo "ciprofloxacina", "diazepan", "anlodipno", "carbamazepna", "omeprazou", Plasil, Flagyl, Decadron, Cipro, Lamictal, "naproxeno sódico", "diclofenaco potássico" e o turno de conversa ("E posso tomar junto com naproxeno?"). O nome inventado (Zylofrex) foi **declarado** como não reconhecido. Nenhum descarte silencioso. Falha: a pergunta conceitual R5 virou o produto "TESTE SISTEMA" (defeito D4). |
| **Consulta ao DDInter** | 29 de 29 pares com a gravidade do gabarito (IC 95%: 88,3%–100%): 13 graves, 6 moderadas, 3 leves, 4 sem classificação e 3 sem registro, incluindo os 3 pares de uma pergunta com três medicamentos |
| **Recuperação** | 22 respostas com trechos (mediana de 6). 7 itens sem bula brasileira de nenhum dos dois fármacos (`no_leaflet`): a gravidade veio só nas limitações. A bula inteira foi lida em 8 perguntas. |
| **Geração e validação** | 40 de 70 afirmações aceitas (57,1%; IC 95%: 45,5%–68,1%). Recusas: 23 sem sustentação no trecho, 4 narração da fonte, 2 recomendação de conduta, 1 negação ausente do trecho. Nenhum trecho cru exibido (0 de 32). |
| **Texto ao usuário** | Nenhuma resposta afirmou que uma combinação é segura. Das 24 respostas com interação registrada, **10** (41,7%; IC 95%: 24,5%–61,2%) citam a gravidade no próprio texto; nas outras 14 ela aparece só na frase fixa das limitações (defeito D3). |
| **Tempo** | Mediana de 8,6 s no servidor |

Perguntas obrigatórias da tarefa:

| Pergunta | Medicamentos | DDInter | Resultado |
|---|---|---|---|
| Posso tomar anlodipino com carbamazepina? | besilato de anlodipino, carbamazepina | grave = gabarito | Responde; gravidade nas limitações; texto do modelo não a cita |
| Existe interação entre ibuprofeno e varfarina? | ibuprofeno, varfarina sódica | grave = gabarito | Responde; idem |
| Posso combinar fenobarbital e itraconazol? | fenobarbital, itraconazol | **moderada** = gabarito | Responde |
| Quais interações existem entre os medicamentos que estou tomando? | — | — | `no_medicine`: a conta de avaliação não tem tratamento cadastrado; **o caminho do tratamento não pôde ser testado** |
| O sistema não encontrou interação. Isso significa que a combinação é segura? | "TESTE SISTEMA" (errado) | — | `not_identified`: a pergunta não foi respondida (defeito D4) |

## 4. RAG (seção 4 da tarefa)

- **Mesmo conjunto e mesmo estado da base:** a rodada de 13/11 (`5923700e`) já comparou os três modos com as mesmas 362 consultas e a mesma base. Acerto@1 / @3 / @5 e MRR: lexical 11,6% / 20,4% / 27,1% / 0,168; vetorial 20,4% / 37,0% / 51,7% / 0,307; híbrida 19,9% / 39,8% / 50,3% / 0,308. Intervalos de vetorial e híbrida se sobrepõem: **não há evidência de superioridade da híbrida**.
- **O que o teste mede:** a consulta é restrita às bulas do princípio ativo (`$query['documents']`), e a relevância é decidida pelo título da seção do trecho. Por construção, o teste **não** mede recuperação de documento errado, e um trecho com seção mal rotulada conta como erro ou acerto conforme o rótulo, não conforme o conteúdo.
- **Origem dos erros: não determinada.** O avaliador não gravava o resultado por consulta, só as médias. Nesta auditoria ele passou a gravar, por consulta e modo, os 5 primeiros trechos com seção, tipo de bula e se são relevantes (`RetrievalEvaluator::detail()`, sem mudar métricas; teste em `EvaluationPipelineTest`). Com essa mudança implantada, repetir a medição e analisar os detalhes separa segmentação, seção mal rotulada e tipo de bula.
- **Evidências indiretas de erro de segmentação:** 39 trechos sem seção; no rastreio do Apêndice F, um trecho rotulado "Interações medicamentosas" era o título "7. Cuidados de armazenamento do medicamento".
- **Comparação com 11/11 não é controlada:** além da base (82 → 102 bulas), o **avaliador** mudou (PR #60, para não contar duas vezes o princípio ativo fundido). O código da busca (`KnowledgeRetriever`, configuração) não mudou entre `947ea4e` e `0a59cc1`.

## 5. Validador (seção 5 da tarefa)

- **Amostra de aceitas (5 respostas, leitura do auditor, sem valor clínico):** as afirmações de B1, N1, M3 e L1 estão sustentadas pelos trechos citados. Em N1, "a rosuvastatina não aparece entre eles" é negação sustentada pela lista da bula, e a própria bula diz "esta lista não é completa"; a resposta preserva a ressalva de que ausência de registro não prova segurança. Em G3, a afirmação sobre creatinina e potássio veio de trecho da bula dos EUA cujo **corpo estava em inglês** (só o título traduzido): a tradução não foi aplicada a esse trecho, ponto a verificar.
- **Recusadas:** o sistema grava o motivo, **não o texto** da afirmação recusada. Não é possível examinar falsos bloqueios, inversões de negação ou alteração de gravidade nas recusadas.
- **Proposta para registrar com segurança:** gravar, em `medication_questions.generation.rejected`, o texto da afirmação recusada, o motivo e os ids dos trechos citados, sob a mesma retenção de 90 dias e o mesmo acesso das perguntas (`api:read`, Admin). O texto é gerado pelo modelo a partir das bulas, mas pode ecoar a pergunta; por isso deve passar pela mesma triagem e retenção da pergunta, e nunca ir para logs.
- A taxa de aceitação (57,1% hoje; 55,6% em 11/11) **não é medida de correção factual**: o validador é lexical.

## 6. Ingestão e qualidade documental (seção 6 da tarefa)

| Item | Estado |
|---|---|
| Documentos | 102 prontos, 2 com falha: **bula profissional do Marevan (id 13, 0 trechos)**, que explica a varfarina "sem bula brasileira"; Combodart (id 95, falha na leitura em 12/11) |
| Duplicados | Nenhum PDF idêntico (SHA-256); nenhum título repetido com arquivo diferente |
| Integridade | Todos os PDFs presentes, tamanho confere, 102/102 ligados a princípio ativo, 7.363/7.363 trechos com *embedding* |
| Classificação | 46 paciente, 44 profissional, **14 sem classificação** |
| Títulos | **18 bulas com título genérico** ("Bula 1794355126111", "excipientes q.s.p…"), que aparecem ao usuário como fonte |
| Seções | 39 trechos sem seção identificada |
| OCR | **1 execução em todo o histórico, interrompida por tempo limite (300 s). Nenhuma evidência de OCR concluído em produção.** O serviço existir não comprova eficácia. A auditoria do PDF do documento 13 também estourou o tempo (HTTP 524) e foi interrompida para não sobrecarregar a produção |
| Bulas dos EUA | 64 prontas, 12 não encontradas; 646 seções traduzidas, 9 recusadas na tradução, todas via openFDA |

## 7. Artigo × implementação (seção 7 da tarefa)

| Afirmação do artigo | Código | Testes | Produção | Classificação |
|---|---|---|---|---|
| A base estruturada decide existência e gravidade; o modelo só explica | `PairAnalyzer`, `MedicationQuestionService::pairInteractions` | `PerguntaInteracaoGraveTest` | 29/29 pares com a gravidade do DDInter | **Confirmada** |
| PHP 8.4, Laravel 13, Livewire 4, PostgreSQL 17 + pgvector | `Dockerfile` (`php:8.4.27`), `composer.json`, `compose.prod.yaml` (`pgvector:pg17`) | — | — | **Confirmada** |
| *Embeddings* BGE-M3, 1.024 dimensões, locais | `compose.prod.yaml` (BGE-M3 Q8_0, GGUF) | — | `/audit/embeddings`: modelo único, 1.024 | **Confirmada** (versão quantizada Q8_0 não declarada) |
| Claude Sonnet 5.5 nas respostas, Haiku 5.5 na interpretação | configuração do provedor | — | registros de produção (`generation.model`, `interpretation.model`) | **Confirmada** |
| Busca híbrida com RRF, k = 60 | `PostgresKnowledgeRetriever::RRF_K = 60` | — | `rrf_k` nos registros | **Confirmada** |
| Até 4 trechos por par e até 3 afirmações | `evidence_per_pair = 4`; "No máximo 3 afirmações" no *prompt* | — | — | **Confirmada** (o 3 é instrução ao modelo, não limite do validador) |
| O validador só aceita afirmação apoiada no trecho | `ExplanationValidator` (lexical) | `ExplanationValidatorSemanticTest` | amostra coerente | **Parcialmente confirmada**: validação lexical; recusadas sem texto |
| OCR com PaddleOCR quando a leitura direta é insuficiente | serviço OCR existe | `KnowledgeOcrSupplementTest` | 1 execução, estourou 300 s | **Não confirmada em produção** |
| Revisão humana para entrar no sistema (Diagrama D1) | pendências com revisão | `KnowledgePendingReviewFlowTest` | — | **Parcialmente confirmada**: revisão nas pendências, não de todo PDF |
| Bula dos EUA traduzida e sinalizada | `App\Services\Knowledge\External\*` | `ExternalLabelsTest` | sinalizada; um trecho em inglês (G3) | **Parcialmente confirmada** |
| Consentimento explícito e revogável | `App\Livewire\Consulta\Perfil` revoga e apaga os dados | — | não verificável pela API | **Confirmada no código** |
| Consultas apagadas após 90 dias | `retention_days = 90`; agendamento diário 03:30; `schedule:work` em produção | `PerfilEPerguntasTest`, `InteractionAnalysisTest` | sistema com menos de 90 dias | **Confirmada no código; não verificável na prática** |
| Provedor recebe pergunta, medicamentos e trechos, sem nome nem e-mail; perfil só com achado | *prompts* sem identificador; `TreatmentContextService::alertsText` | — | — | **Parcialmente confirmada**: sem identificadores, mas a **posologia** registrada (dose e frequência) é enviada em perguntas de dose de remédio em uso, sem achado |
| Transferência internacional sem os mecanismos do art. 33 | — | — | — | **Confirmada** (declarada como pendente) |
| Teste dos 1.483 pares "verifica a identificação do medicamento e a consulta" | `InteractionEvaluator::snapshot()` usa o **número de registro** | — | — | **Parcialmente confirmada**: não exercita o nome digitado |
| Recall 1,000 sem FP nos 1.483 pares | `InteractionEvaluator` | `EvaluationPipelineTest` | `1264b525`, `8601ffa5` | **Confirmada**, com ressalvas: 4% de negativos; gabarito = fonte (circular) |
| Gravidade igual à da base nos 666 classificados | idem | — | `8601ffa5` | **Confirmada**; o texto não diz que os outros 758 são `unknown` |
| Recuperação: Acerto@3 híbrida 39,8% etc. | `RetrievalEvaluator` | idem | `5923700e` | **Confirmada**, com ressalvas: restrita às bulas certas; comparação com 11/11 não controlada (avaliador mudou) |
| 234 perguntas: 55,6% aceitas; 92,8% identificação; 99,4% (176/177) | — | — | `docs/qa/avaliacao-producao-2026-11-11.json` | **Confirmada**; os 177 incluem 7 pedidos de esclarecimento |
| Legibilidade: 98,0% mais fácil que a fonte | `ReadabilityService` | `AuditApiTest` | hoje 98,0% (196/200), medianas 58,8 × 28,7 | **Confirmada** (medianas variam com as "últimas 200") |
| Sem recuperação: 50 de 114 não reconhecidas; 12 de 48 gravidades divergentes; "as 9 graves foram reconhecidas" | — | — | `9d8c430b` | **Confirmada**; mas das 9 graves reconhecidas só **6** com a gravidade certa |
| Anlodipino corrigido em 13/11 | PRs #69 e #70 | `BuscaToleranteAErrosTest`, `PerguntaInteracaoGraveTest` | R1 e T1 hoje: grave | **Confirmada** |

## 8. Defeitos encontrados

**Erros técnicos reais**

| # | Defeito | Severidade | Evidência | Correção sugerida |
|---|---|---|---|---|
| D1 | Fármaco ausente do DDInter (dipirona) é comunicado como "não há interação registrada", como se o par tivesse sido verificado | **Alta** | item D1: `severity: null` para DIPIRONA × VARFARINA SÓDICA; o artigo diz que o DDInter não traz a dipirona | Tratar princípio ativo sem nenhum registro no DDInter como `unverified`, como já é feito para o remédio sem vínculo |
| D2 | Interação **grave** respondida só com uma pergunta (`needs_info`), com a gravidade apenas nas limitações | **Média-alta** | item G1 (haloperidol + metoclopramida) | Com par registrado, a gravidade entra no topo da resposta, em texto fixo |
| D3 | Em 14 de 24 respostas com interação registrada, o texto do modelo não cita a gravidade | Média | análise da rodada nova | Mesmo da D2 |
| D4 | A tolerância a erros reconhece "sistema" como o produto "TESTE SISTEMA" (registro de teste no catálogo); a pergunta conceitual fica sem resposta | Média | item R5 (`fuzzy: true`, `corrected: teste`) | Excluir registros de teste do catálogo; pôr "sistema" na lista de palavras comuns |
| D5 | OCR nunca concluiu em produção | Média | `/audit/pipeline`: 1 execução, 300 s | Investigar o tempo limite e o serviço antes de citar o OCR como funcional |
| D6 | Bula profissional do Marevan falhou | Média | documento 13, 0 trechos | Reprocessar (com autorização) |
| D7 | Texto das afirmações recusadas não é gravado | Média (auditabilidade) | `generation.rejection_reasons` só com contagens | Proposta da seção 5 |
| D8 | O avaliador de recuperação não gravava o resultado por consulta | Baixa (método) | `RetrievalEvaluator` | **Corrigido neste branch**, falta implantar e repetir |
| D9 | Trecho da bula dos EUA com corpo em inglês | A verificar | item G3 | Conferir a tradução das seções `external_openfda` |

**Inconsistências documentais (artigo)**

1. Metodologia: o teste dos 1.483 pares "verifica se a identificação do medicamento e a consulta da interação funcionam" → ele parte do **número de registro** e não testa o nome digitado; o reconhecimento do nome é medido no experimento de identificação e nas perguntas livres.
2. Recuperação: dizer que a busca é restrita às bulas do princípio ativo e que a relevância é decidida pelo título da seção.
3. Recuperação: a explicação "52 dos 56 princípios ativos tinham bula, contra 53" está **imprecisa** (redação feita nesta mesma sessão): a mudança de 369 para 362 vem sobretudo da correção do avaliador para não contar duas vezes o princípio ativo fundido (PR #60), além da base.
4. Verificação de interações: explicar que os 758 pares restantes têm gravidade `unknown` no DDInter.
5. Geração: "99,4% das respostas (176/177)" → "das perguntas com fontes", pois 7 dos 177 são pedidos de esclarecimento.
6. Tecnologias: o OCR não teve execução concluída em produção.
7. LGPD: a posologia registrada é enviada ao provedor em perguntas de dose.
8. Sem recuperação: "as 9 graves foram reconhecidas" → 9 reconhecidas como interação, 6 com a gravidade certa.
9. Diagrama D1: ver a revisão de 13/11 (corresponde à verificação de pares; erros de digitação na imagem).

**Limitações metodológicas (declarar à banca)**

- Gabarito = DDInter, a mesma base consultada pelo sistema: os resultados de interação medem integração, não correção clínica. Não há gabarito clínico independente.
- 59 de 1.483 pares (4%) são negativos: precisão e acurácia ficam pouco informativas; um classificador que sempre diz "há interação" acertaria 96%.
- Gabaritos montados pela própria equipe; os 55 produtos estão no conjunto de ajuste.
- Validador lexical; taxa de aceitação não é correção factual.
- Legibilidade (Flesch) não mede compreensão.
- Sem validação por profissional de saúde nem teste com usuários (SUS), por dependerem do Comitê de Ética.
- O caminho do tratamento do paciente não foi testado em produção (a conta de avaliação não tem remédios cadastrados).
- A rodada nova tem 32 itens: os intervalos são largos (29/29 → 88,3%–100%).

**Melhorias futuras:** gravidade no topo da resposta; gabarito independente do DDInter; reprocessar as 18 bulas de título genérico e as 14 sem classificação; registrar o *commit* em cada registro de `evaluation:run` (hoje `null`).

## 9. Reprodução

```bash
# Rodada independente (consome IA; ~16 min)
python3 scripts/evaluation/independente.py <TOKEN api:evaluate> saida.json
python3 scripts/evaluation/analisar_independente.py saida.json analise.json
# Registros e auditoria (somente leitura)
curl -s -H "Authorization: Bearer <TOKEN>" -A 'BULAI-Avaliacao/1.0' https://bulai.vixforge.com.br/api/v1/audit/evaluations
curl -s -H "Authorization: Bearer <TOKEN>" -A 'BULAI-Avaliacao/1.0' https://bulai.vixforge.com.br/api/v1/audit/quality
# Depois de implantar o detalhe por consulta, na VPS:
sudo bulai-deploy artisan evaluation:run retrieval --dataset=database/evaluation/retrieval.subjects.v1.json --mode=lexical,vector,hybrid --label=auditoria-rag-detalhada
```

| Arquivo | SHA-256 |
|---|---|
| `database/evaluation/freeform.independent.v1.json` | `da527c18956f9eac410566499cec7a2b9001669348bd35157d8caaab0af29f8c` |
| `database/evaluation/interactions.v2.json` (referência dos rótulos) | `62d2ed86ce1a058e4d8b2ad83d3dcf3e614838b7abf1b1ce4a24b9d8b7905ba4` |

Pendente desta tarefa: análise de origem dos erros do RAG (depende do deploy do detalhe por consulta e de nova execução na VPS) e a verificação de acessibilidade das bulas humanizadas (aguardando a exportação pedida em 16/11).
