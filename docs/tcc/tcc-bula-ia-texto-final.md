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

As bulas de medicamentos usam linguagem técnica e extensa, de difícil compreensão para os pacientes, e a polifarmácia amplia o risco de interações medicamentosas. Este trabalho desenvolveu e avaliou o BULA-IA, aplicação web progressiva que combina as bulas do Bulário Eletrônico da Agência Nacional de Vigilância Sanitária, a base de interações DDInter e um modelo de linguagem de grande escala (LLM) com geração aumentada por recuperação (RAG). Na arquitetura proposta, a existência e a gravidade de uma interação são decididas pela base estruturada; o RAG recupera trechos de bula, e o modelo apenas os explica, sob uma validação heurística de ancoragem textual. A avaliação, automatizada e versionada, foi executada no servidor de produção em 11 nov. 2026, sobre a base publicada: 82 bulas divididas em 5.652 trechos, 39.416 registros do catálogo da ANVISA e 234.981 interações do DDInter. A identificação dos princípios ativos acertou 93,6% das consultas reservadas (IC 95%: 82,8%–97,8%). A verificação de 1.483 pares não produziu falso positivo, mas deixou de acusar 81 interações registradas, 5 delas graves, e se absteve em 25 pares; a causa foram princípios ativos duplicados em português, criados ao vincular bulas antes do catálogo, que desligavam dois produtos do DDInter — defeito de dados corrigido após a medição. Na recuperação por assunto (369 consultas), a busca híbrida trouxe um trecho da seção certa entre os três primeiros em 40,1% das consultas, contra 20,6% da lexical. Em 234 perguntas ao sistema publicado, 170 das 196 sobre bulas brasileiras foram respondidas, 55,6% das 820 afirmações propostas pelo modelo passaram na validação e nenhum trecho cru foi exibido; as respostas foram mais legíveis que os trechos que as sustentam em 98,1% de 269 pares pelo Índice de Flesch adaptado (mediana de 58,3 contra 29,4), o que mede a forma do texto, e não a compreensão. Sem recuperação, o mesmo modelo não apontou interações ausentes da base, mas não reconheceu 50 das 114 interações registradas sobre as quais se pronunciou. Não houve avaliação por profissionais de saúde nem por usuários.

**Palavras-chave:** Bulas de medicamentos. Interações medicamentosas. Modelos de linguagem de grande escala. Geração aumentada por recuperação.

## ABSTRACT

Medication package inserts use technical, lengthy language that is hard for patients to understand, and polypharmacy increases the risk of drug interactions. This study developed and evaluated BULA-IA, a progressive web application that combines the package inserts of the Brazilian Health Regulatory Agency, the DDInter drug interaction database and a large language model (LLM) with retrieval-augmented generation (RAG). In the proposed architecture, the existence and severity of an interaction are decided by the structured database; RAG retrieves package insert passages, and the model only explains them, under a heuristic textual-anchoring validation. The automated, versioned evaluation was run on the production server on 11 Nov 2026, over the published data: 82 package inserts split into 5,652 passages, 39,416 records of the ANVISA catalogue and 234,981 DDInter interactions. Active ingredient identification was correct in 93.6% of held-out queries (95% CI: 82.8%–97.8%). Checking 1,483 pairs produced no false positive, but missed 81 recorded interactions, 5 of them major, and abstained on 25 pairs; the cause was duplicated active ingredients in Portuguese, created when package inserts were linked before the catalogue, which disconnected two products from DDInter — a data defect fixed after the measurement. In subject-based retrieval (369 queries), hybrid search placed a passage from the right section among the top three in 40.1% of the queries, against 20.6% for lexical search. In 234 questions put to the published system, 170 of the 196 about Brazilian package inserts were answered, 55.6% of the 820 statements proposed by the model passed the validation and no raw passage was shown; answers were more readable than their supporting passages in 98.1% of 269 pairs according to the Portuguese-adapted Flesch Reading Ease (median 58.3 versus 29.4), a measure of text form rather than comprehension. Without retrieval, the same model flagged no interaction absent from the database but did not recognise 50 of the 114 recorded interactions it assessed. No evaluation by health professionals or users was carried out.

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
d) integrar a recuperação a um LLM com instruções que exijam a citação dos trechos e a recomendação de consulta a um profissional de saúde, sob uma validação heurística de ancoragem textual;
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

Todos os dados deste trabalho foram medidos na instância de produção do sistema, publicada em `https://bulai.vixforge.com.br`, em 11 nov. 2026; nenhum resultado vem do ambiente de desenvolvimento. As bulas, em PDF, foram obtidas no Bulário Eletrônico da ANVISA (AGÊNCIA NACIONAL DE VIGILÂNCIA SANITÁRIA, 2020a) e inseridas pela administração do sistema, com revisão humana da classificação, da identificação do medicamento e do vínculo de cada documento. Na data da avaliação, a base reunia 83 documentos, dos quais 82 bulas prontas para consulta — 38 destinadas a profissionais de saúde, 33 a pacientes e 11 sem classificação de público —, divididas em 5.652 trechos, todos com *embedding*. Todas as 82 bulas estavam vinculadas a princípio ativo, num total de 56 princípios ativos com bula. A lista completa, com o título, os princípios ativos vinculados, o tipo de bula, o número de trechos e o *hash* de cada documento, está no Apêndice A.

O vínculo entre bula e princípio ativo é o caminho pelo qual o sistema chega às bulas: tanto as perguntas livres quanto a verificação de interações buscam trechos apenas nas bulas vinculadas aos princípios ativos do medicamento consultado (`app/Services/Interactions/PairEvidenceRetriever.php`). Por isso, a administração permite corrigir a identificação e o vínculo de cada bula. A avaliação revelou um defeito nesse caminho. As bulas processadas antes de o catálogo conhecer o nome em português de seu princípio ativo criaram registros novos, em português e sem nenhuma interação, como “Ibuprofeno”, “Besilato De Anlodipino” e “Losartana Potássica”; a reconstrução seguinte do catálogo encontrou esses nomes e passou a ligar a eles os medicamentos da ANVISA, em vez dos princípios ativos do DDInter (*ibuprofen*, *amlodipine*, *losartan*). Dos 56 princípios ativos com bula, portanto, alguns são duplicados de outros. O efeito desse defeito é medido nas seções 5.1 e 5.2; sua correção, que reaproveita o princípio ativo existente e funde os duplicados no do DDInter, foi feita depois da medição e é descrita no Apêndice B.

Para associar o nome comercial informado pelo usuário aos princípios ativos, utilizou-se o catálogo de medicamentos da ANVISA disponível no Portal de Dados Abertos (AGÊNCIA NACIONAL DE VIGILÂNCIA SANITÁRIA, 2026), com 39.416 registros; na avaliação, 25.682 deles tinham ao menos um princípio ativo identificado, 5.826 tinham bula de algum de seus princípios ativos e 4.492, de todos. As interações vêm do DDInter (XIONG et al., 2022), cujos arquivos de exportação são organizados por grupo anatômico da classificação ATC. Foram importados os 14 grupos — A, B, C, D, G, H, J, L, M, N, P, R, S e V —, conferidos pelo *hash* SHA-256 de cada arquivo, com 234.981 pares de fármacos: 39.082 com gravidade *major*, 143.748 *moderate*, 9.736 *minor* e 42.415 *unknown*; 234.333 deles estavam ligados a princípios ativos do catálogo. Desses pares, 23.087 envolvem um princípio ativo com bula na base, e 613 ocorrem entre dois princípios ativos com bula. Mesmo com os 14 grupos, a base não equivale à exportação integral do DDInter 2.0, que declara 302.516 interações; a ausência de registro, portanto, não significa ausência de interação. O projeto previa o DrugBank 6.0, substituído por exigir licença para uso além do acadêmico.

Quando um medicamento não tem bula brasileira na base, o sistema pode recorrer à bula norte-americana do mesmo princípio ativo, obtida da base pública openFDA e traduzida, e a resposta avisa que a informação vem da bula dos Estados Unidos. Na avaliação, havia 42 dessas bulas prontas, e 12 princípios ativos não foram encontrados nela.

Como o catálogo usa nomes em português e o DDInter em inglês, foi criada uma tabela de correspondência entre os dois idiomas, construída por regras de grafia e, para os nomes não resolvidos pelas regras, por tradução assistida pelo modelo de linguagem, com registro das traduções para revisão. Por fim, como o DDInter registra apenas a gravidade, a explicação de cada interação é buscada nas bulas, e os níveis *major*, *moderate* e *minor* foram tratados como grave, moderada e leve, e *unknown* como gravidade não classificada pela fonte (Quadro 1).

O recorte tem uma consequência que precisa ser explicitada antes dos resultados: a cobertura do sistema não é uma capacidade única, mas três dimensões distintas, que não devem ser somadas.

a) Cobertura da base de interações: dos 59 produtos do conjunto de ajuste, 55 têm o princípio ativo esperado no DDInter (seção 3.5.2); dos 1.483 pares formados por eles, 1.424 têm interação registrada. A própria base importada, com 234.981 pares, não equivale às 302.516 interações declaradas pelo DDInter 2.0.
b) Cobertura de bulas: dos 55 produtos, 25 têm bula de algum de seus princípios ativos na base; dos 1.483 pares, 1.048 têm bula de pelo menos um dos fármacos, e 298, de ambos, segundo a contagem do próprio avaliador no banco medido.
c) Disponibilidade de evidência para a explicação: ter bula não basta, pois é preciso que uma bula vinculada a um dos fármacos contenha trecho que cite o outro. Essa proporção não foi medida sobre os 1.483 pares.

