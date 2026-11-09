# Resultados experimentais (execuções reais de 04/11/2026)

Mapeamento no docx: Cap. 3, 3.5.1 (avaliação técnica) e 3.5.3; base para o capítulo de Resultados. Procedimento e comandos: `docs/evaluation.md`. Todos os números abaixo vêm de execuções registradas em `storage/app/evaluation/runs/*.json` (nome do registro na última coluna das tabelas); nada foi estimado ou arredondado à mão.

## 0. Ambiente e rastreabilidade

- Código: commit base `9ea4265` **com alterações não commitadas** (flag `dirty: true` em todos os registros). Para reprodução exata, as alterações precisam ser commitadas e os experimentos repetidos; os registros guardam SHA-256 dos datasets, hash do conjunto de documentos (17 bulas READY, `94b460af47…`) e dos CSVs do DDInter.
- Dados: catálogo ANVISA (39.416 registros), DDInter (160.235 pares), 1.806 princípios ativos, 17 bulas (782 chunks).
- Geração: llamafile local `qwen2.5-1.5b-instruct-q4_k_m` via API OpenAI-compatível (**modelo muito pequeno; não representa Claude nem GPT-4o**). Embeddings: **nenhum provedor** na primeira execução (vetorial/híbrido não executados); na iteração 2 (seção 3) `bge-m3` local. Claude: **não validado** na primeira execução; validado com 4 chamadas reais na iteração 2 (seção 3a).
- Recuperação: `lexical`. Semente de amostragem: 42.

## 1. Identificação de medicamentos (produto → princípios ativos)

Gabarito escrito pelo autor, independente do resolvedor; **não validado por profissional**. Métricas ao nível de ingrediente (TP/FP/FN) e por consulta ("exato" = conjunto idêntico).

| Conjunto | Itens | Exato | Parcial | Não resolvido | Precisão | Recall | F1 | Busca top-1 / top-5 | Registro |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| v1 (**desenvolvimento**, usado para ajustar o normalizador) | 59 | 55 (93,2%) | 2 | 2 | 1,000 | 0,937 | 0,967 | 88,1% / 98,3% | `…002428-identification-final-identificationv1` |
| held-out (**não** usado para ajustes) | 47 | 42 (89,4%) | 1 | 4 | 0,977 | 0,915 | 0,945 | 72,3% / 87,2% | `…002429-identification-final-identificationheldout` |

Evolução do v1 durante o desenvolvimento (cada passo corrigiu erros **desse** conjunto, portanto o ganho não estima desempenho futuro): 86,4% → 91,5% → 93,2%. O held-out foi medido uma vez antes do último ajuste (89,4%) e novamente depois (89,4%; o ajuste não o afetou).

**Erros do held-out** (não corrigidos, de propósito): *Zolpidem* (produto "HEMITARTARATO DE ZOLPIDEM" com ingrediente em formato não tratado), *Domperidona* (**ausente do DDInter**: limitação da fonte), *Alopurinol* (regra ortográfica "l→ll" não implementada), *Hidroxicloroquina* ("oxi→oxy" não implementada), *Gentamicina* ("parcial": o produto escolhido pelo construtor é uma associação betametasona+gentamicina; é artefato do dataset, não do sistema). **Erros do v1**: *Lexotan* e *Novalgina* (catálogo sem princípio ativo / dipirona ausente do DDInter), *Dorflex* e *Benegrip* (a dipirona da associação não existe no DDInter).

## 2. Interações por par de produtos

Unidade: par de produtos (registro ANVISA). Rótulo: existência/gravidade no DDInter para os ingredientes **esperados** (gabarito manual), ver `docs/evaluation.md`. Abstenção = inconclusivo/não identificado/erro. **Atenção (circularidade):** o sistema consulta a mesma base que gerou o rótulo; precisão/recall 1,0 nos pares respondidos é consequência do desenho e **não** mede correção clínica — mede se a ponte produto→ingrediente→par funciona. As métricas informativas são a abstenção e o recall conservador.

| Conjunto | Pares | Positivos / negativos (rótulo) | TP | FP | TN | FN | Abstenções | Recall conservador | Concordância de gravidade (TP) | Graves perdidas | Registro |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Completo (v1) | 1.483 | 938 / 545 | 938 | 0 | 539 | 0 | 6 (0,4%) | 1,000 | 100% (407 pares com gravidade classificada) | 0 | `…002505-interactions-v1-final` |
| Protocolo 3.5.1 (30 pares) | 30 | 20 / 10 | 20 | 0 | 10 | 0 | 0 | 1,000 | 100% | 0 | `…002506-interactions-protocol30-final` |

