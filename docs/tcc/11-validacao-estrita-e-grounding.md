# Validação estrita da saída da IA e ancoragem (grounding)

Mapeamento no docx: Cap. 2, seção 2.7.2 (Limitações, riscos e responsabilidade no uso de IA) e Cap. 3, seção 3.3.1 ("Redução do risco de alucinação"), 3.5.3 (Limitações Assumidas).

## Objetivo do tópico

Documentar as salvaguardas que impedem que uma resposta probabilística do modelo altere dados do acervo sem sustentação no texto e no catálogo regulatório.

## 1. Fundamentação teórica

### 1.1 Alucinação e confiabilidade

LLMs podem gerar conteúdo plausível porém não sustentado pela fonte. [referência a incluir: Huang et al. (2023), já listado nas referências do projeto.] Em informação medicamentosa, o impacto potencial exige tratar a saída do modelo como proposta a validar, não como fato.

### 1.2 Confiança declarada pelo modelo

A confiança autorrelatada por um LLM não é, por si só, uma probabilidade calibrada. [referência a incluir: trabalhos sobre calibração de LLMs.] Por isso ela é apenas uma das condições de aceitação.

## 2. Validação estrita do formato

`DocumentClassificationResponse::fromModelOutput` aceita somente as chaves exatas `product_name`, `active_ingredient`, `document_type` (`bula_paciente`, `bula_profissional` ou nulo), `confidence` (número entre 0 e 1) e `evidence` (até 5 textos). JSON malformado, incompleto, com chaves extras, tipos errados ou confiança fora da faixa gera `AI_INVALID_RESPONSE`. Nenhum "conserto" é tentado. Na camada de transporte, a gramática do llamafile reduz respostas fora do contrato (ver `09-llms-locais-quantizacao-llamafile.md`).

## 3. Regras de aceitação (todas necessárias para aplicar)

1. Confiança >= 0,85; caso contrário `AI_LOW_CONFIDENCE`.
2. Grounding: o valor deve constar nos candidatos da heurística ou nos trechos enviados (nomes devem casar por palavra inteira, com no mínimo 4 caracteres).
3. Sem contradição com valores já encontrados pela heurística; do contrário `AI_REJECTED`.
4. O tipo de documento só é aceito se coincidir com um candidato de público encontrado pela heurística.
5. A IA preenche apenas campos que a heurística deixou vazios ou ambíguos.
6. Revalidação no catálogo ANVISA: só é aplicada se o status for `CONFIRMED` e o documento estiver completo (`AI_ACCEPTED`). Caso contrário, é registrada apenas como sugestão (`AI_SUGGESTED`) e o item permanece pendente.
7. Falha, timeout ou saída inválida: `AI_UNAVAILABLE`, mantendo o resultado heurístico.

Princípio: o modelo propõe; heurística, texto-fonte e catálogo oficial confirmam; o humano decide os casos restantes.

## 4. Tensão de projeto identificada

As regras 3 a 5 são conservadoras. Em um caso observado (omeprazol sódico), a IA estava correta e a heurística errada, e a regra de não contradição impediu a correção. Alternativa automática (permitir que a IA corrija a heurística com grounding e `CONFIRMED` simultâneos) não foi implementada; exigiria avaliação em corpus. Em vez disso, a regra conservadora foi mantida para o processamento sem supervisão e a correção passou a depender de decisão humana (ver `12-auditabilidade-e-explicabilidade.md`, seção 5). Na observação do omeprazol, a heurística registrou score 55 e valor errado ("10mL de solução diluente").

## 5. Grounding por campo como apoio ao revisor

O sistema persiste, por campo, `classification.checks` com a verificação de que o valor aparece no texto enviado à IA (`grounded`) e de conflito com a heurística. Esses indicadores são exibidos no quadro comparativo da revisão. Observação no item real de omeprazol (n=1): a IA propôs `document_type = bula_profissional` com `grounded = false` (sem respaldo no texto enviado), enquanto o princípio ativo "omeprazol sódico" estava correto. O contraste entre os dois campos do mesmo item sugere que o grounding por campo é um sinal útil para o revisor distinguir sugestão sustentada de não sustentada. Essa utilidade é uma hipótese apoiada em um caso, não um resultado.

Mesmo na aceitação humana, o sistema aplica somente valores gravados pela própria análise: o administrador escolhe campos e nunca digita valores.

## Evidências no repositório

- `app/Data/Ai/DocumentClassificationResponse.php`
- `app/Services/Knowledge/KnowledgeDocumentClassificationService.php` (`merge`, `rejectionReason`, `isGrounded`)
- `app/Services/Knowledge/KnowledgeDocumentRegulatoryValidator.php`, `AnvisaRegulatoryCatalog.php`
- `config/document_classification.php`
- Testes: `tests/Unit/Services/Ai/DocumentClassificationResponseTest.php`, `tests/Feature/Knowledge/KnowledgeDocumentClassificationTest.php`

## Resultados observados

- Testes automatizados cobrem formato inválido, baixa confiança, rejeição e aceitação.
- Em 6 PDFs reais (observação de teste, n pequeno), todas as respostas do Qwen2.5-1.5B foram rejeitadas pelas validações; nenhuma alteração foi aplicada ao acervo. Isso demonstra a salvaguarda, mas também indica baixa utilidade atual do modelo nesse conjunto.

## Lacunas / a verificar

- Taxa de falsos aceites e falsos rejeitados não medida.
- Sem comparação "com e sem grounding" para quantificar o efeito da salvaguarda.
- Decidir, com dados, sobre a política de correção pela IA.
- Valores aceitos pelo administrador não são revalidados automaticamente no catálogo ANVISA; a regra 6 vale apenas para o fluxo automático.
- O indicador `grounded` por campo não foi avaliado quanto a falsos positivos/negativos; baseia-se em um caso observado.
