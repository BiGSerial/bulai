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
| 1 | Pergunta livre sobre par grave não traz a interação (anlodipino) | P0 | JSON de sondagem acima; teste `PerguntaInteracaoGraveTest` | Nome não resolvido some; par sem vínculo pulado; gravidade dependia do modelo | Correção desta PR | Corrigido no código; **sem deploy, não verificado em produção** |
| 2 | Detector não resolve "anlodipino" (e talvez outros nomes com muitas associações) | P0 | 2 sondagens (`medicamentos_resolvidos`) | Não confirmada (ver acima) | Reproduzir com o catálogo de produção; revisar `focus()` | **Aberto** |
| 3 | Artigo diz que a causa dos 3 casos "não foi investigada" | P2 | `tcc-bula-ia-texto-final.md`, Discussão | Texto anterior ao diagnóstico | Proposta de redação (seção 5); **não alterei DOCX/MD** | Aberto, depende dos autores |
| 4 | Protocolo de 30 pares citado depois da correção, mas medido antes | P1 | Run `7cdf5716` (11/11 08:22, híbrido, 30/30) é anterior à PR #60; **não há protocolo de 30 pós-correção em produção** | Registro único, anterior | Reexecutar `evaluation:run interactions --dataset=…protocol30.v2.json --mode=hybrid` | **Aberto** (número confirmado, procedência pré-correção) |
| 5 | Recuperação (369 consultas) medida com 82 bulas; base atual tem 91 | P1 | Run `87d8519f` (11/11 08:22); inventário 17:03 de 12/11: 91 bulas, 6.699 trechos | Base mudou depois da medição; o artigo já ressalva | Reexecutar `evaluation:run retrieval` na base atual | **Aberto** |
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
| Protocolo de 30 pares: 30/30 | `7cdf5716` (11/11 08:22, pré-correção) | TP 20, TN 10, 0 erros | **Confirmado, procedência pré-correção** |
| Recuperação: Hit@3 híbrido 40,1%, vetorial 36,0%, lexical 20,6% | `87d8519f` (82 bulas) | lexical 0,206; vetorial 0,360 | **Confirmado para a base antiga; não reproduzido na atual** |
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
