# Evidências e checklist para a entrega do TCC

## Identificação da versão avaliada

Preencher antes dos experimentos:

- commit ou tag: `PENDENTE`;
- data da avaliação: `PENDENTE`;
- sistema operacional/ambiente: `PENDENTE`;
- versões de PHP, Laravel, PostgreSQL e Node: `PENDENTE`;
- configuração de CPU e memória: `PENDENTE`;
- driver e capacidade do armazenamento: `PENDENTE`;
- quantidade, origem, licença e características dos PDFs: `PENDENTE`.
- versão e licença da base DrugBank, caso utilizada: `PENDENTE`;
- relação final dos 30 medicamentos do MVP: `PENDENTE`;
- gabarito validado dos 20 pares de avaliação: `PENDENTE`.

## Evidências recomendadas

### Engenharia de requisitos

- problema e público-alvo claramente delimitados;
- objetivos geral e específicos ligados aos requisitos;
- requisitos funcionais e não funcionais priorizados;
- critérios de aceitação e itens fora do escopo;
- casos de uso ou histórias dos principais fluxos.

### Projeto

- diagrama de contexto;
- diagrama de componentes ou implantação;
- modelo entidade-relacionamento derivado das migrations;
- diagrama de sequência da ingestão e da associação manual;
- máquina de estados de documento e item de lote;
- justificativa das tecnologias e alternativas consideradas.

### Implementação

- telas do painel, origens, upload, lotes, pendências e documento;
- exemplo sanitizado de chamada da API;
- exemplo rastreável de chunk com seção e página;
- demonstração de duplicidade, pendência e erro controlado;
- demonstração do Job e da fila;
- referência aos arquivos do repositório, sem copiar grandes blocos de código.

### Validação

- saída completa da suíte de testes no commit avaliado;
- matriz requisito versus teste/evidência;
- procedimento reproduzível para cada experimento;
- massa de dados autorizada e caracterizada;
- métricas brutas e método de cálculo;
- comparação com baseline quando houver hipótese comparativa;
- análise de falhas e casos não atendidos.

### Segurança e ética

- origem, licença e autorização dos documentos;
- análise de dados pessoais e LGPD quando aplicável;
- modelo de ameaças e riscos residuais;
- controles de acesso, retenção, backup e descarte;
- aviso explícito de que o sistema não substitui orientação médica;
- aprovação institucional ou ética, caso exigida pelo contexto da pesquisa.

## Matriz de rastreabilidade sugerida

| Requisito | Implementação | Teste/evidência | Resultado |
| --- | --- | --- | --- |
| RF01/RF02 | Controllers de sessão e middleware Admin | `tests/Feature/Auth` | PENDENTE DE REGISTRO |
| RF03 | Livewire e serviço de origens | `KnowledgeSourceAdminTest` | PENDENTE DE REGISTRO |
| RF04 | Livewire e lifecycle de documentos | `KnowledgeDocumentLifecycleTest` | PENDENTE DE REGISTRO |
| RF05/RF06 | API e serviços de importação/arquivo | `KnowledgeImportApiTest`, `KnowledgeFileApiTest` | PENDENTE DE REGISTRO |
| RF07/RF08/RF09 | Serviço de lote | `KnowledgeBatchApiTest` | PENDENTE DE REGISTRO |
| RF10 | Serviço de associação | `KnowledgePendingItemAssociationApiTest` | PENDENTE DE REGISTRO |
| RF11/RF12 | Pipeline, Job e comando | `KnowledgeDocumentProcessingTest` e testes unitários | PENDENTE DE REGISTRO |
| RF13/RF14 | Livewire Admin e relacionamentos | `tests/Feature/Admin` | PENDENTE DE REGISTRO |

Substitua “PENDENTE DE REGISTRO” pelo resultado real da execução associada à versão avaliada.

## Experimentos mínimos sugeridos

1. executar a suíte completa em banco limpo;
2. importar PDF textual multipágina e verificar ordem, seção e páginas;
3. repetir o mesmo upload e confirmar idempotência/duplicidade;
4. enviar arquivo sem correspondência e resolver pelo fluxo humano;
5. reprocessar documento e confirmar substituição sem duplicação;
6. provocar PDF sem texto e registrar falha controlada;
7. medir tempo de ingestão e processamento em amostra caracterizada;
8. verificar que arquivo e caminho privado não aparecem nas respostas;
9. testar acesso como visitante, `USER` e `ADMIN`;
10. restaurar backup em ambiente de ensaio, caso recuperação seja parte do escopo.

## Protocolo de avaliação previsto no projeto

- corpus-alvo: 30 medicamentos de classes prioritárias;
- teste de interações: 20 pares documentados, sendo 10 graves, 5 moderados e 5 leves;
- usabilidade: SUS com 5 a 10 participantes não especialistas;
- revisão qualitativa: ao menos um profissional de farmácia ou medicina;
- critérios: detecção da interação, gravidade, disclaimer e rastreabilidade da fonte.

Antes de executar, complementar com pares negativos, versões das bases, amostragem, consentimento, tarefas, regra de pontuação e método de análise. Esses itens são planejamento, não resultados atuais.

## Itens que não podem ser apresentados como concluídos

- chatbot farmacêutico;
- respostas geradas por IA;
- busca semântica ou híbrida;
- embeddings armazenados e consultados;
- OCR;
- acurácia clínica;
- conformidade integral com LGPD;
- desempenho ou escalabilidade em produção;
- segurança absoluta;
- implantação S3 pronta.

## Revisão final

- [ ] Todas as figuras têm número, legenda, fonte e chamada no texto.
- [ ] Todas as tabelas possuem unidade, amostra e método de obtenção.
- [ ] As versões e o commit avaliado estão registrados.
- [ ] Resultados são separados de expectativas e trabalhos futuros.
- [ ] Limitações e ameaças à validade estão explícitas.
- [ ] Referências acadêmicas foram incluídas pelo autor do TCC.
- [ ] Capturas e logs foram sanitizados.
- [ ] Documentos usados têm origem e autorização registradas.
- [ ] O texto não faz alegações clínicas não avaliadas.
- [ ] A execução pode ser reproduzida a partir dos comandos documentados.
