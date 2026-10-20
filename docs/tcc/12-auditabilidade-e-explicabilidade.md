# Auditabilidade e explicabilidade da análise automática

Mapeamento no docx: Cap. 2, seção 2.7.2 (responsabilidade no uso de IA); Cap. 3, seções 3.3.1 ("Rastreabilidade das informações") e 3.5.1 (rastreabilidade da fonte citada).

## Objetivo do tópico

Descrever como cada decisão automática de OCR e de IA local fica registrada e visível para o curador, permitindo conferência e reconstrução posterior.

## 1. Fundamentação

Em sistemas de apoio em saúde, rastrear de onde veio cada informação e por que uma decisão automática foi tomada é requisito de confiança e de responsabilização. [referência a incluir: literatura sobre IA explicável e auditoria de sistemas de decisão; LGPD, Lei 13.709/2018, para minimização de dados.] A explicabilidade aqui é procedimental (etapas, limiares, evidências), não uma explicação interna do modelo.

## 2. Trilha de auditoria

Cada análise profunda (`KnowledgePendingItemReanalysisService::reanalyze`, usada por `ClassifyPendingDocument` e pelo botão "Reanalisar") acrescenta um evento `automatic_analysis` em `ingestion_batch_items.metadata.audit_history`, contendo:

| Grupo | Campos |
| --- | --- |
| Extração | método (`pdf_text`/`ocr`), qualidade antes e depois do OCR, engine, páginas, duração, código de erro do OCR |
| Classificação | decisão, score da heurística, limiares vigentes, modelo, confiança, duração, código de erro, motivo |
| Resposta da IA | valores propostos e citações de evidência |
| Resultado | campos resultantes |

Não são armazenados o texto do PDF nem o prompt. Resultados anteriores permanecem em `preliminary_identification_history`.

## 3. Apresentação ao curador

`AutomaticAnalysisReport` converte os dados em etapas rotuladas (leitura do texto, heurística, IA local, decisão) e lista execuções anteriores. A seção "Análise automática" da tela de revisão de pendências avisa quando OCR/IA nunca rodaram e mostra progresso de fila com polling de 3 s enquanto o job está `queued` ou `processing`.

## 4. Relação com o objetivo do projeto

A mesma disciplina (registrar origem, versão e decisão) é a base para a rastreabilidade de fontes exigida das respostas do RAG futuro. Hoje ela cobre apenas a ingestão; não há ainda citação de fonte em respostas ao usuário.

## 5. Supervisão humana sobre a saída do LLM (human-in-the-loop)

### 5.1 Fundamentação

Supervisão humana é uma salvaguarda recorrente para sistemas de IA em saúde: o modelo propõe e a pessoa decide, com registro da decisão. [referência a incluir: literatura sobre human-in-the-loop e supervisão humana de IA em saúde; marco regulatório aplicável a ser definido.]

### 5.2 Quadro comparativo heurística x IA

`AutomaticAnalysisReport::comparison` monta, por campo (nome, princípio ativo, tipo de documento e registro ANVISA), os valores da heurística (estado antes da IA) e da IA, a relação (Igual, Diverge, Só a IA, Só a heurística, comparada após normalização de acentos e pontuação) e o indicador de grounding. Citações de evidência ficam em bloco recolhível. Além de explicar a decisão, o quadro funciona como instrumento de avaliação qualitativa: torna visíveis os casos em que heurística e IA divergem e qual das duas tinha respaldo no texto.

### 5.3 Ações e salvaguardas

| Ação | Efeito | Auditoria |
| --- | --- | --- |
| Aplicar campos marcados / aceitar tudo | grava os valores da IA nos campos escolhidos e pré-preenche "Novo documento" | `ai_suggestion_accepted` (ator, valores anteriores e aplicados) |
| Rejeitar a análise da IA | registra a rejeição, sem alterar campos | `ai_suggestion_rejected` |
| Desfazer | restaura os valores anteriores e volta ao estado sem decisão | `ai_decision_reverted` |

Salvaguardas: decisão por campo; só valores gravados pela análise podem ser aplicados (sem digitação livre); operação em transação com bloqueio da linha; recusa quando o item deixou de estar pendente; `metadata.ai_review` guarda a decisão vigente e é limpa por nova análise, mantendo o histórico na trilha de auditoria.

### 5.4 Achado empírico (n=1, observação de teste)

No item real de omeprazol, a IA acertou o princípio ativo ("omeprazol sódico") e a heurística errou ("10mL de solução diluente", score 55); a regra automática descartou a resposta da IA. A IA também sugeriu `bula_profissional` sem respaldo no texto enviado (`grounded = false`). O quadro permite ao revisor aceitar o primeiro campo e recusar o segundo. Não há medição além desse caso.

## 6. Consulta sob demanda

O botão "Consultar IA agora" executa o classificador mesmo fora da faixa de pontuação (ver `08-classificacao-hibrida-heuristica-llm.md`, seção 6); o dado `classification.forced` indica no registro que a execução foi forçada.

## Evidências no repositório

- Decisão humana: `app/Services/Knowledge/KnowledgeAiSuggestionReviewService.php`; método `comparison` em `AutomaticAnalysisReport.php`; `tests/Feature/Knowledge/KnowledgeAiSuggestionReviewTest.php` (quadro, aceitação, campos fora da sugestão, rejeição, desfazer, item não pendente, consulta forçada); `docs/technical-foundation.md` (seção "Human decision on the AI suggestion (comparison board)").

- `app/Services/Knowledge/AutomaticAnalysisReport.php`, `KnowledgePendingItemReanalysisService.php`, `KnowledgeIngestionAuditService.php`
- `app/Livewire/Admin/Knowledge/Pending/Review.php`; `resources/views/livewire/admin/knowledge/pending/review.blade.php`; `resources/views/livewire/shared/analysis-run.blade.php`
- `docs/technical-foundation.md` (seção "Automatic Analysis Audit and Visibility")
- Testes: `tests/Feature/Knowledge/KnowledgeBackgroundClassificationTest.php`, `tests/Feature/KnowledgeEvidenceAndReanalysisTest.php`

## Resultados observados

Testes automatizados verificam entradas de auditoria, renderização da página de revisão e aviso de análise ainda não executada. Não houve avaliação de utilidade da tela com curadores.

## Lacunas / a verificar

- Sem estudo de usabilidade da tela de análise.
- O botão "Reanalisar" é síncrono e pode levar dezenas de segundos.
- Definir retenção e acesso ao histórico de auditoria.
- Valores aceitos pelo administrador não são revalidados no catálogo ANVISA; uma nova análise descarta a decisão vigente (preservada só na trilha).
- Sem avaliação com curadores do quadro comparativo (tempo de decisão, erros, concordância); sem métricas formais.
- Definir se o aceite humano deve disparar revalidação regulatória automática.