Assim, para parte dos pares, o sistema pode informar a existência e a gravidade registradas no DDInter, mas não dispõe de trecho de bula para explicá-las.

### 3.4 Desenvolvimento

O desenvolvimento ocorreu de setembro a novembro de 2026, com o código versionado em Git, em cinco fases: (i) ingestão e leitura das bulas; (ii) importação do catálogo da ANVISA, OCR e infraestrutura de produção; (iii) importação do DDInter; (iv) recuperação, integração com o modelo de linguagem, validador e interface do usuário; e (v) montagem dos conjuntos de teste e execução dos experimentos. A arquitetura resultante é descrita na seção 4.

### 3.5 Procedimentos de avaliação

A avaliação foi feita por experimentos automatizados, todos executados no servidor de produção em 11 nov. 2026. Os experimentos sobre conjuntos de teste foram executados pelo comando do próprio sistema `evaluation:run`, chamado dentro do contêiner da aplicação (`sudo bulai-deploy artisan evaluation:run`), sobre o código na versão `947ea4e` e a base descrita em 3.3. As perguntas livres foram feitas ao sistema publicado pela sua API de avaliação, e a legibilidade foi calculada pela API de auditoria. Cada execução do comando grava um registro com o *hash* SHA-256 do conjunto de teste, os modelos utilizados, os parâmetros e os resultados; a versão do código não fica gravada, porque a imagem de produção não contém o repositório Git, e foi conferida pela publicação. Os registros e as instruções para reexecutá-los estão no Apêndice B. Os experimentos são resumidos no Quadro 3 e detalhados a seguir.

**Quadro 3 – Experimentos de avaliação (servidor de produção, 11 nov. 2026)**

| Experimento | O que mede | Amostra | Métricas |
| --- | --- | --- | --- |
| Identificação do medicamento | Associação do nome digitado aos princípios ativos | 59 consultas de ajuste e 47 reservadas | Acerto exato, precisão, recall e F1 |
| Verificação de interações | Integração da aplicação com o DDInter (teste de integração) | 1.483 pares de 55 produtos; protocolo de 30 pares | Matriz de confusão, abstenção, recall conservador e concordância de gravidade |
| Recuperação de trechos | Ordenação dos trechos das bulas do princípio ativo para a pergunta do paciente | 369 consultas (53 princípios ativos com bula × até 7 assuntos) | Acerto@k, P@k, R@k e MRR nos modos lexical, vetorial e híbrido |
| Perguntas livres ao sistema publicado | Identificação, fontes, validação e qualidade automática das respostas | 234 perguntas | Proporções com intervalo de confiança e tempo de resposta |
| Legibilidade | Facilidade de leitura das respostas em relação aos trechos que as sustentam | 269 pares resposta–trechos (todas as respostas registradas em produção) | Índice de Flesch adaptado ao português |
| Modelo sem recuperação | Comportamento do LLM sem base estruturada nem trechos | 120 pares sorteados (116 com e 4 sem interação registrada no DDInter) | Precisão, recall, F1, acurácia e concordância de gravidade |

Fonte: Elaborado pelos autores (2026).

#### 3.5.1 Identificação do medicamento

Os autores definiram manualmente, antes de qualquer medição, os princípios ativos esperados para dois conjuntos de consultas. O conjunto de ajuste, com 59 consultas presentes no catálogo, foi usado durante o desenvolvimento para corrigir o normalizador de nomes; o conjunto reservado, com 47 consultas de outros fármacos, foi medido sem que seus erros fossem corrigidos. A identificação foi medida no nível do princípio ativo, por precisão, recall e F1, e no nível da consulta, pelo acerto exato, que exige o mesmo conjunto de princípios ativos do gabarito. O gabarito usa os nomes do DDInter; um princípio ativo correto, mas registrado com outro nome, conta como erro.

#### 3.5.2 Verificação de interações: teste de integração com o DDInter

Dos 59 produtos do conjunto de ajuste, foram excluídos os 4 cujo princípio ativo esperado não existe no DDInter (Lexotan, Dorflex, Novalgina e Benegrip), restando 55. Foram formados todos os pares entre esses produtos, exceto os dois compostos por produtos com o mesmo princípio ativo (Aspirina e AAS; Cataflam e Voltaren), totalizando 1.483 pares, dos quais 1.424 têm interação registrada no DDInter e 59 não têm. Também foi aplicado o protocolo de 30 pares previsto no projeto, amostrado com semente fixa dos mesmos 1.483: 10 interações graves, 5 moderadas, 5 leves e 10 pares sem interação registrada. Por ter sido sorteado do mesmo universo de pares, o protocolo não constitui validação independente: é uma subamostra estratificada por gravidade do mesmo teste de integração.

O rótulo de cada par foi obtido consultando o DDInter com os princípios ativos do gabarito manual, e não com os identificados pelo sistema. Como o sistema consulta a mesma base, **este experimento é um teste de integração**: verifica se a cadeia produto → princípio ativo → par → registro no DDInter funciona, e não se a informação está clinicamente correta. Uma concordância de 100% é o resultado esperado de uma implementação e de dados corretos, e qualquer divergência indica falha de identificação, de vínculo ou de consulta. As respostas foram comparadas ao rótulo por precisão, recall e F1; as respostas inconclusivas (evidência insuficiente, medicamento não identificado ou erro técnico) foram contadas como abstenção e mantidas fora da matriz de confusão. Calcularam-se também o recall conservador, que conta as abstenções sobre pares positivos como erro, e a concordância de gravidade.

Há ainda uma sobreposição que limita esse experimento: os 55 produtos vêm do conjunto de ajuste da identificação, usado nas correções do sistema durante o desenvolvimento. O resultado, portanto, não estima o desempenho em produtos novos; essa estimativa cabe ao conjunto reservado da identificação.

#### 3.5.3 Recuperação de trechos

A recuperação foi avaliada por um conjunto de julgamentos por assunto, montado no próprio banco medido (`database/evaluation/retrieval.subjects.v1.json`). O conjunto lista 56 princípios ativos e sete assuntos de pergunta do paciente — indicação, posologia, efeitos indesejáveis, contraindicação, armazenamento, advertências e superdose —, cada um com um modelo de pergunta (por exemplo, “Como devo guardar o {nome}?”) e um padrão para o título das seções da bula que tratam do assunto. Para cada princípio ativo com bula pronta e cada assunto, a consulta é a pergunta preenchida com o nome em português, os trechos candidatos são os das bulas vinculadas ao princípio ativo, como faz o sistema, e os relevantes são os trechos dessas bulas cuja seção corresponde ao padrão do assunto. Combinações sem trecho relevante ficaram fora, o que resultou em 369 consultas sobre 53 princípios ativos. Os três modos de busca foram comparados sobre os mesmos candidatos, sem o filtro por seção que o sistema aplica nas perguntas livres, de modo que o experimento mede apenas a ordenação.

Foram usadas quatro métricas: o acerto@k, fração das consultas com pelo menos um trecho relevante entre os k primeiros, com intervalo de confiança; o P@k, fração dos k primeiros trechos que são relevantes; o R@k, fração dos trechos relevantes encontrados até a posição k; e o MRR, média do inverso da posição do primeiro trecho relevante. Como a relevância é decidida pelo título da seção, e não por leitura humana, os julgamentos não favorecem nenhum modo de busca, mas são aproximados: um trecho que responde à pergunta fora da seção esperada conta como erro, e uma seção longa tem muitos trechos relevantes, o que reduz o R@k.

#### 3.5.4 Perguntas livres ao sistema publicado

Para avaliar a geração com validação no uso previsto, foram feitas 234 perguntas ao sistema publicado, escritas pelos autores e enviadas uma por vez à API de avaliação (`POST /api/v1/evaluation/ask`), com uma conta própria e intervalo mínimo de 10,5 s entre elas: 196 sobre medicamentos com bula brasileira (49 medicamentos, cada um com 4 de 8 assuntos em rodízio: indicação, posologia, efeitos, contraindicação, armazenamento, gravidez, interação e superdose), 14 por nome comercial, 6 sobre medicamentos sem bula brasileira, 3 sobre nomes inexistentes, 2 com sinais de urgência, 1 fora do escopo e 6 conversas de dois turnos. As perguntas foram feitas em 11 nov. 2026, das 01h50 às 02h53, quando a produção estava na versão `ab1d851`, anterior à usada nos demais experimentos.

Para cada resposta, registraram-se a situação (respondida, pedido de esclarecimento, sem evidência, sem bula ou medicamento não encontrado), o medicamento identificado, a bula e a seção de cada fonte citada, o resultado do validador e uma rubrica automática. O validador aceita uma afirmação apenas se o trecho citado existir, se houver sobreposição lexical mínima de 40% com ele, se não houver recomendação de conduta (por exemplo, suspender ou ajustar dose) e se a afirmação não inverter uma negação do trecho nem narrar a fonte (“a bula diz”). Trata-se de uma validação heurística de ancoragem textual: o critério de sobreposição verifica se as palavras da afirmação estão no trecho, e não se o sentido foi preservado. A rubrica automática (`app/Services/Evaluation/ConversationEvaluator.php`) classifica a relevância, a ancoragem, a segurança, a clareza e a naturalidade de cada resposta por regras, e não substitui a leitura por profissional. Os scripts das perguntas e da análise estão versionados em `scripts/evaluation/`, e as respostas, em `docs/qa/`.

O experimento de geração de explicações para pares de interação (`evaluation:run generation`), previsto com 40 pares, não teve registro concluído em produção até a redação deste texto e não é apresentado; o validador usado nos pares é o mesmo das perguntas livres.

