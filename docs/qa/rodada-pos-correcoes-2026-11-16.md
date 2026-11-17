# Rodada após as correções da auditoria, 16/11/2026

| | |
|---|---|
| Versão medida | `aba1f43` em produção (deploy concluído 23:16 UTC de 16/11; CI verde): PRs #72 (auditoria e detalhe por consulta no avaliador de recuperação), #73 (correções D1, D2/D3, D4, D7 e consultas de leitura na API) e #74 (Laravel 13.42) |
| Versão de comparação | `0a59cc1` (auditoria de 16/11, `auditoria-cientifica-2026-11-16.md`) |
| Mudanças entre as duas | Código: D1, D2/D3, D4, D7, rotas novas, detalhe por consulta no avaliador, Laravel. **Sem** mudança de *prompts*, pesos de busca, normalização de nomes, regras de aceitação do validador, base de dados ou conjuntos de teste |
| Execução | `evaluation:run` na VPS (16/11, 20:36–20:50 BRT, rótulo `pos-auditoria`); rodada independente pela API (mesmo conjunto `freeform.independent.v1`, SHA-256 `da527c18…`); bulas humanizadas por `GET /audit/leaflet-summaries` |
| Evidências | `rodada-pos-correcoes-2026-11-16.independente.json`, `.recusas.json`, `.rag.json`, `.bulas-humanizadas.json`; scripts `analisar_independente.py`, `analisar_rag.py`, `analisar_bulas_humanizadas.py` |

Nada aqui é validação clínica: o gabarito de interações é o DDInter, e a leitura de afirmações é do auditor.

## 1. Experimentos de referência

| Registro novo | Anterior | Experimento | Resultado novo | Igual ao anterior? |
|---|---|---|---|---|
| `28343bc9` | `8601ffa5` | 1.483 pares (lexical) | TP 1.424, FP 0, TN 55, FN 0, 4 abstenções; gravidade 666/666 | **Idêntico** |
| `b0bc2d25` | `67c9b04b` | Protocolo de 30 (híbrida) | 30/30, 0 abstenções | **Idêntico** |
| `568c8792` | `c3356ab6` | Identificação, reservado | 44/47 (93,6%); P 0,958, R 0,979 | **Idêntico** |
| `78c5668c` | `5923700e` | Recuperação, 362 consultas | Acerto@3: híbrida 39,8%, vetorial 37,0%, lexical 20,4%; MRR 0,308 / 0,307 / 0,168 | **Idêntico**, agora com o detalhe por consulta |

Hashes dos conjuntos iguais aos anteriores. **Por que nada mudou:** a correção D1 só altera pares com fármaco sem nenhum registro no DDInter, e nenhum dos 1.483 pares tem esse caso (os produtos com dipirona já estavam fora do conjunto); o código e a base da recuperação não mudaram desde 13/11. Os números do artigo continuam válidos para esta versão.

## 2. Rodada independente (32 perguntas livres), antes × depois

| Indicador | Antes (`0a59cc1`) | Depois (`aba1f43`) |
|---|---|---|
| Medicamentos reais reconhecidos | 60/60 | 60/60 |
| Nome inventado declarado como não reconhecido | 1/1 | 1/1 |
| Pares com a gravidade do DDInter | 29/29 | 29/29 |
| **Respostas com texto que informam a gravidade registrada** | **10/18 (55,6%; IC 95%: 33,7%–75,4%)** | **18/18 (100%; IC 95%: 82,4%–100%)** |
| Fármaco fora do DDInter (dipirona + varfarina) | "Não há interação registrada" | `no_evidence`: "a base de interações consultada não traz a dipirona"; limitação "Dipirona não consta da base de interações (DDInter)" |
| Pergunta conceitual ("O sistema não encontrou interação…") | Reconhecida como o produto "TESTE SISTEMA" | Nenhum medicamento reconhecido (`no_medicine`); a pergunta segue **sem resposta conceitual** |
| Interação grave haloperidol + metoclopramida | Só uma pergunta ao usuário | Abre com "Atenção: … gravidade maior …" |
| Afirmação de que uma combinação é segura | 0 | 0 |
| Trecho cru exibido | 0 | 0 |
| Afirmações aceitas pelo validador | 40/70 (57,1%) | 33/62 (53,2%; IC 95%: 41,0%–65,1%) |
| Recusas gravadas com o texto (D7) | 0/30 | **29/29** |
| Tempo no servidor (mediana) | 8,6 s | 5,6 s |

