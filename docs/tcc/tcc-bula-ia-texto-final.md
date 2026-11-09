# BULA-IA: CONSULTA RASTREÁVEL A BULAS E INTERAÇÕES MEDICAMENTOSAS COM MODELOS DE LINGUAGEM E RECUPERAÇÃO DE FONTES OFICIAIS

# BULA-IA: TRACEABLE ACCESS TO PACKAGE INSERTS AND DRUG INTERACTIONS WITH LANGUAGE MODELS AND RETRIEVAL OF OFFICIAL SOURCES

**DAVI TÂMBARA RODRIGUES**
Graduando em Sistemas de Informação
tambaradavi@gmail.com

**WILTON CARVALHO DE OLIVEIRA**
Graduando em Sistemas de Informação
woliveiravix@gmail.com

**HOWARD CRUZ ROATTI**
Prof. Me. — Centro Universitário FAESA
howard.cruz@faesa.br

---

## RESUMO

As bulas de medicamentos usam linguagem técnica e extensa, de difícil compreensão para os pacientes, e a polifarmácia amplia o risco de interações medicamentosas. Este trabalho desenvolveu e avaliou o BULA-IA, aplicação web progressiva que combina as bulas do Bulário Eletrônico da Agência Nacional de Vigilância Sanitária, a base de interações DDInter e um modelo de linguagem de grande escala (LLM) com geração aumentada por recuperação (RAG). Na arquitetura proposta, a existência e a gravidade de uma interação são decididas pela base estruturada; o RAG recupera trechos de bula, e o modelo apenas os explica, sob um validador de fundamentação. A avaliação, automatizada e versionada, usou uma base restrita: 18 bulas, que cobrem 6 dos 55 produtos estudados. A identificação dos princípios ativos acertou 89,4% das consultas reservadas (IC 95%: 77,4%–95,4%). A verificação dos 1.483 pares concordou integralmente com o DDInter, resultado que valida a integração da aplicação com a base, e não a correção clínica, pois o gabarito provém da mesma fonte; ao ampliar a base de 8 para 14 grupos anatômicos, constatou-se que 89% dos pares antes tidos como sem interação eram lacunas de cobertura. Com o modelo de produção, 8 de 12 explicações passaram no validador, e as respostas foram mais legíveis que os trechos citados pelo Índice de Flesch adaptado (mediana de 60,2 contra 25,6). Sem recuperação, o mesmo modelo não apontou interações ausentes da base, mas deixou de reconhecer 26 das 56 interações registradas que avaliou, sem indicar fontes. Não houve avaliação por profissionais de saúde nem por usuários; a compreensão efetiva permanece não medida.

**Palavras-chave:** Bulas de medicamentos. Interações medicamentosas. Modelos de linguagem de grande escala. Geração aumentada por recuperação.

## ABSTRACT

Medication package inserts use technical, lengthy language that is hard for patients to understand, and polypharmacy increases the risk of drug interactions. This study developed and evaluated BULA-IA, a progressive web application that combines the package inserts of the Brazilian Health Regulatory Agency, the DDInter drug interaction database and a large language model (LLM) with retrieval-augmented generation (RAG). In the proposed architecture, the existence and severity of an interaction are decided by the structured database; RAG retrieves package insert passages, and the model only explains them, under a grounding validator. The automated, versioned evaluation used a restricted corpus: 18 package inserts, covering 6 of the 55 products studied. Active ingredient identification was correct in 89.4% of held-out queries (95% CI: 77.4%–95.4%). Checking of the 1,483 pairs fully agreed with DDInter, a result that validates the application's integration with the database rather than clinical correctness, since the reference standard comes from the same source; expanding the database from 8 to 14 anatomical groups showed that 89% of the pairs previously labeled as non-interacting were coverage gaps. With the production model, 8 of 12 explanations passed the validator, and answers were more readable than the cited passages according to the Portuguese-adapted Flesch Reading Ease (median 60.2 versus 25.6). Without retrieval, the same model flagged no interaction absent from the database but missed 26 of the 56 recorded interactions it assessed, without citing sources. No evaluation by health professionals or users was carried out; actual comprehension remains unmeasured.

**Keywords:** Package inserts. Drug interactions. Large language models. Retrieval-augmented generation.

---

## 1 INTRODUÇÃO

Os danos relacionados a medicamentos estão entre as principais causas evitáveis de morbimortalidade nos sistemas de saúde. Ao lançar seu terceiro Desafio Global de Segurança do Paciente, a Organização Mundial da Saúde apontou a polifarmácia como um dos três domínios prioritários de ação, ao lado das transições de cuidado e das situações de alto risco, e estimou que esses danos custam cerca de US$ 42 bilhões por ano aos sistemas de saúde (WORLD HEALTH ORGANIZATION, 2017).

Nesse contexto, a bula é a principal fonte de informação do paciente sobre o medicamento que utiliza, mas sua compreensão integral ainda é uma barreira. Segundo Fujita, Machado e Teixeira (2014), a linguagem com excesso de termos técnicos e o grande volume de informações dificultam a interpretação por grande parte dos usuários. Silva et al. (2006), em pesquisa com moradores de Araraquara (SP), registraram queixas sobre o excesso de informação, o tamanho reduzido das letras e a linguagem pouco acessível, fatores que comprometem a compreensão do conteúdo e aumentam as chances de uso inadequado dos medicamentos.

Os Modelos de Linguagem de Grande Escala (LLMs) apresentam potencial para reescrever esse conteúdo em linguagem acessível. Contudo, quando utilizados isoladamente, podem produzir informações sem respaldo nas fontes, o que é crítico no domínio da saúde. A Geração Aumentada por Recuperação (RAG) busca reduzir esse risco ao condicionar a resposta do modelo a trechos recuperados de documentos previamente selecionados (LEWIS et al., 2020).

Diante desse cenário, formulou-se o seguinte problema de pesquisa: como os LLMs, apoiados por recuperação de fontes oficiais, podem apoiar a compreensão das bulas e a identificação de interações medicamentosas de forma rastreável? Partiu-se da hipótese de que separar as responsabilidades — deixando a existência e a gravidade da interação a cargo de uma base estruturada e restringindo o modelo à explicação de trechos recuperados de bulas oficiais — produziria respostas rastreáveis, mais legíveis que a bula e menos sujeitas a afirmações sem fonte do que as do modelo de linguagem usado isoladamente.

O objetivo geral deste trabalho foi desenvolver e avaliar o BULA-IA, aplicação web progressiva baseada em LLM e RAG que consulta bulas de medicamentos e uma base de interações e apresenta os resultados em linguagem acessível, sempre com a indicação da fonte. Especificamente, buscou-se:

a) reunir e organizar as bulas do Bulário Eletrônico da ANVISA, o catálogo de medicamentos da ANVISA e a base de interações DDInter;
b) indexar as bulas em PostgreSQL com pgvector, com busca lexical, vetorial e híbrida;
c) implementar a verificação de interações a partir do DDInter, com a classificação de gravidade da própria base;
d) integrar a recuperação a um LLM com instruções que exijam a citação dos trechos e a recomendação de consulta a um profissional de saúde, sob um validador automático de fundamentação;
e) avaliar a identificação dos medicamentos, a integração com a base de interações, a recuperação de trechos, a geração das explicações e sua legibilidade, em comparação com o modelo de linguagem sem recuperação.

A pesquisa se justifica pela necessidade de tornar as informações sobre medicamentos mais claras e acessíveis, uma vez que a dificuldade de compreensão das bulas pode contribuir para erros de administração, automedicação inadequada e interações perigosas. O BULA-IA não se propôs a substituir as bases farmacológicas existentes nem a orientação profissional, e sim a investigar uma arquitetura baseada em RAG como camada de acesso rastreável a fontes previamente selecionadas. A contribuição está, portanto, na forma de recuperação e apresentação das informações ao usuário, e não na criação de uma nova base farmacológica.

## 2 FUNDAMENTAÇÃO TEÓRICA

Esta seção apresenta os fundamentos que sustentam o trabalho: a bula como fonte de informação sobre medicamentos, a polifarmácia e as interações medicamentosas, as bases de dados farmacológicas, os modelos de linguagem e a geração aumentada por recuperação, as estratégias de recuperação, a medida de legibilidade de textos e as ferramentas relacionadas.

### 2.1 A bula como fonte de informação

A bula é o documento que acompanha o medicamento e reúne as informações necessárias ao seu uso seguro, como composição, indicações, contraindicações, posologia, reações adversas e modo de administração. No Brasil, a Agência Nacional de Vigilância Sanitária (ANVISA) regula a elaboração, a atualização, a publicação e a disponibilização das bulas por meio da Resolução RDC nº 47/2009, que estabelece versões distintas para pacientes e para profissionais da saúde (BRASIL, 2009).

A bula destinada ao paciente começa pela identificação do produto, como nome comercial, princípio ativo, forma farmacêutica, via de administração e faixa etária de uso, e organiza o conteúdo em seções em forma de perguntas e respostas, encerrando com os dados da empresa e a identificação do farmacêutico responsável (BRASIL, 2009). Essa padronização em seções é relevante para o processamento automatizado, pois permite localizar o trecho que responde a cada tipo de dúvida.

Além da bula impressa, a ANVISA prevê a bula em formato especial, destinada a pessoas com deficiência visual, em áudio, braile ou fonte ampliada (AGÊNCIA NACIONAL DE VIGILÂNCIA SANITÁRIA, 2020b), e a disponibilização eletrônica do conteúdo, inclusive por QR Code, com busca de palavras-chave e ampliação da fonte. O Bulário Eletrônico, por sua vez, reúne as bulas dos medicamentos regularizados no país (AGÊNCIA NACIONAL DE VIGILÂNCIA SANITÁRIA, 2020a). Essas iniciativas mostram que o próprio órgão regulador reconhece a acessibilidade como um problema; no entanto, ao reproduzirem o mesmo texto técnico em outro suporte, atuam sobre a forma de acesso, e não sobre a dificuldade de compreensão do conteúdo discutida na Introdução. A disponibilidade digital das bulas, por outro lado, viabiliza seu processamento computacional, que é o ponto de partida deste trabalho.

### 2.2 Polifarmácia e interações medicamentosas