#### 3.5.5 Legibilidade

Calculou-se o Índice de Facilidade de Leitura de Flesch adaptado ao português (MARTINS et al., 1996) para cada resposta registrada em produção e para o conjunto dos trechos de bula que sustentam suas afirmações aceitas, formando 269 pares. O cálculo é feito pelo próprio sistema (`app/Services/Audit/ReadabilityService.php`, exposto em `GET /api/v1/audit/readability`): as frases são delimitadas por pontuação final (ponto, exclamação, interrogação, ponto e vírgula e dois-pontos) e por quebras de linha, e as sílabas são contadas por núcleos vocálicos. A mesma rotina é aplicada aos dois textos de cada par, o que torna a comparação válida mesmo que a contagem absoluta tenha pequenos desvios. O índice foi usado sem truncamento: os valores negativos, que a fórmula produz em textos de palavras muito longas, foram mantidos e contados na faixa “muito difícil”. As respostas incluem as 234 perguntas da seção 3.5.4 e as demais feitas ao sistema publicado, todas escritas pelos autores, e não por usuários reais.

#### 3.5.6 Modelo sem recuperação

Para estimar o comportamento do modelo de linguagem usado isoladamente, foram sorteados, com semente 42, 120 dos 1.483 pares, dos quais 116 têm interação registrada no DDInter e 4 não têm. Para cada par, o Claude Sonnet 5.5 recebeu apenas os nomes dos princípios ativos do gabarito, sem base estruturada nem trechos de bula, e respondeu em JSON se havia interação e qual a gravidade, com limite de 1.024 *tokens* por resposta. Como o rótulo é o próprio DDInter, as divergências do modelo foram analisadas por gravidade, distinguindo erros do modelo de lacunas da base.

#### 3.5.7 Tratamento estatístico

Para as proporções, foram calculados intervalos de confiança de 95% pelo método de Wilson (WILSON, 1927), adequado a amostras pequenas e a proporções próximas de 0 ou de 100%. Não se realizaram testes de hipótese entre modos de busca ou entre modelos; as comparações são descritivas e se apoiam na sobreposição dos intervalos.

### 3.6 Avaliações com pessoas não realizadas

Foram definidos dois protocolos: a aplicação da *System Usability Scale* (SUS) (BROOKE, 1996) a 5 a 10 voluntários leigos e a revisão, por profissional de farmácia ou medicina, dos 30 pares do protocolo e de explicações geradas. **Nenhuma dessas avaliações foi realizada.** Ambas envolvem participantes humanos e foram tratadas como sujeitas à apreciação por Comitê de Ética em Pesquisa, conforme a Resolução CNS nº 510/2016 (BRASIL, 2016); como essa apreciação não foi solicitada no prazo do trabalho, nenhum resultado deste texto deve ser lido como validação clínica ou de usabilidade, nem mesmo parcial. Ambas são retomadas como trabalhos futuros. Cabe notar que a SUS mede a usabilidade percebida, e não a compreensão: mesmo aplicada, ela não verificaria se o usuário entendeu o conteúdo da bula, o que exige perguntas de compreensão sobre o próprio conteúdo apresentado.

### 3.7 Limitações do recorte

O protótipo tem limitações que condicionam todos os resultados: a base reúne 82 bulas de 56 princípios ativos, dos quais alguns são duplicados em português, e 25 dos 55 produtos avaliados têm bula, de modo que a cobertura de interações, a de bulas e a de evidência para explicação diferem (seção 3.3); a base importada do DDInter não equivale à sua exportação integral; a ausência de uma interação na base não comprova que a combinação seja segura; os gabaritos e as perguntas foram elaborados pelos autores, sem validação profissional nem perguntas de usuários reais; a relevância dos trechos e a rubrica das respostas são automáticas; e cada experimento foi executado uma vez, numa única data, com um modelo de linguagem não determinístico. Além disso, o sistema não realiza diagnóstico nem substitui a orientação profissional.

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

Assim, a precisão da verificação de interações pertence à base estruturada, e não ao RAG: o RAG contribui com a explicação e a rastreabilidade, e o modelo só é acionado quando há trecho recuperado que cite o par. Quando a base não registra o par, a interface informa “Interação não identificada nas fontes consultadas”, e nunca que a combinação é segura; quando a gravidade não é classificada pela fonte, isso é dito explicitamente.

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
| 8 | Geração e validação | O LLM explica a interação em afirmações curtas, cada uma ligada a um trecho; a validação heurística de ancoragem textual descarta as que têm sobreposição lexical insuficiente com o trecho citado ou que recomendam conduta. Sem trecho, o modelo não é acionado. |

Fonte: Elaborado pelos autores (2026).

Para perguntas livres sobre um medicamento (por exemplo, “para que serve?” ou “pode machucar o estômago?”), o sistema identifica a intenção da pergunta, busca os trechos nas seções correspondentes da bula e pede ao modelo uma resposta organizada em segmentos: fatos citados, limites do que a bula permite afirmar e, quando necessário, uma pergunta de esclarecimento. Perguntas que indicam urgência recebem uma orientação fixa de procurar atendimento, definida por regras, e não pelo modelo. Respostas sem trechos que as sustentem não são exibidas como resposta, e a pergunta segue para uma fila de revisão humana na administração.

### 4.3 Tecnologias utilizadas

O sistema foi desenvolvido como aplicação web progressiva (PWA), em PHP 8.4 com Laravel 13 e Livewire 4, e utiliza o PostgreSQL com a extensão pgvector para armazenar os dados, o índice textual e os vetores. O OCR utiliza o PaddleOCR, e os *embeddings* são gerados localmente pelo modelo BGE-M3 (CHEN et al., 2024), com 1.024 dimensões. Em produção, as respostas são geradas pelo Claude Sonnet 5.5, e a interpretação das perguntas livres, pelo Claude Haiku 5.5, ambos da Anthropic. Em relação ao projeto, Python, LangChain, ChromaDB, GPT-4o e o aplicativo nativo foram substituídos, pois o pgvector dispensou um banco vetorial separado e a PWA atendeu ao uso em celular.

### 4.4 Privacidade e segurança

O sistema trata dados referentes à saúde, considerados dados pessoais sensíveis pela Lei Geral de Proteção de Dados Pessoais (BRASIL, 2018, art. 5º, II). Distinguem-se, a seguir, as medidas de segurança e privacidade efetivamente implementadas no protótipo das que ainda seriam necessárias para uma operação em escala; as primeiras são controles técnicos e não equivalem, por si sós, a conformidade legal.

As medidas implementadas, verificadas no código do sistema, são:

a) consentimento para dados de saúde: o perfil do paciente registra a data do consentimento (`health_consent_at`, em `app/Models/PatientProfile.php`); sem ele, os medicamentos em uso e as alergias não são usados como contexto das respostas, e os lembretes e o modo cuidador não funcionam. A exclusão dos dados de saúde pelo próprio usuário apaga alergias, receitas, medicamentos e perguntas e revoga o consentimento;
b) retenção limitada: as consultas de interações e as perguntas recebem prazo de expiração (`expires_at`), de 90 dias por padrão, e o comando `consultations:purge-expired` (`app/Console/Commands/PurgeExpiredConsultationsCommand.php`), agendado diariamente, apaga as vencidas; o usuário pode ainda excluir seu histórico a qualquer momento;
c) isolamento e minimização: cada consulta só é acessível ao seu dono; o painel de análise de uso é restrito a administradores e identifica os usuários apenas pelo identificador numérico interno, sem ler nome ou e-mail, e a exportação para análise usa identificadores aleatórios;
d) acesso programático: os tokens da API têm escopo, validade máxima de 90 dias e revogação imediata, e o banco guarda apenas o *hash* SHA-256 de cada token;
e) trilha de auditoria: eventos de gestão de usuários, de consentimento, de exclusão de dados de saúde e de emissão e revogação de tokens são registrados (`app/Models/UserAuditEvent.php`);
f) modo cuidador: o acesso de outra pessoa ao tratamento depende da aprovação explícita do paciente, com permissões por vínculo, revogáveis a qualquer momento, condicionadas ao consentimento de saúde do paciente e registradas em trilha própria, consultável por ele;
g) armazenamento: os PDFs das bulas ficam em disco privado, sem endereço público;
h) envio ao provedor do modelo: as mensagens não contêm nome, e-mail ou outro identificador do usuário, mas contêm os medicamentos consultados e, quando o usuário consentiu e os cadastrou, os medicamentos em uso, a posologia e as alergias, necessários para contextualizar a resposta.

As medidas ainda necessárias para uma operação em escala, não implementadas ou não formalizadas neste trabalho, são: a definição e a documentação da base legal para o tratamento de dados sensíveis (BRASIL, 2018, art. 11), com política de privacidade e termos revisados; uma política de retenção que abranja também registros técnicos, *logs* e cópias de segurança; o controle de acesso administrativo com revisão periódica de perfis; procedimentos para o atendimento dos direitos do titular (BRASIL, 2018, art. 18), incluindo a exclusão da conta e dos dados derivados; a indicação de encarregado (BRASIL, 2018, art. 41); e salvaguardas para a transferência internacional de dados ao provedor do modelo, sediado fora do Brasil (BRASIL, 2018, art. 33), como cláusulas contratuais, verificação da retenção de dados pelo provedor e informação clara ao usuário. O sistema não realiza diagnóstico, e um aviso permanente informa que ele não substitui a orientação profissional.

## 5 RESULTADOS E DISCUSSÃO

