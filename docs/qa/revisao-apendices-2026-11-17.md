# Revisão dos Apêndices B, C e G e da LGPD no artigo (17/11/2026)

Revisão pedida pelos autores antes da entrega. Não houve alteração funcional no sistema: só correções documentais, com base nos registros de produção. As fontes estão no fim deste arquivo.

## Pendências resolvidas

| # | Pendência | O que foi verificado | Correção no artigo |
|---|---|---|---|
| 1 | Quantidade de registros e correspondência com conjuntos e hashes | Pela `GET /api/v1/audit/evaluations`, há **16** registros (o texto dizia 12). Todos têm o SHA-256 igual ao do arquivo em `database/evaluation/`: 5 de identificação, 7 de interações, 3 de recuperação e 1 sem recuperação. A Tabela B1 misturava horário de início e de término. Os 3 registros de recuperação usam o mesmo conjunto; 369 → 362 vem do avaliador (PR #60), não do conjunto | Apêndice B: "16 registros", com a distribuição por tipo e a data da conferência; horários padronizados pelo início (8h33, 22h50, 0h58); a explicação de 369 → 362 |
| 2 | Contabilidade das 24 regras | 24 = **14 de erro + 6 de aviso + 4 informativas**. Nenhuma violação nas de erro; 5 avisos sem ocorrência e 1 com ocorrência (648 interações sem princípio ativo resolvido); as 4 informativas têm ocorrências. O texto ("19 sem violação, 0 erros e 1 aviso") somava 20 e omitia uma informativa (8.243 pares duplicados no DDInter) | Tabela C1: composição das 24 regras e a quarta regra informativa |
| 3 | Independência das 32 perguntas | O conjunto foi escrito antes da rodada `0a59cc1`. As correções de `aba1f43` e `cc9cc9a` partiram das falhas dessa rodada (gravidade ausente do texto, dipirona) | Apêndice G (introdução) e título da Tabela G1: só a primeira rodada é independente; as seguintes são regressão. Na metodologia: "independente só na primeira rodada" |
| 4 | RAG: título da seção × relevância semântica | O acerto é decidido pelo título da seção. Isso não é relevância semântica nem um limite inferior dela: conta como erro um trecho de seção vizinha que trata do assunto (15,1% dos erros da híbrida) e como acerto um trecho mal segmentado com o título certo (caso do Apêndice F) | Recuperação de Trechos, Considerações finais ("pelo critério do título da seção") e nota da Tabela G3 |
| 5 | Instabilidade do validador | Reexecutado localmente sobre os textos gravados (mesmo código da rodada final), o validador decide sempre igual para o mesmo texto e o mesmo trecho. A variação vem da geração: a frase da amiodarona é aceita com o trecho da bula e recusada quando cita o registro do DDInter. Na rodada final, 52 recusas, 39 pela regra frase a frase; **17 são a frase "Isso quer dizer que…", que o próprio prompt pede**. Exemplo de conteúdo correto recusado: "exame que mede o tempo de coagulação (INR)", contra "monitorar os níveis de protrombina (INR)" na bula. O registro da recusa não guarda o trecho citado, então cada caso não pode ser reconstituído | Tabela G4 (linha revista e duas novas) e nota da Tabela G4; nota da Tabela C5 (o texto da recusa passou a ser gravado em 16/11, mas o trecho citado não) |
| 6 | Seleção das 10 bulas | As 10 não foram sorteadas. São todas as bulas humanizadas que existiam em produção: geradas sob demanda de 10 a 15/11, no uso e nos testes, quando a tela da bula foi aberta; 3 são associações e 3 ficaram parciais. A v3 refez exatamente os mesmos 10 conjuntos | Nota da Tabela G2: pareada, mas não representa o catálogo |
| 7 | LGPD × receitas, perfil e triagem | O parágrafo citava só alergias, remédios e consultas | Arquitetura: receita por foto (imagem enviada ao modelo e apagada; itens, nome e registro do prescritor gravados até a revogação); revogação apaga perfil, receitas e perguntas; cuidador só com aprovação do paciente; perguntas com sinal de crise enviadas ao modelo; tratamento informado ao modelo; API de auditoria devolve o texto das perguntas, identificadas por número |

## Limitações que continuam

- **Validador lexical:** conflita com o prompt (a frase "Isso quer dizer que…") e recusa conteúdo correto escrito com outras palavras. Pela orientação de não mudar o sistema para melhorar indicadores, ficou documentado e não foi corrigido.
- **Trecho citado:** o registro da afirmação recusada não guarda o trecho citado pelo modelo, o que impede reconstituir cada recusa.
- **Recuperação:** a relevância semântica não foi avaliada por pessoas, e as taxas medem só a localização da seção esperada.
- **Perguntas livres:** depois das correções, não há conjunto independente novo; as rodadas seguintes são de regressão.
- **Legibilidade:** a amostra de 10 bulas é de conveniência e não representa o catálogo.
- **LGPD:** faltam os mecanismos do art. 33. Também ficam em aberto o dado de terceiro (prescritor), o acesso ao texto das perguntas pela API de auditoria, a política de privacidade, o encarregado e o relatório de impacto, que não fazem parte do protótipo.
- **Tamanho do texto:** o corpo do artigo passou de 6.436 para 6.586 palavras. Os autores precisam conferir as páginas no Word.

## Trechos modificados no artigo (`.md` e Word)

1. **Arquitetura do Sistema:** o parágrafo de LGPD (item 7).
2. **Metodologia, Quadro de experimentos:** "independente só na primeira rodada".
3. **Recuperação de Trechos:** fim do parágrafo, sobre a relevância pelo título.
4. **Considerações finais:** "pelo critério do título da seção".
5. **Apêndice B:** horário do commit `037adea` no texto; 3 horários da Tabela B1; o parágrafo dos hashes (16 registros, distribuição, data da conferência, 369 → 362).
6. **Apêndice C:** duas linhas da Tabela C1 e a nota da Tabela C5.
7. **Apêndice G:** introdução (independência), título da Tabela G1, notas das Tabelas G2, G3 e G4, e linhas da Tabela G4.

## Fontes

- `GET /api/v1/audit/evaluations`, em 17/11/2026, e `sha256` dos arquivos em `database/evaluation/`.
- `GET /api/v1/audit/quality`, em 12/11 e 16/11/2026, com a lista das 24 regras.
- Respostas das 3 rodadas das 32 perguntas (`scripts/evaluation/independente.py`) e afirmações recusadas gravadas na rodada `cc9cc9a`.
- `GET /api/v1/audit/leaflet-summaries`, em 16/11/2026: 21 registros (1 v1, 10 v2, 10 v3).
- Reexecução de `ExplanationValidator::validateSegments` sobre os textos gravados. O validador não mudou depois de `cc9cc9a`.