A polifarmácia corresponde ao uso concomitante de múltiplos medicamentos e, embora não haja uma definição única, é frequentemente caracterizada pelo uso rotineiro de cinco ou mais fármacos (WORLD HEALTH ORGANIZATION, 2019). Sua prevalência cresce com o envelhecimento populacional e com o aumento de doenças crônicas não transmissíveis, que demandam tratamentos prolongados e combinados. O uso simultâneo de vários medicamentos amplia o risco de interações, reações adversas e falhas terapêuticas, além de favorecer erros de administração, sobretudo em idosos, nos quais a redução das funções renal e hepática altera a farmacocinética dos fármacos (WORLD HEALTH ORGANIZATION, 2019).

Segundo Katzung e Trevor (2017), interações medicamentosas ocorrem quando os efeitos de um medicamento são alterados pela presença de outro fármaco, de um alimento ou de outra substância química, o que pode reduzir a eficácia terapêutica ou potencializar os efeitos tóxicos, levando a intoxicações, internações hospitalares e, nos casos mais graves, ao óbito. Quanto à natureza, as interações classificam-se em medicamento-medicamento, medicamento-alimento, medicamento-álcool e medicamento-condição clínica (BRUNTON; HILAL-DANDAN; KNOLLMANN, 2018; KATZUNG; TREVOR, 2017). Este trabalho tratou apenas das interações medicamento-medicamento, uma vez que as demais dependem de informações sobre a dieta, os hábitos e as condições clínicas do paciente, que estão fora do escopo da base de interações utilizada.

As interações medicamento-medicamento podem ocorrer antes da administração, como nas incompatibilidades físico-químicas em seringas ou soluções intravenosas, ou após ela, por mecanismos farmacocinéticos ou farmacodinâmicos. As interações farmacocinéticas ocorrem quando um fármaco interfere na absorção, na distribuição, na metabolização ou na excreção de outro, alterando sua concentração no organismo e, com isso, sua eficácia ou toxicidade. As farmacodinâmicas ocorrem quando dois ou mais fármacos potencializam ou reduzem os efeitos um do outro ao atuar sobre os mesmos receptores, sistemas fisiológicos ou mecanismos celulares (KATZUNG; TREVOR, 2017).

Além da natureza, as interações são estratificadas por gravidade, informação essencial tanto para a decisão clínica quanto para sistemas automatizados de apoio. Na escala mais usual, de três níveis, a interação leve produz alterações mínimas no efeito terapêutico, sem exigir mudança de conduta; a moderada pode deteriorar o estado do paciente ou exigir ajuste de dose e monitoramento; e a grave pode provocar complicações potencialmente fatais ou dano permanente, exigindo intervenção imediata e, muitas vezes, a contraindicação da associação (BRUNTON; HILAL-DANDAN; KNOLLMANN, 2018). A detecção e a prevenção desses eventos inserem-se no campo da farmacovigilância, definida como a ciência e as atividades relativas à detecção, à avaliação, à compreensão e à prevenção de efeitos adversos ou de qualquer outro problema relacionado a medicamentos (WORLD HEALTH ORGANIZATION, 2002).

### 2.3 Bases de dados farmacológicas

Bases de dados farmacológicas são sistemas estruturados que armazenam e disponibilizam informações sobre medicamentos, como princípios ativos, mecanismos de ação, contraindicações, efeitos adversos e interações, e que apoiam a decisão clínica ao oferecer acesso rápido a informações confiáveis. Entre as mais citadas na literatura está o DrugBank, que reúne dados químicos, farmacológicos e terapêuticos sobre fármacos e permite a análise estruturada de interações, alvos e mecanismos de ação, com relevância reconhecida em pesquisas biomédicas e na farmacologia clínica (KNOX et al., 2024).

O DDInter é uma base de dados online dedicada especificamente às interações medicamento-medicamento, construída com o objetivo de apoiar a decisão clínica e a segurança do paciente (XIONG et al., 2022). Cada par de fármacos é registrado com um nível de gravidade, o que permite consultar diretamente a existência e a gravidade de uma interação. Por disponibilizar esses dados para fins acadêmicos e trazer a gravidade de forma estruturada, o DDInter foi a base de interações adotada neste trabalho, como se detalha na Metodologia. As bases e ferramentas da área, contudo, não adotam uma escala de gravidade comum, como mostra o Quadro 1.

**Quadro 1 – Escalas de gravidade de interações medicamentosas em bases e ferramentas**

| Base ou ferramenta | Níveis de gravidade | Níveis sem equivalente na escala de três níveis |
| --- | --- | --- |
| Micromedex | Contraindicado, grave, moderado, leve e desconhecido | Contraindicado e desconhecido |
| Drugs.com | Grave, moderada, leve e desconhecida | Desconhecida |
| DDInter | *Major*, *moderate*, *minor* e *unknown* | *Unknown* |

Fonte: Elaborado pelos autores (2026), com base em Micromedex (c2026), Drugs.com (c2026) e Xiong et al. (2022).

O Quadro 1 evidencia que as três fontes compartilham os níveis leve, moderado e grave, mas divergem nos extremos: o Micromedex distingue a associação contraindicada da interação grave, e todas preveem uma categoria de gravidade desconhecida, que não corresponde a nenhum nível de escala clínica usual. Essa heterogeneidade dificulta a comparação direta entre as fontes e exige uma regra explícita de correspondência sempre que se combinam bases diferentes ou se avalia um sistema em relação a uma referência. A categoria desconhecida, em particular, não equivale à ausência de risco: indica apenas que a interação está registrada sem uma classificação de gravidade, o que tem consequência direta na forma como um sistema deve comunicar o resultado ao usuário.

### 2.4 Modelos de linguagem e geração aumentada por recuperação

A Inteligência Artificial (IA) é o campo da computação dedicado a sistemas capazes de realizar tarefas que normalmente exigiriam inteligência humana, como reconhecer padrões, tomar decisões e compreender a linguagem (RUSSELL; NORVIG, 2020). Nesse campo, os Modelos de Linguagem de Grande Escala (LLMs) são sistemas treinados com grandes volumes de texto para compreender e gerar linguagem natural de forma coerente e contextualizada (ZHAO et al., 2023). Esses modelos se baseiam na arquitetura Transformer, cujo mecanismo de atenção identifica relações entre as palavras de um texto independentemente da distância entre elas, o que permite uma compreensão do contexto mais eficiente do que a das abordagens anteriores (VASWANI et al., 2017).

O conhecimento de um LLM, no entanto, fica armazenado nos parâmetros aprendidos durante o treinamento, o que traz duas limitações relevantes para o domínio da saúde: o modelo não se atualiza quando as fontes mudam e não indica de onde veio a informação que apresenta. A Geração Aumentada por Recuperação (RAG) combina essa memória paramétrica com uma memória não paramétrica, formada por um índice de documentos. Antes de gerar a resposta, o sistema recupera os trechos mais relevantes para a pergunta e os fornece ao modelo como contexto, o que permite atualizar o conhecimento sem retreinar o modelo e indicar a origem das informações apresentadas (LEWIS et al., 2020).

### 2.5 Recuperação lexical, vetorial e híbrida

A qualidade da resposta depende, portanto, da etapa de recuperação. Na busca lexical, os documentos são ordenados pela correspondência entre os termos da consulta e os do texto, o que favorece termos exatos, como nomes de fármacos e de princípios ativos. Na busca vetorial, ou densa, a consulta e os documentos são convertidos em *embeddings*, isto é, vetores numéricos nos quais textos de significado semelhante ficam próximos; a recuperação passa a se basear na similaridade entre esses vetores e consegue encontrar trechos relevantes mesmo quando as palavras não coincidem, sendo esse o tipo de recuperação empregado no trabalho original de Lewis et al. (2020). As duas abordagens são complementares: a lexical é precisa para nomes e termos técnicos, enquanto a vetorial capta paráfrases e sinônimos.

A busca híbrida combina as listas de resultados produzidas pelas duas abordagens. Um método amplamente utilizado para essa combinação é a *Reciprocal Rank Fusion* (RRF), que atribui a cada documento a soma de 1/(k + r) para cada lista em que ele aparece, sendo r a sua posição na lista e k uma constante, comumente igual a 60 (CORMACK; CLARKE; BÜTTCHER, 2009). Por utilizar apenas as posições, e não os escores, o método dispensa a calibração entre medidas de relevância de naturezas diferentes e favorece os documentos bem posicionados em mais de uma lista.

### 2.6 Legibilidade de textos

A legibilidade é a facilidade com que um texto pode ser lido, estimada por características superficiais como o comprimento das frases e das palavras. O Índice de Facilidade de Leitura de Flesch (FLESCH, 1948) combina essas duas medidas em uma escala na qual valores maiores indicam textos mais fáceis. Martins et al. (1996) adaptaram o índice ao português brasileiro pela fórmula IFL = 248,835 − 1,015 × (palavras por frase) − 84,6 × (sílabas por palavra), com quatro faixas: muito fácil (75 a 100), fácil (50 a 75), difícil (25 a 50) e muito difícil (0 a 25). Por depender apenas da forma do texto, o índice não mede a compreensão do leitor nem a correção do conteúdo; serve, porém, como indicador objetivo e reprodutível para comparar duas versões de um mesmo conteúdo, como o trecho da bula e a explicação gerada a partir dele.

### 2.7 Ferramentas relacionadas

Há diversas ferramentas digitais para consultar informações sobre medicamentos e identificar potenciais interações, entre elas bases de apoio à decisão como Micromedex e Drugs.com, além do Bulário Eletrônico da ANVISA (AGÊNCIA NACIONAL DE VIGILÂNCIA SANITÁRIA, 2020a; DRUGS.COM, c2026; MICROMEDEX, c2026). Essas soluções diferem quanto ao público-alvo, à forma de acesso, à classificação das interações e à apresentação das informações. Micromedex e Drugs.com oferecem verificadores de interações com níveis de gravidade próprios, apresentados no Quadro 1, enquanto o Bulário tem como foco a disponibilização dos documentos oficiais, sem análise de interações. O Quadro 2 compara essas ferramentas e o BULA-IA quanto ao tipo, à fonte de informação, à classificação de gravidade, ao uso de linguagem para leigos e à indicação da fonte da resposta. Os itens que não puderam ser verificados nas páginas públicas das ferramentas comerciais foram marcados como “não verificado”, e não como ausentes.

**Quadro 2 – Comparação entre ferramentas existentes e o BULA-IA**