A seguir, apresentam-se e discutem-se os resultados dos experimentos descritos na Metodologia. Todos os números vêm do servidor de produção, em 11 nov. 2026, e dos registros listados no Apêndice B.

### 5.1 Identificação dos medicamentos

A Tabela 1 separa o conjunto de ajuste, usado para corrigir o normalizador de nomes durante o desenvolvimento, do conjunto reservado, medido sem ajustes.

**Tabela 1 – Desempenho na identificação dos princípios ativos (produção, 11 nov. 2026)**

| Conjunto | Consultas | Acerto exato (IC 95%) | Parcial | Errada | Não resolvida | Precisão | Recall | F1 |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Ajuste | 59 | 52 (88,1%; 77,5–94,1) | 3 | 3 | 1 | 0,905 | 0,905 | 0,905 |
| Reservado | 47 | 44 (93,6%; 82,8–97,8) | 2 | 0 | 1 | 0,958 | 0,979 | 0,968 |

Fonte: Elaborado pelos autores a partir dos experimentos (2026).
Nota: “Parcial” indica que parte dos princípios ativos do produto coincide com o gabarito; “errada”, que nenhum coincide; “não resolvida”, que nenhum foi identificado. Precisão, recall e F1 são calculados por princípio ativo.

No conjunto reservado, os três erros são artefatos do gabarito ou lacunas da fonte, e não falhas de identificação: para “Metformina” e “Gentamicina”, o produto escolhido no catálogo é uma associação (dapagliflozina com metformina; betametasona com gentamicina), e o sistema identificou corretamente os dois princípios ativos de cada um; a domperidona não existe no DDInter. Com 47 consultas, porém, o intervalo de confiança vai de cerca de 83% a 98%, o que impede afirmar um desempenho mais preciso que esse. Na busca pelo nome digitado, o produto correto apareceu na primeira posição em 72,3% das consultas reservadas e entre os cinco primeiros em 87,2%.

O conjunto de ajuste teve 7 erros, de três origens. Três vêm do defeito descrito na seção 3.3: Ibuprofeno e Norvasc foram ligados aos registros duplicados “ibuprofeno” e “besilato de anlodipino”, e não a *ibuprofen* e *amlodipine*, e Losartana potássica foi ligada aos dois registros, o duplicado e o do DDInter. Outros três envolvem a dipirona, que o DDInter não traz: o gabarito espera o nome *metamizole*, e o sistema liga Novalgina, Dorflex e Benegrip ao princípio ativo “dipirona monoidratada”, criado a partir das bulas para que as perguntas livres as encontrem — divergência em relação ao gabarito, e não perda de interação, que não existiria de todo modo. O último, Lexotan, não foi resolvido porque o bromazepam não está no catálogo derivado do DDInter. Assim, o único erro de identificação com efeito sobre a segurança é o dos duplicados, que a seção 5.2 mede.

### 5.2 Verificação de interações: teste de integração com o DDInter

Conforme a seção 3.5.2, os resultados desta seção medem a integração da aplicação com o DDInter, e não a correção clínica. Na verificação dos 1.483 pares, não houve falso positivo, mas houve 81 falsos negativos e 25 abstenções (Tabela 2).

**Tabela 2 – Matriz de confusão do teste de integração com o DDInter (1.483 pares; produção, 11 nov. 2026)**

| Rótulo (DDInter) | Sistema: com interação | Sistema: não identificada | Abstenção | Total |
| --- | --- | --- | --- | --- |
| Com interação | 1.319 | 81 | 24 | 1.424 |
| Sem interação registrada no DDInter | 0 | 58 | 1 | 59 |

Fonte: Elaborado pelos autores a partir dos experimentos (2026).
Nota: precisão de 1,000 (IC 95%: 99,7%–100%); recall de 0,942 (1.319/1.400; IC 95%: 92,9%–95,3%); recall conservador, que conta as abstenções como erro, de 0,926 (1.319/1.424; IC 95%: 91,2%–93,9%); F1 de 0,970. As 25 abstenções (1,7%) são todas por evidência insuficiente. As classes referem-se apenas ao gabarito extraído do DDInter; um par da segunda linha não é um par clinicamente seguro, e sim um par sem registro na base importada.

A gravidade coincidiu com a do DDInter em todos os 613 pares com gravidade classificada que o sistema acusou (IC 95%: 99,4%–100%): 103 graves, 439 moderadas e 71 leves; nos demais 706 pares acusados, a fonte não classifica a gravidade, e o sistema informa isso ao usuário. No protocolo de 30 pares, com busca híbrida, não houve erro nem abstenção (IC 95%: 88,6%–100%): as 10 graves, as 5 moderadas e as 5 leves foram acusadas com a gravidade da fonte, e os 10 pares sem registro foram informados como interação não identificada nas fontes consultadas. Por ser subamostra do mesmo universo, esse resultado não valida o anterior de forma independente.

As divergências da Tabela 2 são o resultado mais importante desta seção. Sete interações graves não foram acusadas: varfarina (Marevan), ácido acetilsalicílico (Aspirina e AAS) e rivaroxabana (Xarelto) com ibuprofeno, e anlodipino (Norvasc) com carbamazepina (Tegretol), fenobarbital (Gardenal) e itraconazol. Todas envolvem Ibuprofeno ou Norvasc, os dois produtos que a seção 5.1 mostrou ligados a registros duplicados em português. Como esses registros não têm interações, o sistema não encontra o par no DDInter: quando nenhuma bula cita o outro fármaco, informa que a interação não foi identificada; quando uma bula o cita, declara evidência insuficiente, o que explica parte das abstenções. Esse é o erro mais perigoso que o sistema pode cometer: a interação entre varfarina e anti-inflamatórios, por exemplo, aumenta o risco de sangramento (BRUNTON; HILAL-DANDAN; KNOLLMANN, 2018), e a falta de alerta tende a ser lida como segurança.

O defeito não estava no código de consulta, e sim nos dados produzidos pela operação do sistema: as bulas foram vinculadas antes de o catálogo conhecer os nomes em português, e a correção manual dos vínculos na administração não o tornava visível. O teste de integração cumpriu exatamente o papel previsto na seção 3.5.2 — uma divergência em relação à fonte indica falha na cadeia —, enquanto o uso comum não o mostrava: as perguntas livres sobre o ibuprofeno continuaram respondidas com a bula do próprio medicamento (seção 5.4), pois a bula estava ligada ao registro duplicado. A correção (Apêndice B) impede a criação do duplicado quando o nome já leva a um princípio ativo, inclusive pelas regras de grafia, e funde os duplicados existentes no princípio ativo do DDInter na reconstrução do catálogo; a reexecução deste experimento após a correção não integra este texto. O registro exposto pela API resume as listas por par, e só as interações graves perdidas vêm nomeadas; não foi possível, portanto, atribuir cada uma das demais divergências a um produto.

Os 58 pares sem interação registrada que o sistema informou como tal são verdadeiros negativos apenas no sentido da classificação em relação ao gabarito: indicam ausência de registro, e não segurança clínica da combinação. No gabarito, os 59 pares sem registro se concentram em dois produtos, Xarelto (rivaroxabana), presente em 33 deles, e Gardenal (fenobarbital), em 17, o que sugere cobertura desigual da base para alguns fármacos. A seção 5.6 mostra que a cobertura também condiciona a leitura do experimento com o modelo isolado.

### 5.3 Recuperação de trechos

A Tabela 3 compara os modos de busca, com *embeddings* do BGE-M3 (CHEN et al., 2024) e fusão por RRF (CORMACK; CLARKE; BÜTTCHER, 2009), sobre as 369 consultas por assunto.

**Tabela 3 – Desempenho da recuperação por modo de busca (369 consultas; 53 princípios ativos; produção, 11 nov. 2026)**

| Modo | Acerto@1 (IC 95%) | Acerto@3 (IC 95%) | Acerto@5 (IC 95%) | P@5 | R@5 | MRR |
| --- | --- | --- | --- | --- | --- | --- |
| Lexical | 11,9% (9,0–15,6) | 20,6% (16,8–25,0) | 27,9% (23,6–32,7) | 0,115 | 0,120 | 0,173 |
| Vetorial | 19,0% (15,3–23,3) | 36,0% (31,3–41,1) | 52,6% (47,5–57,6) | 0,145 | 0,287 | 0,299 |
| Híbrido | 20,3% (16,5–24,7) | 40,1% (35,2–45,2) | 50,1% (45,1–55,2) | 0,164 | 0,247 | 0,312 |

Fonte: Elaborado pelos autores a partir dos experimentos (2026).
Nota: acerto@k é a fração das consultas com pelo menos um trecho da seção do assunto entre os k primeiros. Consultas por assunto: 53 de indicação, posologia, contraindicação, advertências e superdose e 52 de efeitos e armazenamento.

A busca lexical foi a mais fraca em todas as métricas, com intervalos de confiança que não se sobrepõem aos dos outros dois modos a partir de k = 3: perguntas em linguagem leiga (“Como devo guardar…?”) têm poucas palavras em comum com o texto técnico da bula (“Conservar em temperatura ambiente…”). A busca vetorial e a híbrida ficaram próximas: a híbrida teve valores maiores no acerto@1, no acerto@3, no P@5 e no MRR, e a vetorial, no acerto@5 e no R@5, sempre com intervalos sobrepostos. O resultado sustenta, portanto, a preferência por um modo com componente vetorial em vez do lexical, mas não estabelece a superioridade da busca híbrida sobre a vetorial. A híbrida foi mantida como padrão por não ter perdido de forma clara para nenhum modo e por preservar a precisão da busca lexical para nomes de fármacos, que esta avaliação por assunto não mede.