As 6 respostas sem texto são perguntas sem bula brasileira de nenhum dos dois fármacos (`no_leaflet`); nelas a gravidade aparece só nas limitações, como antes.

## 3. Situação dos defeitos

| # | Defeito | Situação |
|---|---|---|
| D1 | Fármaco fora do DDInter como "não há interação registrada" | **Corrigido e verificado em produção** (D1 e C1) |
| D2/D3 | Gravidade só nas limitações | **Corrigido e verificado** (18/18); a causa raiz está no validador (achado V1) |
| D4 | "sistema" → "TESTE SISTEMA" | **Corrigido e verificado**; a pergunta conceitual continua sem resposta (achado N2) |
| D5 | OCR nunca concluiu em produção | Aberto (operacional, precisa da VPS) |
| D6 | Bula profissional do Marevan falhou | Aberto (reprocessar com autorização) |
| D7 | Texto das recusas não era gravado | **Corrigido e verificado** (29/29) |
| D8 | Avaliador de recuperação sem detalhe por consulta | **Corrigido e usado** na seção 5 |
| D9 | Trecho da bula dos EUA em inglês | Não reavaliado |

## 4. Validador: o que as recusas mostram (agora visíveis pela D7)

Leitura do auditor, sem valor clínico. `docs/qa/rodada-pos-correcoes-2026-11-16.recusas.json` tem as 29.

| Achado | Evidência | Leitura |
|---|---|---|
| **V1. A gravidade do DDInter dita pelo modelo é recusada** | 6 de 29 recusas repetem corretamente a gravidade registrada ("Existe registro de interação de gravidade maior entre anlodipino e carbamazepina…") e saem como `not_grounded_in_cited_evidence` | **Causa raiz de D2/D3.** A frase fixa no topo contorna, mas o validador continua bloqueando a afirmação correta |
| **V2. Conselho de segurança negado é recusado** | T2 e B1: "Não mude nem suspenda nenhum dos dois remédios por conta própria" → `forbidden_recommendation` | A regra procura "pare/troque/suspenda" e não percebe a negação |
| **V3. Avisos relevantes recusados por falta de correspondência lexical** | G5 (síndrome serotoninérgica), G4 (depressão respiratória, recusado como "narra a fonte"), B1, T1, T2 | Pela leitura do auditor, parecem coerentes com as bulas, mas o validador exige as palavras do trecho citado |
| **V4. Decisão instável entre rodadas** | B1 (amiodarona aumenta o efeito da varfarina): **aceita** antes, **recusada** agora | Depende de qual trecho o modelo cita |
| Recusa correta | Z1: "Se o Zylofrex for um remédio para dor…" (especulação) | O validador barra extrapolação |

**Consequência para o artigo:** a taxa de aceitação (53–57%) **não** mede erro do modelo. Parte das recusas é falso bloqueio de afirmações corretas, e isso agora é demonstrável. Corrigir as regras (V1, V2) muda o validador e exige nova rodada com versão identificada; não foi feito.

## 5. RAG: origem dos erros (registro `78c5668c`, 362 consultas)

Classificação pelos títulos de seção dos 3 primeiros trechos das consultas que não acertaram em @3. É classificação por rótulo, não por conteúdo.