| Ferramenta | Tipo | Fonte de informação | Classifica gravidade | Linguagem para leigos | Indica a fonte da resposta |
| --- | --- | --- | --- | --- | --- |
| Micromedex | Base de apoio à decisão clínica (acesso por assinatura) | Base editorial própria | Sim (5 níveis) | Não verificado | Não verificado |
| Drugs.com | Verificador de interações (acesso aberto) | Base editorial própria | Sim (4 níveis) | Não verificado | Não verificado |
| Bulário Eletrônico | Repositório oficial de bulas | Bulas aprovadas pela ANVISA | Não | Não (texto integral da bula) | Não se aplica (o próprio documento é a fonte) |
| BULA-IA | Aplicação web com LLM e RAG | Bulas do Bulário, catálogo da ANVISA e DDInter | Sim (escala do DDInter) | Sim (respostas reescritas e medidas por legibilidade) | Sim (bula, seção e páginas citadas) |

Fonte: Elaborado pelos autores (2026), com base em Agência Nacional de Vigilância Sanitária (2020a), Drugs.com (c2026) e Micromedex (c2026).

## 3 METODOLOGIA

Esta seção descreve a classificação da pesquisa, as fontes teóricas e de dados utilizadas, o recorte da base, as etapas de desenvolvimento do BULA-IA e os procedimentos para avaliá-lo.

### 3.1 Classificação da pesquisa

Quanto à natureza, a pesquisa é aplicada, pois desenvolveu uma solução tecnológica para um problema real de saúde pública: a dificuldade de compreender as bulas e de identificar interações medicamentosas. Quanto à abordagem, é quantitativa e qualitativa: a abordagem quantitativa foi empregada na medição do desempenho do sistema por métricas como precisão, recall, taxa de abstenção e índice de legibilidade, e a qualitativa, na análise das respostas geradas e dos erros observados. Quanto aos objetivos, é exploratória, por investigar a aplicação de LLMs e RAG às bulas brasileiras, e descritiva, por detalhar a arquitetura, a implementação e os resultados do sistema. Quanto aos procedimentos técnicos, combinou pesquisa bibliográfica, desenvolvimento tecnológico e avaliação experimental com conjuntos de teste.

### 3.2 Fontes teóricas

O referencial teórico baseou-se em quatro tipos de fonte: documentos de órgãos de saúde e de regulação, como a OMS e a ANVISA, sobre segurança do paciente, polifarmácia e regulamentação das bulas; obras de referência em farmacologia, para a classificação das interações; artigos científicos sobre a compreensão das bulas, os modelos de linguagem, a recuperação de informação e a legibilidade; e as publicações que descrevem as bases de dados utilizadas ou comparadas, como o DrugBank e o DDInter. As páginas de ferramentas comerciais, como Micromedex e Drugs.com, foram consultadas apenas para comparação.

### 3.3 Fontes de dados e recorte da base

As bulas, em PDF, foram obtidas no Bulário Eletrônico da ANVISA (AGÊNCIA NACIONAL DE VIGILÂNCIA SANITÁRIA, 2020a) e inseridas no sistema por envio em lote, com revisão humana da classificação de cada documento. Na data da avaliação final (7 nov. 2026), a base reunia 18 bulas — 13 destinadas a profissionais de saúde e 5 a pacientes —, divididas em 799 trechos. Essas bulas cobrem apenas 11 princípios ativos: azitromicina, amoxicilina com clavulanato, codeína, ibuprofeno, levotiroxina, nimesulida, omeprazol, pantoprazol, paracetamol, pseudoefedrina e sinvastatina. A lista completa, com o tipo de bula, o número de trechos e o identificador de versão de cada documento, está no Apêndice A.

Para associar o nome comercial informado pelo usuário aos princípios ativos, utilizou-se o catálogo de medicamentos da ANVISA disponível no Portal de Dados Abertos (AGÊNCIA NACIONAL DE VIGILÂNCIA SANITÁRIA, 2026), com 39.416 registros. As interações vêm do DDInter (XIONG et al., 2022), cujos arquivos de exportação são organizados por grupo anatômico da classificação ATC. Na primeira versão da base, foram importados oito grupos (A, B, D, H, L, P, R e V), com 160.235 pares de fármacos. Em 8 nov. 2026, foram acrescentados os seis grupos que faltavam — C (aparelho cardiovascular), G (aparelho geniturinário e hormônios sexuais), J (anti-infecciosos de uso sistêmico), M (sistema musculoesquelético), N (sistema nervoso) e S (órgãos dos sentidos) —, conferidos pelo *hash* SHA-256 de cada arquivo. A base passou a ter 234.981 pares: 39.082 com gravidade *major*, 143.748 *moderate*, 9.736 *minor* e 42.415 *unknown*. Mesmo com os 14 grupos, ela não equivale à exportação integral do DDInter 2.0, que declara 302.516 interações; a ausência de registro, portanto, continua não significando ausência de interação. A comparação entre as duas versões da base é apresentada na seção 5.2. O projeto previa o DrugBank 6.0, substituído por exigir licença para uso além do acadêmico.

Como o catálogo usa nomes em português e o DDInter em inglês, foi criada uma tabela de correspondência entre os dois idiomas, construída por regras de grafia e, para os nomes não resolvidos pelas regras, por tradução assistida pelo modelo de linguagem, com registro das traduções para revisão. Por fim, como o DDInter registra apenas a gravidade, a explicação de cada interação é buscada nas bulas, e os níveis *major*, *moderate* e *minor* foram tratados como grave, moderada e leve, e *unknown* como gravidade não classificada pela fonte (Quadro 1).

O recorte tem uma consequência que precisa ser explicitada antes dos resultados: dos 55 produtos usados na avaliação de interações (seção 3.5.2), apenas 6 têm bula na base (Tylenol, Omeprazol, Pantoprazol, Amoxicilina, Azitromicina e Ibuprofeno). Dos 1.483 pares formados por esses produtos, só 309 têm bula de pelo menos um dos fármacos, e apenas 15 têm bula de ambos. Para os demais pares, o sistema pode informar a existência e a gravidade registradas no DDInter, mas não dispõe de trecho de bula para explicá-las.

### 3.4 Desenvolvimento

O desenvolvimento ocorreu de setembro a novembro de 2026, com o código versionado em Git, em cinco fases: (i) ingestão e leitura das bulas; (ii) importação do catálogo da ANVISA, OCR e infraestrutura de produção; (iii) importação do DDInter; (iv) recuperação, integração com o modelo de linguagem, validador e interface do usuário; e (v) montagem dos conjuntos de teste e execução dos experimentos. A arquitetura resultante é descrita na seção 4.

### 3.5 Procedimentos de avaliação

A avaliação foi feita por experimentos automatizados, executados por comandos do próprio sistema (`evaluation:run` e `evaluation:conversation`). Cada execução grava um registro com a versão do código, o *hash* SHA-256 do conjunto de teste, um *hash* do conjunto de bulas, os *hashes* dos arquivos do DDInter, os modelos utilizados, os parâmetros e os resultados. Os resultados apresentados na seção 5 vêm da reexecução de todos os experimentos em 7 nov. 2026 sobre o código na versão `272b765` e sobre a base de 18 bulas descrita em 3.3. Os experimentos que dependem do DDInter (interações, geração e modelo sem recuperação) foram refeitos em 8 nov. 2026, após a importação dos 14 grupos, sobre a mesma versão do código acrescida da correção do avaliador descrita em 3.5.6; os registros estão listados no Apêndice B. Os experimentos são resumidos no Quadro 3 e detalhados a seguir.

**Quadro 3 – Experimentos de avaliação**

| Experimento | O que mede | Amostra | Métricas |
| --- | --- | --- | --- |
| Identificação do medicamento | Associação do nome digitado aos princípios ativos | 59 consultas de ajuste e 47 reservadas | Acerto exato, precisão, recall e F1 |
| Verificação de interações | Integração da aplicação com o DDInter (teste de integração) | 1.483 pares de 55 produtos; protocolo de 30 pares | Matriz de confusão, abstenção, recall conservador e concordância de gravidade |
| Recuperação de trechos | Ordenação dos trechos de bula | 4 consultas, 3 trechos relevantes | P@k, R@k e MRR nos modos lexical, vetorial e híbrido |
| Geração com validação | Formato e ancoragem das explicações | 12 pares com interação e trechos | Explicações aceitas e afirmações rejeitadas |
| Legibilidade | Facilidade de leitura das respostas em relação aos trechos citados | 66 respostas da versão atual do motor | Índice de Flesch adaptado ao português |
| Modelo sem recuperação | Comportamento do LLM sem base estruturada nem trechos | 60 pares sorteados (57 com e 3 sem interação no DDInter) | Precisão, recall, F1, acurácia e concordância de gravidade |

Fonte: Elaborado pelos autores (2026).

#### 3.5.1 Identificação do medicamento

Os autores definiram manualmente, antes de qualquer medição, os princípios ativos esperados para dois conjuntos de consultas. O conjunto de ajuste, com 59 consultas presentes no catálogo, foi usado durante o desenvolvimento para corrigir o normalizador de nomes; o conjunto reservado, com 47 consultas de outros fármacos, foi medido sem que seus erros fossem corrigidos. A identificação foi medida no nível do princípio ativo, por precisão, recall e F1, e no nível da consulta, pelo acerto exato, que exige o mesmo conjunto de princípios ativos do gabarito.

#### 3.5.2 Verificação de interações: teste de integração com o DDInter

Dos 59 produtos do conjunto de ajuste, foram excluídos os 4 cujo princípio ativo esperado não existe no DDInter (Lexotan, Dorflex, Novalgina e Benegrip), restando 55. Foram formados todos os pares entre esses produtos, exceto os dois compostos por produtos com o mesmo princípio ativo (Aspirina e AAS; Cataflam e Voltaren), totalizando 1.483 pares. Com os 14 grupos do DDInter, 1.424 desses pares têm interação registrada e 59 não têm; com os 8 grupos da primeira versão, eram 938 e 545. Os rótulos foram gerados novamente sobre os mesmos pares (gabarito v2), e o gabarito anterior (v1) foi mantido para a comparação. Também foi aplicado o protocolo de 30 pares previsto no projeto, amostrado com semente fixa dos mesmos 1.483: 10 interações graves, 5 moderadas, 5 leves e 10 pares sem interação registrada.

O rótulo de cada par foi obtido consultando o DDInter com os princípios ativos do gabarito manual, e não com os identificados pelo sistema. Como o sistema consulta a mesma base, **este experimento é um teste de integração**: verifica se a cadeia produto → princípio ativo → par → registro no DDInter funciona, e não se a informação está clinicamente correta. Uma concordância de 100% é o resultado esperado de uma implementação correta, e qualquer divergência indica falha de identificação ou de consulta. As respostas foram comparadas ao rótulo por precisão, recall e F1; as respostas inconclusivas (evidência insuficiente, medicamento não identificado ou erro técnico) foram contadas como abstenção e mantidas fora da matriz de confusão. Calcularam-se também o recall conservador, que conta as abstenções sobre pares positivos como erro, e a concordância de gravidade.