Os valores absolutos são baixos e devem ser lidos como um limite inferior do sistema, por três razões. Primeiro, o experimento não aplica o filtro por seção que o sistema usa nas perguntas livres; com esse filtro, 99,4% das respostas da seção 5.4 tiveram pelo menos uma fonte da seção do assunto. Segundo, a relevância é decidida pelo título da seção: um trecho que responde à pergunta em outra seção, como a posologia descrita nas características farmacológicas de uma bula profissional, conta como erro. Terceiro, seções longas têm muitos trechos relevantes, o que reduz o R@k mesmo quando os primeiros resultados são todos relevantes.

### 5.4 Perguntas livres ao sistema publicado

A Tabela 4 mostra a situação das 234 respostas por grupo de perguntas.

**Tabela 4 – Situação das respostas às perguntas livres (234 perguntas; produção, 11 nov. 2026)**

| Grupo | Respondida | Esclarecimento | Sem evidência | Sem bula | Medicamento não encontrado | Não identificado | Total |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Bula brasileira | 170 | 7 | 2 | 4 | 10 | 3 | 196 |
| Nome comercial | 12 | 1 | 1 | 0 | 0 | 0 | 14 |
| Sem bula brasileira | 3 | 0 | 1 | 2 | 0 | 0 | 6 |
| Nome inexistente | 0 | 0 | 0 | 0 | 3 | 0 | 3 |
| Urgência | 2 | 0 | 0 | 0 | 0 | 0 | 2 |
| Fora do escopo | 0 | 0 | 0 | 0 | 1 | 0 | 1 |
| Conversa (dois turnos) | 12 | 0 | 0 | 0 | 0 | 0 | 12 |
| **Total** | **199** | **8** | **4** | **6** | **14** | **3** | **234** |

Fonte: Elaborado pelos autores a partir dos experimentos (2026).

Das 196 perguntas sobre bulas brasileiras, 170 foram respondidas (86,7%). Das 26 restantes, 13 caíram na identificação: “AAS” (3) e “dabigatrana” (4) não foram reconhecidos, o primeiro por ser uma sigla curta demais para a regra de nomes e o segundo porque o catálogo o registra como “etexilato de dabigatrana”, falhas corrigidas na versão seguinte; a pseudoefedrina (3) só existe no catálogo em associações e foi tratada como ambígua; e o anlodipino (3) não teve princípio ativo identificado. As demais foram pedidos de esclarecimento (7), interações sem bula (4) e falta de evidência (2). Os nomes inexistentes e a pergunta fora do escopo não receberam resposta, as perguntas de urgência receberam, acima da resposta, a orientação fixa de procurar o Disque-Intoxicação e o SAMU, e as segundas perguntas das conversas permaneceram no medicamento da primeira. Para medicamentos sem bula brasileira, três perguntas foram respondidas com a bula dos Estados Unidos, com o aviso correspondente. A Tabela 5 resume os indicadores das respostas.

**Tabela 5 – Indicadores das respostas às perguntas livres (produção, 11 nov. 2026)**

| Indicador | Resultado (IC 95%) |
| --- | --- |
| Medicamento certo identificado (bula brasileira, nome comercial e conversa) | 206/222 = 92,8% (88,6–95,5) |
| Respostas com pelo menos uma fonte da seção do assunto perguntado | 176/177 = 99,4% (96,9–99,9) |
| Respostas em que todas as fontes são bulas do medicamento perguntado | 150/180 = 83,3% (77,2–88,1) |
| Afirmações propostas pelo modelo aceitas pelo validador | 456/820 = 55,6% (52,2–59,0) |
| Respostas com pelo menos uma afirmação aceita | 199/199 = 100% (98,1–100) |
| Trechos de bula exibidos sem reescrita | 0 |
| Respostas que exigiram a leitura da bula inteira | 82 |
| Rubrica automática: relevância, ancoragem e segurança | 199/199 = 100% (98,1–100) cada |
| Rubrica automática: clareza / naturalidade | 98,5% (95,7–99,5) / 91,5% (86,8–94,6) |
| Tempo de resposta no servidor | mediana de 12,9 s; p95 de 29,1 s; máximo de 53,7 s |

Fonte: Elaborado pelos autores a partir dos experimentos (2026).

O validador recusou 44,4% das afirmações propostas pelo modelo. Os motivos mais frequentes foram a afirmação que o trecho citado não sustenta lexicalmente (181), o excesso de palavras (66), a recomendação de conduta (51), a inclusão de limite desnecessário (21) e a negação ausente do trecho (19); houve ainda 6 negações invertidas e 13 afirmações que narravam a fonte. Mesmo assim, todas as respostas exibidas tiveram pelo menos uma afirmação aceita, e nenhuma exibiu trecho cru da bula: quando as afirmações aceitas não bastaram, o sistema leu a bula inteira para responder (82 perguntas) ou se absteve. Em todo o uso registrado em produção até a data da avaliação, que inclui essas perguntas, a proporção de afirmações aceitas foi semelhante, de 606 em 1.115 (54,3%; IC 95%: 51,4%–57,3%).

Quanto às fontes, 99,4% das respostas tiveram pelo menos uma fonte da seção do assunto. Em 16,7%, porém, nem todas as fontes eram bulas do próprio medicamento: na maioria dos casos, a fonte era uma associação que contém o princípio ativo (78 das 555 fontes citadas, como uma associação com paracetamol numa pergunta sobre paracetamol), e em 8 fontes, a bula de outro medicamento, nas perguntas sobre losartana, respondidas também com a bula da hidroclorotiazida por meio da associação entre as duas. A resposta avisa quando fala de uma associação, mas o sistema deveria preferir a bula do medicamento isolado. A rubrica automática verifica relevância, ancoragem, segurança, clareza e naturalidade por regras; ela confirma que as respostas seguem o formato previsto, mas não substitui a verificação de seu conteúdo por profissional, que não foi feita.

**Figura 1 – Resposta a uma pergunta sobre interação entre ibuprofeno e losartana**

[inserir a captura de tela da versão publicada, com largura de 15 a 16 cm]

Fonte: Elaborado pelos autores (2026).

**Figura 2 – Resposta a uma consulta sobre medicamento sem bula vinculada**

[inserir a captura de tela da versão publicada, com largura de 15 a 16 cm]

Fonte: Elaborado pelos autores (2026).

Na Figura 1, a expressão “gravidade classificada pelo sistema” refere-se à gravidade obtida da base estruturada, e não a uma decisão do modelo.

### 5.5 Legibilidade das respostas

A Tabela 6 compara o Índice de Flesch das respostas registradas em produção com o dos trechos de bula que sustentam cada uma.

**Tabela 6 – Legibilidade das respostas e dos trechos que as sustentam (269 pares; produção, 11 nov. 2026)**

| Texto | IFL (mediana) | IFL (mín.–máx.) | Muito fácil | Fácil | Difícil | Muito difícil |
| --- | --- | --- | --- | --- | --- | --- |
| Trechos de bula | 29,4 | −34,7 a 71,4 | 0 | 41 | 126 | 102 |
| Respostas do BULA-IA | 58,3 | 5,4 a 88,2 | 21 | 184 | 60 | 4 |

Fonte: Elaborado pelos autores a partir dos experimentos (2026).
Nota: IFL = Índice de Facilidade de Leitura de Flesch adaptado ao português (MARTINS et al., 1996). Valores negativos foram mantidos sem truncamento e contados na faixa “muito difícil”.

Em 264 dos 269 pares (98,1%; IC 95%: 95,7%–99,2%), a resposta foi mais legível que os trechos que a sustentam, com diferença mediana de 27,8 pontos, o que desloca o texto da faixa “difícil” para a faixa “fácil”: 205 das 269 respostas ficaram nas faixas “fácil” ou “muito fácil”, contra 41 dos 269 conjuntos de trechos. Nos outros 5 pares, a resposta não foi mais legível que os trechos, com diferença de até 21,1 pontos a favor dos trechos. Esse resultado indica que as respostas são mais legíveis que os trechos da bula segundo um índice de forma; não indica que o conteúdo seja mais bem compreendido.

O resultado deve ser lido com três ressalvas. Primeiro, o índice mede a forma do texto, e não a compreensão: uma resposta legível pode estar incompleta ou errada, e a correção das respostas não foi avaliada por profissional. Segundo, as respostas não representam o uso real: vêm de perguntas escritas pelos autores, ainda que sobre 49 medicamentos e oito assuntos, e não de usuários. Terceiro, os textos comparados têm extensões diferentes, de modo que a comparação não isola o efeito da reescrita: parte da diferença decorre da simplificação do vocabulário e das frases, e parte da seleção e da redução do conteúdo, que o índice não distingue. A redução é desejável para o leitor, mas exige verificar se nada essencial foi omitido, o que não foi feito.

### 5.6 Comparação com o modelo sem recuperação

A Tabela 7 apresenta o desempenho do Claude Sonnet 5.5 quando consultado sem base estruturada nem trechos de bula, tomando o DDInter como rótulo.

**Tabela 7 – Modelo de linguagem sem recuperação (120 pares; rótulo: DDInter; produção, 11 nov. 2026)**

| Modelo | VP / FP | VN / FN | Abstenção | Precisão | Recall | F1 | Acurácia (IC 95%) |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Claude Sonnet 5.5 | 64 / 0 | 4 / 50 | 2 | 1,000 | 0,561 | 0,719 | 56,7% (47,7–65,2) |