Matriz de confusão de gravidade (completo; linhas = rótulo, colunas = sistema; pares `unknown` ficam fora): maior 66/0/0, moderada 0/282/0, menor 0/0/59 (sem erros). Distribuição de vereditos (completo): 938 documentadas, 539 não encontradas, 6 insuficientes.

Uma versão anterior do código (antes do ajuste "ti→ty") produziu 54 abstenções por "medicamento não identificado" (abstenção 4,05%, recall conservador 0,977, **uma** interação grave perdida: amitriptilina × ondansetrona, registro `…002352-interactions-lexical-final`). O número mostra o quanto a cobertura da ponte condiciona o resultado.

**Ameaças específicas** (ver também `15-...`): (a) os 545 "negativos" são pares **ausentes do DDInter**; vários são interações conhecidas na farmacologia (ex.: fenobarbital+propranolol, captopril+espironolactona — a verificar por profissional). O sistema responde "não encontrada nas fontes consultadas", o que está correto em relação à fonte, mas **não** é evidência de ausência de interação. (b) Os 6 pares "insuficiente" são pares sem registro no DDInter cujas bulas citam o outro fármaco; merecem revisão. (c) O conjunto de 30 pares é amostra determinística dos 1.483 pares (55 produtos elegíveis, após excluir os 4 cujo ingrediente esperado não existe no DDInter), não os 30 medicamentos prioritários do projeto.

## 3. Recuperação

4 consultas direcionais, 3 trechos relevantes, julgamentos do autor sobre o pool lexical (`database/evaluation/retrieval.v1.json`).

| Modo | P@1 | R@1 | P@3 | R@3 | P@5 | R@5 | MRR | Estado | Registro |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| lexical | 0,250 | 0,500 | 0,083 | 0,500 | 0,150 | 1,000 | 0,3125 | executado | `…001818-retrieval-modes`; repetido em `…025153-retrieval-modes-bge-m3` |
| vetorial (bge-m3, 1024 dims) | 0,000 | 0,000 | 0,000 | 0,000 | 0,100 | 0,500 | 0,0625 | executado (iteração 2) | `…025153-retrieval-modes-bge-m3-233ca6a6` |
| híbrido (RRF k=60) | 0,250 | 0,500 | 0,250 | 1,000 | 0,150 | 1,000 | 0,375 | executado (iteração 2) | idem |

Iteração 2 (04–05/11/2026): embeddings reais com `bge-m3` Q8_0 (BAAI, multilíngue, 1024 dimensões, 634.553.760 bytes, SHA-256 `950f4a8e…821167`) servidos localmente pelo llamafiler em CPU; 782/782 chunks indexados (~1,7 s por chunk com 2 threads). O híbrido domina o lexical (R@3 0,5 → 1,0; MRR 0,3125 → 0,375); o vetorial sozinho é fraco neste conjunto. Leitura cuidadosa: amostra mínima (4 consultas, 3 trechos relevantes); recall relativo ao pool **lexical** (viés contra o vetorial: trechos que só o vetorial recupera não estão no gabarito); dois itens sem trecho relevante no corpus entram na precisão. A comparação é indicativa e exige mais consultas e julgamentos independentes antes de qualquer afirmação forte. Complementar: o protocolo de 30 pares em modo híbrido, sem geração (`…025531-interactions-protocol30-hybrid-nogen-dd767913`), manteve concordância de gravidade 1,0 e nenhum falso positivo.

### 3a. Provedor real (Anthropic, iteração 2)

Quatro chamadas reais a `claude-sonnet-5-5` (todas HTTP 200, custo total US$ 0,024 em `ai_usage_logs`): teste de conectividade (1,5 s); consulta azitromicina + Vaslip → interação moderada no DDInter, nenhum trecho citando o outro fármaco, modelo não chamado (abstenção correta, 1,1 s); consulta ibuprofeno + Neolefrin Dia → 3 evidências (lexical + vetorial), modelo declarou insuficiente → só trechos (2.320/242 tokens, 2,9 s, US$ 0,0071); pergunta sobre omeprazol em jejum → `excerpts_only` (2.581/156 tokens, US$ 0,0067); pergunta sobre reações adversas do omeprazol → **respondida**, 4 afirmações aceitas e 0 rejeitadas pelo validador (2.729/485 tokens, 3,6 s, US$ 0,0103), texto em linguagem simples rastreável à bula do profissional. Isso valida a integração (`20`) e mostra o comportamento conservador do pipeline: o modelo prefere declarar insuficiência a inventar.

## 4. Geração (modelo local de 1,5 bilhão de parâmetros)

12 pares com interação documentada e evidência (amostra com semente 42), `…002136-generation-qwen15b`.

