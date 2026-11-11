# Perguntar: intenção, recuperação orientada e resposta proporcional

Correção P0 (09/11/2026) do assistente conversacional do PWA (`/consulta/perguntar`). Este documento registra o diagnóstico
feito **antes** das alterações, o que mudou e como verificar.

## 1. Diagnóstico (antes das alterações)

### Fluxo real da pergunta

1. `App\Livewire\Consulta\Perguntar::perguntar()` aplica limite por hora, arquiva a troca anterior e chama
   `MedicationQuestionService::askFreeform()` com as últimas 3 trocas.
2. `InputGuard::screen()` faz a triagem; `UrgencyDetector::detect()` procura sinais de urgência (determinístico).
3. `MedicationMentionDetector::detect()` reconhece os remédios, com tolerância a erro de digitação (`Diporona` vira `DIPIRONA`,
   marcado `fuzzy`). Sem remédio na pergunta, herda os da troca anterior (`matched_text = 'conversa'`).
4. `MedicineLeafletFinder::find()` localiza as bulas (bula do paciente primeiro): a **bula da própria marca** (produto da bula sem ®, "Pac", "Pro", "Paciente": DORFLEX acha "Dorflex Pac", não "DORFLEX MAX®" nem "DORFLEX® UNO") somada às bulas ligadas aos princípios ativos **que não trazem ativo de fora do remédio** (o Torsilax, que só divide a cafeína com o Dorflex, não entra). Remédio de um ativo só ainda aceita, na falta de outra, a bula de uma associação que o contém. Sem vínculo por princípio ativo, vale o nome (marca ou ativo declarado na bula). Desde 10/11/2026.
5. `QuestionIntentDetector::plan()` define assuntos e seções por palavras-chave (`config/bulai.php`,
   `leaflet.sections.*.keywords` e `questions.intents`).
6. Para cada seção do plano: evidências já extraídas (`LeafletEvidenceService::stored()`) e busca por trechos
   (`PostgresKnowledgeRetriever`, modo `lexical` por padrão), restrita aos padrões da seção. O resultado é intercalado, com no máximo 8 trechos.
7. `ExplanationGenerator::answer()` envia pergunta, assunto, conversa e trechos ao provedor de texto (Anthropic, prompt
   `interaction-explanation-v3:qa`, esforço baixo). `ExplanationValidator::validateSegments()` confere cada segmento
   (`fact` citado e ancorado, `limit`, `ask`), aplica o orçamento de palavras e define a cobertura.
8. Cobertura incompleta leva à leitura da bula inteira (`LeafletEvidenceService::extract()`), que usa o texto **indexado**
   (os chunks) e não o PDF. O trecho literal é conferido e gravado em `knowledge_leaflet_facts` como `pending`. Depois vem uma segunda geração.
9. `finish()` grava tudo em `medication_questions` (evidências, retrieval, generation, pipeline, duração e fila de revisão).
   `Perguntar::fala()` monta o balão; as fontes ficam recolhidas em "Fontes utilizadas".

### Reprodução

Não há bula de dipirona no banco de desenvolvimento, e os registros de produção não são acessíveis daqui. A parte
determinística do fluxo (classificação e plano de seções) foi reproduzida chamando `QuestionIntentDetector::plan()`:

| Pergunta | Assuntos detectados | Seções consultadas |
| --- | --- | --- |
| Posso tomar dipirona para dor de cabeça? | segurança de uso, **efeitos colaterais** | para que serve, **não use se, riscos, efeitos colaterais** |
| Diporona, serve para dor de cabeça? | para que serve, **efeitos colaterais** | para que serve, **efeitos colaterais** |
| Dipirona baixa febre? | nenhum (geral) | para que serve, riscos |
| Quantas gotas posso tomar? | **segurança de uso** | para que serve, não use se, riscos |
| Tenho alergia à dipirona, posso tomar? | segurança de uso, não use se | para que serve, não use se, riscos |
| Tomei uma quantidade excessiva de dipirona | nenhum (geral), **sem urgência** | para que serve, riscos |

A detecção do remédio funcionou (`Diporona` vira `DIPIRONA`, com `fuzzy: true`).

### Causas comprovadas