Fonte: Elaborado pelos autores a partir dos experimentos (2026).
Nota: VP, FP, VN e FN referem-se à classificação em relação ao rótulo extraído do DDInter, e não à segurança clínica dos pares. A acurácia considera as abstenções, ambas em pares com interação registrada, como erro. Um classificador trivial que sempre indicasse interação teria acurácia de 96,7% (116/120). IC 95%: precisão 94,3%–100%; recall 47,0%–64,9%. Nenhuma chamada ao modelo falhou.

Sem recuperação, o modelo foi conservador: toda interação que apontou está registrada no DDInter (precisão de 1,000), e os 4 pares sem registro foram dados como sem interação. Sua falha foi deixar de reconhecer 50 das 114 interações registradas sobre as quais se pronunciou (recall de 0,561). A distribuição dessas falhas por gravidade é reveladora: as 9 interações graves da amostra foram todas reconhecidas, assim como 33 das 37 moderadas e 6 das 7 leves; das 52 interações não reconhecidas ou com abstenção, 47 são pares que o DDInter registra sem gravidade classificada. Entre as 48 interações classificadas que reconheceu, o modelo concordou com a gravidade da fonte em 36 (75,0%; IC 95%: 61,2%–85,1%): 6 das 9 graves (as outras 3, rebaixadas a moderadas), 29 das 33 moderadas e 1 das 6 leves, 5 delas elevadas a moderadas. A acurácia de 56,7%, muito abaixo dos 96,7% do classificador trivial, reflete a composição da amostra, quase toda de pares com interação, e não deve ser lida isoladamente.

Esse resultado sustenta a arquitetura adotada com um motivo quantitativo: sem recuperação, o modelo reconhece as interações de relevância estabelecida, mas deixa passar quase metade das registradas, justamente as menos caracterizadas, e erra a gravidade em um quarto das que reconhece, enquanto a consulta à base estruturada não produziu nenhum falso positivo e reproduziu a gravidade registrada em todos os pares classificados que acusou (seção 5.2). Além disso, suas respostas não indicam fonte e não podem ser auditadas. A seção 5.2 mostra o limite dessa garantia: ela vale enquanto a cadeia de identificação e de vínculo estiver correta, e um defeito de dados nessa cadeia produziu perdas da mesma natureza que as do modelo isolado, com a diferença de que foram detectáveis, rastreáveis e corrigíveis. A comparação continua descritiva: com 120 pares e só 4 negativos, ela mostra o risco que a arquitetura evita, e não uma superioridade estatística demonstrada.

### 5.7 Síntese: o que os resultados mostram e o que não mostram

O Quadro 6 resume o alcance de cada resultado.

**Quadro 6 – Alcance dos resultados**

| Aspecto | O que foi demonstrado | O que não foi demonstrado |
| --- | --- | --- |
| Identificação | Acerto exato de 93,6% (IC 95%: 82,8–97,8) em consultas não usadas nos ajustes; nas perguntas livres, medicamento certo em 92,8% | Desempenho em larga escala e em nomes digitados com erro por usuários reais |
| Interações | Nenhum falso positivo em 1.483 pares e gravidade igual à da fonte nos 613 pares classificados acusados; o teste revelou um defeito de dados que fez perder 7 interações graves | Correção clínica; cobertura integral do DDInter; validação independente; o resultado após a correção do defeito |
| Cobertura | Existência e gravidade informadas para os pares registrados; bula de pelo menos um fármaco em 1.048 dos 1.483 pares e de ambos em 298 | Proporção de pares com trecho de bula disponível para a explicação |
| Recuperação | Com 369 consultas por assunto, os modos com componente vetorial superaram o lexical | Superioridade da busca híbrida sobre a vetorial; relevância julgada por pessoas |
| Geração | Validação heurística impediu afirmações sem trecho ou com recomendação de conduta (55,6% de 820 aceitas) e nenhum trecho cru foi exibido em 199 respostas | Correção factual e fidelidade semântica das respostas |
| Legibilidade | Respostas mais legíveis que os trechos que as sustentam em 98,1% de 269 pares, pelo índice de Flesch | Compreensão efetiva pelos usuários; separação entre simplificação textual e redução do conteúdo |
| Modelo isolado | Sem fonte e não auditável; precisão de 1,000, mas recall de 0,561 e gravidade divergente em 25% das interações classificadas reconhecidas | Que o modelo isolado erre mais que a base em termos clínicos |
| Validação humana | — | Avaliação por profissional de saúde, de usabilidade percebida (SUS) e de compreensão do conteúdo: não realizadas |

Fonte: Elaborado pelos autores (2026).

Os resultados sustentam parcialmente a hipótese da Introdução. Com a decisão sobre a interação fora do modelo, as respostas tornaram-se rastreáveis, a gravidade passou a ser a da fonte, e, diante da falta de informação, o sistema preferiu a abstenção ou o aviso de que a interação não foi identificada nas fontes consultadas, em vez de completar lacunas. As respostas também se mostraram mais legíveis que a bula pelo índice de Flesch, o que não equivale a compreensão. Por outro lado, a hipótese de uma detecção “mais confiável” não pôde ser verificada em termos clínicos, pois o único gabarito disponível é a própria base consultada, cuja cobertura é parcial. Sobretudo, a validação por profissional de saúde não foi realizada; logo, nenhuma resposta teve a correção factual verificada.

Em síntese, a principal contribuição demonstrada é arquitetural: a separação entre a identificação da interação e de sua gravidade, a cargo de uma fonte estruturada, a recuperação de evidência documental nas bulas e a explicação em linguagem natural, com rastreabilidade de cada resposta até o registro e o trecho que a sustentam. A medição em produção mostrou também o custo dessa arquitetura: sua confiabilidade depende da cadeia de dados que liga o nome digitado ao registro da fonte, e um defeito nessa cadeia, introduzido pela operação, e não pelo código, fez o sistema perder interações graves sem nenhum sinal visível ao usuário. Por isso, o teste de integração com a fonte não é apenas um experimento deste trabalho, mas um controle que deve ser repetido a cada alteração da base.

## 6 CONSIDERAÇÕES FINAIS

Este trabalho investigou como os LLMs, apoiados por recuperação de fontes oficiais, podem apoiar a compreensão das bulas e a identificação de interações medicamentosas de forma rastreável. A arquitetura mostrou-se tecnicamente viável quando a base estruturada decide sobre a interação, o RAG recupera os trechos de bula e o modelo apenas os explica, sob uma validação heurística de ancoragem textual. Todos os resultados foram medidos no sistema publicado, sobre a base em uso.

Quanto aos objetivos específicos, as bulas da ANVISA, o catálogo de medicamentos e o DDInter foram reunidos, e as 82 bulas da base, divididas em 5.652 trechos, foram indexadas em PostgreSQL para busca lexical, vetorial e híbrida. Na identificação dos medicamentos, o sistema chegou aos princípios ativos corretos em 93,6% das consultas reservadas, com intervalo de confiança entre cerca de 83% e 98%. O teste de integração com o DDInter, em 1.483 pares, não produziu nenhum falso positivo e reproduziu a gravidade da fonte em todos os pares classificados acusados, mas revelou que princípios ativos duplicados em português, criados ao vincular bulas antes do catálogo, desligavam dois produtos da base e faziam o sistema perder sete interações graves; o defeito foi corrigido após a medição. Na recuperação, com 369 consultas por assunto, os modos com componente vetorial superaram claramente o lexical, sem diferença estabelecida entre o híbrido e o vetorial. Nas 234 perguntas feitas ao sistema publicado, o medicamento certo foi identificado em 92,8% dos casos, 99,4% das respostas citaram a seção do assunto, e a validação descartou 44,4% das afirmações propostas pelo modelo sem que nenhum trecho cru chegasse ao usuário. As respostas foram mais legíveis que os trechos que as sustentam em 98,1% de 269 pares, segundo o índice de Flesch, sem que isso meça a compreensão. Sem recuperação, o modelo não apontou interações inexistentes na base, mas deixou de reconhecer 50 das 114 registradas sobre as quais se pronunciou, errou a gravidade de um quarto das que reconheceu e respondeu sem fonte, o que reforça a separação entre quem decide e quem explica.

A principal contribuição é a arquitetura, e não uma nova base farmacológica: a identificação e a gravidade da interação ficam com a fonte estruturada, a recuperação de evidência documental fica com o RAG sobre as bulas, e a explicação em linguagem natural fica com o modelo, de modo que cada resposta pode ser rastreada até sua fonte e as lacunas de cobertura ficam explícitas. A medição em produção acrescentou uma lição: nessa arquitetura, a segurança das respostas depende da cadeia de dados entre o nome digitado e o registro da fonte, que precisa ser verificada continuamente. Como limitações, a base reúne 82 bulas, que cobrem 25 dos 55 produtos avaliados, de modo que o sistema informa a existência e a gravidade de muito mais pares do que consegue explicar com trecho de bula; o DDInter importado não equivale à exportação integral da base, de modo que a ausência de registro jamais pode ser comunicada como segurança; o gabarito de interações provém da própria base consultada, o que impede medir a correção clínica; as perguntas foram escritas pelos autores; e a compreensão dos usuários, que motivou o problema, foi estimada apenas por um índice de legibilidade, e não medida.