Há ainda uma sobreposição que limita esse experimento: os 55 produtos vêm do conjunto de ajuste da identificação, e uma versão anterior do sistema foi corrigida a partir de falhas observadas nesses mesmos pares (seção 5.2). O resultado, portanto, não estima o desempenho em produtos novos; essa estimativa cabe ao conjunto reservado da identificação.

#### 3.5.3 Recuperação de trechos

A recuperação foi avaliada com quatro consultas e três trechos julgados relevantes pelos próprios autores, a partir dos candidatos retornados pela busca lexical. Foram usadas três métricas: o P@k, fração dos k primeiros trechos que são relevantes; o R@k, fração dos trechos relevantes encontrados até a posição k; e o MRR, média do inverso da posição do primeiro trecho relevante. Pela dimensão da amostra e pela forma de julgamento, este experimento tem caráter apenas indicativo.

#### 3.5.4 Geração com validação

Foram sorteados, com semente fixa, pares com interação registrada no DDInter e com trechos de bula recuperados, até completar 12 pares. Para cada um, o modelo recebeu a gravidade informada pela base e os trechos, e deveria produzir afirmações curtas, cada uma ligada a um trecho. O validador aceita uma afirmação apenas se a referência existir, se houver sobreposição lexical mínima de 40% com o trecho citado e se não houver recomendação de conduta (por exemplo, suspender ou ajustar dose). O experimento foi executado com o modelo local Qwen2.5-1.5B-Instruct (YANG et al., 2024), em 4 nov. 2026, e com o modelo de produção, Claude Sonnet 5.5, em 7 nov. 2026. Ele mede formato e ancoragem lexical, e não a correção factual das explicações.

#### 3.5.5 Legibilidade

Calculou-se o Índice de Facilidade de Leitura de Flesch adaptado ao português (MARTINS et al., 1996) para cada resposta gerada pelo motor de perguntas livres na versão atual (66 respostas registradas em 7 nov. 2026) e para o conjunto dos trechos de bula citados como fonte da mesma resposta, formando 66 pares. As frases foram delimitadas por pontuação final (ponto, exclamação, interrogação, ponto e vírgula e dois-pontos) e por quebras de linha, e as sílabas foram contadas por uma rotina heurística de núcleos vocálicos, verificada manualmente em palavras de controle. A mesma rotina foi aplicada aos dois textos de cada par, o que torna a comparação válida mesmo que a contagem absoluta tenha pequenos desvios. As respostas vieram das rodadas de avaliação do motor, com perguntas escritas pelos autores e repetidas entre rodadas, sobre quatro princípios ativos (ibuprofeno, omeprazol, paracetamol e nimesulida), e não de usuários reais.

#### 3.5.6 Modelo sem recuperação

Para estimar o comportamento do modelo de linguagem usado isoladamente, foram sorteados, com semente 42, 60 dos 1.483 pares, dos quais 57 têm interação registrada no DDInter com 14 grupos e 3 não têm (com 8 grupos, eram 39 e 21). Para cada par, o modelo recebeu apenas os nomes dos princípios ativos, sem base estruturada nem trechos de bula, e respondeu em JSON se havia interação e qual a gravidade. O experimento foi executado com o Qwen2.5-1.5B-Instruct e com o Claude Sonnet 5.5. Na primeira execução com o Claude, 43 das 60 chamadas falharam porque o avaliador limitava a resposta a 60 *tokens* e o modelo consome parte desse limite em raciocínio interno antes de responder. O avaliador foi corrigido para 1.024 *tokens*, com teste automatizado, e passou a gravar a resposta de cada par; o experimento foi refeito com o mesmo enunciado e a mesma amostra, sem nenhuma falha de chamada, e é essa execução que se apresenta. O Qwen2.5-1.5B foi avaliado em 4 nov. 2026 com o gabarito de 8 grupos, e o avaliador da época não gravava as respostas por par, de modo que seu resultado não pôde ser recalculado com o gabarito atual. Como o rótulo é o próprio DDInter, as divergências do modelo foram analisadas uma a uma, distinguindo erros do modelo de lacunas da base.

#### 3.5.7 Tratamento estatístico

Para as proporções, foram calculados intervalos de confiança de 95% pelo método de Wilson (WILSON, 1927), adequado a amostras pequenas e a proporções próximas de 0 ou de 100%. Não se realizaram testes de hipótese entre modos de busca ou entre modelos, pois as amostras não têm tamanho para isso; as comparações são descritivas.

### 3.6 Avaliações com pessoas não realizadas

Foram definidos dois protocolos: a aplicação da *System Usability Scale* (SUS) (BROOKE, 1996) a 5 a 10 voluntários leigos e a revisão, por profissional de farmácia ou medicina, dos 30 pares do protocolo e de explicações geradas. **Nenhuma dessas avaliações foi realizada.** Ambas envolvem participantes humanos e foram tratadas como sujeitas à apreciação por Comitê de Ética em Pesquisa, conforme a Resolução CNS nº 510/2016 (BRASIL, 2016); como essa apreciação não foi solicitada no prazo do trabalho, nenhum resultado deste texto deve ser lido como validação clínica ou de usabilidade, nem mesmo parcial. Ambas são retomadas como trabalhos futuros.

### 3.7 Limitações do recorte

O protótipo tem limitações que condicionam todos os resultados: a base reúne 18 bulas de 11 princípios ativos, e apenas 6 dos 55 produtos avaliados têm bula; mesmo com os 14 grupos ATC, a base importada do DDInter não equivale à sua exportação integral; a ausência de uma interação na base não comprova que a combinação seja segura; os gabaritos foram elaborados pelos autores, sem validação profissional; e as amostras de recuperação, geração e legibilidade são pequenas. Além disso, o sistema não realiza diagnóstico nem substitui a orientação profissional.

## 4 DESENVOLVIMENTO DO BULA-IA

### 4.1 Arquitetura e divisão de responsabilidades

A abordagem RAG (LEWIS et al., 2020) foi adotada por permitir vincular cada resposta a um trecho de bula, reduzir o risco de afirmações sem fonte e incorporar novas bulas sem retreinar o modelo. A decisão central da arquitetura é a divisão de responsabilidades entre três componentes, resumida no Quadro 4.

**Quadro 4 – Divisão de responsabilidades na arquitetura do BULA-IA**

| Componente | Responsabilidade | O que não faz |
| --- | --- | --- |
| Base estruturada (DDInter) | Decide se há interação registrada e qual a gravidade | Não explica o mecanismo nem a conduta |
| Recuperação (RAG sobre as bulas) | Localiza os trechos de bula que tratam do par ou da pergunta | Não decide se há interação |
| Modelo de linguagem com validador | Reescreve em linguagem acessível o que os trechos dizem, citando-os | Não decide existência nem gravidade; não preenche lacunas das fontes |

Fonte: Elaborado pelos autores (2026).

Assim, a precisão da verificação de interações pertence à base estruturada, e não ao RAG: o RAG contribui com a explicação e a rastreabilidade, e o modelo só é acionado quando há trecho que sustente a explicação. Quando a base não registra o par, a interface informa “Interação não identificada nas fontes consultadas”, e nunca que a combinação é segura; quando a gravidade não é classificada pela fonte, isso é dito explicitamente.

### 4.2 Fluxo de processamento

O fluxo do sistema é apresentado no Quadro 5.

**Quadro 5 – Fluxo de processamento do BULA-IA**

| Etapa | Componente | Descrição |
| --- | --- | --- |
| 1 | Ingestão | Envio das bulas em PDF, em lote, com revisão humana da classificação do documento. |
| 2 | Extração | Leitura do texto do PDF, com OCR quando a extração direta é insuficiente. |
| 3 | Segmentação | Divisão da bula por seções e em trechos de cerca de 1.800 caracteres (máximo de 2.600), com sobreposição de 200. |
| 4 | Indexação | Índice textual e *embedding* de cada trecho no PostgreSQL. |
| 5 | Identificação | Associação do nome comercial aos princípios ativos pelo catálogo da ANVISA e pela tabela de correspondência português-inglês. |
| 6 | Verificação | Consulta de cada par no DDInter, que define a existência e a gravidade da interação. |
| 7 | Recuperação | Busca híbrida de até quatro trechos por par, nas seções de interações, advertências, precauções e contraindicações. |
| 8 | Geração e validação | O LLM explica a interação em afirmações curtas, cada uma ligada a um trecho; o validador descarta as que não têm apoio no texto citado ou que recomendam conduta. Sem trecho, o modelo não é acionado. |

Fonte: Elaborado pelos autores (2026).

Para perguntas livres sobre um medicamento (por exemplo, “para que serve?” ou “pode machucar o estômago?”), o sistema identifica a intenção da pergunta, busca os trechos nas seções correspondentes da bula e pede ao modelo uma resposta organizada em segmentos: fatos citados, limites do que a bula permite afirmar e, quando necessário, uma pergunta de esclarecimento. Perguntas que indicam urgência recebem uma orientação fixa de procurar atendimento, definida por regras, e não pelo modelo. Respostas sem trechos que as sustentem não são exibidas como resposta, e a pergunta segue para uma fila de revisão humana na administração.

### 4.3 Tecnologias utilizadas

O sistema foi desenvolvido como aplicação web progressiva (PWA), em PHP 8.4 com Laravel 13 e Livewire 4, e utiliza o PostgreSQL com a extensão pgvector para armazenar os dados, o índice textual e os vetores. O OCR utiliza o PaddleOCR, e os *embeddings* são gerados localmente pelo modelo BGE-M3 (CHEN et al., 2024), com 1.024 dimensões. Em produção, as respostas são geradas pelo Claude Sonnet 5.5, da Anthropic; o Qwen2.5-1.5B-Instruct (YANG et al., 2024), executado localmente, foi usado nos primeiros experimentos e mantido como termo de comparação. Em relação ao projeto, Python, LangChain, ChromaDB, GPT-4o e o aplicativo nativo foram substituídos, pois o pgvector dispensou um banco vetorial separado e a PWA atendeu ao uso em celular.

### 4.4 Privacidade e segurança

