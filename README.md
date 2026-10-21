# BULA-IA

O BULA-IA é uma aplicação Laravel para ingestão, curadoria, processamento e rastreabilidade de uma base privada de documentos farmacêuticos. A implementação atual fornece a fundação documental para a evolução futura do projeto em direção a recuperação aumentada por geração (RAG), análise de interações medicamentosas e apresentação de informações em linguagem acessível.

Embeddings, recuperação vetorial, integração com LLM e análise de interações ainda não fazem parte do estado implementado.

## Funcionalidades atuais

- autenticação administrativa por sessão e controle de acesso pelo perfil `ADMIN`;
- gestão de usuários em `/admin/users` (cadastro com senha provisória exibida uma única vez, edição, ativação/desativação, nova senha provisória e histórico de auditoria);
- página "Primeiro acesso" em `/first-access`: quem usa senha provisória define a própria senha (sem repetir a provisória), com boas-vindas e explicação do sistema por perfil na primeira vez;
- página "Minha conta" em `/account` para qualquer perfil, com troca voluntária de senha;
- login endurecido: limite de 5 tentativas por minuto por e-mail e IP, e-mail sem diferenciar maiúsculas, bloqueio de acesso desativado e registro do último acesso;
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

O seeder não cria o administrador quando e-mail ou senha não são informados explicitamente. O e-mail é gravado em minúsculas, e o administrador do seed fica sempre ativo e sem troca de senha obrigatória (serve como acesso de recuperação). Os demais acessos são criados pela tela de usuários.

## Módulo administrativo

O acesso começa em `/login`. As principais páginas protegidas são:

- `/admin/knowledge`: painel geral;
- `/admin/knowledge/upload`: upload de PDFs;
- `/admin/knowledge/sources`: origens documentais;
- `/admin/knowledge/documents`: catálogo de documentos;
- `/admin/knowledge/batches`: lotes de ingestão;
- `/admin/knowledge/pending`: itens que exigem revisão;
- `/admin/users`: usuários, perfis, situação do acesso e histórico de auditoria.

Somente usuários com perfil `ADMIN` ativos e sem troca de senha pendente podem acessar essas rotas; um `USER` recebe `403`. Depois do login, quem usa senha provisória vai para `/first-access` ("Primeiro acesso") até definir a própria senha; depois disso, o `ADMIN` segue para o admin e o perfil `USER` vai para `/account` ("Minha conta"), onde troca a senha e encerra a sessão.

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

PDFs compostos apenas por imagem passam por OCR como fallback (ver abaixo). Sem o serviço OCR disponível, esses PDFs falham de forma controlada (`TEXT_EXTRACTION_FAILED` com OCR desabilitado/não configurado, `OCR_UNAVAILABLE` quando o serviço falha).

### OCR (fallback)

```text
PDF -> extração textual -> avaliação de qualidade -> (qualidade ok) -> normalização -> seções -> chunks
                                   \-> (insuficiente) -> serviço OCR -> normalização -> seções -> chunks
```

O OCR **não** roda em todos os documentos: apenas quando a extração textual é reprovada pelo avaliador determinístico (`config/document_ocr.php`, `DOCUMENT_QUALITY_*`). O serviço Python fica em `services/document-ocr` (CPU-only, sem GPU):

```bash
docker compose up -d --build document-ocr
# .env: DOCUMENT_OCR_ENABLED=true DOCUMENT_OCR_URL=http://127.0.0.1:8090
```

Com OCR habilitado, use `KNOWLEDGE_JOB_TIMEOUT_SECONDS=330`, `DB_QUEUE_RETRY_AFTER=400` e `queue:work --timeout=330`. O método usado fica em `knowledge_documents.metadata.processing.extraction`.

### Classificador local (llamafile)

Camadas separadas: extração (PDF/OCR) → classificação da ingestão (heurística + IA local como fallback) → recuperação/RAG (futuro) → IA principal (futuro). A IA local só resolve ambiguidades de identificação de documentos e **não** responde a usuários.

```bash
cp .env.example .env
./scripts/install-local-model.sh        # baixa o modelo (~1,1 GB) para services/local-ai/models (fora do Git)
docker compose up -d
docker compose ps                       # local-ai deve ficar "healthy" quando o modelo carregar
curl -s http://127.0.0.1:8088/status    # READY | MODEL_LOADING | MODEL_NOT_INSTALLED | ERROR
```

Regra de decisão (`DOCUMENT_CLASSIFICATION_*`): score ≥ 85 aceita a heurística; 40–84 consulta a IA local; < 40 revisão manual. A IA nunca promove um documento sozinha: exige confiança ≥ 0,85, valores ancorados nas evidências e confirmação no catálogo ANVISA. O estado da IA local e do OCR aparece no cabeçalho do Admin. Detalhes em `docs/technical-foundation.md`; opções de tempo real em `docs/realtime-options.md`.

### Provedores de IA (infraestrutura preparada)

Contratos `TextGenerationProvider`, `EmbeddingProvider` e `VisionProvider` resolvidos por `AI_TEXT_DRIVER`, `AI_EMBEDDING_DRIVER` e `AI_VISION_DRIVER` (`config/ai.php`). Drivers: `openai_compatible` e `fake`. **Nenhum fluxo de negócio chama IA ainda**: embeddings, RAG e LLM não estão implementados.

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
- [Implantação em produção (VPS KingHost, Docker Compose, GitHub Actions)](docs/deployment/kinghost.md)
- [Material técnico para o TCC](docs/tcc/README.md)

O material do TCC separa funcionalidades concluídas, limitações, evidências pendentes e trabalhos futuros. O projeto acadêmico preliminar é utilizado como contexto, não como restrição de linguagem, banco de dados ou framework.

## Repositório

Repositório remoto:

```text
https://github.com/BiGSerial/bulai.git
```
