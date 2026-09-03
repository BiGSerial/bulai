# BULA-IA Technical Foundation

## Purpose

This document records the first MVP foundation for BULA-IA: a Laravel monolith with a private knowledge base ingestion core and a minimal Livewire administrative surface. The current scope is intentionally limited to trusted data ingestion, document registration, chunk persistence, scoped Bearer Token authentication, private PDF attachment, SHA-256 integrity, simple ADMIN/USER session authentication, read-only reactive document inspection, and automated tests.

The application should evolve toward Hybrid RAG, but embeddings, chat orchestration, and advanced admin UI are intentionally deferred until the knowledge data foundation is stable.

## Runtime Stack

- Laravel 13.30.1
- PHP 8.4
- PostgreSQL 17 via Docker Compose
- pgvector via `pgvector/pgvector:pg17`
- PHPUnit 12
- Livewire 4.4.3
- Alpine.js bundled by Livewire 4, not installed as a separate frontend dependency
- Laravel Queue configured through the default database driver
- Private filesystem storage through Laravel's `local` disk
- Laravel session authentication for the admin web surface
- Tailwind CSS 4 through Vite

## Local Services

`compose.yaml` defines a PostgreSQL service:

- Database: `bulai`
- Test database: `bulai_test`
- User: `postgres`
- Password: `postgres`
- Port: `5432`

Start the database:

```sh
docker compose up -d postgres
```

Run migrations:

```sh
php artisan migrate:fresh
```

Run tests:

```sh
php artisan test
```

## Repository Publishing

The project repository remote is configured as:

```sh
origin  https://github.com/BiGSerial/bulai.git
```

Use `git push -u origin main` for the first publish from a fresh local checkout. The default branch should remain `main`.

Files touched for this operational setup:

- `README.md`
- `.git/config`
- `docs/technical-foundation.md`

No runtime code, database tables, or HTTP endpoints are changed by this setup.

## Knowledge Schema

The core migration is:

- `database/migrations/2026_09_02_000000_create_knowledge_core_tables.php`

Tables created:

- `knowledge_sources`: trusted documentary sources such as ANVISA or technical literature providers.
- `ingestion_batches`: logical import batches for assisted or bulk ingestion.
- `knowledge_documents`: document-level metadata, independent content/file/embedding states, private file reference, and SHA-256.
- `knowledge_chunks`: recoverable document fragments with section and page traceability.
- `ingestion_tokens`: hashed, scoped, expiring Bearer Tokens for internal ingestion.
- `users.role`: string-backed application role for `ADMIN` and `USER` access control.

`knowledge_chunks.embedding` uses pgvector when the active database driver is PostgreSQL. Non-PostgreSQL fallback exists only to keep the migration portable, not as the target production path.

## Status Model

`knowledge_documents` keeps independent states:

- `content_status`
- `file_status`
- `embedding_status`

Accepted baseline values are:

- `PENDING`
- `PROCESSING`
- `READY`
- `FAILED`

This allows states such as processed content without a PDF, or an attached PDF before chunking and embeddings.

## Internal API

Routes are registered in `routes/api.php` under the `api/internal` prefix:

- `POST /api/internal/knowledge/import`
- `POST /api/internal/knowledge/{document}/file`

Both routes require:

- Bearer Token authentication through `App\Http\Middleware\AuthenticateIngestionToken`
- Scope: `knowledge:ingest`
- Rate limit: `knowledge-ingest`

The rate limit is registered in `App\Providers\AppServiceProvider`.

## Admin Authentication

The web admin surface uses Laravel's default session guard and a project role enum:

- `App\UserRole::Admin` stores `ADMIN`
- `App\UserRole::User` stores `USER`
- `App\Http\Middleware\EnsureUserIsAdmin` protects admin routes through the `admin` middleware alias.

Routes in `routes/web.php`:

- `GET /login`
- `POST /login`
- `POST /logout`
- `GET /admin/documents`
- `GET /admin/documents/{document}`

Unauthenticated admin requests redirect to `/login`. Authenticated non-admin users receive `403`.

To seed the first admin, set these environment variables before running `php artisan db:seed`:

```sh
BULAI_ADMIN_NAME="BULA-IA Admin"
BULAI_ADMIN_EMAIL="admin@example.com"
BULAI_ADMIN_PASSWORD="change-me-locally"
```

The seeder skips admin creation unless both `BULAI_ADMIN_EMAIL` and `BULAI_ADMIN_PASSWORD` are present. Password hashing is handled by the `User` model's `hashed` cast.

## Admin Document UI

The first admin UI is intentionally read-only and implemented with Livewire 4 full-page components:

- `/admin/documents` lists documents, source, type/language, independent statuses, chunk count, and a shortened SHA-256.
- `/admin/documents` supports reactive search and file-status filtering with query-string state.
- `/admin/documents/{document}` shows metadata, source, batch, filename, full SHA-256, processing timestamp, and ordered chunks.

Primary files:

- `app/Http/Controllers/Auth/AuthenticatedSessionController.php`
- `app/Livewire/Admin/Documents/Index.php`
- `app/Livewire/Admin/Documents/Show.php`
- `app/Http/Middleware/EnsureUserIsAdmin.php`
- `app/UserRole.php`
- `resources/views/auth/login.blade.php`
- `resources/views/layouts/app.blade.php`
- `resources/views/livewire/admin/documents/index.blade.php`
- `resources/views/livewire/admin/documents/show.blade.php`
- `database/migrations/2026_09_03_001054_add_role_to_users_table.php`

