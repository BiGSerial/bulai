# Auditoria crítica do TCC (Issue #68), 12/11/2026 — rodada 1 (parcial)

| | |
|---|---|
| Data | 12/11/2026, a partir das 17:00 (UTC−3) |
| Ambiente lido | Produção, `https://bulai.vixforge.com.br`, token `api:evaluate` (somente `GET` e 3 perguntas de avaliação) |
| Código auditado | `main` em `6fe39e3` + a correção desta PR. **Produção roda `947ea4e` + `14e61bc`, deploy informado pelo responsável em 11/11; o SHA não é exposto pela API** (`code.version` é `null` nos registros). |
| Estado | **P0 diagnosticado e corrigido no código, ainda sem deploy nem verificação em produção.** P1/P2 parcialmente cobertos; o que falta está na seção "Não feito". |

## 1. P0 — interação grave em perguntas livres (anlodipino)

**Evidência.** Três sondagens, 12/11, `docs/qa/auditoria-critica-tcc-2026-11-12.sonda-anlodipino.json` (e as de 11/11 em `…pos-correcoes-2026-11-11.sonda-interacoes-pos-consolidacao.json`):

| Pergunta | Interpretação (IA) | Medicamentos resolvidos | Resultado |
|---|---|---|---|
| anlodipino + carbamazepina | `anlodipino`, `carbamazepina` | **só CARBAMAZEPINA** | `answered`, sem par, sem gravidade |
| anlodipino + itraconazol | `anlodipino`, `itraconazol` | **só ITRACONAZOL** | `answered`, sem par, sem gravidade |
| Norvasc + Tegretol | `Norvasc`, `Tegretol` | NORVASC, TEGRETOL (os dois) | `answered`; o par existe, mas a resposta não traz a gravidade do DDInter |

**Causa-raiz** (reproduzida por teste; os três itens são independentes):
1. **Nome sumindo sem aviso.** O interpretador nomeia os dois remédios, mas a detecção (`MedicationMentionDetector`) pode não resolver um deles (por exemplo, termo ambíguo, `focus()` devolve vazio). O fluxo segue só com o outro remédio: nenhum par se forma, o DDInter não é consultado e a resposta nem diz que "anlodipino" não foi reconhecido. **Por que o detector não resolve "anlodipino" em produção não foi confirmado**: exige o catálogo de produção, e a API não oferece consulta ao catálogo.
2. **Par pulado em silêncio.** `pairInteractions()` ignorava (`continue`) o par quando um dos medicamentos não tinha princípio ativo ligado: o modelo recebia zero linha do DDInter e respondia "não há orientação específica sobre esse par".
3. **Gravidade dependia do modelo.** Mesmo com o par consultado e `major`, a gravidade só aparecia se o modelo decidisse citá-la (caso Norvasc + Tegretol).

O verificador de pares (`InteractionEvaluator`, usado nos 1.483 pares) usa outro caminho (nome do produto → princípios ativos) e já estava correto; por isso o teste de integração fechou em zero falso negativo e o fluxo livre seguiu falhando. **Os dois caminhos medem coisas diferentes.**

**Correção** (`app/Services/Patient/MedicationQuestionService.php`): (a) par sem princípio ativo ligado vira `unverified` ("não foi possível verificar; isso não indica que não exista"), nunca "sem interação"; (b) nome apontado pela interpretação e não resolvido é declarado ao modelo e em `limitations`, e o par não é dado como verificado; (c) para todo par com interação registrada, uma frase fixa em `limitations` informa a gravidade do DDInter, sem depender da redação do modelo. `Perguntar.php` não mostra chip de gravidade para `unverified`; a API de avaliação passa a devolver `pair_interactions` e `retrieval.unresolved_names`, para auditoria.

**Testes** (`tests/Feature/Consulta/PerguntaInteracaoGraveTest.php`, 4 testes, 22 asserções): par grave por nome comercial nas duas ordens; medicamento sem vínculo; nome não resolvido; ausência de registro mantém a redação honesta e não gera limitação. **Antes da correção os 4 falham; depois, passam.** Suíte completa: 831 testes, 829 aprovados, 1 ignorado, 1 falho (`ResumoMedicoTest::test_the_patient_downloads_the_pdf_only_with_consent_and_the_download_is_audited` — `Barryvdh\DomPDF\Facade\Pdf` não instalado neste ambiente; não relacionado).