| Métrica | Valor |
| --- | --- |
| Explicações aceitas (validadas) | 3 de 12 (25%) |
| Explicações descartadas | 9 de 12 (saída com afirmações em formato inválido) |
| Afirmações aceitas / rejeitadas | 9 / 9 (taxa de rejeição 50%; motivo único: `malformed_claim`) |
| Tokens de entrada / saída | 14.612 / 1.990 |
| Tempo total de geração | 171 s (≈ 14 s por par) |

Interpretação: o validador cumpriu o papel (nenhuma saída malformada chegou ao usuário e os pares seguiram com gravidade estruturada + trechos). **Não foi medida correção factual**; as 3 explicações aceitas passaram nos filtros automáticos (referência válida, ancoragem lexical, sem conduta), que são heurísticos. O resultado **não** pode ser extrapolado para Claude ou outros modelos maiores.

## 5. Baseline sem RAG (mesmo modelo, sem base estruturada e sem trechos)

60 pares (amostra semente 42; 39 positivos, 21 negativos), `…002240-baseline-norag-qwen15b`.

| TP | FP | TN | FN | Precisão | Recall | F1 | Abstenção | Severidade dos TP (rótulo→resposta) |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 33 | 18 | 3 | 6 | 0,647 | 0,846 | 0,733 | 0 | maior→maior 4/4; moderada→maior 9, menor 2; menor→maior 2 (nenhuma moderada ou menor acertada) |

Acurácia do baseline = 36/60 = 60%; um classificador trivial "sempre sim" teria 65% (39/60). O modelo pequeno responde "sim" quase sempre (18 de 21 negativos errados) e superestima a gravidade. A comparação com o pipeline é **qualitativa**: o pipeline não deixa a existência da interação a cargo do modelo (vem da base estruturada), portanto os dois não competem na mesma tarefa; comparar o pipeline "estruturado + RAG" com "modelo sem contexto" mostra o risco que a arquitetura evita, não uma superioridade estatística demonstrada.

## 6. Resumo honesto

Demonstrado: a ponte PT→EN liga 21.923 de 39.416 medicamentos e 100% dos pares do DDInter; identificação exata de 89% (held-out) a 93% (desenvolvimento) em amostras de ~50 consultas; abstenção de 0,4% e nenhuma interação grave perdida no conjunto de 1.483 pares; validador descarta saídas ruins do modelo local; recuperação lexical encontra os trechos julgados relevantes até a 5ª posição.

**Não demonstrado**: correção clínica; qualidade das explicações com modelos capazes; benefício de embeddings/híbrido; usabilidade (SUS); concordância com profissional; funcionamento com a API real da Anthropic.

## 7. Reexecução final para o texto do TCC (07/11/2026)

Código `272b765` **limpo**, 18 bulas (799 trechos, documentos `8c2eff89…`). Texto consolidado: [tcc-bula-ia-texto-final.md](tcc-bula-ia-texto-final.md) (Apêndice B lista os registros).

- Identificação, interações (1.483 e protocolo 30, este em modo híbrido) e recuperação: **idênticos** aos de 04–05/11 (`20261107-1729*`, `20261107-173000-*`).
- Geração com `claude-sonnet-5-5` (12 pares, híbrido, semente 42): 8/12 explicações aceitas; 19 afirmações aceitas, 4 rejeitadas (`not_grounded_in_cited_evidence`); 23.768/5.658 tokens (`20261107-173630-tcc-generation-claude-1a0500e8`).
- Baseline sem RAG com Claude: `evaluation:run baseline` falhou em 43/60 chamadas (`AI_INVALID_RESPONSE`): o `BaselineEvaluator` limita a saída a 60 tokens e o modelo gasta esse limite em raciocínio (resposta só com bloco `thinking`). Repetido por script com o mesmo prompt/amostra e 1.024 tokens, resultado por par em `20261107-baseline-norag-claude-1024-items.json`: VP 19, FP 11, VN 10, FN 18, 2 abstenções; P 0,633, R 0,514, F1 0,567, acurácia 48,3%. Os 18 FN são todos pares `unknown` no DDInter; vários FP são interações conhecidas fora dos grupos ATC importados (diazepam+fenobarbital, atenolol+propranolol, sertralina+clonazepam). Gravidade: 13/17 pares classificados. **Pendente:** subir `maxOutputTokens` do `BaselineEvaluator` e gravar resultados por par.
- Legibilidade (Flesch adaptado ao português, Martins et al., 1996; sílabas por heurística de núcleos vocálicos): 66 respostas `interaction-explanation-v3:qa` × trechos citados: mediana 60,2 × 25,6; resposta mais legível em 66/66; diferença mediana 28,7. Respostas de rodadas de avaliação (4 princípios ativos, perguntas repetidas), não de usuários.
- Recorte: só 6 dos 55 produtos do conjunto de interações têm bula; 309/1.483 pares têm bula de ao menos um fármaco, 15 de ambos.