| Origem | Híbrida (654 posições) | Lexical | Vetorial |
|---|---|---|---|
| Outra seção da bula | 250 (38,2%) | 47,5% | 35,8% |
| **Seção de interações** (listas longas que repetem o nome do fármaco) | 136 (20,8%) | 22,3% | 4,4% |
| Seção vizinha que também trata do assunto (critério estrito) | 99 (15,1%) | 18,1% | 9,6% |
| **Rótulo que não é seção** (nome de produto, nome de arquivo) | 89 (13,6%) | 6,9% | 21,9% |
| Seção administrativa (composição, identificação) | 74 (11,3%) | 5,1% | 25,0% |
| Sem seção | 6 (0,9%) | 0,1% | 3,2% |

- Consultas sem acerto em @3: híbrida 218, vetorial 228, lexical 288. Em 38 erros da híbrida o trecho certo estava em 4º ou 5º.
- **Lexical** é puxada para as seções de interações; **vetorial**, para identificação, composição e rótulos de segmentação defeituosos.
- **Segmentação e rotulagem** (rótulo que não é seção + sem seção) explicam 14,5% das posições erradas na híbrida e 25% na vetorial.
- **Critério:** 15% das posições erradas da híbrida são seções que tratam do assunto ("Características farmacológicas" para "para que serve"). O Acerto@3 é, em parte, **subestimado** pelo critério.
- Por assunto (híbrida, Acerto@3): advertências 83%, armazenamento 45%, posologia 44%, indicação 35%, contraindicação 33%, superdose 23%, efeitos 16%. A expressão de "advertências" é a mais ampla (inclui "O que devo saber antes de usar"); o critério não é igualmente exigente entre assuntos.
- Não foram variados os parâmetros de fusão (RRF, k = 60) nem os *embeddings*: a tarefa vedava mudar pesos de busca na rodada de referência.

## 6. Bulas humanizadas: a linguagem é acessível?

`GET /audit/leaflet-summaries`: 11 bulas geradas (10 com o *prompt* atual `humanized-leaflet-v2`). Índice de Flesch adaptado (Martins et al., 1996), o mesmo do artigo.

| Medida | Resultado (v2) |
|---|---|
| Faixa das bulas | **9 "difícil", 1 "fácil"**; nenhuma "muito fácil" |
| Mediana do índice | 41,9 (bula humanizada) × 32,2 (trechos de origem) |
| Mais fácil que a fonte | 9/10 (a do id 4 ficou mais difícil: 39,3 × 45,5) |
| Palavras por frase (mediana) | 14,6 |
| Comparação | As respostas do Perguntar têm mediana 58,8 ("fácil"): a bula humanizada é **mais difícil** que as respostas do próprio sistema |

Por seção (mediana): "Como usar" 70,6 (fácil); "Riscos e cuidados" 52,8 (fácil); "Não use se" 49,5; "Benefícios" 43,8; "Efeitos colaterais" 37,8; "Procure ajuda se" 33,5; **"Para que serve" 23,9** e **"Interações" 23,6** (muito difícil).

- As frases não são longas; o que pesa é o **vocabulário**. 54 de 197 frases (27%, todas as versões) têm algum termo técnico de uma lista de 32 termos; os mais frequentes: "agranulocitose", "administração", "contraindicado", "anafilaxia", "broncoespasmo", "hipersensibilidade".
- **O modelo explica muitos termos entre parênteses** ("analgésico (para dor)", "broncoespasmo (aperto nos brônquios)"), mas o termo técnico continua na frase, e o índice o penaliza: "A dipirona é indicada como analgésico (para dor) e antitérmico (para febre)" sai "difícil" (25,2), embora seja clara.
- Ainda há jargão **sem explicação**: "atelectasias pulmonares", "mucoviscidose", "microrganismos sensíveis à associação de trimetoprima e sulfametoxazol", "beta-2-agonista", "glaucoma de ângulo fechado".
- A seção de interações é, em boa parte, lista de nomes de fármacos, que baixam o índice inevitavelmente (limitação da medida).