**Limites desta correção.** Não prescreve conduta. **Não resolve a causa de o detector não achar "anlodipino"**: só impede que a falha seja silenciosa. Ainda não foi verificada em produção. Risco equivalente (marcas, sinônimos, associações, grafias, ordem invertida, contexto) coberto apenas em parte: marca e ordem invertida por teste; associações, sinônimos, grafias e contexto **não** foram testados.

## 2. Achados

| # | Achado | Sev. | Evidência | Causa-raiz | Ação | Estado |
|---|---|---|---|---|---|---|
| 1 | Pergunta livre sobre par grave não traz a interação (anlodipino) | P0 | JSON de sondagem acima; teste `PerguntaInteracaoGraveTest` | Nome não resolvido some; par sem vínculo pulado; gravidade dependia do modelo | PRs #69 e #70 | **Corrigido e verificado em produção** nos 3 pares (seção 8); a gravidade vem em texto fixo, não no corpo da resposta |
| 2 | Detector não resolve "anlodipino" | P0 | 2 sondagens (`medicamentos_resolvidos`); catálogo de produção (seção 7) | `focus()` decidia pelo texto do catálogo, que grafa o mesmo fármaco de formas incoerentes | Foco pelos vínculos (seção 7) | **Corrigido e verificado em produção** (`0a59cc1`, seção 8) |
| 3 | Artigo diz que a causa dos 3 casos "não foi investigada" | P2 | `tcc-bula-ia-texto-final.md`, Discussão | Texto anterior ao diagnóstico | Proposta de redação (seção 5); **não alterei DOCX/MD** | Aberto, depende dos autores |
| 4 | Protocolo de 30 pares citado depois da correção, mas medido antes | P1 | Run `7cdf5716` (11/11 08:22, híbrido, 30/30) é anterior à PR #60; **não há protocolo de 30 pós-correção em produção** | Registro único, anterior | Reexecutado em 13/11 (`67c9b04b`): 30/30 | **Resolvido**; artigo atualizado |
| 5 | Recuperação (369 consultas) medida com 82 bulas; base atual tem 91 | P1 | Run `87d8519f` (11/11 08:22); inventário 17:03 de 12/11: 91 bulas, 6.699 trechos | Base mudou depois da medição; o artigo já ressalva | Reexecutado em 13/11 (`5923700e`, 102 bulas, 362 consultas) | **Resolvido**; artigo atualizado |
| 6 | Circularidade: gabarito DDInter = fonte da aplicação | P1 | Matriz 1.424/55/4, recall 1,000 | O teste mede integração, não validade clínica | O artigo já diz "integração, não correção clínica"; falta dizer a circularidade (seção 5) | Parcial |
| 7 | Os dois caminhos de interação divergem (pares × pergunta livre) | P1 | Pares: FN = 0; pergunta livre: falhou em 3 casos | Fluxos distintos | Teste de regressão do fluxo livre (esta PR); incluir pares do protocolo no fluxo livre | Parcial |
| 8 | Medição em produção usa conta de avaliação; sondagens entram nos totais | P2 | `audit/tcc`: 379 perguntas, 5 usuários | As perguntas de avaliação entram em `/stats` | Filtrar por `evaluation_user_id` ao citar uso | Aberto |
| 9 | Falha do OOM do PostgreSQL e limite de 1,5 GB | P1 | `audit/embeddings`, `audit/quality`, `audit/tcc` respondem 200, 0 grupos com falha | PR #65 | **O limite de memória efetivo não é verificável pela API** | **Não verificado** |
| 10 | Transferência internacional, retenção de 90 dias, consentimento | P1 | Código: `BULAI_QUESTIONS_RETENTION_DAYS=90`, `PurgeExpiredConsultationsCommand`; artigo cita o art. 33 | — | Valor em produção não verificado | Implementado em código; **pendente em produção** |

## 3. Matriz número do artigo ↔ registro de produção