## 8. DDInter com 14 grupos ATC (08/11/2026)

A base tinha só 8 dos 14 arquivos ATC do DDInter (A, B, D, H, L, P, R, V; 160.235 pares). Faltavam **C** (cardiovascular), **G** (geniturinário e hormônios sexuais), **J** (anti-infecciosos), **M** (musculoesquelético), **N** (sistema nervoso) e **S** (órgãos dos sentidos), justamente classes de uso frequente em idosos.

- Fonte: pacote `DDInter_14_grupos_verificado` (manifesto com sha256 por arquivo; 234.981 pares únicos; 0 conflitos de gravidade; **não** é a exportação integral do DDInter 2.0, que tem 302.516 DDI). Os 8 arquivos antigos têm o mesmo sha256 dos já importados.
- Importação: `php artisan knowledge:import-drug-interactions <pasta>/originais` (idempotente por sha256; só os 6 novos inseriram): +74.746 pares (C 27.586, G 7.848, J 11.082, M 4.760, N 23.309, S 161) → **234.981**. Depois `php artisan knowledge:build-ingredients` (o comando de importação, ao contrário do job do painel, não enfileira o catálogo): 1.832 princípios ativos; 234.981/234.981 interações ligadas; medicamentos ANVISA ligados 24.648/39.416; tradução por IA: 1.200 nomes propostos, 299 aceitos (`pending_review`).
- Gabarito `interactions.v2.json` (`evaluation:build-datasets --name=v2`; o v1 foi mantido): mesmos 1.483 pares; positivos 938 → **1.424**, negativos 545 → **59**. **486 dos 545 "negativos" do v1 (89%) eram falta de cobertura**, não ausência de interação. Gravidade: major 66 → 110, moderate 282 → 482, minor 59 → 74, unknown 531 → 758.
- Interações (teste de integração, gabarito de ingredientes): VP 1.424, VN 59, 0 abstenções (as 6 do v1 eram pares sem registro que agora têm), concordância de gravidade 666/666 (`20261108-181838-ddinter14-v2-a577c9d1`).
- Baseline sem RAG (Claude): as mesmas 60 respostas de 07/11 repontuadas contra o v2, sem nova chamada à API: VP 30, **FP 0**, VN 3, FN 25, 2 abstenções; P **1,000**, R 0,545, F1 0,706, acurácia 55,0%; gravidade 19/26. Os 11 FP do v1 eram interações reais fora dos grupos importados. 24 dos 25 FN são pares `unknown` (`20261108-baseline-norag-claude-rescored-v2.json`).
- Geração com Claude (v2, 12 pares, híbrido, semente 42): 8/12 aceitas; 21 afirmações aceitas, 3 rejeitadas; 26.034/6.644 tokens; 57,9 s no total (≈4,8 s por par) (`20261108-182107-ddinter14-generation-claude-22d34939`).
- Não refeitos: protocolo de 30 pares (derivado do v1), identificação e recuperação (não dependem do DDInter).
- Correção do `BaselineEvaluator` (08/11): `maxOutputTokens` 60 → 1.024 e resultado por par (`pairs`) no registro; datasets padrão do `evaluation:run` passaram para v2. Teste: `EvaluationPipelineTest::test_baseline_leaves_room_for_reasoning_and_records_each_pair`.
- Baseline oficial com o avaliador corrigido (v2, 60 pares, semente 42): 0 erros de provedor; VP 30, FP 0, VN 3, FN 26, 1 abstenção; P 1,000 (IC 88,6–100), R 0,536 (40,7–66,0), F1 0,698, acurácia 55,0% (42,5–66,9); gravidade 21/26. FN: 24 `unknown`, 2 moderadas (atorvastatina+metronidazol, clonazepam+carvedilol) (`20261108-192731-ddinter14-baseline-norag-claude-035df9dd`).
- Protocolo de 30 pares refeito sobre o v2 (`interactions.protocol30.v2.json`, híbrido): 30/30, gravidade 20/20 (`20261108-192514-ddinter14-protocol30-hybrid-b1b39c1f`).
- Os 59 negativos do v2 concentram-se em Xarelto (33) e Gardenal (17).
- Qwen2.5-1.5B não foi recalculado: o registro de 04/11 não tem respostas por par.
- Texto do TCC atualizado (Resumo/Abstract, 3.3, 3.5, 3.5.2, 3.5.6, 3.7, 5.2, 5.4, 5.6, Quadro 6, Considerações finais, Apêndice B).