Por tratar dados referentes à saúde, considerados dados pessoais sensíveis pela Lei Geral de Proteção de Dados Pessoais (BRASIL, 2018, art. 5º, II), o sistema mantém o histórico de consultas e perguntas por 90 dias. As informações enviadas ao provedor do modelo não contêm nome, e-mail ou outro identificador do usuário, mas contêm os medicamentos consultados e, quando o usuário os cadastrou, os medicamentos em uso, a posologia e as alergias, necessários para contextualizar a resposta. Como o provedor do modelo está sediado fora do Brasil, esse envio configura transferência internacional de dados, sujeita às hipóteses do art. 33 da mesma lei (BRASIL, 2018); a adequação formal dessa transferência, por exemplo por cláusulas contratuais com o provedor e por informação clara ao usuário, deve ser verificada antes de uma operação em escala. O sistema não realiza diagnóstico, e um aviso permanente informa que ele não substitui a orientação profissional.

## 5 RESULTADOS E DISCUSSÃO

A seguir, apresentam-se e discutem-se os resultados dos experimentos descritos na Metodologia. Todos os números vêm dos registros de execução listados no Apêndice B, que guardam a versão do código e dos dados utilizados.

### 5.1 Identificação dos medicamentos

A Tabela 1 separa o conjunto de ajuste, usado para corrigir o normalizador de nomes, do conjunto reservado, medido sem ajustes.

**Tabela 1 – Desempenho na identificação dos princípios ativos**

| Conjunto | Consultas | Acerto exato (IC 95%) | Parcial | Não resolvida | Precisão | Recall | F1 |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Ajuste | 59 | 55 (93,2%; 83,8–97,3) | 2 | 2 | 1,000 | 0,937 | 0,967 |
| Reservado | 47 | 42 (89,4%; 77,4–95,4) | 1 | 4 | 0,977 | 0,915 | 0,945 |

Fonte: Elaborado pelos autores a partir dos experimentos (2026).
Nota: “Parcial” indica que parte dos princípios ativos esperados foi identificada; “não resolvida”, que nenhum foi. Precisão, recall e F1 são calculados por princípio ativo.

O ganho do conjunto de ajuste durante o desenvolvimento, de 86,4% para 93,2% de acerto exato, decorre da correção de seus próprios erros e não estima o desempenho futuro. Essa estimativa cabe ao conjunto reservado, cujo acerto de 89,4% não mudou com o último ajuste e foi reproduzido sem alteração na reexecução final. Com 47 consultas, porém, o intervalo de confiança vai de cerca de 77% a 95%, o que impede afirmar um desempenho mais preciso que esse.

Os erros vieram de três origens: regras de grafia ainda não tratadas na conversão para o inglês, como em alopurinol e hidroxicloroquina; ingrediente em formato não reconhecido, como o hemitartarato de zolpidem; e lacunas das fontes, como a ausência da dipirona e da domperidona no DDInter. O único caso parcial do conjunto reservado é um artefato do gabarito: para a consulta “Gentamicina”, o produto escolhido no catálogo é uma associação de betametasona com gentamicina, e o sistema identificou corretamente os dois princípios ativos. Na busca pelo nome digitado, o produto correto apareceu na primeira posição em 72,3% das consultas reservadas e entre os cinco primeiros em 87,2%.

### 5.2 Verificação de interações: teste de integração com o DDInter

Conforme a seção 3.5.2, os resultados desta seção medem a integração da aplicação com o DDInter, e não a correção clínica. Na verificação dos 1.483 pares com a base de 14 grupos, não houve falso positivo, falso negativo nem abstenção (Tabela 2).

**Tabela 2 – Matriz de confusão do teste de integração com o DDInter (1.483 pares)**

| Rótulo (DDInter) | Sistema: com interação | Sistema: não identificada | Abstenção | Total |
| --- | --- | --- | --- | --- |
| Com interação | 1.424 | 0 | 0 | 1.424 |
| Sem interação | 0 | 59 | 0 | 59 |

Fonte: Elaborado pelos autores a partir dos experimentos (2026).
Nota: IC 95% do acerto nos pares com interação: 99,7%–100%; nos pares sem interação: 93,9%–100%. Com a base de 8 grupos (7 nov. 2026), o resultado foi de 938/938 e 539/545, com 6 abstenções.

A gravidade coincidiu com a do DDInter em todos os 666 pares com gravidade classificada: as 110 interações graves, as 482 moderadas e as 74 leves foram atribuídas à mesma categoria; nos demais 758 pares com interação, a fonte não classifica a gravidade, e o sistema informa isso ao usuário. No protocolo de 30 pares, sorteado novamente sobre o gabarito atual, não houve erro nem abstenção (IC 95%: 88,6%–100%). Na base de 8 grupos, houvera 6 abstenções (0,4%), todas em pares então sem registro cujas bulas citavam o outro fármaco; com os 14 grupos, esses pares passaram a ter interação registrada, o que confirma que o sistema agira bem ao declarar evidência insuficiente em vez de ausência de interação.

A importância deste teste aparece em uma versão anterior do sistema, executada sobre os mesmos pares em 4 nov. 2026. Ela deixou 54 pares sem resposta por não identificar um dos medicamentos; somados às 6 abstenções por evidência insuficiente, foram 60 pares sem resposta (4,05%). Desses 60, 22 eram pares com interação registrada, o que resulta em um recall conservador de 916/938 = 0,977. Entre eles estava a interação grave entre amitriptilina e ondansetrona, perdida por faltar uma regra de grafia na conversão do nome para o inglês. Esse é o erro mais perigoso que o sistema pode cometer: a interação grave pode causar complicações potencialmente fatais (BRUNTON; HILAL-DANDAN; KNOLLMANN, 2018), e a falta de alerta tende a ser lida como segurança. A falha foi corrigida a partir desse mesmo conjunto, o que caracteriza ajuste sobre os dados de teste: o resultado de 100% da Tabela 2 confirma que a correção funcionou nesses pares, mas não garante que falhas semelhantes não ocorram com produtos fora do conjunto.

A comparação entre as duas versões da base é o resultado mais importante desta seção. Dos 545 pares que a base de 8 grupos dava como sem interação, 486 (89,2%; IC 95%: 86,3%–91,5%) têm interação registrada nos grupos acrescentados: 44 graves, 200 moderadas, 15 leves e 227 sem gravidade classificada. Entre as graves estão combinações frequentes em idosos, como losartana com captopril, valsartana com espironolactona, captopril com espironolactona e azitromicina com haloperidol. Nenhum dos 938 pares que já tinham registro mudou de gravidade. Ou seja, a versão anterior não errava ao consultar a base, mas a base deixava passar quase todas as interações entre fármacos cardiovasculares, do sistema nervoso e anti-infecciosos — e, como o teste de integração usa a própria base como gabarito, essa lacuna não aparecia como erro. Isso confirma a ressalva da seção 3.5.2: concordância de 100% com a fonte não mede a correção clínica.

Os 59 verdadeiros negativos restantes indicam só ausência de registro, comunicada como interação não identificada nas fontes consultadas. Eles se concentram em dois produtos, Xarelto (rivaroxabana), presente em 33 pares, e Gardenal (fenobarbital), em 17, o que sugere cobertura ainda desigual para alguns fármacos. Como a base de 14 grupos não equivale à exportação integral do DDInter (seção 3.3), parte desses negativos pode ser lacuna, e a ausência de registro não pode ser comunicada como segurança. A seção 5.6 mostra que a cobertura também altera a leitura do experimento com o modelo isolado.

### 5.3 Recuperação de trechos

A Tabela 3 compara os modos de busca, com *embeddings* do BGE-M3 (CHEN et al., 2024) e fusão por RRF (CORMACK; CLARKE; BÜTTCHER, 2009). Os valores devem ser lidos como indicativos: a amostra tem quatro consultas e três trechos relevantes, julgados pelos autores sobre candidatos da busca lexical.

**Tabela 3 – Desempenho da recuperação por modo de busca (4 consultas, 3 trechos relevantes; resultado indicativo)**

| Modo | P@1 / R@1 | P@3 / R@3 | P@5 / R@5 | MRR |
| --- | --- | --- | --- | --- |
| Lexical | 0,250 / 0,500 | 0,083 / 0,500 | 0,150 / 1,000 | 0,313 |
| Vetorial | 0,000 / 0,000 | 0,000 / 0,000 | 0,100 / 0,500 | 0,063 |
| Híbrido | 0,250 / 0,500 | 0,250 / 1,000 | 0,150 / 1,000 | 0,375 |

Fonte: Elaborado pelos autores a partir dos experimentos (2026).

O modo híbrido encontrou todos os trechos relevantes até a terceira posição e obteve o maior MRR; o vetorial foi o mais fraco. A comparação, porém, não sustenta conclusões gerais: um único trecho altera as métricas, os candidatos julgados vieram da busca lexical, o que penaliza o vetorial, duas das quatro consultas não têm trecho relevante no corpus, e a vantagem do híbrido em R@3 decorre de uma única consulta. Os valores foram idênticos na base de 17 e na de 18 bulas. O modo híbrido foi adotado como padrão por não ter perdido para o lexical em nenhuma métrica, decisão que deve ser revista com um conjunto de julgamentos maior e independente.

### 5.4 Geração e fundamentação das respostas

A Tabela 4 compara a geração com o modelo local e com o modelo de produção.

**Tabela 4 – Geração de explicações com validação automática (12 pares)**

| Modelo | Explicações aceitas (IC 95%) | Afirmações aceitas / rejeitadas | Motivo das rejeições |
| --- | --- | --- | --- |
| Qwen2.5-1.5B-Instruct (local) | 3 de 12 (25,0%; 8,9–53,2) | 9 / 9 | Formato inválido |
| Claude Sonnet 5.5 (produção) | 8 de 12 (66,7%; 39,1–86,2) | 21 / 3 | Sem apoio no trecho citado |

Fonte: Elaborado pelos autores a partir dos experimentos (2026).
Nota: as duas execuções sortearam os pares com a mesma semente, mas o modelo local foi avaliado com recuperação lexical e o de produção com a híbrida; como só entram pares com trechos recuperados, os conjuntos de pares podem diferir. O resultado do Claude é o da execução de 8 nov. 2026, com a base de 14 grupos; em 7 nov., com 8 grupos, foram também 8 de 12 explicações aceitas, com 19 afirmações aceitas e 4 rejeitadas. O tempo de geração foi de 57,9 s para os 12 pares (cerca de 4,8 s por par).