## Frontend And PWA Direction

The frontend direction is Livewire-first:

- Use Livewire 4 for reactive pages and server-backed UI state.
- Use Livewire's bundled Alpine.js runtime for small client-side behavior such as local toggles.
- Do not add `alpinejs` to `package.json` or import a separate Alpine runtime unless a documented integration requires manual bundling.
- Keep layouts responsive, route-addressable, and progressively useful for both standard web and PWA usage.

Initial PWA files:

- `public/manifest.webmanifest`
- `public/icon.svg`
- `public/sw.js`
- `resources/js/app.js`

The current service worker avoids caching authenticated navigations under `/admin` and `/login`; static build assets may be cached. Any future offline mode for authenticated content must be explicit and reviewed for data exposure risk.

## Token Issuance

Use the Artisan command:

```sh
php artisan knowledge:issue-token "curadoria-dia"
```

Useful options:

```sh
php artisan knowledge:issue-token "curadoria-dia" --expires="today 23:59:59"
php artisan knowledge:issue-token "curadoria-dia" --scope="knowledge:ingest"
```

The plain token is shown only once. The database stores only `sha256(token)`.

## Assisted Import Flow

The internal import endpoint accepts a logical document, source metadata, optional batch metadata, and chunks. Documents are created or updated by `external_key`.

Example:

```json
{
  "external_key": "anvisa_ibuprofeno_bula_2026",
  "title": "Bula Profissional - Ibuprofeno",
  "document_type": "bula",
  "language": "pt-BR",
  "source": {
    "name": "ANVISA",
    "organization": "Agencia Nacional de Vigilancia Sanitaria"
  },
  "metadata": {
    "drug": "Ibuprofeno",
    "agency": "ANVISA"
  },
  "chunks": [
    {
      "index": 0,
      "section": "Indicacoes",
      "page_start": 1,
      "page_end": 2,
      "content": "Conteudo curado do documento."
    }
  ]
}
```

Successful imports return:

- `document_id`
- `external_key`
- `content_status`
- `file_status`
- `embedding_status`
- `chunks_count`

## PDF Attachment Flow

The file endpoint attaches a PDF to an existing document by explicit document id. It does not infer a relationship from the medication name or filename.

Storage path:

```text
storage/app/private/knowledge/originals/{document_id}/original.pdf
```

The original filename remains in `knowledge_documents.original_filename`.

The attachment service:

- validates PDF MIME through Laravel validation;
- calculates SHA-256 from the uploaded file contents;
- rejects duplicate SHA-256 values already attached to another document;
- stores the file outside `public/`;
- updates `file_status` to `READY`.

## Main Code Paths

- `app/Models/Knowledge/KnowledgeSource.php`
- `app/Models/Knowledge/IngestionBatch.php`
- `app/Models/Knowledge/KnowledgeDocument.php`
- `app/Models/Knowledge/KnowledgeChunk.php`
- `app/Models/Knowledge/IngestionToken.php`
- `app/Services/Knowledge/KnowledgeImportService.php`
- `app/Services/Knowledge/KnowledgeFileService.php`
- `app/Http/Middleware/AuthenticateIngestionToken.php`
- `app/Http/Controllers/Internal/KnowledgeImportController.php`
- `app/Http/Controllers/Internal/KnowledgeFileController.php`
- `app/Console/Commands/IssueIngestionToken.php`

## Tests

Knowledge feature tests live in:

- `tests/Feature/Knowledge/KnowledgeImportApiTest.php`
- `tests/Feature/Knowledge/KnowledgeFileApiTest.php`
- `tests/Feature/Auth/AuthenticationTest.php`
- `tests/Feature/Auth/AdminAccessTest.php`
- `tests/Feature/Auth/AdminSeederTest.php`
- `tests/Feature/Admin/KnowledgeDocumentAdminTest.php`
- `tests/Feature/PwaAssetTest.php`

Covered behavior:

- valid ingestion token;
- expired token rejection;
- revoked token rejection;
- scope mismatch rejection;
- invalid import payload;
- document import with chunks and no PDF;
- repeated `external_key` updates the logical document and replaces chunks;
- PDF attachment after document creation;
- non-PDF rejection;
- duplicate PDF hash rejection.
- login, invalid login, and logout.
- admin route redirect for guests and `403` for authenticated `USER` role.
- configured admin seed creation and skip behavior when credentials are absent.
- admin document list/detail rendering, reactive filters, filter reset, and escaped chunk content.
- PWA manifest and service worker policy for authenticated paths.

## Known Limitations

- Token generation is command-line only.
- There is no ZIP or manifest batch upload yet.
- Embedding generation is not implemented.
- RAG retrieval and assistant response generation are not implemented.
- No production HTTPS enforcement middleware has been added yet.
- PDF text extraction and deterministic chunking jobs are deferred.
- Admin document UI is read-only and has no write actions yet.
- Admin creation depends on explicit environment variables; there is no user-management UI yet.
- The PWA baseline does not provide offline authenticated document access.

## Documentation Rule

Every relevant implementation or architecture change must update or add technical documentation under `docs/`. Prefer concise docs that make the project easier to operate, review, test, and continue.
