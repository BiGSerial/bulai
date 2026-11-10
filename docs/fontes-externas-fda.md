# Bula dos EUA (FDA) como complemento: openFDA e DailyMed

## Propósito

As bulas brasileiras cadastradas costumam ser deficientes em **interações medicamentosas** e **reações adversas**: só 15 dos 1.483 pares avaliados no TCC têm bula dos dois remédios. Este recurso traz, só para esses dois assuntos, a bula de prescrição da FDA (EUA) do mesmo princípio ativo. O texto é traduzido, conferido e guardado com a origem, e entra **só quando a nossa base não cobre a pergunta**, sempre identificado e com crédito.

## Fontes e licença

- **Principal:** openFDA, Drug Label API (`https://api.fda.gov/drug/label.json`, https://open.fda.gov/apis/drug/label/).
  - Dados em domínio público, com dedicação **CC0 1.0** (https://open.fda.gov/license/).
  - Limites: sem chave, 240 consultas por minuto e **1.000 por dia** por IP; com `OPENFDA_API_KEY` (gratuita), 120.000 por dia.
  - O próprio openFDA avisa que os dados "não devem ser usados para decisões de cuidado médico" e que as bulas foram reformatadas sem verificação da FDA. Por isso o sistema usa esses dados só como complemento, identificado.
- **Reserva (redundância):** DailyMed, U.S. National Library of Medicine (`https://dailymed.nlm.nih.gov/dailymed/services/v2`). São as mesmas bulas SPL, em XML, e a API é usada quando o openFDA falha ou não tem bula aceitável.
- **Créditos exibidos:**
  - na resposta do Perguntar: "Origem: FDA, via openFDA e DailyMed (U.S. National Library of Medicine)", com o aviso de tradução automática e de que a bula brasileira não traz a informação;
  - nas fontes da resposta: o título "… — bula dos EUA (FDA), tradução automática";
  - nas limitações dos pares da consulta de interações;
  - no rodapé e no "De onde vêm as informações?" da página inicial.
- A CC0 não exige atribuição, mas pede para não sugerir endosso da FDA. O texto nunca diz que a FDA aprova a resposta.

## O que é importado (só o confiável)

`App\Services\Knowledge\External\ExternalLabelImporter`, por princípio ativo:

1. Busca pelos nomes em inglês do princípio ativo (aliases do DDInter, `active_ingredient_aliases.language = en`).
2. Só **bula de prescrição para uso humano**: no openFDA, `openfda.product_type:"HUMAN PRESCRIPTION DRUG"`; na DailyMed, documento LOINC `34391-3`.
3. Só produto com **um único princípio ativo**, igual ao procurado. Associações e kits ficam de fora.
4. Entre as válidas, vale a **mais recente** que tenha texto real de interações: pelo menos metade do texto da candidata mais completa e 400 caracteres. Isso descarta bulas em que a tabela de interações é só imagem.
5. Só duas seções: **interações medicamentosas** (LOINC `34073-7`, campo `drug_interactions` com a tabela `drug_interactions_table`) e **reações adversas** (`34084-4`, `adverse_reactions`). Cada seção vira até 12 trechos de cerca de 1.500 caracteres.
6. **Tradução conferida** (`LabelTranslator`): tradução fiel para o português do Brasil, com os nomes dos princípios ativos em DCB. Ela é **recusada** se:
   - mudar o tamanho de forma incompatível;
   - perder ou alterar algum número;
   - acrescentar um número que o original não trazia por extenso ("once daily" pode virar "1 vez ao dia").

   Trecho recusado fica guardado em inglês e **nunca** é usado como evidência.

## Tabelas

- `external_labels`: uma linha por princípio ativo e fonte. Campos:
  - origem: `source` (`fda`) e `retrieved_via` (`openfda` ou `dailymed`);
  - situação: `status` (`ready`, `not_found` ou `failed`);
  - identificação da bula: `set_id`, `spl_version`, `title`, `labeler`, `published_at`, `source_url` (página da DailyMed) e `unii`;
  - controle: `searched_name`, `error` e `fetched_at`.
- `external_label_sections`: os trechos. Campos `section_code` (LOINC), `section_name`, `position`, `text_original`, `text_translated`, `translation_status` (`translated`, `rejected` ou `pending`), `translation_model` e `translated_at`.

## Quando é usado

- **Perguntar** (`MedicationQuestionService`, nível 2b): só quando a intenção principal é `ADVERSE_EFFECT` ou `DRUG_INTERACTION` e as bulas brasileiras, inclusive a leitura da bula inteira e a releitura do PDF, **não cobriram** a pergunta por inteiro.
  - Efeitos adversos: os primeiros trechos da seção, onde a bula resume os efeitos mais comuns e os graves.
  - Interação entre dois remédios: só trechos que **citam o outro remédio pelo nome**, em inglês ou português.
  - Interação de um remédio só: os primeiros trechos da seção.
  - O prompt avisa o modelo, que diz na resposta que a informação vem da bula dos Estados Unidos. A validação de ancoragem continua valendo sobre o texto traduzido.
  - O rastreio fica em `retrieval.external`.
- **Consulta de interações (pares)** (`PairAnalyzer`): só quando nenhuma bula brasileira traz trecho sobre o par, e só com trechos que citam o outro remédio.
  - A gravidade continua vindo do DDInter.
  - Sem registro estruturado, o resultado continua "evidência insuficiente".
  - A limitação diz que o trecho é da bula dos EUA, traduzido.
- **Princípio ativo sem bula estrangeira importada:** a importação vai para a fila (`ImportExternalLabel`) e fica pronta para a próxima pergunta. A resposta atual não espera.

## Comandos

```bash
# Princípios ativos que o sistema usa (bulas cadastradas, perguntas, consultas e tratamentos), pela fila
php artisan knowledge:import-fda-labels --used
# Alguns pelo nome (português ou inglês) ou id, aqui mesmo
php artisan knowledge:import-fda-labels sertralina ibuprofen --sync
# Atualizar os já importados; só baixar, deixando a tradução para o primeiro uso
php artisan knowledge:import-fda-labels --used --refresh --no-translate
```

Em produção: `sudo bulai-deploy artisan knowledge:import-fda-labels --used`. O andamento aparece em Admin › Fila e serviços ("Importar bula dos EUA (FDA)").

## Configuração (`config/bulai.php`, `external_labels`)

- `BULAI_EXTERNAL_LABELS_ENABLED` (padrão `true`; `false` em `phpunit.xml`).
- `OPENFDA_API_KEY` (opcional), `OPENFDA_TIMEOUT` e `OPENFDA_MAX_CANDIDATES` (25).
- `DAILYMED_BASE_URL`, `DAILYMED_TIMEOUT` e `DAILYMED_MAX_CANDIDATES` (6).
- `BULAI_EXTERNAL_MAX_CHUNKS_PER_SECTION` (12), `BULAI_EXTERNAL_CHUNK_CHARS` (1.500) e `BULAI_EXTERNAL_EVIDENCE_LIMIT` (3 por resposta).
- `BULAI_EXTERNAL_TRANSLATION_MODEL`: vazio usa o modelo de texto configurado.

## Testes

`tests/Feature/Knowledge/ExternalLabelsTest.php`:
- importação pelo openFDA com a origem, escolhendo a bula mais recente e de princípio ativo único;
- reserva pela DailyMed quando o openFDA falha;
- tradução recusada quando um número muda, sem virar evidência;
- princípio ativo não aprovado nos EUA registrado como `not_found`;
- Perguntar usando a bula dos EUA só para efeitos adversos, com o crédito, e nunca para indicação;
- par usando só trechos que citam o outro remédio;
- fila de importação para o princípio ativo sem bula estrangeira;
- comando.

## Limitações

- **Remédios não aprovados nos EUA não têm bula da FDA.** Exemplos: dipirona (metamizol), nimesulida e fenoterol (Berotec).
- **A bula americana descreve o produto e o contexto regulatório dos EUA.** Por isso não é usada para dose, indicação ou apresentação: isso continua vindo só da ANVISA.
- **A tradução é automática.** A conferência barra a mudança de números, não outros erros de sentido. O original fica guardado para auditoria.
- **Fora dos experimentos do TCC.** Os resultados do TCC foram medidos sem esta fonte, e o recurso é uma evolução posterior.
- **Termos da DailyMed.** A licença CC0 é a do openFDA. A página de serviços da DailyMed só remete à política de direitos autorais da NLM (https://www.nlm.nih.gov/web_policies.html), que cabe conferir.