- **C1. Sintoma a tratar lido como efeito adverso.** "dor de cabeça" está nas palavras-chave de `efeitos_colaterais`. Nas
  duas perguntas relatadas, a dor que a pessoa quer aliviar puxou a seção "Quais os males que este medicamento pode me causar?".
- **C2. "Posso tomar" sempre vira avaliação de segurança completa.** `seguranca_de_uso` expande para para que serve,
  quando não usar e advertências, mesmo quando a pergunta já diz a finalidade. Com quatro seções intercaladas, a indicação
  fica com 1/4 dos trechos. O assunto "Segurança de uso" e a regra do prompt ("explique para que serve, quando NÃO deve ser
  usado, cuidados") fazem o modelo abrir com riscos.
- **C3. Duas intenções produzem resposta longa.** Com mais de um assunto, o orçamento de fatos sobe para 150 palavras e as perguntas
  de esclarecimento ficam liberadas (`no_ask_intents` só vale com um assunto). Esse é o comportamento da pergunta 2.
- **C4. Limitação interna exposta.** O prompt manda declarar em `limit` "o que não foi possível confirmar", sem proibir
  vocabulário interno. O validador aceita limites como "os trechos que consultei não trazem…". Em perguntas informativas com
  cobertura completa, esses limites só alongam a resposta.
- **C5. Vocabulário de intenção incompleto.** Faltam "febre" e "baixa febre" (a pergunta cai em "geral" e consulta riscos),
  "gotas" e "quantas" (a pergunta de dose vira segurança de uso) e "quantidade excessiva" (nem urgência nem "procure ajuda").
  "Alergia + posso tomar" deixa a contraindicação em segundo plano.
- **C6. Conversa antiga após troca de remédio.** Quando a pessoa cita outro remédio, os remédios anteriores não são herdados,
  mas o resumo da conversa anterior continua indo ao modelo.
- **C7. A "bula inteira" é o texto indexado.** O fallback lê os chunks e não o PDF guardado. Se o índice estiver
  truncado (limite de 150 mil caracteres), incompleto (páginas sem chunk) ou com extração de baixa qualidade, nada recorre ao
  original. Também não há cache negativo: cada pergunta sem resposta relê a bula inteira (custo e latência).
- **C8. Suficiência só depois de gerar.** Não há critério determinístico antes da geração: mesmo sem nenhum trecho da
  seção da intenção principal, o sistema gasta uma geração com trechos fora do assunto antes de ir à bula inteira.

### Hipóteses não confirmadas ou descartadas

- **Ranking lexical.** Pode trazer trecho de outra seção quando o rótulo da seção é ruim: `retrieve()` cai na busca sem restrição.
  É um efeito secundário de C2/C8, não a causa principal.
- **Configuração de geração** (esforço baixo, teto de 4.096 tokens). Não explica o conteúdo; não houve truncamento.
- **Memória inventando dados clínicos.** Não encontrado: a conversa vai rotulada como "não é fonte de fatos" e as perguntas
  repetidas são barradas (`ask_already_answered_or_asked`).
- **Busca híbrida.** Existe (`hybrid`), mas exige embeddings indexados. Não foi ligada nesta correção.

## 2. O que mudou

### Intenção (`QuestionIntentDetector`)

- **Intenções canônicas** com uma principal: `INDICATION`, `PERSONAL_USE`, `DOSAGE`, `CONTRAINDICATION`, `ADVERSE_EFFECT`,
  `DRUG_INTERACTION`, `DRUG_INFORMATION`, `FOLLOW_UP` e `EMERGENCY`. Os assuntos antigos (`para_que_serve`, `seguranca_de_uso`…)
  continuam gravados e servem às seções. A ordem é por prioridade (`PRIORITY`); a primeira intenção é a principal.
- **Papel do sintoma** (`symptom()`). Os sintomas saíram das palavras-chave de efeitos colaterais (`bulai.questions.symptoms`).
  "para / alivia / baixa / estou com + sintoma" é indicação; "dá / causa / me deu + sintoma" ou "depois de tomar" é efeito adverso.
- **"Posso tomar" com assunto concreto** (finalidade, dose, alergia, interação dita): o assunto manda e a segurança de uso
  vira só a seção "quando não usar". Sozinho, "posso tomar?" continua consultando as três seções.
- **Dose** com "gotas", "quantas", "intervalo"…; com dose, "posso tomar" não vira segurança de uso. A interação presumida
  (dois remédios citados) não passa à frente de um assunto dito.
- **Superdose**: "quantidade excessiva", "em excesso", "mais do que devia" passam a ser urgência (`UrgencyDetector`) e seção
  "procure ajuda".
- **Extras**: apresentação citada (`presentation()`) e "outro remédio" sem nome (`mentionsUnnamedMedicine()`).

### Recuperação (`MedicationQuestionService`, `config/bulai.php`)

- A seção da intenção principal vem primeiro na intercalação e traz um trecho a mais.
- **Suficiência determinística.** Sem nenhum trecho da seção principal, a bula inteira é lida **antes** da primeira geração.
  As exceções são urgência e "outro remédio" sem nome, que vão direto à orientação ou à pergunta. O veredito fica em
  `retrieval.sufficiency`: `primary_section_found`, `verdict` (`sufficient`, `partial` ou `insufficient`) e `fallback`
  (`none`, `leaflet_text` ou `original_pdf`).
- Bulas cujo título cita a apresentação perguntada ("gotas") vêm primeiro.
- O título padronizado da superdose ("…quantidade maior do que a **indica**da…") casava com o padrão `indica` das seções de
  indicação e benefícios. Agora está excluído (`!quantidade maior`).

### PDF original (`OriginalLeafletReader`, `LeafletEvidenceService`)

1. Primeiro lê o texto indexado inteiro, como antes.
2. Sem resposta, relê o PDF do armazenamento privado **somente** se o índice não representa a bula: sem chunks
   (`no_index`), cortado pelo limite (`index_truncated`), extração abaixo de `document_ocr.quality.min_score`
   (`low_extraction_quality`) ou páginas sem nenhum chunk (`pages_missing_from_index`). Fora desses casos, reler daria o mesmo texto.
3. A extração é a do processamento (texto nativo e, se ele não for confiável, OCR), com o texto em cache por `sha256` por 24 h e
   uma trava por documento. O PDF nunca sai do servidor.
4. Cada trecho é conferido literalmente na página. Fica ligado ao chunk que o contém, quando existe; senão fica sem chunk,
   com id citável negativo (`-(1.000.000 + id)`). É gravado como `original_pdf_ai` ou `original_ocr_ai`, sempre `pending`.
   Sem chunk, não é reaproveitado automaticamente: aguarda a curadoria.
5. Com OCR de baixa qualidade, o trecho vai para a curadoria (confiança ≤ 0,3, sem chunk), mas **não** sustenta a resposta.
6. **Cache negativo de 12 h.** A mesma pergunta, na mesma versão da bula, sem resposta, não é procurada de novo (`recent_miss`).

### Resposta (`ExplanationGenerator`, `ExplanationValidator`)

- O prompt recebe a intenção principal e o guia de resposta de cada intenção (`bulai.questions.response_guides`).
  O tamanho segue a intenção principal (`fact_words_by_intent`: indicação 60 palavras, interação 130…).
- Intenções informativas (`no_ask_intents` / `no_limit_intents`) descartam perguntas e, com cobertura completa, também ressalvas.
- **Voz da bula humanizada.** A resposta fala do remédio, com acolhimento, e não narra a leitura. O validador retira aberturas
  como "Segundo a bula, …" e "A bula diz que …" e reprova (`narrates_source`) segmentos com "li na bula", "trechos",
  "evidências", "consultei" e similares; a reprovação gera uma reformulação orientada. Os textos fixos da tela
  (`Perguntar::fala`, `x-consulta.resposta`) deixaram de dizer "procurei", "encontrei" e "também olhei".
- Evidências conflitantes devem ser ditas em um `limit`, sem escolher uma delas.

### Perfil e tratamento (`TreatmentContextService::alertsText`)

- O perfil é sempre verificado, mas só vira evidência (T1) quando há achado: interação registrada com um remédio em uso,
  mesmo princípio ativo ou alergia registrada. Sem achado, o modelo não recebe nada do perfil e não fala dele.
- A posologia do próprio tratamento entra só em perguntas de dose.
- Os remédios citados **na pergunta** continuam recebendo o resultado da base de interações ("não registra; isso não comprova
  que seja seguro"), porque é a combinação perguntada.
- A verificação fica registrada em `retrieval.treatment_check`.

### Conversa

- Quando a pessoa cita outro remédio, o resumo da conversa anterior e o assunto anterior não entram mais no contexto
  (`classification.medicine_switched`).

### Rastreabilidade (`medication_questions`, sem migration)

- `retrieval.classification`: intenção principal e lista, assuntos, sintoma e papel, apresentação, outro remédio sem nome,
  uso da conversa e troca de remédio.
- `retrieval.sufficiency`, `retrieval.treatment_check` e `retrieval.leaflet_fallback.per_medicine[].documents[]`:
  `source` = `index` ou `original_pdf`, motivo, `status`, método, qualidade, `cached`, `skipped` e `not_used`.
- Já existiam: modelo/provedor (`generation`, `pipeline`), duração, evidências com documento, versão, seção e página, e fila de revisão.

### Correções da avaliação em produção de 09/11/2026 (`docs/qa/avaliacao-api-2026-11-09.md`)

- **Superdose com número (crítico):** "Tomei 10 comprimidos de dipirona de uma vez" saía sem orientação de socorro. Causa: o detector só reconhecia quantidades por extenso logo após o verbo.
  - O `UrgencyDetector` ganhou o tipo `overdose_quantidade`: 3 ou mais unidades em algarismo ou por extenso, "de uma vez", "a caixa/cartela toda".
  - Ganhou também o tipo `ingestao_acidental` (criança que engoliu, "tomou sem querer").
  - Defesa em profundidade: `fromEvidence()`. Se a resposta usou a seção de superdose da bula e a pergunta não foi reconhecida, o resultado leva a orientação do Disque-Intoxicação/SAMU mesmo assim (tipo `secao_superdose`).
  - "Tomei 2 comprimidos" e "20 gotas" não disparam (dose comum).
- **Pronome solto ("Ela é…"):** acontecia quando a primeira afirmação era recusada e a seguinte, com pronome, abria a resposta. O validador agora troca "Ela/Ele" inicial pelo nome do remédio, no gênero do pronome (`ExplanationValidator::nameSubject`, opção `subject`). Nada vai de novo ao modelo. A heurística de naturalidade passou a contar "ela/ele".
- **Interação com dois remédios citados:** o guia `DRUG_INTERACTION` exige que a primeira frase trate do par perguntado. Ele proíbe listar interações com remédios não citados e manda dizer em um `limit` quando nada fala do par.
- **Combinação achada para um único princípio ativo:** "losartana" achava losartana + hidroclorotiazida. Agora `QuestionIntentDetector::partialCombination` detecta o caso, e o prompt manda dizer na primeira frase que a orientação é da combinação (`classification.combination`).
- **"Como se usa":** entrou nas palavras-chave de `como_usar`, junto com "como se toma", "modo de uso" e "jeito de usar".
- **Seções da bula do paciente:** os títulos em forma de pergunta ("6. COMO DEVO USAR ESTE MEDICAMENTO?") eram descartados por terminar em "?", e o trecho herdava a seção anterior ("INTERAÇÕES MEDICAMENTOSAS").
  - O `KnowledgeSectionDetector` aceita título em maiúsculas terminado em "?" e junta títulos quebrados em duas linhas.
  - **Vale só para bulas processadas depois desta versão.** Para corrigir as já carregadas: `php artisan knowledge:process-document {id} --force --sync`, depois `php artisan knowledge:index-embeddings`.
- **Validador (análise crítica do TCC):** além da sobreposição de 40%, são recusados:
  - números de dose ausentes do trecho citado (`dose_number_not_in_evidence`);
  - polaridade invertida, como "deve ser usado" contra "não deve ser usado" (`negation_inverted`);
  - negação que não existe em nenhum trecho citado (`negation_not_in_evidence`).

  Continua sendo **validação heurística de ancoragem textual**: barra as inversões mais comuns e não prova correção farmacológica. Os resultados do TCC foram medidos antes dessas checagens.
- **Tela:** a pergunta enviada aparece na hora como balão da pessoa, com o "digitando…" abaixo. Quando a resposta chega, a tela rola até o começo da fala do BULA-IA (`resources/views/livewire/consulta/perguntar.blade.php`).

### A IA interpreta a pergunta antes da busca (`QuestionInterpreter`, 10/11/2026)

Motivo: em perguntas de conversa as regras fixas erravam (capturas de 10/11/2026).
- "Ela serve para dores de cabeça?", depois de uma pergunta sobre efeitos adversos, herdou o assunto "efeitos" e ficou sem resposta.
- "E na versão gotas para nebulização?", depois do Berotec, virou o remédio VERSA, por busca aproximada de "versao".
- "Tá, mas serve para dor de cabeça?" ficou sem resposta, porque a bula diz "analgésico", não "dor de cabeça".

Agora o fluxo é:
1. **Triagem e urgência por regras**, sobre o texto original (como antes; nada disso depende da IA).
2. **Interpretação pela IA:** o modelo recebe a pergunta e as últimas trocas (pergunta, remédios e resposta resumida) e devolve um JSON com:
   - `medicines`: o remédio da pergunta ou o herdado da conversa;
   - `question`: a pergunta reescrita completa;
   - `intent` e `presentation`;
   - `urgency`.

   Ele não responde e não traz fatos.
3. **Conferência:**
   - Cada remédio apontado precisa aparecer, com grafia próxima, na pergunta ou na conversa. Senão é descartado e registrado em `dropped`.
   - A detecção no catálogo procura só esses nomes.
   - Se a IA apontou um remédio que o catálogo não tem, o sistema não responde com o remédio anterior.
   - A urgência apontada pela IA só soma: nunca apaga a das regras.
4. **Busca e resposta:**
   - O planejamento, a busca, a apresentação e o prompt usam a pergunta reescrita.
   - A intenção da IA vem primeiro, e o assunto da troca anterior deixa de ser herdado.
   - O prompt também mostra o que a pessoa escreveu.

Ajustes que acompanham a etapa:
- **Guia INDICATION:** se a bula indica o remédio para dor ou febre em geral, uma dor comum está incluída. A resposta diz que sim, sem afirmar que a bula cita a dor pelo nome.
- **Validador, palavras da pergunta:** palavras repetidas da própria pergunta ("dipirona", "dor de cabeça") não contam contra a ancoragem. O restante da afirmação continua tendo de estar no trecho. Se só sobrarem palavras da pergunta, elas mesmas são verificadas.
- **Validador, ressalva de combinação:** em pergunta sobre um remédio só e que não é de interação, a ressalva genérica de "combinação" é recusada (`combination_off_topic`). O alerta do perfil, quando existe, sai à parte e nomeia o remédio (quadro de risco).

Configuração (`config/bulai.php`, `interpretation`):
- `BULAI_INTERPRETATION_ENABLED` (padrão `true`; desligado em `phpunit.xml`, cada teste liga quando precisa).
- `BULAI_INTERPRETATION_MODEL`: vazio usa o mesmo modelo de texto; um modelo menor, como `claude-haiku-5-5`, reduz a latência.
- `BULAI_INTERPRETATION_MAX_OUTPUT_TOKENS` (600) e `BULAI_INTERPRETATION_EFFORT` (`low`).

Se o modelo falhar ou responder fora do formato, a interpretação devolve `null` e o fluxo segue com as regras de antes.

Rastreio: `medication_questions.retrieval.interpretation` guarda a pergunta reescrita, os remédios, os descartados, a intenção, a apresentação, a urgência, o modelo, os tokens e a duração. A API de avaliação devolve esse rastreio em `retrieval`.

Custo: uma chamada a mais por pergunta, de saída curta. Cabe medir a latência na próxima bateria de avaliação.

Testes: `tests/Feature/Consulta/InterpretacaoDaPerguntaTest.php`, que cobre os casos das capturas, remédio inventado pela IA, remédio fora do catálogo, falha do provedor, urgência, ressalva de combinação e ancoragem com palavras da pergunta.

### Ajustes da 2ª avaliação em produção (`docs/qa/avaliacao-api-2026-11-10.md`)

- **Marca × princípio ativo (N1):** "A Novalgina serve para dor de cabeça?" ficava sem resposta.
  - Os trechos vêm da bula de dipirona, e a afirmação "a dipirona da Novalgina…" era recusada por citar o princípio ativo.
  - Agora os princípios ativos do remédio perguntado (`subject_terms`) contam como palavras da pergunta na ancoragem.
  - Palavras que não afirmam nada ("princípio", "ativo", "geral", "inclui", "medicamento", "porque", "também") saem da conta (`ExplanationValidator::GENERIC_TERMS`).
- **Exame (N2):** "atrapalha o exame?" é `DRUG_INFORMATION`, não interação. `DRUG_INTERACTION` vale só para outro remédio, álcool ou alimento.
- **Rastreio (N3):** a interpretação fica gravada também quando nenhum remédio é encontrado (`no_medicine`).
- Testes: `InterpretacaoDaPerguntaTest::test_a_brand_question_is_grounded_on_the_active_ingredient_leaflet` e `…_interpretation_is_recorded_even_when_no_medicine_is_found`.

## 3. Antes e depois (classificação e seções)

| Pergunta | Antes | Depois |
| --- | --- | --- |
| Posso tomar dipirona para dor de cabeça? | segurança + efeitos → para que serve, não use se, riscos, efeitos | PERSONAL_USE + INDICATION → para que serve, não use se |
| Dipirona serve para dor de cabeça? | para que serve + efeitos (150 palavras, perguntas liberadas) | INDICATION → para que serve (60 palavras, sem perguntas nem ressalvas) |
| Dipirona baixa febre? | geral → para que serve, riscos | INDICATION → para que serve |
| Quantas gotas posso tomar? | segurança → para que serve, não use se, riscos | DOSAGE (+ FOLLOW_UP) → como usar, apresentação "gotas" |
| Tenho alergia à dipirona, posso tomar? | segurança + não use se | CONTRAINDICATION + PERSONAL_USE → não use se |
| Tomei uma quantidade excessiva de dipirona | geral, sem urgência | EMERGENCY → orientação de urgência + procure ajuda |
| Dipirona dá sono? | efeitos | ADVERSE_EFFECT → efeitos (inalterado) |

O texto final depende do modelo real. Para medir com ele, rode `php artisan evaluation:conversation` (docs/tcc) num ambiente com
as bulas carregadas; o banco de desenvolvimento não tem a bula da dipirona.

## 4. Testes

- `tests/Feature/Consulta/InteligenciaConversacionalTest.php` (29 testes):
  - as 10 perguntas da especificação, mais "dá sono?": intenção principal, intenções e seções;
  - papel do sintoma; indicação direta, sem ressalva nem pergunta, na voz humanizada; grafia corrigida; uso pessoal proporcional;
  - efeitos, febre, alergia e urgência buscando a própria seção; dose em acompanhamento com apresentação;
  - "outro remédio" sem nome perguntado antes de ler a bula; interação sem tratar ausência como segurança;
  - perfil só com achado; voz da bula; troca de remédio sem a conversa antiga; apresentação; evidências conflitantes;
  - falha do provedor;
  - PDF disponível com índice incompleto (lido, conferido, curadoria, cache, sem duplicar);
  - PDF indisponível (limite declarado e sem nova busca); OCR de baixa confiança (curadoria, sem sustentar a resposta);
  - ausência de informação (honesta, com fila de revisão).
- Testes existentes ajustados à nova voz e à nova ordem (bula inteira antes de gerar quando falta a seção principal):
  `PerguntaLivreTest`, `PerguntarConfiavelTest`, `PerfilEPerguntasTest`, `RemediosDeHojeTest`.
- Suíte completa: `php artisan test --compact`.

## 5. Limitações e próximos passos

- A classificação continua determinística (palavras-chave). Perguntas fora do vocabulário caem em `DRUG_INFORMATION`.
  Um classificador por modelo poderia entrar como segunda opinião, mas com custo e latência.
- A busca segue `lexical` por padrão. O modo `hybrid` existe, mas exige embeddings indexados.
- Um trecho achado só no PDF original não é reaproveitado até a curadoria verificar e a bula ser reprocessada (o índice passa a contê-lo).
- O painel de tratamento da tela (interações e itens não verificáveis) é determinístico e continua aparecendo como antes.
  Só a resposta do assistente deixou de falar do perfil sem achado.
- Não houve avaliação com o modelo real nesta correção (custo e falta da bula de teste no ambiente de desenvolvimento).
