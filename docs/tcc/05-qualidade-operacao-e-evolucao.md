# Qualidade, operação e evolução

## Estratégia de testes

O projeto usa PHPUnit e separa testes de feature e unitários. Os testes de feature validam fluxos atravessando HTTP, Livewire, banco, fila e filesystem falso; os unitários verificam transformações determinísticas do pipeline.

Principais grupos cobertos:

- login, logout, perfil administrativo e seeder;
- emissão e validação indireta de tokens por API;
- importação estruturada e atualização idempotente;
- anexação privada e rejeição de PDF inválido ou duplicado;
- ingestão de lotes com resultados mistos;
- origem obrigatória e isolamento entre origens;
- quarentena, promoção, compensação e falhas de persistência;
- associação manual, conflitos, repetição e concorrência;
- páginas administrativas, filtros, paginação e escape de conteúdo;
- extração, normalização, seções, chunking e rastreabilidade;
- processamento, reprocessamento atômico e preservação de chunks;
- portabilidade do filesystem e limpeza de cópias temporárias;
- política do manifesto e service worker.

Comandos de verificação:

```bash
php artisan test --compact tests/Feature/Knowledge/KnowledgeDocumentProcessingTest.php
php artisan test
vendor/bin/pint --dirty --format agent
```

O TCC deve informar o resultado real da execução, data, commit, ambiente e quantidade de testes. A existência da suíte não prova, sozinha, ausência de defeitos ou adequação clínica.

## Ambiente de desenvolvimento

Serviços e passos principais:

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
docker compose up -d postgres
php artisan migrate:fresh
npm run build
composer run dev
```

O PostgreSQL do Compose usa a imagem pgvector para PostgreSQL 17. O banco de teste é separado. Em produção, migrations devem ser executadas sem `fresh` para preservar dados.

## Operação

Componentes que precisam permanecer disponíveis:

- aplicação web Laravel;
- PostgreSQL;
- disco privado configurado;
- worker da fila `database`;
- servidor de assets construídos pelo Vite;
- logs protegidos e com rotação.

Verificações operacionais mínimas:

- conexão com banco e execução das migrations;
- escrita, leitura e exclusão no disco de conhecimento;
- worker ativo e sem Jobs acumulados ou falhos;
- extensões PHP exigidas pelo parser (`gd`, `iconv`, `zlib`);
- limites de upload do PHP e do proxy compatíveis com a aplicação;
- permissões de diretórios, HTTPS e configuração de sessão;
- backup e teste de restauração do banco e dos objetos.

## Observabilidade atual

O processamento registra início, métricas de extração, quantidade de chunks, conclusão e falhas controladas, sem registrar o texto do documento. Erros inesperados recebem identificador para correlação com logs.

Ainda faltam dashboards operacionais, métricas temporais consolidadas, alertas de fila, auditoria administrativa e SLOs formais.

## Limitações técnicas atuais

- sem OCR para PDFs baseados em imagem;
- sem embeddings, busca vetorial, Hybrid RAG ou LLM;
- ingestão do lote síncrona na requisição;
- um único PDF ativo por documento e sem histórico de versões;
- sem edição geral de metadados do documento no Admin;
- sem interface de gestão de usuários e tokens;
- sem expiração automática da quarentena;
- sem reconciliação automática entre banco e filesystem;
- sem acesso autenticado offline;
- sem configuração S3 pronta;
- sem validação de desempenho em carga real;
- sem avaliação com corpus farmacêutico real autorizado.

## Trabalhos futuros tecnicamente coerentes

1. instituir governança, versionamento e reconciliação de arquivos;
2. adicionar OCR com indicação clara do método e da confiança;
3. definir modelo de embedding e tokenizer, com versionamento;
4. implementar recuperação lexical e vetorial com filtros de procedência;
5. avaliar chunking, recall, precisão e relevância com conjunto de referência;
6. implementar reranking e geração com citações de fonte e página;
7. criar avaliação humana por especialistas do domínio;
8. definir políticas de atualização, expiração e remoção do corpus;
9. instrumentar latência, throughput, falhas e custo;
10. realizar análise de segurança e privacidade antes de produção.

## Métricas recomendadas para avaliação

Nenhum valor deve ser preenchido sem experimento. Possíveis métricas:

| Dimensão | Exemplos |
| --- | --- |
| Ingestão | arquivos/minuto, taxa de erro, taxa de pendência, duplicidades |
| Extração | documentos processados, páginas/segundo, falhas por tipo de PDF |
| Chunking | chunks/documento, tamanho médio, preservação de seção/página |
| Operação | latência p50/p95, Jobs falhos, uso de memória, espaço armazenado |
| Recuperação futura | Recall@k, Precision@k, MRR, nDCG |
| Resposta futura | correção, completude, fidelidade às fontes, taxa de citações válidas |
| Usabilidade | tempo de tarefa, erros de operação, satisfação dos curadores |

O protocolo já proposto no projeto do TCC prevê 20 pares com interação documentada — 10 graves, 5 moderadas e 5 leves — e aplicação do SUS com 5 a 10 participantes. Antes da execução, devem ser acrescentados pares sem interação, versão das fontes, gabarito validado, regras para respostas inconclusivas, tarefas de usabilidade e procedimento de consentimento.

## Validade dos resultados

Ao analisar resultados, o TCC deve discutir:

- validade interna: controle do ambiente e repetibilidade;
- validade externa: representatividade dos PDFs e usuários avaliados;
- validade de construto: se as métricas realmente representam qualidade;
- limitações de conclusão: tamanho da amostra e ausência de significância;
- viés do corpus e de avaliadores;
- distinção entre sucesso técnico e segurança de uso farmacêutico.