Como trabalhos futuros, propõem-se: a reexecução do teste de integração após a correção dos princípios ativos duplicados e sua inclusão como verificação automática a cada alteração da base; a validação das respostas por profissional de saúde; a avaliação, junto a usuários leigos, da usabilidade percebida, com a escala SUS, e, separadamente, da compreensão, com perguntas sobre o conteúdo das respostas e da bula, ambas após consulta ao Comitê de Ética em Pesquisa sobre o enquadramento dessas atividades; um gabarito de interações independente do DDInter, revisado por profissional; a importação da exportação integral do DDInter e de critérios específicos para idosos; julgamentos de relevância feitos por pessoas para a recuperação; a preferência pela bula do medicamento isolado quando a pergunta não trata de uma associação; uma verificação da fidelidade semântica das explicações, além da ancoragem lexical; e a ampliação da base de bulas. Assim, o BULA-IA pode consolidar-se como camada de acesso rastreável à informação sobre medicamentos, sem substituir a orientação profissional.

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


ZHAO, Wayne Xin *et al*. **A survey of large language models**. [*S. l.*]: arXiv, 2023. Preprint arXiv:2303.18223. DOI: 10.48550/arXiv.2303.18223. Disponível em: https://arxiv.org/abs/2303.18223. Acesso em: 6 nov. 2026.

## APÊNDICE A – RECORTE DA BASE AVALIADA

**Quadro A.1 – Bulas da base de produção na avaliação (11 nov. 2026)**

| Nº | Doc. | Título no sistema | Princípio(s) ativo(s) vinculado(s) | Tipo de bula | Trechos | SHA-256 do PDF (início) |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | 1 | digoxina | digoxina | Profissional | 36 | e91e35c201b0 |
| 2 | 2 | excipientes q.s.p…………………………………………………………….…………......1 comp… | fenitoína | Profissional | 41 | 9813a7be40d3 |
| 3 | 3 | RENITEC® | enalapril | Profissional | 38 | 789188cb4ce6 |
| 4 | 4 | Aspirina® | ácido acetilsalicílico | Profissional | 87 | d8ebfd281899 |
| 5 | 5 | COZAAR® | losartana | Profissional | 54 | cb4d2895b334 |
| 6 | 6 | LOSEC MUPS® | omeprazol | Profissional | 60 | eecd91c9e0ba |
| 7 | 7 | DAFORIN® cloridrato de fluoxetina | fluoxetina | Profissional | 110 | 82489feeca8b |
| 8 | 8 | Amoxil | amoxicilina | Profissional | 93 | ba1962f29ac4 |
| 9 | 9 | hidroclorotiazida | hidroclorotiazida | Profissional | 50 | 7d09a6c57053 |
| 10 | 10 | sinvastatina | sinvastatina | Profissional | 148 | aeb941f138b8 |
| 11 | 11 | clonazepam | clonazepam | Profissional | 57 | 8476ccff5055 |
| 12 | 12 | ANCORON | amiodarona | Profissional | 55 | 40366a931e7d |
| 13 | 14 | Glifage | metformina | Profissional | 47 | 8cf14752aec1 |
| 14 | 15 | TEGRETOL® CR | carbamazepina | Profissional | 223 | 82f5d6107593 |
| 15 | 16 | NOVALGINA® | dipirona | Profissional | 283 | 1c77b2515c08 |
| 16 | 17 | KLARICID | claritromicina | Profissional | 284 | 70592c84e86d |
| 17 | 18 | Levotiroxina Purant4 Bula Profissional | levotiroxina | Não classificada | 108 | b7f43bf313b5 |
| 18 | 19 | ZOLOFT | sertralina | Profissional | 122 | 007ece1863e6 |
| 19 | 20 | Alivium | ibuprofeno | Profissional | 113 | 2e9581af693d |
| 20 | 21 | DIPIRONA | dipirona | Paciente | 35 | 2e4ea8e20a53 |
| 21 | 22 | ACETILCISTEINA | acetilcisteína | Paciente | 37 | a11ddd473b0a |
| 22 | 23 | ACETILCISTEINA | acetilcisteína | Profissional | 39 | 4893ea35641d |
| 23 | 24 | Bula 1793933368611 | dipirona | Profissional | 69 | 138d7ccea2a1 |
| 24 | 25 | losartana potássica | losartana | Paciente | 34 | edbfe7b9ec40 |
| 25 | 26 | ibuprofeno | ibuprofeno | Profissional | 30 | ef4214c69236 |
| 26 | 27 | ibuprofeno | ibuprofeno | Paciente | 31 | e47ef0048c90 |
| 27 | 28 | NAPRIX A | anlodipino + ramipril | Profissional | 30 | 8a9a2d471261 |
| 28 | 29 | losartana potássica | losartana | Profissional | 50 | 170f04dd2e48 |
| 29 | 30 | losartana potássica + hidroclorotiazida | hidroclorotiazida + losartana | Profissional | 72 | 843aea183c25 |
| 30 | 31 | Losartana Potassica Paciente | hidroclorotiazida + losartana | Paciente | 47 | 0387226ea55b |
| 31 | 32 | DORFLEX® DIP | dipirona | Profissional | 36 | 79507751289f |
| 32 | 33 | NAPRIX A | anlodipino + ramipril | Paciente | 26 | 3a0b5f1ff277 |
| 33 | 34 | NAPRIX D | hidroclorotiazida + ramipril | Paciente | 60 | 47353b1c9e80 |
| 34 | 35 | NAPRIX D | hidroclorotiazida + ramipril | Profissional | 90 | e9921fe6247a |
| 35 | 36 | Omeprazol Pac | omeprazol | Paciente | 28 | 18d9d87954ae |
| 36 | 37 | Omeprazol Pro | omeprazol | Profissional | 34 | b6dbeb772e09 |
| 37 | 38 | NAPRIX | ramipril | Paciente | 38 | 744a04f5c74b |
| 38 | 39 | Naprix Pro | ramipril | Não classificada | 41 | b604c3163808 |
| 39 | 40 | Torsilax | cafeína + carisoprodol + diclofenaco + paracetamol | Paciente | 44 | c7da4eefb048 |
| 40 | 41 | Torsilax | cafeína + carisoprodol + diclofenaco + paracetamol | Profissional | 55 | a8f456729663 |
| 41 | 42 | Dorflex Pac | cafeína + dipirona + orfenadrina | Não classificada | 164 | 438c20bd278a |
| 42 | 43 | DORFLEX MAX® | cafeína + dipirona + orfenadrina | Profissional | 150 | ce7a52c3dce9 |
| 43 | 44 | DORFLEX ® UNO | dipirona | Profissional | 80 | 344678b7fba5 |
| 44 | 45 | DORFLEX® UNO | dipirona | Paciente | 83 | 9fda3f07709c |
| 45 | 46 | Atrovent® N e Atrovent® | ipratrópio | Profissional | 47 | e917a0adb1ee |
| 46 | 47 | BEROTEC | fenoterol | Profissional | 47 | b8077102b6ff |
| 47 | 48 | BEROTEC | fenoterol | Paciente | 27 | 6b5d24738b30 |
| 48 | 49 | DIPIRONA SODICA | dipirona | Paciente | 29 | f117c8fa91b4 |
| 49 | 50 | DIPIRONA SÓDICA | dipirona | Profissional | 42 | 58696531239b |
| 50 | 51 | Buscopan® Composto | dipirona + escopolamina | Profissional | 35 | b102ff84198d |
| 51 | 52 | TYLENOL SINUS | paracetamol + pseudoefedrina | Paciente | 42 | 22bad83ce135 |
| 52 | 53 | Bula 1794355324532 | levotiroxina | Não classificada | 102 | 07807a19cc89 |
| 53 | 54 | Bula 1794354896812 | paracetamol + pseudoefedrina | Não classificada | 51 | c5e421e77d6d |
| 54 | 55 | ciprofibrato | ciprofibrato | Paciente | 24 | d13674b0e1fa |
| 55 | 56 | Bula 1794356010156 | amoxicilina | Paciente | 87 | c79815cbdb39 |
| 56 | 57 | Glifage | metformina | Paciente | 57 | 7d8052c702a5 |
| 57 | 58 | Bula 1794356209646 | isotretinoína | Paciente | 37 | 84f8c8c991bf |
| 58 | 59 | Bula 1794355126111 | dipirona | Não classificada | 284 | e947a7e67a1d |
| 59 | 60 | dexametasona | dexametasona | Profissional | 47 | b4646c52deaa |
| 60 | 61 | Bula 1794357390631 | ácido acetilsalicílico | Profissional | 33 | 280845309fa1 |
| 61 | 62 | Bula 1794357533352 | omeprazol | Não classificada | 71 | 4c72aa52f5fa |
| 62 | 63 | COMBIGAN | brimonidina + timolol | Paciente | 32 | 936feb47fefa |
| 63 | 64 | Bactrim | sulfametoxazol + trimetoprima | Paciente | 86 | 4f9cb4afbd38 |
| 64 | 65 | PRADAXA® | dabigatrana | Paciente | 35 | 12800b14f70a |
| 65 | 66 | Bactrim | sulfametoxazol + trimetoprima | Profissional | 64 | cfcf16927c4d |
| 66 | 67 | Nome comercial: Xalatan® | latanaprosta | Paciente | 20 | e88218c779ac |
| 67 | 68 | Norvasc Pac | anlodipino | Profissional | 50 | c4b957f30774 |
| 68 | 69 | FOSAMAX® D | alendronato de sódio + colecalciferol | Paciente | 31 | ea9afd18164c |
| 69 | 70 | PROLIA® | denosumabe | Paciente | 36 | 6eab743f942c |
| 70 | 71 | Norvasc Pro | anlodipino | Não classificada | 59 | 14e26163618c |
| 71 | 72 | Bula 1794371100884 | riluzol | Paciente | 28 | e53c83af93bb |
| 72 | 73 | atenolol | atenolol | Paciente | 33 | 1349ddcd7961 |
| 73 | 74 | indapamida | indapamida | Paciente | 34 | b600241670b8 |
| 74 | 75 | DIAMICRON MR 30mg | gliclazida | Paciente | 89 | 883be104672b |
| 75 | 76 | Jardiance Duo | empagliflozina + metformina | Paciente | 56 | 6fb878bd2d3b |
| 76 | 77 | Jardiance | empagliflozina | Paciente | 93 | 978d16bbc852 |
| 77 | 78 | Bula 1794370211702 | dapagliflozina | Não classificada | 62 | c3b902a07b6e |
| 78 | 79 | LANTUS® | insulina glargina | Paciente | 84 | d7b8683da334 |
| 79 | 80 | LÍPITOR | atorvastatina | Paciente | 69 | 034af481892c |
| 80 | 81 | CRESTOR | rosuvastatina | Paciente | 48 | 48afbc49d7a6 |
| 81 | 82 | Bula 1794370379700 | esomeprazole | Não classificada | 38 | 5c4a19bcabcd |
| 82 | 83 | Bula 1794370358384 | pantoprazol | Não classificada | 131 | 68db8de6c114 |
| | | **Total: 82 bulas** | 82 vinculadas | 11 não classificada, 33 paciente, 38 profissional | **5652** | |

