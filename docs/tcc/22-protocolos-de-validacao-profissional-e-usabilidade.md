# Protocolos de validação por profissional de saúde e de usabilidade (SUS)

Mapeamento no docx: Cap. 3, 3.5.1 (verificação de integridade pelo profissional), 3.5.2 (SUS) e 3.5.3.

> **Estado: NÃO REALIZADOS.** Este documento organiza os protocolos e instrumentos. Nenhum resultado de profissional de saúde ou de usuário existe; nenhuma conclusão clínica ou de usabilidade pode ser declarada a partir dele.

## 1. Validação por profissional (farmácia/medicina)

**Objetivo**: verificar a integridade e a adequação clínica das saídas do sistema, o que nenhuma métrica automática mede.

**Amostra sugerida**: os 30 pares de `database/evaluation/interactions.protocol30.v1.json` (10 graves, 5 moderadas, 5 leves, 10 sem interação) + ~10 explicações geradas por LLM com evidência. O profissional deve receber a lista **sem** o rótulo do sistema em uma primeira rodada (avaliação cega) e depois com ele.

**Ficha por par** (preencher): (1) Existe interação clinicamente relevante entre os fármacos? sim/não/incerto; (2) gravidade que atribuiria (menor/moderada/maior); (3) o rótulo do sistema está de acordo? ; (4) explicação gerada: fiel à bula / imprecisa / errada / enganosa; (5) o trecho de bula citado sustenta a explicação? ; (6) a mensagem pode induzir o usuário a conduta indevida? ; (7) comentários.

**Pontos já suspeitos para revisão prioritária** (levantados pelo autor, sem validação): pares rotulados "sem interação" por ausência no DDInter que a farmacologia geral descreve como interativos — por exemplo fenobarbital+propranolol (indução enzimática) e captopril+espironolactona (hipercalemia) —; aliases `pending_review` em `active_ingredient_aliases`; ambiguidade de nomes ("cálcio", "ferro"); equivalência Menor/Moderada/Maior ↔ Leve/Moderada/Grave.

**Requisitos formais** (a decidir com a instituição): submissão ao comitê de ética quando houver participantes humanos; Termo de Consentimento Livre e Esclarecido; identificação e habilitação do profissional (registro no conselho); declaração de conflito de interesses; registro de quem avaliou e quando. **Não declarar concluída sem participação efetiva de profissional habilitado.**

**Análise sugerida**: concordância (Cohen kappa se houver dois avaliadores) [referência a incluir], sensibilidade/especificidade do sistema **tendo o profissional como referência**, lista de erros por categoria.

## 2. Usabilidade — SUS

**Participantes**: 5 a 10 voluntários leigos em saúde, por conveniência (conforme 3.5.2); resultado **exploratório**, sem generalização estatística.

**Tarefas** (cronometrar e anotar erros): (T1) instalar/abrir o aplicativo e entrar; (T2) encontrar "Marevan" e "Aspirina" e adicioná-los; (T3) rodar a análise e dizer, em palavras próprias, o que o resultado informa; (T4) encontrar de qual bula veio o trecho e em que página; (T5) distinguir um resultado "documentado" de um "inconclusivo" e dizer se "não encontrada" significa segura (resposta correta: não); (T6) apagar a consulta do histórico.

**Questionário SUS** (Brooke, 1996) — itens em tradução livre do autor; usar a versão em português validada [referência a incluir: adaptação transcultural brasileira do SUS]. Escala 1 (discordo totalmente) a 5 (concordo totalmente):
1. Eu acho que gostaria de usar este sistema com frequência. 2. Achei o sistema desnecessariamente complexo. 3. Achei o sistema fácil de usar. 4. Acho que precisaria de ajuda de uma pessoa técnica para usar o sistema. 5. Achei que as várias funções estavam bem integradas. 6. Achei que havia muita inconsistência no sistema. 7. Imagino que a maioria das pessoas aprenderia a usar o sistema rapidamente. 8. Achei o sistema muito pesado/incômodo de usar. 9. Senti-me muito confiante ao usar o sistema. 10. Precisei aprender muitas coisas antes de conseguir usar o sistema.

**Cálculo**: itens ímpares contribuem (resposta − 1); pares contribuem (5 − resposta); soma × 2,5 → escore 0–100 por participante; reportar média, desvio, mediana e mínimo/máximo. Referências do projeto: > 68 acima da média; > 80,3 excelência (BANGOR; KORTUM; MILLER, 2009).

**Perguntas adicionais** (qualitativas): o que você entendeu sobre "Interação não identificada"? Em quais telas teve dúvida? Confiaria em decidir apenas com isto? (esperado: não).

**Registro**: planilha com id anônimo, idade, familiaridade com tecnologia, tempos, erros, respostas SUS; sem dados pessoais identificáveis nem medicamentos reais dos participantes.

## 3. Cronograma sugerido
Revisão do profissional antes do SUS (para corrigir erros graves de conteúdo que contaminariam a percepção de usabilidade); SUS depois do ajuste final; reportar ambos separadamente.
