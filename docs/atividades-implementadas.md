# Atividades implementadas

## Objetivo

Este documento apresenta o que já está pronto no BULA-IA e como cada fluxo funciona. Ele descreve o estado observado no código e nos testes em 1º de outubro de 2026.

## Resumo funcional

| Atividade | Estado | Como acessar ou executar |
| --- | --- | --- |
| Autenticação administrativa | Pronta | `/login` |
| Controle de acesso por perfil | Pronto | Middleware `auth` e `admin` |
| Painel da base de conhecimento | Pronto | `/admin/knowledge` |
| Cadastro e gestão de origens | Prontos | `/admin/knowledge/sources` |
| Cadastro manual de documento lógico | Pronto | `/admin/knowledge/documents/create` |
| Catálogo e inspeção de documentos | Prontos | `/admin/knowledge/documents` |
| Upload administrativo de PDFs em lote | Pronto | `/admin/knowledge/upload` |
| Acompanhamento de lotes | Pronto | `/admin/knowledge/batches` |
| Revisão e associação de pendências | Prontas | `/admin/knowledge/pending` |
| Importação estruturada por API | Pronta | `POST /api/internal/knowledge/import` |
| Anexação de PDF por API | Pronta | `POST /api/internal/knowledge/{document}/file` |
| Ingestão de lote por API | Pronta | `POST /api/internal/knowledge/batches` |
| Associação manual por API | Pronta | `POST /api/internal/knowledge/batches/{batch}/items/{item}/associate` |
| Extração e fragmentação de PDFs | Prontas | Job, Admin ou comando Artisan |
| Armazenamento privado e portável | Pronto | Disco definido por `KNOWLEDGE_FILESYSTEM_DISK` |
| PWA básica e identidade visual | Prontas | Manifesto, service worker e SVGs em `public/brand` |

## 1. Autenticação e autorização administrativa

O sistema usa autenticação de sessão do Laravel. O usuário entra por `/login` e encerra a sessão por `POST /logout`. Todas as páginas administrativas exigem autenticação e o perfil `ADMIN`; usuários com perfil `USER` recebem `403`.

O primeiro administrador pode ser criado pelo seeder:

```bash
BULAI_ADMIN_NAME="BULA-IA Admin" \
BULAI_ADMIN_EMAIL="admin@example.com" \
BULAI_ADMIN_PASSWORD="change-me-locally" \
php artisan db:seed
```

O seeder não cria uma conta quando e-mail ou senha não estão definidos explicitamente.

## 2. Painel da base de conhecimento

`/admin/knowledge` apresenta contagens atualizadas de documentos, arquivos, lotes, itens pendentes e erros. A página funciona como entrada para os fluxos de origens, documentos, upload, lotes e pendências.

## 3. Origens dos documentos

Em `/admin/knowledge/sources`, o administrador pode pesquisar, paginar e filtrar origens ativas ou inativas. Também pode:

- criar uma origem;
- editar seus dados de procedência;
- consultar seus documentos recentes;
- ativar ou desativar a origem sem apagar o histórico.

As origens distinguem nome, coleção, tipo, organização, URL, país e descrição. A combinação normalizada de nome e coleção evita duplicidades que diferem apenas por maiúsculas e minúsculas. Somente origens ativas podem receber novos uploads.

A tela de upload oferece criação rápida de origem e mantém os PDFs já selecionados no formulário.

## 4. Documentos lógicos

O documento lógico representa a identidade e os metadados de um conteúdo, independentemente do PDF físico.

Em `/admin/knowledge/documents/create`, o administrador pode cadastrar um documento sem PDF informando origem, título, tipo e identificadores. Em `/admin/knowledge/documents`, pode pesquisar por título ou chave externa, filtrar por estado do arquivo e navegar com paginação.

A página de detalhes mostra procedência, lote, metadados, estados independentes de arquivo, conteúdo e embedding, SHA-256, informações de processamento e chunks ordenados. O conteúdo exibido é escapado, e caminhos físicos privados não são expostos.

Os aliases `/admin/documents` e `/admin/documents/{document}` continuam disponíveis para compatibilidade.

## 5. Upload e ingestão em lote

O upload administrativo em `/admin/knowledge/upload` aceita múltiplos PDFs e exige a seleção de uma origem ativa. O limite padrão é de 15 arquivos e 20 MiB por arquivo, configurável por `KNOWLEDGE_INGESTION_MAX_FILES` e `KNOWLEDGE_INGESTION_MAX_FILE_KILOBYTES`.

Cada arquivo é tratado isoladamente. Uma falha não invalida os demais itens do lote. A resolução procura, nesta ordem:

1. `external_key` explícita;
2. SHA-256 já conhecido;
3. identificador da origem, limitado à origem do lote;
4. nome de arquivo normalizado;
5. criação automática, somente quando existem identidade e metadados estruturados suficientes.

O resultado de cada item é:

- `ASSOCIATED`: PDF associado ao documento;
- `DUPLICATE`: os mesmos bytes já existem e a nova cópia é descartada;
- `PENDING`: associação não segura; o PDF fica em quarentena privada;
- `ERROR`: falha controlada do item, sem manter o arquivo.

O sistema nunca sobrescreve silenciosamente um documento que já possui outro PDF. Os lotes e seus contadores podem ser consultados em `/admin/knowledge/batches` e `/admin/knowledge/batches/{batch}`.

## 6. Revisão de pendências

`/admin/knowledge/pending` lista apenas itens que ainda exigem ação. Na revisão, o administrador pode:

- pesquisar e selecionar um documento existente da mesma origem; ou
- criar um novo documento lógico e associá-lo ao PDF pendente.

A confirmação revalida o item, o arquivo e o documento dentro de transação e com bloqueio das linhas. O serviço detecta repetição, arquivo ausente ou alterado, documento ocupado e conflitos de SHA-256. Em caso de sucesso, promove o PDF da quarentena para o armazenamento definitivo, recalcula os contadores do lote e agenda o processamento do documento.

## 7. API interna de ingestão

As rotas sob `/api/internal` exigem Bearer Token com escopo `knowledge:ingest` e possuem limitação de requisições. Um token temporário é emitido por:

```bash
php artisan knowledge:issue-token "curadoria-dia"
```

O token em texto puro é mostrado uma única vez; somente seu hash SHA-256 é persistido. Tokens expirados, revogados ou com escopo incorreto são rejeitados.

Fluxos disponíveis:

- `POST /api/internal/knowledge/import`: cria ou atualiza um documento por `external_key` e substitui seus chunks por conteúdo estruturado;
- `POST /api/internal/knowledge/{document}/file`: anexa um PDF ao documento indicado, sem inferir identidade pelo nome;
- `POST /api/internal/knowledge/batches`: recebe lote multipart e aplica a mesma lógica usada pelo upload administrativo;
- `POST /api/internal/knowledge/batches/{batch}/items/{item}/associate`: resolve um item pendente sem exigir novo upload.

Erros inesperados retornam um código controlado e um `error_id`. Mensagens SQL, stack traces, segredos e caminhos privados ficam apenas nos logs.

## 8. Processamento de PDFs

O pipeline implementado é:

```text
PDF privado -> extração por página -> normalização conservadora
-> detecção de seções -> chunks rastreáveis -> knowledge_chunks
```

O processador extrai texto localmente, preserva ordem e página, reconhece títulos de seções e divide o conteúdo sem cruzar seções. Cada chunk registra índice, seção e intervalo de páginas.

O processamento pode ser iniciado pela página do documento ou pelos comandos:

```bash
php artisan knowledge:process-document 123
php artisan knowledge:process-document 123 --force
php artisan knowledge:process-document 123 --force --sync
```

Sem `--sync`, o comando agenda o Job na fila. O worker de produção deve estar ativo:

```bash
php artisan queue:work database --queue=default --timeout=60
```

Documentos já prontos são ignorados, salvo com `--force`. O reprocessamento substitui os chunks atomicamente; uma falha mantém os chunks válidos anteriores e grava somente código e referência controlados do erro. PDFs compostos apenas por imagem falham porque OCR ainda não está implementado.

## 9. Armazenamento e integridade

PDFs ficam fora de `public/`, no disco Laravel definido por `KNOWLEDGE_FILESYSTEM_DISK` (padrão `knowledge`). As chaves são geradas pela aplicação:

```text
knowledge/originals/{document_id}/{operation_uuid}.pdf
knowledge/pending/{batch_id}/{uuid}.pdf
knowledge/staging/{operation_uuid}/original.pdf
```

SHA-256 protege a identidade dos bytes e detecta duplicidades ou alterações. A promoção entre quarentena, staging e destino usa streams e verificação de checksum, permitindo trocar o driver do filesystem futuramente. Falhas comuns têm compensação de arquivos; uma queda abrupta exatamente entre filesystem e commit de banco ainda exige futura rotina de reconciliação.

## 10. PWA e interface

A aplicação possui manifesto instalável, service worker e identidade visual em `public/brand`. O service worker pode armazenar assets estáticos, mas não guarda navegações autenticadas de `/admin` ou `/login`. Não há uso offline dos documentos administrativos.

## Verificação automatizada

A suíte cobre os principais fluxos e falhas de autenticação, autorização, importação, anexação, lotes, duplicidades, quarentena, associação manual, origens, Admin, processamento, portabilidade de armazenamento e PWA.

Para executar toda a suíte:

```bash
php artisan test
```

Para mudanças futuras, deve-se executar primeiro o arquivo de teste mais diretamente relacionado e, quando apropriado, a suíte completa.

## Limitações conhecidas

- embeddings, busca vetorial, RAG e respostas por LLM ainda não foram implementados;
- OCR não está disponível;
- não existe interface de gestão de usuários;
- documentos podem ser criados e inspecionados no Admin, mas ainda não há edição geral de seus metadados;
- emissão e revogação administrativa de tokens não possuem interface web;
- ingestão em lote ocorre sincronamente durante a requisição;
- não existe importação por ZIP nem manifesto separado;
- não há expiração ou comando de reconciliação para arquivos abandonados em quarentena/staging;
- limites reais de upload do PHP e do proxy precisam ser validados no ambiente de produção;
- acesso autenticado offline não está disponível;
- a ativação de S3 ainda depende de instalação/configuração do adapter, credenciais e infraestrutura.

## Arquivos e áreas relacionadas

- Rotas web: `routes/web.php`
- Rotas internas: `routes/api.php`
- Componentes administrativos: `app/Livewire/Admin`
- Serviços de domínio: `app/Services/Knowledge`
- Job de processamento: `app/Jobs/ProcessKnowledgeDocument.php`
- Modelos: `app/Models/Knowledge`
- Migrações: `database/migrations`
- Testes funcionais: `tests/Feature`
- Testes unitários do pipeline: `tests/Unit/Services/Knowledge`
- Detalhes de arquitetura: `docs/technical-foundation.md`