Com o modelo local, todas as rejeições foram por formato inválido, e nenhuma saída malformada chegou ao usuário. Com o modelo de produção, não houve erro de formato: as três afirmações rejeitadas citavam um trecho que não as sustentava lexicalmente, e as quatro explicações não aceitas foram substituídas pela gravidade registrada e pelos trechos da bula. Em nenhum dos casos o modelo decidiu a existência ou a gravidade da interação. As explicações aceitas passaram em filtros automáticos — referência válida, ancoragem lexical e ausência de recomendação de conduta —, que são heurísticos: a correção factual dessas explicações não foi verificada por profissional de saúde.

O comportamento diante da falta de informação foi observado em consultas manuais com o modelo de produção, em 5 nov. 2026. Na consulta de azitromicina com sinvastatina, nenhum trecho citava o outro fármaco, e o modelo nem foi acionado: o sistema exibiu apenas a gravidade registrada no DDInter. Nas consultas sobre ibuprofeno com paracetamol e sobre omeprazol em jejum, o modelo declarou os trechos insuficientes, e o sistema exibiu só os trechos. A pergunta sobre reações adversas do omeprazol foi respondida com quatro afirmações aceitas e nenhuma rejeitada. Assim, diante da falta de informação, o sistema se abstém ou informa que a interação não foi identificada nas fontes consultadas, sem que o modelo preencha a lacuna (Figuras 1 e 2).

**Figura 1 – Resposta a uma pergunta sobre interação entre ibuprofeno e losartana**

[inserir a captura de tela da versão publicada, com largura de 15 a 16 cm]

Fonte: Elaborado pelos autores (2026).

**Figura 2 – Resposta a uma consulta sobre medicamento sem bula vinculada**

[inserir a captura de tela da versão publicada, com largura de 15 a 16 cm]

Fonte: Elaborado pelos autores (2026).

Na Figura 1, a expressão “gravidade classificada pelo sistema” refere-se à gravidade obtida da base estruturada, e não a uma decisão do modelo.

### 5.5 Legibilidade das respostas

A Tabela 5 compara o Índice de Flesch das respostas com o dos trechos de bula citados como fonte de cada uma.

**Tabela 5 – Legibilidade das respostas e dos trechos citados (66 pares)**

| Texto | IFL (mediana) | IFL (mín.–máx.) | Palavras por frase (mediana) | Sílabas por palavra (mediana) | Faixa predominante |
| --- | --- | --- | --- | --- | --- |
| Trechos de bula citados | 25,6 | −4,0 a 45,0 | 11,3 | 2,49 | Difícil (39) e muito difícil (27) |
| Respostas do BULA-IA | 60,2 | 38,2 a 73,6 | 17,4 | 2,01 | Fácil (47) e difícil (19) |

Fonte: Elaborado pelos autores a partir dos experimentos (2026).
Nota: IFL = Índice de Facilidade de Leitura de Flesch adaptado ao português (MARTINS et al., 1996). Para referência, a mediana dos 799 trechos da base foi de 31,7.

Em todos os 66 pares, a resposta foi mais legível que os trechos citados, com diferença mediana de 28,7 pontos, o que a desloca da faixa “difícil” ou “muito difícil” para a faixa “fácil” em 47 casos. A melhora vem do vocabulário: as respostas usam palavras mais curtas (2,01 contra 2,49 sílabas por palavra), ao substituir termos como “gastrintestinal” por “no estômago ou no intestino”. As frases das respostas, por outro lado, são mais longas que as dos trechos, em parte porque a bula se divide em títulos e itens curtos, o que indica espaço para encurtar as frases geradas. Como respostas mais curtas e um vocabulário simples reduzem o esforço de leitura, esse resultado é o primeiro indício objetivo, neste trabalho, de que o sistema torna o conteúdo da bula mais acessível.

O resultado deve ser lido com três ressalvas. Primeiro, o índice mede a forma do texto, e não a compreensão: uma resposta legível pode estar incompleta ou errada, e a correção das respostas não foi avaliada por profissional. Segundo, as 66 respostas não são independentes: vêm de rodadas de avaliação do próprio motor, com perguntas escritas pelos autores e repetidas, sobre quatro princípios ativos. Terceiro, a resposta é muito menor que os trechos de origem (mediana de 147 contra 1.243 palavras), de modo que parte da facilidade decorre da seleção do conteúdo, e não apenas da reescrita — o que é desejável para o leitor, mas exige verificar se nada essencial foi omitido.

### 5.6 Comparação com o modelo sem recuperação

A Tabela 6 apresenta o desempenho dos dois modelos quando consultados sem base estruturada nem trechos de bula, tomando o DDInter como rótulo.

**Tabela 6 – Modelo de linguagem sem recuperação (60 pares; rótulo: DDInter)**

| Modelo | VP / FP | VN / FN | Abstenção | Precisão | Recall | F1 | Acurácia (IC 95%) |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Qwen2.5-1.5B-Instruct (rótulo com 8 grupos) | 33 / 18 | 3 / 6 | 0 | 0,647 | 0,846 | 0,733 | 60,0% (47,4–71,4) |
| Claude Sonnet 5.5 (rótulo com 8 grupos) | 19 / 11 | 10 / 18 | 2 | 0,633 | 0,514 | 0,567 | 48,3% (36,2–60,7) |
| Claude Sonnet 5.5 (rótulo com 14 grupos) | 30 / 0 | 3 / 26 | 1 | 1,000 | 0,536 | 0,698 | 55,0% (42,5–66,9) |

Fonte: Elaborado pelos autores a partir dos experimentos (2026).
Nota: a acurácia considera as abstenções como erro. Um classificador trivial que sempre indicasse interação teria acurácia de 65,0% (39/60) com o rótulo de 8 grupos e de 95,0% (57/60) com o de 14 grupos. As duas linhas do Claude com 8 grupos e com 14 grupos vêm de execuções distintas sobre os mesmos 60 pares (7 e 8 nov. 2026); repontuadas com o rótulo de 14 grupos, as respostas de 7 nov. dão VP 30, FP 0, VN 3, FN 25 e 2 abstenções, o que indica pouca variação entre execuções. IC 95%: precisão 88,6%–100%; recall 40,7%–66,0%.

O modelo local apontou interação em quase todos os pares e elevou onze interações moderadas ou leves a grave: dos 17 pares com gravidade classificada que reconheceu, acertou a gravidade de apenas 4. Com o rótulo de 8 grupos, o modelo de produção parecia errar muito: 11 falsos positivos e acurácia abaixo da do classificador trivial. A análise par a par já indicava que vários desses falsos positivos eram interações descritas na farmacologia, como diazepam com fenobarbital, atenolol com propranolol e sertralina com clonazepam, ausentes da base por envolverem fármacos dos grupos então não importados.

A ampliação da base confirmou essa leitura. Com o rótulo de 14 grupos, os 11 falsos positivos desapareceram: toda interação apontada pelo modelo de produção está registrada no DDInter (precisão de 1,000). Ou seja, o erro estava no rótulo, e não no modelo. O modelo também concordou com a gravidade do DDInter em 21 dos 26 pares classificados que reconheceu (80,8%; IC 95%: 62,1%–91,5%). Sua falha é de outra natureza: deixou de reconhecer 26 das 56 interações registradas sobre as quais se pronunciou (recall de 0,536). Dessas 26, 24 são pares que o DDInter registra sem gravidade classificada, como amoxicilina com haloperidol ou ácido acetilsalicílico com rosuvastatina, e 2 são moderadas (atorvastatina com metronidazol e clonazepam com carvedilol). A acurácia de 55,0%, abaixo dos 95,0% do classificador trivial, reflete sobretudo a amostra, que passou a ter só 3 pares sem interação, e não deve ser lida isoladamente.

Esse resultado tem duas consequências. A primeira reforça a crítica à avaliação da seção 5.2: um rótulo extraído de uma base incompleta pode fazer um acerto parecer erro, e só a ampliação da base revelou isso. A segunda sustenta a arquitetura adotada, agora com um motivo quantitativo: sem recuperação, o modelo de produção é conservador — quando afirma uma interação, ela existe na base —, mas deixa passar quase metade das interações registradas, justamente as de relevância menos estabelecida, que a consulta à base estruturada detecta em todos os casos. Além disso, suas respostas não indicam fonte, mudam entre modelos — o modelo local e o de produção divergiram de forma oposta nos mesmos pares — e não podem ser auditadas. Com a divisão de responsabilidades, cada resposta do BULA-IA pode ser rastreada até o registro do DDInter e o trecho de bula que a sustentam, e as lacunas ficam visíveis como lacunas da fonte, em vez de serem preenchidas por conhecimento paramétrico não verificável. A comparação continua descritiva: com 60 pares e só 3 negativos, ela mostra o risco que a arquitetura evita, e não uma superioridade estatística demonstrada.

### 5.7 Síntese: o que os resultados mostram e o que não mostram

O Quadro 6 resume o alcance de cada resultado.

**Quadro 6 – Alcance dos resultados**

| Aspecto | O que foi demonstrado | O que não foi demonstrado |
| --- | --- | --- |
| Identificação | Acerto exato de 89,4% (IC 95%: 77,4–95,4) em consultas não usadas nos ajustes | Desempenho em larga escala e em nomes digitados com erro por usuários reais |
| Interações | Integração correta com o DDInter em 1.483 pares, sem abstenção; a ampliação para 14 grupos revelou que 89% dos negativos anteriores eram lacunas | Correção clínica; cobertura integral do DDInter |
| Recuperação | Indício favorável à busca híbrida | Superioridade da busca híbrida com amostra adequada |
| Geração | Validador impede saídas malformadas ou sem trecho; 8 de 12 explicações aceitas com o modelo de produção | Correção factual das explicações |
| Legibilidade | Respostas mais legíveis que os trechos citados em 66 de 66 pares | Compreensão efetiva pelos usuários |
| Modelo isolado | Sem fonte e não auditável; com 14 grupos, precisão de 1,000, mas recall de 0,536 | Que o modelo isolado erre mais que a base em termos clínicos |
| Validação humana | — | Avaliação por profissional de saúde e usabilidade (SUS): não realizadas |

Fonte: Elaborado pelos autores (2026).

