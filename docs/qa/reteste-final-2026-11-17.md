# Reteste final após as correções de 16/11/2026

| | |
|---|---|
| Versão medida | `cc9cc9a` em produção (deploy concluído 00:42 UTC de 17/11; CI verde): validador frase a frase e conselho negado (V1, V2), legibilidade medida com reescrita nas bulas humanizadas e *prompt* `humanized-leaflet-v3` (PR #76) |
| Comparação | `0a59cc1` (antes das correções da auditoria) e `aba1f43` (correções D1–D7), mesmas 32 perguntas (`freeform.independent.v1`, SHA-256 `da527c18…`) |
| Execução | Rodada independente pela API em 16/11, 22:39–22:50 BRT; bulas humanizadas por `GET /audit/leaflet-summaries`; legibilidade das respostas por `GET /audit/readability?limit=200` |
| Evidências | `reteste-final-2026-11-17.independente.json`, `.recusas.json`, `.bulas-humanizadas-v3.json` |

Nada aqui é validação clínica.

## 1. Experimentos de referência

As mudanças de `aba1f43` para `cc9cc9a` estão no validador e na bula humanizada; o veredito e a gravidade dos pares vêm do DDInter (`PairAnalyzer`), a identificação e a recuperação não foram tocadas. Os registros de 16/11 (`28343bc9`, `b0bc2d25`, `568c8792`, `78c5668c`, versão `aba1f43`) seguem como a referência: 1.483 pares com FN = 0 e FP = 0, protocolo de 30 com 30/30, identificação reservada 44/47 e recuperação com Acerto@3 de 39,8% (híbrida), 37,0% (vetorial) e 20,4% (lexical). **Não foram reexecutados em `cc9cc9a`.** O verificador de pares gera explicação pelo validador, mas a métrica binária e a gravidade não dependem dela.

## 2. Rodada independente (32 perguntas livres) nas três versões

| Indicador | `0a59cc1` | `aba1f43` | `cc9cc9a` |
|---|---|---|---|
| Medicamentos reais reconhecidos | 60/60 | 60/60 | 60/60 |
| Nome inventado declarado | 1/1 | 1/1 | 1/1 |
| Pares com a gravidade do DDInter | 29/29 | 29/29 | 29/29 |
| Respostas com texto que informam a gravidade | 10/18 | 18/18 | 18/18 |
| Quem informa a gravidade | modelo, quando o validador aceita | frase fixa no topo | **o próprio modelo** (frase aceita pelo validador frase a frase) |
| Dipirona + varfarina (fora do DDInter) | "não há interação registrada" | não verificada, declarada | não verificada, declarada |
| "O sistema não encontrou interação…" | virou "TESTE SISTEMA" | nenhum medicamento | nenhum medicamento (**sem resposta conceitual**) |
| Respondidas / esclarecimento / sem evidência / sem bula / sem medicamento | 22 / 1 / 0 / 7 / 1 (+1 não identificado) | 20 / 1 / 2 / 7 / 2 | **23 / 0 / 0 / 7 / 2** |
| Afirmação de que a combinação é segura | 0 | 0 | 0 |
| Tempo no servidor (mediana) | 8,6 s | 5,6 s | 6,2 s |

**Validador em `cc9cc9a`:** 55 afirmações aceitas e 52 recusas gravadas com texto. As recusas: 39 frases retiradas de afirmações aceitas em parte (`sentence_not_grounded`), 12 afirmações inteiras sem apoio e 1 negação ausente do trecho. Como a unidade mudou (frases retiradas passaram a contar), **a taxa de aceitação não é comparável com as versões anteriores**. Avisos antes recusados aparecem agora nas respostas: síndrome serotoninérgica (sertralina + tramadol), "não pare nem troque nada por conta própria" (omeprazol + clopidogrel), aumento do risco de sangramento (varfarina + amiodarona).

## 3. Bulas humanizadas

| Medida | `humanized-leaflet-v2` | `humanized-leaflet-v3` |
|---|---|---|
| Faixa | 9 difícil, 1 fácil | **10 fácil** |
| Índice: mediana (mín.–máx.) | 41,9 (32,5–50,1) | **56,5 (50,7–63,0)** |
| Ganho por bula (mesmo remédio) | — | +6,7 a +24,1 (mediana +13,1); todas melhoraram |
| Palavras por frase (mediana) | 14,6 | 11,6 |
| Frases com termo técnico da lista | 27% (todas as versões) | 11% (21/193) |
| Frases aceitas por bula (mediana) | 18 | 19 |
| Reescritas necessárias | — | nenhuma (alvo atingido na 1ª geração) |

Ainda abaixo de 50 por seção: "Para que serve" (34,7), "Interações" (38,2) e "Procure ajuda se" (49,4). Em "Interações", parte da dificuldade é a lista de nomes de fármacos, que o índice penaliza. As respostas do Perguntar seguem mais fáceis que a fonte em 98,5% (197/200; mediana 59,0 × 26,3).

## 4. Situação dos defeitos

| # | Defeito | Situação |
|---|---|---|
| D1 | Fármaco fora do DDInter como "não há interação registrada" | Corrigido e verificado |
| D2/D3 | Gravidade fora do texto | Corrigido e verificado (18/18), agora pelo próprio modelo |
| D4 | "TESTE SISTEMA" | Corrigido; a pergunta conceitual continua sem resposta (N2) |
| D5 | OCR nunca concluiu em produção | Aberto (operacional) |
| D6 | Bula profissional do Marevan falhou | Aberto (operacional) |
| D7 | Texto das recusas | Corrigido e verificado |
| D8 | Detalhe por consulta no avaliador | Corrigido e usado |
| D9 | Trecho da bula dos EUA em inglês | Não reavaliado |
| V1 | Gravidade correta recusada pela frase emendada | **Corrigido e verificado** |
| V2 | "Não suspenda por conta própria" recusado | **Corrigido e verificado** |
| V3/V4 | Recusa por falta de correspondência lexical; decisão instável | Em parte: avisos antes recusados agora passam; o critério continua lexical |
| N1 | Bulas humanizadas "difíceis" | **Corrigido e verificado** (10/10 "fácil") |
| N2 | Pergunta conceitual sem resposta | Aberto |
| N3/N4 | Concordância "dele"; repetição do "Atenção" | Não reavaliados (o "Atenção" deixou de ser necessário na maior parte das respostas) |

## 5. Atualização do artigo

As mudanças aplicadas no MD e no DOCX estão na PR que acompanha este relatório: os 9 pontos documentais da auditoria de 16/11, a legibilidade das bulas humanizadas antes e depois, a rodada independente e o Apêndice G.