**Conclusão:** pelo índice, as bulas humanizadas **não estão em linguagem fácil** (9 de 10 "difícil"), embora sejam mais fáceis que a fonte em 9 de 10 e expliquem parte dos termos. O *prompt* atual escreve "na voz da bula" (PR #58), o que favorece a fidelidade e mantém o vocabulário técnico. Legibilidade não mede compreensão; só um teste com usuários mediria.

## 7. Novos achados e pendências

| # | Achado | Severidade | Sugestão |
|---|---|---|---|
| V1 | Validador recusa a gravidade do DDInter dita pelo modelo | Média | Aceitar afirmação sobre gravidade quando ela confere com o registro estruturado (evidência T1) |
| V2 | "Não suspenda por conta própria" recusado como recomendação de conduta | Média | Não bloquear verbos negados ("não pare", "não suspenda") |
| V3/V4 | Avisos coerentes recusados; decisão instável entre rodadas | Média (metodológica) | Revisão profissional de amostra das recusas; não usar a taxa de aceitação como correção factual |
| N1 | Bulas humanizadas "difíceis" pelo índice | Média | *Prompt* com glossário obrigatório e termos técnicos só entre parênteses; medir de novo |
| N2 | Pergunta conceitual sobre segurança fica sem resposta (`no_medicine`) | Baixa | Resposta fixa para perguntas sem medicamento sobre ausência de registro × segurança |
| N3 | Concordância: "Dipirona não consta…; a interação que depende **dele**" | Baixa | Ajustar o texto |
| N4 | Com três ou mais pares, o topo repete "Atenção: …" uma vez por par | Baixa | Agrupar numa frase |
| — | D5 (OCR), D6 (Marevan), D9 (bula dos EUA em inglês) | — | Continuam abertos |

## 8. O que muda no artigo

- **Números de referência:** inalterados (seção 1), com a versão `aba1f43` como confirmação.
- **Geração e validação:** acrescentar que a taxa de aceitação inclui falsos bloqueios demonstrados (V1–V4) e que a gravidade é informada em texto fixo. As inconsistências documentais da auditoria (seção 8 de `auditoria-cientifica-2026-11-16.md`) seguem valendo.
- **Recuperação:** a origem dos erros (seção 5) e o critério de relevância por título de seção, que subestima o acerto.
- **Bulas humanizadas:** o artigo não mede a legibilidade delas. Se forem citadas, citar com este resultado (9 de 10 "difícil").
- Nenhuma alteração feita no artigo nesta rodada.

## Reprodução

```bash
# Na VPS (rótulo pos-auditoria)
sudo bulai-deploy artisan evaluation:run interactions --dataset=database/evaluation/interactions.v2.json --mode=lexical --label=pos-auditoria-interacoes-1483
sudo bulai-deploy artisan evaluation:run interactions --dataset=database/evaluation/interactions.protocol30.v2.json --mode=hybrid --label=pos-auditoria-protocolo30
sudo bulai-deploy artisan evaluation:run identification --dataset=database/evaluation/identification.heldout.json --label=pos-auditoria-identificacao-reservado
sudo bulai-deploy artisan evaluation:run retrieval --dataset=database/evaluation/retrieval.subjects.v1.json --mode=lexical,vector,hybrid --label=pos-auditoria-recuperacao
# Local, pela API
python3 scripts/evaluation/independente.py <TOKEN> saida.json && python3 scripts/evaluation/analisar_independente.py saida.json analise.json
curl -s -H "Authorization: Bearer <TOKEN>" https://bulai.vixforge.com.br/api/v1/audit/evaluations/78c5668c > rag.json
python3 scripts/evaluation/analisar_rag.py rag.json database/evaluation/retrieval.subjects.v1.json rag.analise.json
curl -s -H "Authorization: Bearer <TOKEN>" "https://bulai.vixforge.com.br/api/v1/audit/leaflet-summaries?limit=200" > bulas.json
python3 scripts/evaluation/analisar_bulas_humanizadas.py bulas.json bulas.analise.json
```
