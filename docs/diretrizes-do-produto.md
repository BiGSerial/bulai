# Diretrizes do produto BULA-IA

Fonte única das regras que governam o que o aplicativo pode e não pode fazer. O texto fica em `config/bulai.php` (`guidelines`) e é usado em três lugares: interface do usuário (rodapé do app do paciente e página inicial), prompts de sistema enviados ao provedor de IA e documentação.

## 1. O sistema nunca substitui o médico

- O BULA-IA é ferramenta informativa de apoio à leitura de bulas. Não diagnostica, não prescreve, não recomenda suspender, substituir, interromper, iniciar nem ajustar dose.
- Toda tela do app do paciente exibe o aviso (`guidelines.disclaimer`); toda chamada ao modelo de linguagem recebe a regra (`guidelines.prompt_rule`) no prompt de sistema; o `ExplanationValidator` descarta qualquer afirmação com verbos de conduta (suspender, trocar, ajustar dose etc.), mesmo que o modelo a produza.
- "Nenhuma interação encontrada" nunca é apresentada como "combinação segura".

## 2. Respostas só com evidência cadastrada

- O modelo responde exclusivamente a partir de trechos recuperados da base (bulas da ANVISA, base de interações DDInter e material científico ingerido pela administração). Sem trecho relevante, o sistema diz que não sabe e orienta a consultar a bula ou um profissional.
- Cada afirmação cita as evidências que a sustentam; afirmações sem citação, com citação inexistente ou sem sobreposição lexical com o trecho citado são descartadas (`ExplanationValidator::MIN_GROUNDING_OVERLAP`).
- Existência e gravidade de interações vêm do motor estruturado (DDInter), nunca do modelo. O contexto de tratamento (medicamentos em uso, alergias) entra no prompt como fato determinístico citável, não como instrução.

## 2a. Entradas livres são dados não confiáveis (defesa em três camadas)

1. **Triagem determinística** (`App\Services\Safety\InputGuard`), antes de qualquer prompt: remove caracteres invisíveis e de controle, neutraliza tags, limita o tamanho e recusa (a) injeção de instruções ("ignore as regras", "você agora é", "system prompt", tags `<evidencia>`, JSON de saída forjado, shell/SQL), (b) código/markup no lugar de pergunta, (c) pedidos de dano (dose letal, autoagressão: resposta fixa com o CVV 188), (d) assuntos fora da proposta. Entradas recusadas são registradas (`medication_questions.status = blocked`, `guard`) sem chamar o modelo.
2. **Prompt de sistema**: o texto do usuário e os trechos de bula vão delimitados e escapados como dado; o modelo é instruído a ignorar instruções contidas neles, a responder só sobre medicamentos e a devolver sempre o mesmo JSON (`insufficient` quando fora do escopo).
3. **Validação da saída** (`ExplanationValidator`): só passam afirmações ancoradas nas evidências citadas e sem conduta clínica; qualquer saída fora do formato é descartada.

O mesmo vale para a receita por foto: o texto da imagem é tratado como dado, a saída é JSON estrito validado e o usuário confirma cada item antes de qualquer gravação.

## 3. O que o usuário vê e o que o backend guarda

| Camada | Conteúdo |
| --- | --- |
| Usuário (PWA) | Resposta em linguagem simples, classificação de risco, aviso "baseado em evidências cadastradas", fontes compactas (documento, seção, página). Trechos brutos só quando a resposta em linguagem simples não pôde ser validada. |
| Backend (admin → Analytics) | Trechos completos, afirmações aceitas/rejeitadas e motivos, modelo, tokens, custo estimado, latências por etapa, modo de recuperação, versão do prompt e do código, contexto de tratamento usado. |

## 4. Dados de saúde

- Alergias, medicamentos em uso, receitas e perguntas só são guardados após consentimento explícito; podem ser apagados a qualquer momento pelo próprio usuário; expiram pelo prazo de retenção (`BULAI_CONSULTATION_RETENTION_DAYS`, `BULAI_QUESTIONS_RETENTION_DAYS`).
- Nenhum dado identificável do usuário é enviado ao provedor de IA: apenas nomes de medicamentos, gravidades e trechos de bula.
- Prompts e respostas brutas do modelo não são armazenados; apenas as afirmações validadas e as métricas.

## 5. Onde cada regra é verificada

| Regra | Código | Teste |
| --- | --- | --- |
| Aviso em toda tela | `resources/views/components/consulta/shell.blade.php` | `tests/Feature/Consulta/ConsultaPwaTest.php` |
| Regra no prompt de sistema | `ExplanationGenerator`, `LeafletSummaryGenerator` | `tests/Feature/Interactions`, `tests/Feature/Consulta/BulaHumanizadaTest.php` |
| Bloqueio de conduta | `ExplanationValidator::FORBIDDEN` | `tests/Feature/Interactions/InteractionAnalysisTest.php` |
| Gravidade só do motor | `PairAnalyzer`, `TreatmentContextService` | `tests/Feature/Consulta/PerfilEPerguntasTest.php` |
| Consentimento e apagamento | `Livewire\Consulta\Perfil` | `PerfilEPerguntasTest` |
| Triagem de entradas (injeção, código, dano, escopo) | `App\Services\Safety\InputGuard` | `tests/Unit/Services/Safety/InputGuardTest.php` |
| Receita por foto: imagem descartada, JSON validado, confirmação humana | `PrescriptionExtractionService`, `Livewire\Consulta\Receita\Revisar` | `tests/Feature/Consulta/ReceitaPorFotoTest.php` |