| Número no artigo | Registro / endpoint | Resultado | Status |
|---|---|---|---|
| Identificação, ajuste: 55/59 (93,2%), parcial 2, errada 1, não resolvida 1; antes 52/59 (88,1%) | `26e00d60` (11/11 22:50); `5a135fd9` | 55/59; antes 52/59 | **Confirmado** |
| Identificação, reservado: 44/47 (93,6%), P 0,958, R 0,979, F1 0,968 | `c3356ab6`; `6ada6bd2` | idem | **Confirmado** (2 medições iguais) |
| Interações: 1.424 TP, 55 TN, 4 abstenções, FN 0, FP 0; sete graves recuperadas | `8601ffa5` (12/11 00:58) e `1264b525` (11/11 22:50); hash do dataset `62d2ed86…` | TP 1.424 / FP 0 / TN 55 / FN 0; abstenções 4; `major_interactions_missed` vazio | **Confirmado** |
| Gravidade: 666 coincidentes (110 graves, 482 moderadas, 74 leves) | `8601ffa5` | 110 / 482 / 74, concordância 1,0 | **Confirmado** |
| Protocolo de 30 pares: 30/30 | `7cdf5716` (antes) e `67c9b04b` (13/11, depois) | 30/30 nas duas; gravidade 10/5/5 | **Confirmado antes e depois** |
| Recuperação: Hit@3 híbrido 40,1% → 39,8%, vetorial 36,0% → 37,0%, lexical 20,6% → 20,4% | `87d8519f` (82 bulas, 369) e `5923700e` (102 bulas, 362) | conclusão igual; intervalos sobrepostos | **Atualizado no artigo** (população diferente: 52 princípios ativos com bula, eram 53) |
| 234 perguntas: 55,6% aceitas, 92,8% identificação etc. | `docs/qa/avaliacao-producao-2026-11-11.md` (código da PR #59) | — | **Confirmado no relatório**; não reexecutado |
| Flesch: mediana 58,3 × 30,3; 98,0% (196/200) | `GET /audit/readability?limit=200` hoje | 58,6 × 30,1; 98,0% (196/200) | **Divergente leve** (a população "últimas 200" muda; a proporção coincide) |
| Uso: 375 perguntas, 5.386 chamadas, US$ 21,63 | `GET /audit/tcc` hoje | 379 perguntas, 5.412 chamadas, US$ 21,88 | **Divergente por data** (consulta posterior; inclui perguntas de avaliação) |
| Base: 91 bulas prontas, 6.699 trechos, 1.846 princípios ativos, 60 com bula, 814 interações entre bulas | `GET /stats/knowledge` 17:03 | 91 / 6.699 / 1.846 / 60 / 814 | **Confirmado** |
| Qualidade: 24 regras | `GET /audit/quality` | 24 regras, 19 aprovadas, 0 erros, 1 aviso | **Confirmado** |

## 4. Não feito nesta rodada (e por quê)

- **Recuperação na base atual, protocolo de 30 pós-correção, nova bateria de 234 perguntas, comparação de modelos:** `evaluation:run` roda na VPS; a bateria consome IA (estimativa: ordem de US$ 5–10, a partir dos tokens da bateria de 11/11) e o Issue pede cuidado com recursos. Não executei sem autorização.
- **Amostra dos falsos bloqueios do validador (820 afirmações):** não feita; a rejeição não deve ser lida como alucinação comprovada.
- **Verificação do limite de memória do PostgreSQL (768 → 1.536 MB):** a API não expõe; precisa de `docker stats`/`inspect` na VPS.
- **Causa de o detector não resolver "anlodipino":** depende do catálogo de produção.
- **Risco equivalente** com sinônimos, associações, grafias e contexto: sem teste.
- Nenhum deploy, merge, nem alteração em produção. Apenas as 3 perguntas desta rodada (conta de avaliação, id 8).

## 5. Diffs propostos para o artigo (não aplicados)

1. **Discussão e Limitações:** trocar "cuja causa não foi investigada" por: "a causa foi investigada em 12 nov. 2026: a pergunta livre percorre um caminho distinto do verificador de pares; nele, um remédio citado e não reconhecido era descartado sem aviso e o par não era consultado; a correção torna a falha explícita, mas ainda não foi verificada em produção". Substitui "não foi investigada"; preserva o resultado negativo histórico.
2. **Verificação de Interações:** acrescentar que o gabarito é o próprio DDInter e que o resultado perfeito é, por construção, um teste de integração (circularidade), e indicar que o protocolo de 30 pares foi medido antes da correção.
3. **Recuperação:** manter a ressalva de que a medição é da base de 82 bulas; marcar como pendente a repetição com 91.

## Comandos e artefatos

`GET /api/v1/{me,stats/knowledge,audit/evaluations,audit/tcc,audit/quality,audit/readability?limit=200|1000}`; 3× `POST /api/v1/evaluation/ask`; `php artisan test --compact` (831 testes); `vendor/bin/pint --dirty`. Hashes dos datasets em `…pos-correcoes-2026-11-11.md`.

## 6. Verificação em produção após o deploy de `8104b3e` (12/11/2026, ~19:00)

Deploy informado pelo responsável; confirmado pelo comportamento: a resposta da API passou a trazer `pair_interactions` e `retrieval.unresolved_names`. 5 perguntas, `docs/qa/auditoria-critica-tcc-2026-11-12.sonda-pos-deploy-8104b3e.json`. É uma sondagem qualitativa, não substitui o conjunto de pares.

| Pergunta | Antes (11–12/11) | Depois (`8104b3e`) |
|---|---|---|
| anlodipino + carbamazepina | par ausente; "sem orientação específica" | `unresolved_names: [anlodipino]`; limitação "Não consegui identificar no catálogo: anlodipino… não foi verificada"; resposta diz que não foi possível confirmar |
| anlodipino + fenobarbital | `no_evidence` | idem (anlodipino não resolvido, declarado) |
| anlodipino + itraconazol | `needs_info` | idem; `no_evidence`, "a interação não foi verificada" |
| Norvasc + Tegretol | par existia, gravidade não aparecia | `pair_interactions`: **major**; limitação "DDInter registra interação de gravidade maior entre NORVASC e TEGRETOL" |
| ibuprofeno + varfarina | alerta pela bula dos EUA, sem gravidade do DDInter | `pair_interactions`: **major** (IBUPROFENO × VARFARINA SÓDICA); limitação com a gravidade |

**Leitura.** A garantia do P0 vale: nenhum dos 5 casos foi apresentado como "sem interação", e a gravidade registrada chega em texto fixo quando o par é resolvido. **A causa nº 2 persiste**: o detector ainda não resolve "anlodipino", então os três pares com anlodipino continuam **sem a interação grave**; agora a falha é declarada, não silenciosa. O achado nº 2 segue aberto, e o critério "os três casos de anlodipino trazem a interação grave" **não foi atendido**. Observação: a resposta do ibuprofeno + varfarina não cita a gravidade no corpo do texto (só em `limitations`).

## 7. Causa de o detector não resolver "anlodipino" (12/11/2026, noite)

**Dados de produção** (consultas de leitura via `bulai-deploy artisan tinker`, rodadas pelo responsável): 172 registros do catálogo citam "anlodip"; os 60 primeiros foram examinados. Quase todos estão ligados a `Amlodipine` (aliases: `besilato de anlodipino`, `amlodipine`, `amlodipina`), mas o **texto** do catálogo é incoerente:
- `AMLOCOR` (id 8374): ativo "BESILATO DE AMLODIPINA, BESILATO DE ANLODIPINO", o mesmo fármaco escrito duas vezes, ligado só a `Amlodipine`;
- `ANLODIPINO BESILATO` (id 7367): inativo, sem princípio ativo informado nem vínculo;
- `VALSARTANA + BESILATO DE ANLODIPINO` (id 11040): ativo informado só "VALSARTANA".

**Mecanismo, reproduzido em teste com os 60 registros reais:** a regra de foco (`MedicationMentionDetector::focus()`) escolhia o grupo "só com o ativo" pelos **componentes do texto**. O `AMLOCOR` faz o grupo de `Amlodipine` parecer uma associação ("amlodipina" + "anlodipino"), então o único grupo "de um componente" era o registro 7367, inativo e sem vínculo: a pergunta virava "ANLODIPINO BESILATO", sem princípio ativo e sem interações. Basta mais um registro incoerente (nome "ANLODIPINO…" com ativo "AMLODIPINA", forma que existe no catálogo) para que nenhum texto seja comum a todos os grupos; com mais de 3 grupos, o termo vira **ambíguo** e some, como observado em produção. **O registro exato que causa o sumiço em produção não foi identificado** (os 112 registros restantes não foram examinados); os dois modos têm a mesma causa e a mesma correção.

**Correção:** antes do foco pelo texto, o detector usa os **vínculos**: se todos os grupos ligados partilham um princípio ativo e há um grupo ligado só a ele, é esse o remédio (`linkedSingleIngredientGroups()`). Termos sem vínculo (ex.: pseudoefedrina só em associações) seguem a regra antiga.

**Testes:** `BuscaToleranteAErrosTest::test_a_base_name_follows_the_ingredient_links_when_the_catalog_text_spells_the_same_drug_inconsistently` e `PerguntaInteracaoGraveTest::test_anlodipino_with_carbamazepine_reaches_the_major_interaction_despite_inconsistent_catalog_spellings` (registros na forma real; anlodipino + carbamazepina chega ao DDInter com gravidade maior). Os dois falham sem a correção e passam com ela. Suíte completa: 833 testes, 831 aprovados, 1 ignorado, 1 falho (o mesmo do DomPDF, de ambiente).

**Pendente:** deploy e nova sondagem dos três pares com anlodipino; conferir se outros nomes do catálogo sofrem do mesmo problema (o padrão vale para qualquer fármaco com grafias incoerentes).

## 8. Verificação em produção após o deploy de `0a59cc1` (13/11/2026, ~10:15)

Deploy `0a59cc1` concluído às 13:03 UTC (Actions, `success`). 6 perguntas, `docs/qa/auditoria-critica-tcc-2026-11-12.sonda-pos-deploy-0a59cc1.json`. Sondagem qualitativa; não substitui o conjunto de pares.

| Pergunta | Medicamentos resolvidos | Par (DDInter) | Status |
|---|---|---|---|
| anlodipino + carbamazepina | BESILATO DE ANLODIPINO, CARBAMAZEPINA | **major** | `answered` |
| anlodipino + fenobarbital | BESILATO DE ANLODIPINO, FENOBARBITAL | **major** | `no_evidence` |
| anlodipino + itraconazol | BESILATO DE ANLODIPINO, ITRACONAZOL | **major** | `needs_info` |
| Para que serve o anlodipino? | BESILATO DE ANLODIPINO | — | `answered` (antes: `not_identified`) |
| Norvasc + Tegretol | NORVASC, TEGRETOL | **major** | `answered` |
| ibuprofeno + varfarina | IBUPROFENO, VARFARINA SÓDICA | **major** | `answered` |

Em todos os pares, `limitations` traz "A base de interações (DDInter) registra interação de gravidade maior entre …", e `unresolved_names` está vazio.

**Critério do P0 atendido nesta sondagem:** os três casos de anlodipino trazem a interação grave registrada no DDInter, e nenhum par foi apresentado como "sem interação".

**Ressalva (aberta):** o corpo da resposta, escrito pelo modelo, **não cita a gravidade** em nenhum dos 5 pares; ela chega só pela frase fixa em `limitations` (e pelo alerta da tela). Em `no_evidence`/`needs_info`, a resposta não explica a interação. Um próximo passo é exibir a gravidade registrada no topo da resposta, não só em `limitations`. Isso não foi feito.

## 9. Medições de 13/11/2026 e atualização do artigo

Rodadas na VPS pelo responsável, com o código de `0a59cc1`, lidas por `GET /api/v1/audit/evaluations`:

| Registro | Experimento | Resultado |
|---|---|---|
| `67c9b04b` (12:39–12:40) | Protocolo de 30 pares, híbrido, `interactions.protocol30.v2.json` (`fa993eed…`) | TP 20, TN 10, FP 0, FN 0, 0 abstenções; gravidade 10 graves, 5 moderadas, 5 leves, concordância 1,0. Igual a `7cdf5716`. |
| `5923700e` (12:41–12:50) | Recuperação por assunto, `retrieval.subjects.v1.json` (`5e2b54ee…`), base de 102 bulas e 7.363 trechos | 362 consultas (52 dos 56 princípios ativos com bula). Hit@3: híbrida 39,8% (34,9–44,9), vetorial 37,0% (32,2–42,1), lexical 20,4% (16,6–24,9). MRR 0,308 / 0,307 / 0,168. Hit@5: vetorial 51,7%, híbrida 50,3%. |

A recuperação não é uma comparação controlada com a de 11/11: a base passou de 82 para 102 bulas e o conjunto gerou 362 consultas, não 369. A conclusão do artigo não muda: a lexical é a mais fraca; vetorial e híbrida têm intervalos sobrepostos.

**Artigo (MD e DOCX):** resumo e abstract (362 consultas e novos percentuais; "91 a 102 bulas, conforme a data da medição"), Metodologia (base de 13/11 e repetição com `0a59cc1`), Quadro 3 (362 consultas), Resultados (protocolo antes e depois; recuperação de 13/11 com a de 11/11 como comparação), Discussão (causa do anlodipino e correção verificada) e Apêndice B (dois registros novos). Nenhum outro número foi alterado. **A contagem de páginas precisa ser refeita no Word.**

## 10. Revisão crítica do artigo inteiro (13/11/2026)

Leitura completa de `docs/tcc/tcc-bula-ia-texto-final.md` (versão da PR #71), procurando contradições e conferindo os itens do Issue #68. Correções aplicadas no MD e no DOCX:

| # | Problema | Onde | Correção |
|---|---|---|---|
| 1 | "a recuperação não foi medida com a base atual" e "medir a recuperação com a base atual", depois da medição de 13/11 | Considerações Finais (limitações e trabalhos futuros) | Removido; trabalho futuro: repetir a medição a cada alteração da base |
| 2 | "na data da avaliação final (12 nov.)", com medições em 13/11; e "(Apêndice A)" citado depois dos números de 13/11, que não estão no Apêndice A | Metodologia | "em 12 nov. 2026 (Apêndice A)"; a referência ao apêndice passou para os números dele |
| 3 | O Apêndice B atribuía todas as medições, exceto as de 13/11, ao *commit* 14e61bc. Pelo histórico de implantação (Actions): 11/11 8h20–8h38 rodou em `947ea4e`; 12/11 0h59 (`8601ffa5`) em `037adea` | Apêndice B | Versões corrigidas por medição, "conforme o histórico de implantação" |
| 4 | "após a correção, a de 12 nov." e Tabela B2 "Depois (12 nov.)", mas uma das medições pós-correção é de 11/11 22h52 | Resultados e Tabela B2 | "as de 11 nov. (22h52) e 12 nov."; "Depois (11 e 12 nov.)" |
| 5 | O Fluxo diz que, sem afirmação aceita, o sistema "mostra a gravidade e os trechos"; os Resultados dizem que "nenhuma [resposta] exibiu trecho cru" | Fluxo × Geração | O Fluxo distingue a verificação de pares (mostra os trechos) das perguntas livres (lê a bula inteira ou se abstém, sem trecho cru); as Considerações Finais dizem "nas perguntas livres". A Discussão, que fala do "caminho da pergunta livre", agora tem onde se apoiar |
| 6 | Recall conservador da primeira medição não informado (Issue #68: distinguir do recall condicionado) | Nota da Tabela 2 e Tabela B2 | "*recall* conservador de 0,926"; linha nova na Tabela B2 (0,926 → 1,000) |
| 7 | Ressalva de representatividade pedida no Issue ausente | Discussão | "as amostras são pequenas e não representam o conjunto dos medicamentos comercializados" |
| 8 | O recorte de 28 produtos / 1.132 / 376 pares sem data | Metodologia | "na medição de 12 nov. 2026" |

**Conferido sem problema:** remissões e numeração (Quadros 1–3, Tabelas 1–3, A1, B1–B2, C1–C4, Diagrama D1, Figuras E1–E2); todas as citações têm referência; circularidade do gabarito (resumo, Metodologia, Discussão, Considerações); DDInter exportado × 2.0; LGPD separando implementado (consentimento, 90 dias, sem identificadores) de pendente (art. 33); legibilidade ≠ compreensão; rejeição do validador não tratada como alucinação; somas das Tabelas C2 e C3; bulas dos EUA 64 prontas e 12 não encontradas.

**Deixado para os autores:** "et al." sem itálico em 6 citações (Lewis, Martins, Sicard, Tian ×2, Xiong) e em itálico nas demais (estilo); paginação no Word.

## 11. Evidências técnicas acrescentadas aos apêndices (13/11/2026)

- **Tabela B3:** SHA-256 dos 5 conjuntos de teste; conferido que o hash de cada um dos 12 registros de `evaluation:run` em produção coincide com o arquivo do repositório.
- **Tabela C5:** os 364 motivos de recusa das 820 afirmações da bateria de 11/11, com o significado de cada código do `ExplanationValidator`; 89 (24,5%) são recusas por forma. O texto das afirmações recusadas não é gravado, só o motivo (limitação registrada na nota).
- **Apêndice F:** Tabela F1, as perguntas de anlodipino em 5 momentos, com a versão do código pelo histórico de implantação e os medicamentos reconhecidos lidos de `GET /api/v1/questions/{uuid}`; Tabela F2, o rastreio completo da pergunta `98ac21b7` (13/11, 10h16), incluindo os limites que ele expõe (trecho mal segmentado; trecho relevante recuperado e não usado).
- Remissões no corpo: Discussão → Apêndice F; Geração → Tabela C5; Considerações Finais (rastreabilidade) → Apêndice F.