Os resultados sustentam parcialmente a hipótese da Introdução. Com a decisão sobre a interação fora do modelo, as respostas tornaram-se rastreáveis, a gravidade passou a ser a da fonte, e, diante da falta de informação, o sistema preferiu a abstenção, comportamento seguro, a completar lacunas. As respostas também se mostraram mais legíveis que a bula. Por outro lado, a hipótese de uma detecção “mais confiável” não pôde ser verificada em termos clínicos, pois o único gabarito disponível é a própria base consultada, cuja cobertura é parcial — como mostrou a ampliação de 8 para 14 grupos, que mudou 486 rótulos. Sobretudo, a validação por profissional de saúde não foi realizada; logo, nenhuma explicação teve a correção factual verificada.

## 6 CONSIDERAÇÕES FINAIS

Este trabalho investigou como os LLMs, apoiados por recuperação de fontes oficiais, podem apoiar a compreensão das bulas e a identificação de interações medicamentosas de forma rastreável. A arquitetura mostrou-se tecnicamente viável quando a base estruturada decide sobre a interação, o RAG recupera os trechos de bula e o modelo apenas os explica, sob um validador de fundamentação.

Quanto aos objetivos específicos, as bulas da ANVISA, o catálogo de medicamentos e o DDInter foram reunidos, e as bulas, indexadas em PostgreSQL para busca lexical, vetorial e híbrida. Na identificação dos medicamentos, o sistema chegou aos princípios ativos corretos em 89,4% das consultas reservadas, com intervalo de confiança entre cerca de 77% e 95%. O teste de integração com o DDInter confirmou que a aplicação reproduz fielmente a base nos 1.483 pares avaliados, inclusive as 110 interações graves; o mesmo teste revelou, numa versão anterior, a perda de uma interação grave por falha de identificação, o risco mais sério do sistema. A ampliação da base de 8 para 14 grupos ATC mostrou que 486 dos 545 pares antes tidos como sem interação eram lacunas de cobertura, o que evidencia que a qualidade da fonte limita a do sistema. Na geração, nenhuma explicação malformada ou sem trecho chegou ao usuário, e o modelo de produção teve 8 de 12 explicações aceitas pelo validador. As respostas foram mais legíveis que os trechos de bula citados em todos os 66 pares analisados. Sem recuperação, o modelo de produção não apontou interações inexistentes na base, mas deixou de reconhecer quase metade das registradas, e respondeu sem fonte, o que reforça a separação entre quem decide e quem explica.

A contribuição está na forma de recuperar e apresentar a informação, e não em uma nova base farmacológica, pois cada resposta pode ser rastreada até sua fonte. Como limitações, a base reúne 18 bulas, que cobrem só 6 dos 55 produtos avaliados; o DDInter importado, mesmo com 14 grupos ATC, não equivale à exportação integral da base, de modo que a ausência de registro jamais pode ser comunicada como segurança; o gabarito de interações provém da própria base consultada, o que impede medir a correção clínica; e a compreensão dos usuários, que motivou o problema, foi estimada apenas por um índice de legibilidade, e não medida.

Como trabalhos futuros, propõem-se: a validação das respostas por profissional de saúde e a avaliação de usabilidade e compreensão com a escala SUS junto a usuários leigos, após consulta ao Comitê de Ética em Pesquisa sobre o enquadramento dessas atividades; um gabarito de interações independente do DDInter, revisado por profissional; a importação da exportação integral do DDInter e de critérios específicos para idosos; amostras maiores de recuperação, geração e legibilidade, com julgamentos independentes; e a ampliação da base de bulas. Assim, o BULA-IA pode consolidar-se como camada de acesso rastreável à informação sobre medicamentos, sem substituir a orientação profissional.

## REFERÊNCIAS

AGÊNCIA NACIONAL DE VIGILÂNCIA SANITÁRIA. **Bulário Eletrônico**. Brasília, DF: Anvisa, 2020a. Disponível em: https://www.gov.br/anvisa/pt-br/sistemas/bulario-eletronico. Acesso em: 6 nov. 2026.

AGÊNCIA NACIONAL DE VIGILÂNCIA SANITÁRIA. **Perguntas e respostas sobre bulas**. Brasília, DF: Anvisa, 2020b. Disponível em: https://www.gov.br/anvisa/pt-br/assuntos/medicamentos/bulas-e-rotulos/perguntas-e-respostas-sobre-bulas. Acesso em: 6 nov. 2026.

AGÊNCIA NACIONAL DE VIGILÂNCIA SANITÁRIA. **Medicamentos registrados no Brasil**. Brasília, DF: Anvisa, 2026. Conjunto de dados (arquivo DADOS_ABERTOS_MEDICAMENTOS.csv). Disponível em: https://dados.anvisa.gov.br/dados/DADOS_ABERTOS_MEDICAMENTOS.csv. Acesso em: 6 nov. 2026.

BRASIL. Agência Nacional de Vigilância Sanitária. Resolução RDC nº 47, de 8 de setembro de 2009. Estabelece regras para elaboração, harmonização, atualização, publicação e disponibilização de bulas de medicamentos para pacientes e para profissionais de saúde. **Diário Oficial da União**: seção 1, Brasília, DF, n. 172, p. 31, 9 set. 2009.

BRASIL. Conselho Nacional de Saúde. Resolução nº 510, de 7 de abril de 2016. Dispõe sobre as normas aplicáveis a pesquisas em Ciências Humanas e Sociais. **Diário Oficial da União**: seção 1, Brasília, DF, n. 98, p. 44-46, 24 maio 2016.

BRASIL. Lei nº 13.709, de 14 de agosto de 2018. Dispõe sobre a proteção de dados pessoais e altera a Lei nº 12.965, de 23 de abril de 2014 (Marco Civil da Internet). **Diário Oficial da União**: seção 1, Brasília, DF, n. 157, p. 59, 15 ago. 2018.

BROOKE, John. SUS: a "quick and dirty" usability scale. *In*: JORDAN, Patrick W. *et al*. (ed.). **Usability evaluation in industry**. London: Taylor & Francis, 1996. p. 189-194.

BRUNTON, Laurence L.; HILAL-DANDAN, Randa; KNOLLMANN, Björn C. (org.). **As bases farmacológicas da terapêutica de Goodman e Gilman**. 13. ed. Porto Alegre: AMGH, 2018.

CHEN, Jianlv *et al*. M3-Embedding: multi-linguality, multi-functionality, multi-granularity text embeddings through self-knowledge distillation. *In*: ANNUAL MEETING OF THE ASSOCIATION FOR COMPUTATIONAL LINGUISTICS, 62., 2024, Bangkok. **Findings of the Association for Computational Linguistics**: ACL 2024. Bangkok: Association for Computational Linguistics, 2024. p. 2318-2335. DOI: 10.18653/v1/2024.findings-acl.137.

CORMACK, Gordon V.; CLARKE, Charles L. A.; BÜTTCHER, Stefan. Reciprocal rank fusion outperforms Condorcet and individual rank learning methods. *In*: INTERNATIONAL ACM SIGIR CONFERENCE ON RESEARCH AND DEVELOPMENT IN INFORMATION RETRIEVAL, 32., 2009, Boston. **Proceedings** [...]. New York: Association for Computing Machinery, 2009. p. 758-759. DOI: 10.1145/1571941.1572114.

DRUGS.COM. **Drug interaction checker**. [*S. l.*]: Drugs.com, c2026. Disponível em: https://www.drugs.com/drug_interactions.html. Acesso em: 6 nov. 2026.

FLESCH, Rudolf. A new readability yardstick. **Journal of Applied Psychology**, Washington, v. 32, n. 3, p. 221-233, 1948. DOI: 10.1037/h0057532.

FUJITA, Patricia Lopes; MACHADO, Carlos José Saldanha; TEIXEIRA, Márcia de Oliveira. A bula de medicamentos e a regulação de suas configurações em termos de forma e conteúdo no Brasil. **Saúde e Sociedade**, São Paulo, v. 23, n. 1, p. 277-292, 2014. DOI: 10.1590/S0104-12902014000100022.

KATZUNG, Bertram G.; TREVOR, Anthony J. (org.). **Farmacologia básica e clínica**. 13. ed. Porto Alegre: AMGH, 2017.

KNOX, Craig *et al*. DrugBank 6.0: the DrugBank knowledgebase for 2024. **Nucleic Acids Research**, Oxford, v. 52, n. D1, p. D1265-D1275, 2024. DOI: 10.1093/nar/gkad976.

LEWIS, Patrick *et al*. Retrieval-augmented generation for knowledge-intensive NLP tasks. *In*: CONFERENCE ON NEURAL INFORMATION PROCESSING SYSTEMS, 34., 2020, Vancouver. **Advances in Neural Information Processing Systems**. Red Hook: Curran Associates, 2020. v. 33, p. 9459-9474. Disponível em: https://proceedings.neurips.cc/paper/2020/hash/6b493230205f780e1bc26945df7481e5-Abstract.html. Acesso em: 6 nov. 2026.

MARTINS, Teresa B. F. *et al*. **Readability formulas applied to textbooks in Brazilian Portuguese**. São Carlos: ICMC-USP, 1996. (Notas do ICMC, n. 28).

MICROMEDEX. **Drug interactions**. Ann Arbor: Merative, c2026. Base de dados. Disponível em: https://www.micromedexsolutions.com. Acesso em: 6 nov. 2026.

RUSSELL, Stuart J.; NORVIG, Peter. **Artificial intelligence**: a modern approach. 4. ed. Hoboken: Pearson, 2020.

SILVA, M. *et al*. Estudo da bula de medicamentos: uma análise da situação. **Revista de Ciências Farmacêuticas Básica e Aplicada**, Araraquara, v. 27, n. 3, p. 229-236, 2006.

VASWANI, Ashish *et al*. Attention is all you need. *In*: CONFERENCE ON NEURAL INFORMATION PROCESSING SYSTEMS, 31., 2017, Long Beach. **Advances in Neural Information Processing Systems**. Red Hook: Curran Associates, 2017. v. 30, p. 5998-6008. Disponível em: https://proceedings.neurips.cc/paper/2017/hash/3f5ee243547dee91fbd053c1c4a845aa-Abstract.html. Acesso em: 6 nov. 2026.

WILSON, Edwin B. Probable inference, the law of succession, and statistical inference. **Journal of the American Statistical Association**, [*S. l.*], v. 22, n. 158, p. 209-212, 1927. DOI: 10.1080/01621459.1927.10502953.

WORLD HEALTH ORGANIZATION. **The importance of pharmacovigilance**: safety monitoring of medicinal products. Geneva: WHO, 2002. Disponível em: https://www.who.int/publications/i/item/10665-42493. Acesso em: 6 nov. 2026.

