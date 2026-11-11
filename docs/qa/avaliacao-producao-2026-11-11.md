# Avaliação do Perguntar em produção pela API (ampla), 11/11/2026

| | |
|---|---|
| Execução | 11/11/2026, das 01:50:58 às 02:53:09 (horário de Brasília, UTC−3) |
| Ambiente | Produção, `https://bulai.vixforge.com.br`, `main` em `ab1d851` (PR #58). As correções desta rodada estão na PR #59 e ainda não estavam em produção. |
| Como | `POST /api/v1/evaluation/ask` com o token "IA CLAUDE" (`api:evaluate`) e a conta de avaliação (id 8), uma pergunta por vez, com pelo menos 10,5 s entre elas |
| Modelos | Resposta `claude-sonnet-5-5`; interpretação da pergunta `claude-haiku-5-5`; embeddings `bge-m3` (1.024 dimensões); busca híbrida (RRF k = 60) |
| Perguntas | **234**: 196 sobre bula brasileira (49 remédios × 4 assuntos, em rodízio entre 8 assuntos), 14 de marca, 6 de remédios sem bula brasileira (bula dos EUA/FDA), 3 inexistentes, 2 de urgência, 1 fora do escopo e 6 conversas de 2 turnos |
| Dados | Respostas compactas: `docs/qa/avaliacao-producao-2026-11-11.json`. Indicadores: `docs/qa/avaliacao-producao-2026-11-11.analise.json`. Scripts: `scripts/evaluation/bateria.py` (perguntas), `analisar.py` (indicadores) e `relatorio.py` (tabelas). |
| Pontuação | Automática: status, remédio identificado, tipo e seção das fontes, validador e rubrica do `ConversationEvaluator`, mais a leitura das falhas. Não substitui a validação profissional. |

Esta rodada substitui, para o TCC, os números medidos na máquina de desenvolvimento. Todos os números abaixo vêm de produção.

## Base de produção

| Medida | Início (01:47) | Fim (02:57) |
|---|---|---|
| Bulas prontas (PDF da ANVISA) | 82 (78 vinculadas a princípio ativo) | 82 (**82 vinculadas**) |
| Tipo | 37 profissional, 33 paciente, 12 não classificadas | 38 profissional, 33 paciente, 11 não classificadas |
| Trechos indexados / com embedding | 5.652 / 4.582 | 5.652 / **5.652** |
| Princípios ativos com bula | 56 | 57 |
| Catálogo ANVISA: registros / com princípio ativo identificado | 39.416 / 25.568 | 39.416 / 25.596 |
| Registros do catálogo com bula de algum dos seus princípios ativos / de todos | 5.622 / 4.324 | 5.704 / 4.406 |
| Interações DDInter (14 grupos ATC) / ligadas a princípios ativos | 234.981 / 234.333 | igual |
| Interações que envolvem um princípio ativo com bula / entre dois com bula | 22.929 / 604 | 23.087 / 613 |
| Bulas dos EUA (openFDA) prontas / não encontradas | 26 / 12 | igual |

A base foi corrigida no Admin enquanto a bateria rodava: 4 bulas ganharam vínculo e os embeddings pendentes foram concluídos. As primeiras perguntas usaram a base do início; nenhuma das perguntas depende das 4 bulas corrigidas (Xalatan, Norvasc, Diamicron e a bula 82). Fontes: `GET /api/v1/stats/knowledge` às 01:47 e às 02:57.

## Resultados

### Status por assunto (bula brasileira)

| Assunto | answered | needs_info | no_evidence | no_leaflet | no_medicine | not_identified | Total |
| --- | --- | --- | --- | --- | --- | --- | --- |
| indicacao | 21 | 0 | 0 | 0 | 3 | 1 | 25 |
| efeitos | 21 | 0 | 0 | 0 | 3 | 1 | 25 |
| armazenamento | 21 | 0 | 0 | 0 | 3 | 1 | 25 |
| interacao | 18 | 4 | 2 | 0 | 1 | 0 | 25 |
| posologia | 22 | 1 | 0 | 1 | 0 | 0 | 24 |
| contraindicacao | 21 | 2 | 0 | 1 | 0 | 0 | 24 |
| gravidez | 23 | 0 | 0 | 1 | 0 | 0 | 24 |
| superdose | 23 | 0 | 0 | 1 | 0 | 0 | 24 |
| **Total** | **170** | **7** | **2** | **4** | **10** | **3** | **196** |

### Status por bloco

| Bloco | answered | needs_info | no_evidence | no_leaflet | no_medicine | not_identified | Total |
| --- | --- | --- | --- | --- | --- | --- | --- |
| bula brasileira | 170 | 7 | 2 | 4 | 10 | 3 | 196 |
| marca | 12 | 1 | 1 | 0 | 0 | 0 | 14 |
| sem bula BR | 3 | 0 | 1 | 2 | 0 | 0 | 6 |
| inexistente | 0 | 0 | 0 | 0 | 3 | 0 | 3 |
| urgencia | 2 | 0 | 0 | 0 | 0 | 0 | 2 |
| fora do escopo | 0 | 0 | 0 | 0 | 1 | 0 | 1 |
| conversa | 12 | 0 | 0 | 0 | 0 | 0 | 12 |
| **Total** | **199** | **8** | **4** | **6** | **14** | **3** | **234** |

### Indicadores

| Indicador | Resultado |
| --- | --- |
| Remédio certo identificado (bula brasileira, marcas, conversa) | 206/222 = 92,8% (IC 95%: 88,6–95,5) |
| Respostas em que **todas** as fontes são bulas do remédio perguntado | 150/180 = 83,3% (IC 95%: 77,2–88,1) |
| Respostas com ao menos uma fonte da seção do assunto | 176/177 = 99,4% (IC 95%: 96,9–99,9) |
| Respondidas com ao menos uma afirmação aceita | 199/199 = 100,0% (IC 95%: 98,1–100,0) |
| Afirmações aceitas pelo validador | 456/820 = 55,6% (IC 95%: 52,2–59,0) |
| Trechos crus da bula exibidos ao paciente | 0 |
| Leitura da bula inteira (quando os trechos não bastaram) | 82 perguntas |
| Cobertura das respostas sobre bula brasileira | full: 143, partial: 27, none: 26 |
| Fontes citadas, por tipo | combinacao que contem: 78, do remedio: 467, desconhecida: 2, de outro remedio: 8 |
| Motivos de rejeição de afirmações | not_grounded_in_cited_evidence: 181, over_word_budget: 66, forbidden_recommendation: 51, limit_not_needed: 21, negation_not_in_evidence: 19, narrates_source: 13, negation_inverted: 6, combination_off_topic: 2, asks_disabled: 2, too_dense: 2, ask_without_question_mark: 1 |
| Tempo de resposta no servidor (s) | mediana 12.9, p95 29.1, máx. 53.7, média 14.1 |
| Tokens (entrada/saída) | geração 963273/105182; interpretação 292205/18496; leitura da bula 2113019/53818 |
| Modelos de geração | claude-sonnet-5-5: 211 |

### Rubrica automática das respostas (respondidas)

| Dimensão | Resultado |
| --- | --- |
| relevancia | 199/199 = 100,0% (IC 95%: 98,1–100,0) |
| ancoragem | 199/199 = 100,0% (IC 95%: 98,1–100,0) |
| seguranca | 199/199 = 100,0% (IC 95%: 98,1–100,0) |
| clareza | 196/199 = 98,5% (IC 95%: 95,7–99,5) |
| naturalidade | 182/199 = 91,5% (IC 95%: 86,8–94,6) |


**Legibilidade em produção** (`GET /api/v1/audit/readability?limit=1000`, 02:57; todas as respostas registradas em produção, inclusive estas): **269 pares** resposta × trechos que a sustentam. Índice de Flesch adaptado (Martins et al., 1996): mediana **58,3** nas respostas contra **29,4** nos trechos citados, com diferença mediana de 27,8 pontos. A resposta foi mais legível que os seus trechos em **98,1%** dos pares (IC 95%: 95,7%–99,2%). Faixas das respostas: fácil 184, muito fácil 21, difícil 60, muito difícil 4. Faixas dos trechos: difícil 126, muito difícil 102, fácil 41.

## Leitura

- **Bula brasileira (196 perguntas):** 170 respondidas (86,7%). Das 26 restantes:
  - 13 caíram por **identificação**: AAS (3), dabigatrana (4) e pseudoefedrina (3) como `no_medicine`, e anlodipino (3) como `not_identified`.
  - 7 viraram pedido de esclarecimento (`needs_info`), 4 interações ficaram sem bula (`no_leaflet`) e 2 sem evidência (`no_evidence`).
- **A fonte certa:** em 99,4% das respostas com fonte, pelo menos uma vem da seção do assunto perguntado. Em 83,3%, todas as fontes são bulas só do remédio perguntado. Nas demais, a fonte é uma combinação que contém o princípio ativo (78 das 555 fontes citadas, por exemplo Torsilax para "paracetamol") ou, em 8 fontes, a bula de outro remédio: hidroclorotiazida nas perguntas sobre losartana.
- **Validador:** 55,6% das 820 afirmações propostas pelo modelo foram aceitas. O motivo mais comum de rejeição é a afirmação que o trecho citado não sustenta (181). Todas as 199 respostas tiveram ao menos uma afirmação aceita, e nenhum trecho cru da bula foi mostrado ao paciente.
- **Quando os trechos não bastam:** em 82 perguntas o sistema leu a bula inteira (`leaflet_fallback`).
- **Bula dos EUA (FDA):**
  - Funcionou para efeitos e interações de remédios sem bula brasileira: prednisona, enoxaparina e Dramin. A resposta avisa "Essa informação vem da bula dos Estados Unidos".
  - Para outros assuntos (para que serve a prednisona, como tomar varfarina) responde `no_leaflet`, como previsto.
- **Inexistentes e fora do escopo:** os 3 nomes inventados e "Qual a capital da França?" não receberam resposta (`no_medicine`).
- **Urgência:** as 2 perguntas receberam a faixa de urgência do Disque-Intoxicação (0800 722 6001) e do SAMU (192) acima da resposta.
- **Conversa:** as 6 segundas perguntas ("E qual a dose máxima por dia?", "Criança pode tomar?"…) ficaram no remédio da primeira.
- **Tempo:** mediana de 12,9 s no servidor, p95 de 29,1 s e máximo de 53,7 s. As abstenções saem em cerca de 1 s, sem chamada ao modelo de resposta.

## Achados e encaminhamento

| # | Achado | Causa | Situação |
|---|---|---|---|
| 1 | "Para que serve o AAS?" → "não encontrei o remédio" | A interpretação e o detector descartavam termos com menos de 4 letras | **Corrigido na PR #59**: sigla curta vale quando a pergunta a escreve igual (teste `test_a_short_brand_like_aas_is_kept_when_written_exactly_and_dropped_when_only_similar`) |
| 2 | "dabigatrana" → "não encontrei o remédio" | O catálogo registra "etexilato de dabigatrana", e o detector só casava nome igual ou que começa pelo termo | **Corrigido na PR #59**: nome-base de 6+ letras casa com "… de <nome>" (teste `test_a_base_name_finds_the_medicine_the_catalog_names_after_its_salt`) |
| 3 | "anlodipino" → `not_identified` | O registro escolhido ("ANLODIPINO BESILATO") não tem princípio ativo ligado no catálogo | Dado. A PR #59 faz o detector preferir "besilato de anlodipino". Depois do deploy, rodar `knowledge:build-ingredients` |
| 4 | "pseudoefedrina" → `no_medicine` | No catálogo só existe em combinações, com mais de 3 grupos, e a regra trata isso como ambíguo | Comportamento previsto. Melhoria futura: perguntar "qual destes?" em vez de dizer que não achou |
| 5 | Perguntas sobre losartana citam a bula da hidroclorotiazida | A busca escolhe a combinação losartana + hidroclorotiazida e lê as bulas dos dois princípios ativos | Aberto (já apontado em 09/11). A resposta avisa que fala da combinação, mas deveria preferir a bula de losartana sozinha |
| 6 | "vitamina D colecalciferol" → BONECAL D, respondido com a bula do FOSAMAX D | Não há bula de colecalciferol sozinho, e a resposta usa uma combinação que o contém | A resposta avisa que a bula é de outro remédio. Falta bula de vitamina D isolada |
| 7 | Levofloxacina (sem bula BR, com bula FDA pronta) → `no_evidence` com uma frase solta ("Se você sentir algum desses efeitos…") | Nenhuma afirmação sobre os efeitos foi aceita, mas a frase de cuidado ficou | Aberto: com zero fatos aceitos, a frase de cuidado não deve aparecer sozinha |
| 8 | Dados da base | A dipirona está em 3 princípios ativos (Dipirona, Dipirona Monoidratada, Dipirona Sódica) e a losartana em 2; o título da bula 2 é "excipientes q.s.p…"; a bula 82 apareceu ligada a levodopa + benserazida no início e a esomeprazol no fim | Conferir no Admin |

## Como reproduzir

```bash
python3 scripts/evaluation/bateria.py <TOKEN api:evaluate> saida.json        # 234 perguntas; retoma de onde parou; respeita o limite de 300/dia
curl -s -H "Authorization: Bearer <TOKEN>" -A 'BULAI-Avaliacao/1.0' https://bulai.vixforge.com.br/api/v1/stats/knowledge > inventario.json
python3 scripts/evaluation/analisar.py saida.json analise.json inventario.json
python3 scripts/evaluation/relatorio.py analise.json
```

Limites: as perguntas foram escritas pelos autores, não por usuários. A rubrica e a classificação das fontes são automáticas (os princípios ativos de cada bula vêm do vínculo gravado na base). A correção do conteúdo não foi verificada por profissional de saúde.
