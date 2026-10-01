# BULA-IA

O BULA-IA é uma aplicação Laravel para ingestão, curadoria, processamento e rastreabilidade de uma base privada de documentos farmacêuticos. A implementação atual fornece a fundação documental para a evolução futura do projeto em direção a recuperação aumentada por geração (RAG), análise de interações medicamentosas e apresentação de informações em linguagem acessível.

Embeddings, recuperação vetorial, integração com LLM e análise de interações ainda não fazem parte do estado implementado.

## Funcionalidades atuais

- autenticação administrativa por sessão e controle de acesso pelo perfil `ADMIN`;
- cadastro, edição, ativação e desativação de origens documentais;
- cadastro e consulta de documentos lógicos;
- upload administrativo de múltiplos PDFs;
- ingestão estruturada e multipart pela API interna;
- detecção de duplicidades por SHA-256;
- quarentena privada para documentos sem associação segura;
- revisão e associação manual de itens pendentes;
- identificação preliminar de metadados em bulas compatíveis;
- armazenamento privado e portável pelo Laravel Filesystem;
- extração de texto, normalização, detecção de seções e chunking rastreável;
- processamento e reprocessamento assíncrono pela fila;
- painel administrativo para documentos, origens, lotes e pendências;
- base PWA sem cache de navegações autenticadas.

## Stack

- PHP 8.4;
- Laravel 13;
- Livewire 4;
- PostgreSQL 17 com pgvector;
- Laravel Queue com driver de banco de dados;
- Tailwind CSS 4 e Vite;
- PHPUnit 12;
- PrinsFrank PDF Parser.

## Instalação local

Instale as dependências:

```bash
composer install
npm install
```

Crie o arquivo de ambiente e gere a chave da aplicação:

```bash
cp .env.example .env
php artisan key:generate
```

Inicie o PostgreSQL, execute as migrations e compile os assets:

```bash
docker compose up -d postgres
php artisan migrate:fresh
npm run build
```

Para iniciar o ambiente de desenvolvimento:

```bash
composer run dev
```

## Primeiro administrador

Configure as credenciais antes de executar o seeder:

```bash
BULAI_ADMIN_NAME="BULA-IA Admin" \
BULAI_ADMIN_EMAIL="admin@example.com" \
BULAI_ADMIN_PASSWORD="change-me-locally" \
php artisan db:seed
```

O seeder não cria o administrador quando e-mail ou senha não são informados explicitamente.

## Módulo administrativo

O acesso começa em `/login`. As principais páginas protegidas são:

- `/admin/knowledge`: painel geral;
- `/admin/knowledge/upload`: upload de PDFs;
- `/admin/knowledge/sources`: origens documentais;
- `/admin/knowledge/documents`: catálogo de documentos;
- `/admin/knowledge/batches`: lotes de ingestão;
- `/admin/knowledge/pending`: itens que exigem revisão.

Somente usuários com perfil `ADMIN` podem acessar essas rotas.

## API interna

Emita um Bearer Token temporário:

```bash
php artisan knowledge:issue-token "curadoria-dia"
```

Endpoints disponíveis:

- `POST /api/internal/knowledge/import`;
- `POST /api/internal/knowledge/{document}/file`;
- `POST /api/internal/knowledge/batches`;
- `POST /api/internal/knowledge/batches/{batch}/items/{item}/associate`.

Todos exigem o escopo `knowledge:ingest` e estão sujeitos ao rate limiter de ingestão. O token em texto puro é exibido apenas uma vez; somente seu hash é persistido.

## Processamento de documentos

O pipeline atual executa:

```text
PDF privado -> extração por página -> normalização
-> detecção de seções -> chunks rastreáveis
```

Para agendar ou executar o processamento:

```bash
php artisan knowledge:process-document 123
php artisan knowledge:process-document 123 --force
php artisan knowledge:process-document 123 --force --sync
```

Mantenha um worker ativo para o processamento assíncrono:

```bash
php artisan queue:work database --queue=default --timeout=60
```

PDFs compostos apenas por imagem não são processados, pois OCR ainda não foi implementado.

## Armazenamento

Os documentos são armazenados fora de `public/`, no disco configurado por `KNOWLEDGE_FILESYSTEM_DISK`. O padrão é o disco privado `knowledge`, com raiz em `storage/app/private`.

Limites de ingestão e processamento podem ser configurados pelas variáveis `KNOWLEDGE_*` documentadas em `.env.example`.

## Qualidade

Execute a suíte automatizada:

```bash
php artisan test
```

Formate os arquivos PHP alterados antes de um commit:

```bash
vendor/bin/pint --dirty --format agent
```

## Documentação

- [Atividades implementadas](docs/atividades-implementadas.md)
- [Fundação técnica](docs/technical-foundation.md)
- [Material técnico para o TCC](docs/tcc/README.md)

O material do TCC separa funcionalidades concluídas, limitações, evidências pendentes e trabalhos futuros. O projeto acadêmico preliminar é utilizado como contexto, não como restrição de linguagem, banco de dados ou framework.

## Repositório

Repositório remoto:

```text
https://github.com/BiGSerial/bulai.git
```