WORLD HEALTH ORGANIZATION. **Medication without harm**: WHO global patient safety challenge. Geneva: WHO, 2017. (WHO/HIS/SDS/2017.6). Disponível em: https://www.who.int/publications/i/item/WHO-HIS-SDS-2017.6. Acesso em: 6 nov. 2026.

WORLD HEALTH ORGANIZATION. **Medication safety in polypharmacy**: technical report. Geneva: WHO, 2019. (WHO/UHC/SDS/2019.11). Disponível em: https://www.who.int/publications/i/item/WHO-UHC-SDS-2019.11. Acesso em: 6 nov. 2026.

XIONG, Guoli *et al*. DDInter: an online drug–drug interaction database towards improving clinical decision-making and patient safety. **Nucleic Acids Research**, Oxford, v. 50, n. D1, p. D1200-D1207, 2022. DOI: 10.1093/nar/gkab880.

YANG, An *et al*. **Qwen2.5 technical report**. [*S. l.*]: arXiv, 2024. Preprint arXiv:2412.15115. DOI: 10.48550/arXiv.2412.15115. Disponível em: https://arxiv.org/abs/2412.15115. Acesso em: 6 nov. 2026.

ZHAO, Wayne Xin *et al*. **A survey of large language models**. [*S. l.*]: arXiv, 2023. Preprint arXiv:2303.18223. DOI: 10.48550/arXiv.2303.18223. Disponível em: https://arxiv.org/abs/2303.18223. Acesso em: 6 nov. 2026.

## APÊNDICE A – RECORTE DA BASE AVALIADA

**Quadro A.1 – Bulas da base na avaliação final (7 nov. 2026)**

| Nº | Princípio(s) ativo(s) | Tipo de bula | Trechos | Versão (início do *hash* do conteúdo) | Vinculada a princípio ativo no sistema |
| --- | --- | --- | --- | --- | --- |
| 1 | Azitromicina di-hidratada | Paciente | 15 | fff8adf8983d | Sim |
| 2 | Pantoprazol sódico sesqui-hidratado | Profissional | 44 | 0e10e7d1d237 | Sim |
| 3 | Omeprazol sódico 40 mg | Profissional | 27 | c86ce1333e68 | Sim |
| 4 | Omeprazol | Profissional | 93 | 3f53898fddc2 | Sim |
| 5 | Sinvastatina | Profissional | 44 | 8e5dea95bb2b | Sim |
| 6 | Amoxicilina + clavulanato de potássio | Profissional | 76 | caa802b748fc | Sim |
| 7 | Nimesulida | Profissional | 29 | 65939b77e9f1 | Não |
| 8 | Ibuprofeno | Paciente | 37 | 3a80239e96c7 | Sim |
| 9 | Não identificado no título (documento “Bula 1790900554429”) | Profissional | 19 | 8e9020baa136 | Não |
| 10 | Omeprazol sódico 40 mg | Paciente | 29 | 3a8b6353da99 | Não |
| 11 | Omeprazol | Paciente | 45 | 83bfa638fba8 | Sim |
| 12 | Paracetamol | Profissional | 26 | 9b845ee53d3c | Sim |
| 13 | Levotiroxina sódica | Profissional | 35 | 0c121c749e72 | Não |
| 14 | Paracetamol + fosfato de codeína | Profissional | 61 | 1981b3beff3c | Sim |
| 15 | Paracetamol + codeína | Profissional | 100 | d2c9ee33b02d | Sim |
| 16 | Paracetamol + cloridrato de pseudoefedrina | Profissional | 65 | 002d3d176eb8 | Sim |
| 17 | Ibuprofeno | Profissional | 37 | 8d1dda657920 | Sim |
| 18 | Nimesulida | Paciente | 17 | 40fcc7d3f951 | Sim |
| | **Total** | 13 profissional, 5 paciente | **799** | | 14 de 18 |

Fonte: Elaborado pelos autores a partir da base do sistema (2026).
Nota: as bulas 1 a 17 foram processadas em 18 out. 2026 e a 18 em 6 nov. 2026. Antes da publicação, recomenda-se completar este quadro com a data de publicação de cada bula no Bulário e identificar o documento 9.

**Quadro A.2 – Produtos da avaliação de interações (55) e cobertura**

| Produto | Princípio ativo (DDInter) | Bula na base |
| --- | --- | --- |
| Marevan | warfarin | Não |
| Tylenol | acetaminophen | Sim |
| Aspirina | acetylsalicylic acid | Não |
| AAS | acetylsalicylic acid | Não |
| Losartana potássica | losartan | Não |
| Cataflam | diclofenac | Não |
| Voltaren | diclofenac | Não |
| Omeprazol | omeprazole | Sim |
| Pantoprazol | pantoprazole | Sim |
| Amoxicilina | amoxicillin | Sim |
| Azitromicina | azithromycin | Sim |
| Ciprofloxacino | ciprofloxacin | Não |
| Glifage | metformin | Não |
| Diovan | valsartan | Não |
| Norvasc | amlodipine | Não |
| Atenolol | atenolol | Não |
| Captopril | captopril | Não |
| Maleato de enalapril | enalapril | Não |
| Lasix | furosemide | Não |
| Hidroclorotiazida | hydrochlorothiazide | Não |
| Cloridrato de sertralina | sertraline | Não |
| Cloridrato de fluoxetina | fluoxetine | Não |
| Rivotril | clonazepam | Não |
| Diazepam | diazepam | Não |
| Haldol | haloperidol | Não |
| Tegretol | carbamazepine | Não |
| Gardenal | phenobarbital | Não |
| Lamictal | lamotrigine | Não |
| Neurontin | gabapentin | Não |
| Prednisona | prednisone | Não |
| Dexametasona | dexamethasone | Não |
| Digoxina | digoxin | Não |
| Cloridrato de amiodarona | amiodarone | Não |
| Bissulfato de clopidogrel | clopidogrel | Não |
| Xarelto | rivaroxaban | Não |
| Rosuvastatina cálcica | rosuvastatin | Não |
| Atorvastatina cálcica | atorvastatin | Não |
| Viagra | sildenafil | Não |
| Fluconazol | fluconazole | Não |
| Cetoconazol | ketoconazole | Não |
| Itraconazol | itraconazole | Não |
| Metronidazol | metronidazole | Não |
| Claritromicina | clarithromycin | Não |
| Estolato de eritromicina | erythromycin | Não |
| Ibuprofeno | ibuprofen | Sim |
| Naproxeno | naproxen | Não |
| Meloxicam | meloxicam | Não |
| Cloridrato de tramadol | tramadol | Não |
| Cloridrato de amitriptilina | amitriptyline | Não |
| Carvedilol | carvedilol | Não |
| Propranolol | propranolol | Não |
| Espironolactona | spironolactone | Não |
| Cloridrato de metoclopramida | metoclopramide | Não |
| Ondansetrona | ondansetron | Não |
| Loratadina | loratadine | Não |

Fonte: Elaborado pelos autores a partir dos conjuntos de teste (2026).
Nota: excluídos por ausência do princípio ativo esperado no DDInter: Lexotan (bromazepam), Dorflex, Novalgina e Benegrip (dipirona). Pares com bula de pelo menos um fármaco: 309 de 1.483; de ambos: 15.

## APÊNDICE B – REGISTROS DE EXECUÇÃO DOS EXPERIMENTOS

Todos os registros estão em `storage/app/evaluation/runs/` e guardam a versão do código, o conjunto de 18 bulas (*hash* `8c2eff89d180…`) e os *hashes* SHA-256 dos arquivos do DDInter usados. Os de 7 nov. 2026 usam o código `272b765` sem alterações locais e os 8 grupos do DDInter; os de 8 nov. 2026 usam a mesma versão com a correção do avaliador do modelo sem recuperação e os 14 grupos.

| Experimento | Registro | Data |
| --- | --- | --- |
| Identificação (ajuste) | `20261107-172905-tcc-identification-v1-7786f8d3.json` | 7 nov. 2026 |
| Identificação (reservado) | `20261107-172906-tcc-identification-heldout-d5196b2e.json` | 7 nov. 2026 |
| Interações (1.483 pares, 14 grupos) | `20261108-181838-ddinter14-v2-a577c9d1.json` | 8 nov. 2026 |
| Interações (protocolo de 30 pares, busca híbrida, 14 grupos) | `20261108-192514-ddinter14-protocol30-hybrid-b1b39c1f.json` | 8 nov. 2026 |
| Geração (Claude Sonnet 5.5, 14 grupos) | `20261108-182107-ddinter14-generation-claude-22d34939.json` | 8 nov. 2026 |
| Modelo sem recuperação (Claude, 14 grupos, resultado por par) | `20261108-192731-ddinter14-baseline-norag-claude-035df9dd.json` | 8 nov. 2026 |
| Respostas de 7 nov. repontuadas com o rótulo de 14 grupos | `20261108-baseline-norag-claude-rescored-v2.json` | 8 nov. 2026 |
| Interações (1.483 pares, 8 grupos) | `20261107-172952-tcc-interactions-v1-9bfebe2e.json` | 7 nov. 2026 |
| Interações (protocolo de 30 pares, busca híbrida, 8 grupos) | `20261107-172958-tcc-interactions-protocol30-hybrid-cf0ebd77.json` | 7 nov. 2026 |
| Recuperação (três modos) | `20261107-173000-tcc-retrieval-modes-a8f42789.json` | 7 nov. 2026 |
| Geração (Claude Sonnet 5.5, 8 grupos) | `20261107-173630-tcc-generation-claude-1a0500e8.json` | 7 nov. 2026 |
| Modelo sem recuperação (Claude, limite de 60 *tokens*; descartado) | `20261107-173148-tcc-baseline-norag-claude-ab1754b6.json` | 7 nov. 2026 |
| Modelo sem recuperação (Claude, limite de 1.024 *tokens*, 8 grupos, resultado por par) | `20261107-baseline-norag-claude-1024-items.json` | 7 nov. 2026 |
| Geração (Qwen2.5-1.5B) | `20261104-002136-generation-qwen15b-be2a4984.json` | 4 nov. 2026 |
| Modelo sem recuperação (Qwen2.5-1.5B) | `20261104-002240-baseline-norag-qwen15b-2d16a6f2.json` | 4 nov. 2026 |
| Versão anterior das interações (54 não identificados) | `20261104-002352-interactions-lexical-final-0576d52b.json` | 4 nov. 2026 |
| Legibilidade (respostas do motor de perguntas) | tabela `medication_questions`, versão de *prompt* `interaction-explanation-v3:qa` | 7 nov. 2026 |
