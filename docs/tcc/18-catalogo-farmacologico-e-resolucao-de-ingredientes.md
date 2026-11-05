# Catálogo farmacológico e resolução de princípios ativos

Mapeamento no docx: Cap. 3, 3.2.2 (Base de Dados Farmacológicas), 3.3.2 etapas 1, 2 e 7.

## Objetivo do tópico

Explicar como medicamentos brasileiros (ANVISA, em português) são ligados a pares de interação (DDInter, em inglês), por que essa ponte é o ponto crítico de cobertura e como sua qualidade foi medida.

## 1. Problema

O catálogo ANVISA nomeia "VARFARINA SÓDICA"; o DDInter, "Warfarin". Sem ponte, apenas 21.532 dos 160.235 pares casavam por coincidência de grafia (captopril, atenolol...). Além disso, 10.574 registros não têm princípio ativo preenchido.

## 2. Solução implementada (determinística e auditável)

1. **Princípio ativo canônico** (`active_ingredients`): nome DDInter sem qualificadores de via/forma ("phenylephrine (nasal)" → "phenylephrine"); 1.806 ingredientes.
2. **Aliases** (`active_ingredient_aliases`) com `origin` (`ddinter`, `curated`, `rule`) e `review_status` (`reviewed`/`pending_review`).
3. **Normalização do lado ANVISA** (`IngredientNameNormalizer`): minúsculas, sem acentos, remoção de sais, ésteres e hidratos ("cloridrato de", "sódica", "trihidratada"…). A remoção de sais **não** é aplicada ao lado DDInter: na primeira versão isso tornava "calcium" alias exclusivo de "calcium acetate" e ligava "cálcio" ao fármaco errado (detectado em teste e corrigido).
4. **Regras ortográficas PT→EN** (`IngredientCognateGenerator`: "-ina→-ine", "ilina→illin", "cl→chl", "fen→phen"…). Um candidato só é aceito se **coincidir exatamente** com um nome DDInter; a regra nunca cria ingrediente. 610 aliases gerados; amostra de 90 conferida por leitura (nenhum erro encontrado) — não substitui revisão farmacêutica.
5. **Aliases curados** (`database/data/ingredient_aliases.csv`, 68 aplicados) para irregulares (paracetamol→acetaminophen, varfarina→warfarin).
6. **Ambiguidade**: nome que aponta para mais de um ingrediente **não é ligado** (errar a ligação geraria interações falsas; não ligar só reduz cobertura).
7. **Vínculos**: `anvisa_medicine_ingredients` guarda método (`alias`, `salt_stripped`, `cognate_rule`, `product_name`) e confiança; `drug_interactions` recebe as duas pontas resolvidas (100% dos pares); bulas ligam-se a ingredientes (`knowledge_document_ingredients`) por registro ANVISA > metadado > título.

Comando único e idempotente: `php artisan knowledge:build-ingredients`.

## 3. Resultado medido

Em 04/11/2026: 21.923 de 39.416 medicamentos ligados a ao menos um princípio ativo; 13 das 17 bulas ligadas. Qualidade da ponte: seção "Resultados" em `23-resultados-experimentais.md`.

## 4. Limitações

- **Dipirona/metamizol não existe no DDInter**: um dos analgésicos mais usados no Brasil nunca terá interação estruturada (resultado: abstenção). Nomes comerciais sem princípio ativo no catálogo (ex.: Lexotan) também não resolvem.
- Medicamentos fitoterápicos, vitaminas e vacinas em grande parte não constam no DDInter.
- Preços/licença do DDInter e a revisão dos aliases `pending_review` por farmacêutico são pendências.
- Classes terapêuticas (ex.: "anticoagulantes orais" em texto de bula) não são mapeadas para ingredientes.