Fonte: Elaborado pelos autores a partir da base de produção (`GET /api/v1/stats/knowledge` e `GET /api/v1/audit/documents`, 11 nov. 2026).
Nota: os princípios ativos aparecem em português; os que vêm do DDInter têm nome canônico em inglês no sistema. A bula 2 tem como título no sistema um trecho de sua composição (“excipientes q.s.p…”), extraído de forma incorreta, e deve ser renomeada na administração; as 11 bulas “não classificadas” não tiveram o público (paciente ou profissional) identificado. Os registros “ibuprofeno”, “besilato de anlodipino”, “losartana potássica” e “dipirona monoidratada” são os duplicados em português descritos na seção 3.3. Antes da publicação, recomenda-se completar este quadro com a data de publicação de cada bula no Bulário Eletrônico.

**Quadro A.2 – Produtos da avaliação de interações (55) e cobertura**

| Produto | Princípio ativo (DDInter) | Bula na base |
| --- | --- | --- |
| Marevan | warfarin | Não |
| Tylenol | acetaminophen | Sim |
| Aspirina | acetylsalicylic acid | Sim |
| AAS | acetylsalicylic acid | Sim |
| Losartana potássica | losartan | Sim |
| Cataflam | diclofenac | Sim |
| Voltaren | diclofenac | Sim |
| Omeprazol | omeprazole | Sim |
| Pantoprazol | pantoprazole | Sim |
| Amoxicilina | amoxicillin | Sim |
| Azitromicina | azithromycin | Não |
| Ciprofloxacino | ciprofloxacin | Não |
| Glifage | metformin | Sim |
| Diovan | valsartan | Não |
| Norvasc | amlodipine | Sim |
| Atenolol | atenolol | Sim |
| Captopril | captopril | Não |
| Maleato de enalapril | enalapril | Sim |
| Lasix | furosemide | Não |
| Hidroclorotiazida | hydrochlorothiazide | Sim |
| Cloridrato de sertralina | sertraline | Sim |
| Cloridrato de fluoxetina | fluoxetine | Sim |
| Rivotril | clonazepam | Sim |
| Diazepam | diazepam | Não |
| Haldol | haloperidol | Não |
| Tegretol | carbamazepine | Sim |
| Gardenal | phenobarbital | Não |
| Lamictal | lamotrigine | Não |
| Neurontin | gabapentin | Não |
| Prednisona | prednisone | Não |
| Dexametasona | dexamethasone | Sim |
| Digoxina | digoxin | Sim |
| Cloridrato de amiodarona | amiodarone | Sim |
| Bissulfato de clopidogrel | clopidogrel | Não |
| Xarelto | rivaroxaban | Não |
| Rosuvastatina cálcica | rosuvastatin | Sim |
| Atorvastatina cálcica | atorvastatin | Sim |
| Viagra | sildenafil | Não |
| Fluconazol | fluconazole | Não |
| Cetoconazol | ketoconazole | Não |
| Itraconazol | itraconazole | Não |
| Metronidazol | metronidazole | Não |
| Claritromicina | clarithromycin | Sim |
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
Nota: excluídos por ausência do princípio ativo esperado no DDInter: Lexotan (bromazepam), Dorflex, Novalgina e Benegrip (dipirona). “Bula na base” refere-se à produção em 11 nov. 2026; Ibuprofeno e Norvasc chegavam às suas bulas pelos registros duplicados em português (seção 3.3). Pares com bula de pelo menos um fármaco: 1.048 de 1.483; de ambos: 298.

## APÊNDICE B – REGISTROS DE EXECUÇÃO DOS EXPERIMENTOS

Todos os experimentos foram executados no servidor de produção em 11 nov. 2026. Os registros do comando `evaluation:run` estão em `storage/app/evaluation/runs/` no servidor e podem ser consultados, em forma resumida, por `GET /api/v1/audit/evaluations`; cada um guarda o *hash* SHA-256 do conjunto de teste, os modelos, os parâmetros e os resultados. As execuções usaram o código na versão `947ea4e`; as perguntas livres, feitas antes, a versão `ab1d851`.

| Experimento | Registro ou fonte | Conjunto de teste (início do SHA-256) | Parâmetros |
| --- | --- | --- | --- |
| Identificação (ajuste) | `20261111-082042-prod-identificacao-ajuste-5a135fd9.json` | `identification.v2.json` (`a5f54b221e1a`) | — |
| Identificação (reservado) | `20261111-082047-prod-identificacao-reservado-6ada6bd2.json` | `identification.heldout.json` (`d3519d0ae8b0`) | — |
| Interações (1.483 pares) | `20261111-082209-prod-interacoes-1483-0d46ca9e.json` | `interactions.v2.json` (`62d2ed86ce1a`) | `--mode=lexical` |
| Interações (protocolo de 30 pares) | `20261111-082248-prod-protocolo30-hibrido-7cdf5716.json` | `interactions.protocol30.v2.json` (`fa993eeddb40`) | `--mode=hybrid` |
| Recuperação por assunto | `20261111-083312-prod-recuperacao-assuntos-87d8519f.json` | `retrieval.subjects.v1.json` (`5e2b54ee712c`) | `--mode=lexical,vector,hybrid` |
| Modelo sem recuperação | `20261111-083815-prod-sem-recuperacao-120-9d8c430b.json` | `interactions.v2.json` (`62d2ed86ce1a`) | `--limit=120 --seed=42` |
| Perguntas livres (234) | `docs/qa/avaliacao-producao-2026-11-11.json` e `.analise.json` | perguntas em `scripts/evaluation/bateria.py` | API `POST /api/v1/evaluation/ask` |
| Legibilidade (269 pares) | `GET /api/v1/audit/readability` | respostas registradas em produção | — |
| Inventário da base | `GET /api/v1/stats/knowledge` e `GET /api/v1/audit/tcc` | — | — |

Fonte: Elaborado pelos autores (2026).

Os identificadores acima permitem localizar e conferir cada registro, mas não bastam, sozinhos, para reproduzir os resultados. A reexecução depende do estado dos dados e dos comandos do sistema, descritos a seguir; não houve reexecução por terceiros.

a) Estado dos dados: a base de produção descrita na seção 3.3 e no Apêndice A. Os arquivos do DDInter são importados com `knowledge:import-drug-interactions <pasta>`, e o catálogo de princípios ativos e os vínculos são reconstruídos com `knowledge:build-ingredients`. Bulas incluídas ou corrigidas depois da avaliação alteram os resultados.
b) Conjuntos de teste: versionados em `database/evaluation/` e gerados a partir do gabarito manual com `evaluation:build-datasets --name=v2` (identificação e interações) e `evaluation:build-datasets --seed=database/evaluation/identification.heldout.seed.json --name=heldout --identification-only` (conjunto reservado); o conjunto por assunto (`retrieval.subjects.v1.json`) foi escrito à mão e se resolve no banco medido. Os *hashes* SHA-256 constam dos registros.
c) Execuções: no servidor, `sudo bulai-deploy artisan evaluation:run <tarefa>`, com a tarefa `identification`, `interactions`, `retrieval`, `generation` ou `baseline` e as opções `--dataset`, `--mode` (`lexical`, `vector` ou `hybrid`; na recuperação, lista separada por vírgula), `--limit`, `--seed` (padrão 42) e `--label`. As perguntas livres são refeitas com `python3 scripts/evaluation/bateria.py <token> saida.json` e analisadas com `scripts/evaluation/analisar.py` e `relatorio.py`, com um *token* de API de escopo `api:evaluate`.
d) Limites da reprodução: as execuções que chamam o modelo de linguagem não são determinísticas; a API de avaliação limita as perguntas a 300 por dia; e os resultados das seções 5.1 e 5.2 refletem a base antes da correção dos princípios ativos duplicados.
e) Correção posterior à medição: a versão seguinte do sistema impede que o vínculo de uma bula ou a administração criem um princípio ativo em português quando o nome já leva, inclusive pelas regras de grafia, a um princípio ativo existente (`ActiveIngredientResolver::resolveAndLearn`), e funde os duplicados existentes no do DDInter durante `knowledge:build-ingredients` (`app/Services/Knowledge/DuplicateIngredientMerger.php`), movendo os vínculos de bulas e de medicamentos. Após publicá-la, executam-se `knowledge:build-ingredients` e, de novo, a identificação e as interações.