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
- `POST /api/internal/knowledge/batches`
- `POST /api/internal/knowledge/batches/{batch}/items/{item}/associate`

All routes require:

- Bearer Token authentication through `App\Http\Middleware\AuthenticateIngestionToken`
- Scope: `knowledge:ingest`
- Rate limit: `knowledge-ingest`

The rate limit is registered in `App\Providers\AppServiceProvider`.

## Admin Authentication

The web admin surface uses Laravel's default session guard and a project role enum:

- `App\UserRole::Admin` stores `ADMIN` ("Administrador")
- `App\UserRole::User` stores `USER` ("Usuário"); labels come from `UserRole::options()` / `label()`
- `App\Http\Middleware\EnsureUserIsAdmin` protects admin routes through the `admin` middleware alias.
- `App\Http\Middleware\EnsureUserIsActive` logs out a deactivated account (`users.active = false`) and redirects to `/login`.
- `App\Http\Middleware\EnsureTemporaryPasswordIsChanged` redirects to `/first-access` while `users.must_change_password` is true (except on the first-access routes and logout). It protects the admin group and the "Minha conta" routes.

The two new middlewares are referenced **by class** in `routes/web.php` (`bootstrap/app.php` is not changed). The admin group runs `auth` → `EnsureUserIsActive` → `admin` → `EnsureTemporaryPasswordIsChanged`, so a `USER` still receives `403` before any password redirect. `AppServiceProvider` registers all three as Livewire persistent middleware (see "Gestão de usuários e endurecimento do login").

Routes in `routes/web.php`:

- `GET /login`
- `POST /login` (`throttle:login`)
- `POST /logout`
- `GET /first-access`, `PUT /first-access/password` ("Primeiro acesso": mandatory change of the temporary password, any role)
- `GET /account`, `PUT /account/password` ("Minha conta", any role, voluntary password change)
- `GET /admin/users`, `/admin/users/create`, `/admin/users/{user}`, `/admin/users/{user}/edit`
- `GET /admin/documents`
- `GET /admin/documents/{document}`

Unauthenticated admin requests redirect to `/login`. Authenticated non-admin users receive `403`. After login, anyone with a temporary password goes to `/first-access`; an `ADMIN` without a pending password change goes to the intended admin page (default `/admin/documents`); a `USER` goes to `/account`. Except for that `ADMIN`, the intended URL is discarded. `/` follows the same rule (`User::homeRouteName()`).

Login hardening (`AuthenticatedSessionController`, `AppServiceProvider`): e-mail normalized with `Str::lower(trim())`; rate limiter `login` = 5 attempts/minute per e-mail + IP, answered with a redirect to the form and `auth.throttle` in the `email` error; `Auth::attemptWhen` refuses inactive accounts, and the "acesso desativado" message is shown only after the password matches; `last_login_at` is recorded; `Password::defaults()` = at least 12 characters with letters and numbers.

To seed the first admin, set these environment variables before running `php artisan db:seed`:

```sh
BULAI_ADMIN_NAME="BULA-IA Admin"
BULAI_ADMIN_EMAIL="admin@example.com"
BULAI_ADMIN_PASSWORD="change-me-locally"
```

The seeder skips admin creation unless both `BULAI_ADMIN_EMAIL` and `BULAI_ADMIN_PASSWORD` are present. Password hashing is handled by the `User` model's `hashed` cast. The e-mail is stored lowercased, and the seeded admin is always set active and without a forced password change (it doubles as the recovery account).

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
- `public/brand/bulai-icone-app.svg`
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

## Knowledge document lifecycle

The ingestion domain keeps three separate identities: a `knowledge_source` is provenance, a `knowledge_document` is the logical record, and the PDF stored by disk/key is the physical object. Chunks belong to the logical document. The current schema stores one active PDF per logical document and does not yet retain file-version history.

Batch matching is deterministic and tries, in order, `external_key`, physical SHA-256, source-scoped `source_document_id`, and source-scoped normalized filename. SHA-256 identifies the same bytes, not the logical identity of different document versions. An ambiguous official identifier or filename remains `PENDING`.

A batch may create a logical document automatically only when it contains all of the following structured information: an active batch source, either `external_key` or `source_document_id`, `metadata.title`, and `metadata.document_type`. Filename and SHA-256 alone never authorize automatic creation. The source row is locked while identities are rechecked, preventing concurrent batches from creating the same source document. The generated fallback external key is deterministic for a source identifier.

Administrative uploads currently provide origin, filename, SHA-256 and `ingestion_channel`, but do not extract a regulatory identifier before matching. Consequently, opaque files such as `bula_1790735935676.pdf` correctly remain `PENDING` with `no_match_found`; PDF text extraction begins only after association. The review page translates that code and offers two explicit resolutions: associate an existing document or create and associate a new logical document. Manual creation preserves the pending object, source and checksum, then delegates promotion, locking, counters and processing dispatch to `KnowledgePendingItemAssociationService`.

Creation never uses filename as logical identity. Source-specific candidates must come from reproducible structured extraction and still pass lifecycle policy.

### Preliminary PDF identification

ANVISA/Bulário batch items now pass through a deterministic identification stage before logical matching. `KnowledgeDocumentIdentificationService` reuses the existing `PdfTextExtractor` contract and `PrinsFrankPdfTextExtractor`; `AnvisaLeafletMetadataExtractor` receives only the project's page DTOs, so no PrinsFrank type leaks into matching or lifecycle code.

The parser currently materializes the uploaded PDF locally through Livewire/PHP upload handling and parses it with `prinsfrank/pdfparser`. Identity analysis is limited to the first two and last four pages: the first pages contain product, active ingredient and audience, while the inspected real ANVISA sample places its registration on page 8 of 11 and holder on page 9. These limits are configurable with `KNOWLEDGE_IDENTIFICATION_FIRST_PAGES` and `KNOWLEDGE_IDENTIFICATION_LAST_PAGES`. The library still parses the PDF structure as a whole; the limit controls metadata analysis, not parser I/O.

Extracted candidates are stored under `ingestion_batch_items.metadata.preliminary_identification`, including fields, concise evidence with page and pattern, ambiguous fields, score, extractor and analyzed-page count. No schema change is required. The review UI labels these as unconfirmed candidates and pre-fills manual document creation without preventing correction.

The score is deterministic: registration `40`, product `25`, leaflet audience/type `20`, active ingredient `10`, holder `5`. Product identification is evidence-based: candidates are collected from the `IDENTIFICAÇÃO DO MEDICAMENTO` block, the heading immediately preceding `APRESENTAÇÕES`, parenthesized active-ingredient notation and composition statements. Generic regulatory headings such as `RDC`, `Texto de Bula`, `Modelo de Bula`, section names, expediente codes and the Lei 9.787 marker are rejected as identities. Agreement between the selected product and composition/generic active ingredient is recorded as `identity_convergence`; equally strong conflicting candidates become ambiguous instead of being guessed. ANVISA registration accepts the explicit labels `Registro`, `Registro MS`, `Reg.` and `MS`, while retaining the 9/13-digit validation.

Automatic creation requires at least `80`, a structurally reliable or convergently confirmed product, no ambiguity, a recognized ANVISA registration, title and controlled document type. The lifecycle independently enforces source, official identifier, title and type. Missing text, parser failure, conflicting registrations/audience/product candidates, and insufficient evidence remain `PENDING`. Known SHA-256 files skip preliminary parsing and retain the existing duplicate path.

The verified `bula_1790735935676.pdf` sample produced ATROVENT/ATROVENT N, brometo de ipratrópio, registration `1.0367.0004` normalized to `103670004`, professional leaflet, and Boehringer Ingelheim as holder, with score `100`. The numeric filename was not used as evidence.

### ANVISA regulatory catalog and convergent evidence

`DADOS_ABERTOS_MEDICAMENTOS.csv` is imported as structured data into `anvisa_medicines`; it is never converted into a knowledge document or chunk. `anvisa_catalog_imports` records dataset name, original filename, SHA-256, encoding, delimiter, counters, status and timestamps. Run `php artisan knowledge:import-anvisa-medicines /path/DADOS_ABERTOS_MEDICAMENTOS.csv --encoding=ISO-8859-1`. An identical completed checksum returns the existing import without rewriting the catalog. The importer streams the CSV, converts each field to UTF-8 and performs chunked PostgreSQL upserts by stable `record_key`.

Canonical 9- or 13-digit registration numbers are preserved as text and indexed; the original value is retained separately. Rows without a valid registration remain available by process/technical record key but cannot provide strong registration confirmation. Product, active ingredient and holder retain their source strings alongside controlled comparable forms.

`AnvisaLeafletMetadataExtractor` remains database-independent and reports only documentary evidence. `KnowledgeDocumentIdentificationService` passes its result to `KnowledgeDocumentRegulatoryValidator`, which queries the `AnvisaRegulatoryCatalog` contract. Validation is stored under `preliminary_identification.regulatory_validation` with one of `CONFIRMED`, `CONFLICT`, `INCONCLUSIVE`, `NOT_FOUND` or `NOT_AVAILABLE`, plus field comparisons, conflicts and catalog provenance. A product or active-ingredient conflict disables automatic creation; missing/unavailable catalog data keeps the pre-existing documentary fallback and never pretends to be confirmation. Registration status is contextual and does not reject historical leaflets.

The administrative review separates PDF candidates from catalog values and shows divergences explicitly. The real catalog row for registration `103670004` identifies ATROVENT, brometo de ipratrópio, Boehringer Ingelheim and status `Ativo`; controlled comparison with the verified Atrovent leaflet confirms product, ingredient and holder independently.

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
storage/app/private/knowledge/originals/{document_id}/{operation_uuid}.pdf
```

The original filename remains in `knowledge_documents.original_filename`.

The attachment service:

- validates PDF MIME through Laravel validation;
- calculates SHA-256 from the uploaded file contents;
- rejects duplicate SHA-256 values already attached to another document;
- stores the file outside `public/`;
- updates `file_status` to `READY`.

## Batch PDF Ingestion Flow

The batch endpoint accepts roughly 10 PDFs per request and associates each one to an existing `knowledge_documents` row, without ever creating a document from a PDF alone. Every file is matched independently and one item's failure never invalidates the others — each item runs in its own database transaction, wrapped in a per-item try/catch.

Schema additions (`database/migrations/2026_09_05_000000_add_batch_ingestion_support.php`):

- `ingestion_batches`: `description`, and item counters `total_items`, `associated_items`, `duplicate_items`, `pending_items`, `error_items`.
- `knowledge_documents`: `source_document_id` (indexed, not unique — ambiguity between documents is expected and detected) and `normalized_filename` (indexed; also populated by the single-file attach flow for consistency).
- `ingestion_batch_items`: one row per manifest entry, always recording `original_filename`, `sha256`, `mime_type`, `file_size`, `status`, `resolution_method`, and a human-readable `reason`.

Resolution order per item (`App\Services\Knowledge\KnowledgeBatchIngestionService::resolveDocument`):

1. explicit `external_key` (exact match, unique column);
2. SHA-256 already known (`knowledge_documents.sha256`) — this always means the exact file is already attached somewhere, so the outcome is `DUPLICATE`, never a re-attach;
3. source-provided `source_document_id` — **only attempted when the batch itself declares `source_id`** (`ingestion_batches.source_id`, resolved from optional `batch.source_id` in the request, validated against `knowledge_sources`). The lookup is scoped with `where('source_id', $sourceId)`, so two documents from different sources sharing the same `source_document_id` never collide. If `batch.source_id` is omitted, this step is skipped entirely (falls through to filename matching) instead of searching unscoped. If more than one document from that source shares the value, the item is left `PENDING` with reason `ambiguous_source_document_id`, never auto-resolved;
4. normalized original filename (`KnowledgeFileService::normalizeFilename`, ASCII-folded/lowercased/hyphenated) — same ambiguity rule, reason `ambiguous_normalized_filename`;
5. no match — `PENDING`, reason `no_match_found`.

Once a target document is resolved, the item is still not attached if:

- the computed SHA-256 already belongs to a *different* document (`PENDING`, `sha256_conflicts_with_other_document`);
- the target document already has a file (`storage_path` not null) and the content differs (`PENDING`, `document_already_has_different_file` — never a silent overwrite);
- the target document already has the exact same file attached (`DUPLICATE`, `sha256_already_attached`, no-op).

Only when none of the above guards trigger does the item stage and attach the file through `KnowledgeFileService` and become `ASSOCIATED`.

Before deciding whether the file can be attached, both the batch flow and `KnowledgeFileService::attachPdf()` reload the target document with PostgreSQL `FOR UPDATE` (`lockForUpdate()`) inside the database transaction. The checks of `storage_path` and SHA-256, the document update, and the batch-item insert therefore execute while that row is locked. A waiting request observes the committed result of the first request and becomes `DUPLICATE` or `PENDING` instead of overwriting it.

Each attachment uses a UUID owned by that operation. The upload is first written under `knowledge/staging/{operation_uuid}/original.pdf`, then moved to `knowledge/originals/{document_id}/{operation_uuid}.pdf` before the database transaction commits. If persistence or any later statement in the transaction fails, compensation deletes only that UUID-specific file. It cannot delete a file created by another request. Staging and compensation do not claim distributed transaction semantics: a process or host crash at the exact boundary between filesystem and database operations can still require operational reconciliation.

MIME-type validation for the batch is deliberately **not** part of the request-level `$request->validate()` call: an invalid file only fails validation inside the service, becoming a single `ERROR` item (`reason: invalid_mime_type`) so the rest of the batch still processes. Request-level validation only enforces structure (`batch.name` required, 1–15 items, each item has an uploaded file up to 20MB).

### File Retention Lifecycle Per Item Status

Every item's PDF bytes are handled differently depending on the final status, and the rule is enforced entirely inside the same per-item database transaction so a partial failure can never leave a mismatched file/row pair:

| Status | File retained? | Location |
| --- | --- | --- |
| `ASSOCIATED` | Yes | `storage/app/private/knowledge/originals/{document_id}/{operation_uuid}.pdf` |
| `PENDING` | Yes | `storage/app/private/knowledge/pending/{batch_id}/{batch_item_id}/original.pdf` |
| `DUPLICATE` | No | content is already stored under the matched document; the freshly uploaded copy is discarded |
| `ERROR` | No | nothing is written to disk |

Both storage roots live under the `local` disk (`storage_path('app/private')`, never `public`), so no pending or associated PDF is ever reachable by URL.

`KnowledgeBatchIngestionService::storePendingItem()` implements the `PENDING` path (`app/Services/Knowledge/KnowledgeBatchIngestionService.php`):

1. the `IngestionBatchItem` row is created first, *without* `storage_path`, so its auto-incremented id is available to build the quarantine path (`knowledge/pending/{batch_id}/{item_id}/original.pdf` — both ids are database-generated, never derived from user input, so path traversal through `original_filename`/`source_document_id` is not possible);
2. the PDF is written to that path; if the disk write fails (`Storage::disk('local')->putFileAs()` returns `false`), a `RuntimeException` is thrown *before* the item is ever considered valid — the surrounding `DB::transaction` rolls back the just-created row, and the outer per-item catch in `processItem()` persists the result as a plain `ERROR` item instead (no orphaned file, no orphaned `PENDING` row);
3. only after a successful write does `persistPendingStoragePath()` update the row with `storage_path`; if that database update throws, the just-written file is deleted before the exception is rethrown (same rollback path as step 2), so a `PENDING` row is never left pointing at a missing file, and a quarantined file is never left without a row that references it.

The outer transaction compensation also tracks the pending path, so a failure during the transaction commit removes the quarantine file after the database rollback.

`persistPendingStoragePath()` is a `protected` seam kept specifically so tests can simulate a database failure after a successful disk write (see `test_pending_database_failure_removes_the_quarantined_file` in `tests/Feature/Knowledge/KnowledgeBatchApiTest.php`) without needing to break the real database connection.

This structure intentionally prepares for — but does not implement — a future manual-review endpoint: a `PENDING` item's row already carries everything such an endpoint would need to move the file onto a human-chosen document (`storage_path`, `original_filename`, `sha256`, `mime_type`, `file_size`). Moving the file out of quarantine, deleting it after resolution, and any expiry/cleanup policy for abandoned quarantine files are explicitly deferred to that next increment.

Example request (`multipart/form-data`):

```
POST /api/internal/knowledge/batches
Authorization: Bearer <ingestion-token>

batch[name]=Lote diário ANVISA
batch[description]=Curadoria 2026-09-05
batch[source_id]=1
items[0][external_key]=anvisa_ibuprofeno_bula_2026
items[0][file]=@ibuprofeno.pdf
items[1][source_document_id]=fonte-doc-482
items[1][file]=@paracetamol.pdf
items[2][file]=@sem-correspondencia.pdf
```

`batch.source_id` is optional but required for `source_document_id` matching to run at all (see resolution order above); it must reference an existing `knowledge_sources.id`.

Example response (`201 Created`):

```json
{
  "batch_id": 7,
  "name": "Lote diário ANVISA",
  "status": "COMPLETED",
  "total_items": 3,
  "associated_items": 1,
  "duplicate_items": 0,
  "pending_items": 2,
  "error_items": 0,
  "items": [
    {"id": 21, "status": "ASSOCIATED", "document_id": 12, "file_attached": true, "reason": null, "error_id": null},
    {"id": 22, "status": "PENDING", "document_id": null, "file_attached": false, "reason": "ambiguous_source_document_id", "error_id": null},
    {"id": 23, "status": "PENDING", "document_id": null, "file_attached": false, "reason": "no_match_found", "error_id": null}
  ]
}
```

See "File Retention Lifecycle Per Item Status" above for exactly which statuses keep the uploaded bytes and where.

`storage_path` is never returned by either ingestion API. Unexpected exceptions are logged internally with a generated `error_id`; the persisted/client-facing reason is the controlled code `PROCESSING_ERROR`, while SQL messages, infrastructure names, paths, and stack traces remain only in internal logs.

## Manual Association of Pending Items

`POST /api/internal/knowledge/batches/{batch}/items/{item}/associate` resolves a quarantined `PENDING` item without another upload. The nested route uses scoped binding, so the item must belong to the batch. It uses the existing Bearer token, `knowledge:ingest` scope, and `knowledge-ingest` rate limiter.

Request payload:

```json
{
  "document_id": 123
}
```

The service starts a new transaction and does not trust the state captured during ingestion. It reloads the item with `FOR UPDATE`, verifies that it is still `PENDING`, checks that the private pending file exists and still matches its stored SHA-256, then reloads the selected document with `FOR UPDATE`. Current document occupancy and global SHA-256 duplication are evaluated only after those locks are acquired.

For a normal association, the pending file is copied to `knowledge/staging/{operation_uuid}/original.pdf` and promoted to `knowledge/originals/{document_id}/{operation_uuid}.pdf`. The document and item are persisted in the same database transaction, the item records `resolution_method=manual` plus `metadata.manual_resolution`, and all batch counters are recalculated from the actual item statuses while the batch row is locked. The quarantine file is removed only after the database commit succeeds.

Successful response (`200 OK`):

```json
{
  "batch_id": 10,
  "item_id": 42,
  "document_id": 123,
  "status": "ASSOCIATED",
  "file_attached": true,
  "error_code": null,
  "error_id": null
}
```

Controlled outcomes:

- `ALREADY_RESOLVED` (`409`): the locked item is no longer `PENDING`; retries do not create files or change counters.
- `DOCUMENT_ALREADY_HAS_FILE` (`409`): the selected document has different bytes; the existing definitive file and pending item remain unchanged.
- `PENDING_FILE_MISSING` (`409`): the database points to a missing quarantine file; the item remains `PENDING` and the incident is logged.
- `PENDING_FILE_INVALID` (`409`): the quarantine bytes no longer match the stored SHA-256; the item remains `PENDING` and the incident is logged.
- `DUPLICATE` (`200`): the SHA-256 is already attached to a document. The item is resolved as `DUPLICATE`, counters are recalculated, and its redundant quarantine copy is removed after commit.
- `PROCESSING_ERROR` (`500`): an unexpected exception is logged with `error_id`; internal messages and paths are not returned. Database changes roll back, the UUID-owned definitive file is compensated, and the pending file remains available.

Filesystem and PostgreSQL are not a distributed transaction. Normal exceptions and rollbacks are compensated, but an abrupt process or host failure at the filesystem/commit boundary can leave residue for future reconciliation. A future `knowledge:reconcile-files` command remains backlog and is not implemented here.

## Knowledge Admin

The session-authenticated administrative module is available under `/admin/knowledge` and uses the existing `auth` and `admin` middleware. An ingestion Bearer token does not grant web access.

Pages:

- `/admin/knowledge`: live aggregate counts for documents, files, batches, pending items, and errors;
- `/admin/knowledge/documents`: searchable, server-paginated document catalog;
- `/admin/knowledge/batches`: searchable, server-paginated import list using persisted batch counters;
- `/admin/knowledge/batches/{batch}`: paginated batch items and state summary;
- `/admin/knowledge/pending`: global server-paginated pending queue;
- `/admin/knowledge/batches/{batch}/items/{item}/review`: explicit document search, selection, confirmation, and association.

The Livewire review component does not manipulate files or statuses. It calls `KnowledgePendingItemAssociationService`, which remains the single implementation of item/document locking, current-state validation, duplicate detection, UUID destination promotion, compensation, counter recalculation, and retry behavior. Controlled results are translated to administrative feedback; unexpected failures display only a generic message and optional `error_id`.

Lists eager-load the relationships they render and use SQL search, counts, limits, and pagination. Candidate search starts after two characters and returns at most ten documents. Metadata rendering is compact, escaped, and omits keys that indicate private paths, storage, SQL, stacks, exceptions, secrets, or tokens. Physical paths are never rendered.

The interface is responsive Tailwind markup within the existing PWA layout. It deliberately has no offline mutation behavior: review requires a live authenticated backend. Existing `/admin/documents` routes remain compatibility aliases.

Residual risk: eligibility displayed in search is advisory and may become stale before confirmation; the locked domain service is authoritative and returns a controlled conflict. The pre-existing filesystem/commit crash-window and future reconciliation backlog remain unchanged.

## Document Processing Pipeline

The document pipeline is intentionally separate from vector processing:

`private PDF → page extraction → conservative normalization → deterministic sections → structural chunks → knowledge_chunks`

The only external service is the optional internal OCR container, used as a fallback (see *OCR Fallback and AI Provider Layer*). No embedding generation, vector retrieval, or LLM is used.

### PDF extraction

`PdfTextExtractor` is the application boundary. `PrinsFrankPdfTextExtractor` is the initial implementation and converts library objects immediately into application-owned `ExtractedPdf` and `ExtractedPage` values. Pages are numbered from `1..N` in source order. No PrinsFrank type leaves the extractor.

The installed parser is `prinsfrank/pdfparser` 3.4.0. It runs locally without `pdftotext` or `pdfinfo`. Production and the PHP CLI used by the KingHost queue worker must enable `gd`, `iconv`, and `zlib`. The development CLI has all three enabled.

Only paths recorded on `knowledge_documents` and located below `knowledge/originals/{document_id}/` on the private local disk are accepted. Traversal components and missing files are rejected before parsing. Defaults are configurable through `config/knowledge.php`:

- PDF: 25 MiB maximum;
- pages: 500 maximum;
- extracted normalized text: 5,000,000 characters maximum;
- usable text minimum: 20 characters;
- queued Job timeout: 60 seconds, below the database queue's 90-second retry reservation;
- chunk target: 1,800 characters;
- chunk maximum: 2,600 characters;
- same-section overlap: 200 characters.

Image-only PDFs and PDFs with insufficient text are sent to the OCR fallback when it is enabled; with OCR disabled/unconfigured they fail with `TEXT_EXTRACTION_FAILED`, and with an OCR outage and unusable text they fail with `OCR_UNAVAILABLE`. Invalid/unsupported PDFs produce the generic `PROCESSING_ERROR`; technical exceptions remain in logs with an `error_id`.

### Normalization and sections

Normalization standardizes line endings, removes control characters, collapses horizontal whitespace, trims line whitespace, and limits excessive blank lines. It does not rewrite clinical content, units, decimal commas, percentages, ranges, medication names, or dosage instructions. Header/footer removal is not attempted because the current corpus does not provide sufficient structural evidence for a safe heuristic.

Section detection first matches a conservative list of common medicine-leaflet headings after removing optional numbering and comparing case/accent-insensitively. A generic fallback accepts short standalone uppercase headings and preserves their original title. Ordinary prose remains under a `null` section rather than receiving an invented category.

### Structural chunking and traceability

Chunking is performed independently within each section. Paragraph blocks accumulate toward the target; oversized blocks are split at sentence/whitespace boundaries where possible. Moderate overlap is taken only from the immediately preceding chunk in the same section. Chunks never cross section or document boundaries.

The existing `knowledge_chunks` schema is reused: `document_id`, stable zero-based `chunk_index`, `section`, `page_start`, `page_end`, `content`, nullable `token_count`, and compact metadata (`section_original`, `extraction_method`). `embedding` remains null. Token counts remain null until a real model tokenizer is selected.

### Processing, reprocessing, and queue operation

`ProcessKnowledgeDocument` is the queued coordinator and delegates to `KnowledgeDocumentProcessor`; it contains no extraction or chunking implementation. The processor uses the existing `content_status` lifecycle (`PENDING → PROCESSING → READY|FAILED`) and logs start, extraction metrics, chunk creation, completion, and controlled failure without logging document text.

Extraction, normalization, section detection, and chunk construction finish before existing chunks are touched. The final replacement runs in one database transaction under a document row lock and revalidates the file path/SHA snapshot. A failure leaves the previous valid chunk set intact. Successful reprocessing deletes and recreates the set atomically, so retries do not append duplicates. `content_hash` stores SHA-256 of normalized page text; processing counts, duration, controlled error code, and `error_id` live under `metadata.processing`.

Operational commands:

```bash
php artisan knowledge:process-document 123
php artisan knowledge:process-document 123 --force
php artisan knowledge:process-document 123 --force --sync
php artisan queue:work database --queue=default --timeout=60
```

The default command dispatches the same Job used by the Admin. `--sync` is intended for controlled diagnostics. A queue worker must be running in production.

The document detail in Knowledge Admin displays content status, processing time, controlled error/reference, chunk count, and paginated chunks with section and page range. Process/reprocess actions require explicit confirmation in the admin dialog (`confirm-modal`; reprocessing uses the `danger` tone because it replaces the current chunks) and only dispatch the Job.

The committed multi-page fixture is synthetic but pharmacologically representative and validates ordering, page association, sections, dosages, units, decimal commas, and percentages. No licensed or redistributable real pharmaceutical PDF was present in the repository; real-source acceptance testing remains required before embedding work.

## Main Code Paths

- `app/Models/Knowledge/KnowledgeSource.php`
- `app/Models/Knowledge/IngestionBatch.php`
- `app/Models/Knowledge/KnowledgeDocument.php`
- `app/Models/Knowledge/KnowledgeChunk.php`
- `app/Models/Knowledge/IngestionToken.php`
- `app/Models/Knowledge/IngestionBatchItem.php`
- `app/Services/Knowledge/KnowledgeImportService.php`
- `app/Services/Knowledge/KnowledgeFileService.php`
- `app/Services/Knowledge/KnowledgeBatchIngestionService.php`
- `app/Services/Knowledge/KnowledgePendingItemAssociationService.php`
- `app/Services/Knowledge/PdfTextExtractor.php`
- `app/Services/Knowledge/PrinsFrankPdfTextExtractor.php`
- `app/Services/Knowledge/KnowledgeTextNormalizer.php`
- `app/Services/Knowledge/KnowledgeSectionDetector.php`
- `app/Services/Knowledge/KnowledgeChunker.php`
- `app/Services/Knowledge/KnowledgeDocumentProcessor.php`
- `app/Jobs/ProcessKnowledgeDocument.php`
- `app/Http/Middleware/AuthenticateIngestionToken.php`
- `app/Http/Controllers/Internal/KnowledgeImportController.php`
- `app/Http/Controllers/Internal/KnowledgeFileController.php`
- `app/Http/Controllers/Internal/KnowledgeBatchController.php`
- `app/Http/Controllers/Internal/KnowledgePendingItemAssociationController.php`
- `app/Console/Commands/IssueIngestionToken.php`
- `app/Console/Commands/ProcessKnowledgeDocumentCommand.php`

## Tests

Knowledge feature tests live in:

- `tests/Feature/Knowledge/KnowledgeImportApiTest.php`
- `tests/Feature/Knowledge/KnowledgeFileApiTest.php`
- `tests/Feature/Knowledge/KnowledgeBatchApiTest.php`
- `tests/Feature/Knowledge/KnowledgePendingItemAssociationApiTest.php`
- `tests/Feature/Knowledge/KnowledgeDocumentProcessingTest.php`
- `tests/Feature/Auth/AuthenticationTest.php`
- `tests/Feature/Auth/AdminAccessTest.php`
- `tests/Feature/Auth/AdminSeederTest.php`
- `tests/Feature/Admin/KnowledgeDocumentAdminTest.php`
- `tests/Feature/Admin/KnowledgeAdminTest.php`
- `tests/Feature/Admin/KnowledgeDocumentProcessingAdminTest.php`
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
- re-attaching a PDF to a document that already has one is rejected (no silent overwrite).
- batch of multiple PDFs is processed and each item resolved independently;
- batch association by explicit `external_key`;
- batch duplicate detection by known SHA-256 (no re-attach, no error);
- batch item with no matching document stays pending with a readable reason;
- batch item with an ambiguous `source_document_id` (2+ candidates) is never auto-associated;
- batch item targeting a document that already has a different file is left pending instead of overwriting it;
- an invalid file inside a batch becomes an isolated `ERROR` item without blocking the others;
- batch endpoint rejects requests without a valid ingestion token;
- batch items are traceable through `ingestion_batch_id` and the returned `batch_id`;
- `source_document_id` matching is scoped to the batch's `source_id` and never matches across sources; it is skipped entirely when no `source_id` is given;
- a pending batch item's PDF is quarantined on the private disk and its path is recorded on the item;
- a disk-write failure while quarantining a pending PDF becomes an `ERROR` item, never an invalid `PENDING` one;
- a database failure after a successful quarantine write removes the orphaned file;
- `DUPLICATE` and `ERROR` items never write or leave behind any file;
- a batch's item counters (`total_items`/`associated_items`/`duplicate_items`/`pending_items`/`error_items`) stay consistent with the actual status of its items, including in mixed-outcome batches.
- manual association promotes an existing pending file without another upload and recalculates counters;
- nested batch/item scope, document validation, missing/tampered pending files, terminal item states, occupied documents, and SHA-256 duplicates;
- manual-association retries are idempotent and stale state cannot overwrite a committed document;
- promotion and post-promotion failures preserve quarantine, roll back database state, compensate only operation-owned files, and sanitize internal errors;
- manual association requires a valid token with the existing `knowledge:ingest` scope and never exposes `storage_path`.
- login, invalid login, and logout.
- login rate limit, case-insensitive e-mail, `last_login_at`, inactive accounts, and role-based destination after login.
- admin route redirect for guests and `403` for authenticated `USER` role.
- configured admin seed creation and skip behavior when credentials are absent; seed e-mail normalization and reactivation.
- user management, account page, first-access page, account-state middlewares, and Livewire persistent middleware (see "Gestão de usuários e endurecimento do login").
- admin document list/detail rendering, reactive filters, filter reset, and escaped chunk content.
- parser boundary, ordered one-based pages, no-text and invalid PDFs, conservative normalization, section detection, structural chunking, page traceability, atomic reprocessing, Job/command dispatch, and Admin chunk inspection.
- PWA manifest and service worker policy for authenticated paths.

## Known Limitations

- Token generation is command-line only.
- Batch upload is `multipart/form-data` with inline per-item fields; there is no ZIP or a separate JSON-manifest-plus-files upload format.
- `PENDING` batch items retain the uploaded PDF privately under `knowledge/pending/{batch_id}/{uuid}.pdf`; `DUPLICATE` and `ERROR` items retain only computed metadata (filename, SHA-256, MIME, size), never the bytes. There is no expiry or reconciliation command for abandoned quarantine or staging files yet.
- Knowledge ingestion has no application-level item count limit. Each Admin/API batch is limited to 100 MB in aggregate by `KNOWLEDGE_INGESTION_MAX_BATCH_KILOBYTES`; the temporary-upload per-file ceiling defaults to the same 100 MB. PHP/web-server settings such as `upload_max_filesize`, `post_max_size`, and especially `max_file_uploads` can still impose infrastructure limits and must be configured consistently in production.
- Batch processing is synchronous within the request (one item per `DB::transaction()`, not queued); a very large or slow batch will hold the HTTP request open for its full duration.
- Embedding generation is not implemented.
- RAG retrieval and assistant response generation are not implemented.
- No production HTTPS enforcement middleware has been added yet.
- OCR runs only as a fallback in the queued processing job (not during upload-time identification, which still uses `PdfTextExtractor` only); the OCR engine's real-world accuracy on the pharmaceutical corpus has not been measured.
- Knowledge Admin supports manual pending-item association and creation of logical documents, but does not yet provide general editing of document metadata.
- The first admin still depends on explicit environment variables (`db:seed`); further accounts are created in `/admin/users` with a temporary password handed over by the administrator (no e-mail invitation, no account deletion, no curator role).
- The PWA baseline does not provide offline authenticated document access.

## Administrative Upload and Portable Knowledge Storage

`GET /admin/knowledge/upload` is an authenticated, admin-only Livewire 4 screen for selecting an active `knowledge_source` and uploading multiple PDFs. It calls the same `KnowledgeBatchIngestionService` used by `POST /api/internal/knowledge/batches`; the component does not perform matching, hashing, storage promotion, duplicate detection, or filesystem cleanup. There is no application-level quantity cap; `KNOWLEDGE_INGESTION_MAX_BATCH_KILOBYTES` limits the sum of all PDFs to 102400 KiB (100 MB) and `KNOWLEDGE_INGESTION_MAX_FILE_KILOBYTES` protects each temporary upload at the same default ceiling. The UI uses Livewire temporary uploads, server-side PDF MIME/extension validation, browser upload progress, sanitized errors, and links to the resulting batch and pending-review queue.

Before importing, the administrator can remove a single selected PDF (`Upload::removeFile`) or clear the whole selection (`Upload::clearFiles`, shown from two files on, confirmed in the admin dialog `confirm-modal`). Removal identifies the file by the Livewire temporary filename (`TemporaryUploadedFile::getFilename()`), never by its list position: positions shift after each removal and Livewire 4 defers a second click until the first request finishes, so an index would remove the next file. An identifier that does not belong to the current selection is a no-op. Both actions delete the temporary upload (`TemporaryUploadedFile::delete()`) and clear the `files` / `files.*` validation errors; this is the only filesystem cleanup in the component, which still never touches knowledge storage. The identifier is rendered into `wire:click` through `Js::from`. In Livewire 4, a new selection on the `multiple` input is appended to the current one (`uploadMultiple(..., append = true)`), so removal is the way to correct a wrong pick. Coverage: `tests/Feature/Admin/KnowledgeUploadAdminTest.php` (remove one, clear all, unknown or already-removed identifier, import after removal).

Knowledge files use the disk selected by `KNOWLEDGE_FILESYSTEM_DISK` (default `knowledge`). The current `knowledge` disk is private local storage rooted at `storage/app/private`; switching its Laravel Filesystem driver is the intended migration boundary for future S3 work. `knowledge_documents` and `ingestion_batch_items` persist `storage_disk` plus a provider-neutral `storage_key`. `storage_path` remains populated as a legacy logical-key alias during the transition, never as an absolute path. Existing non-null keys are backfilled by migration and model readers fall back to `storage_path`.

Object keys are application-generated and do not derive their destination from client filenames:

- definitive: `knowledge/originals/{document_id}/{operation_uuid}.pdf`;
- quarantine: `knowledge/pending/{batch_id}/{uuid}.pdf`;
- staging: `knowledge/staging/{operation_uuid}/original.pdf`.

`KnowledgeFileService` owns Laravel Filesystem access, stream-based SHA-256, staging, copy/verify/delete promotion, compensation, and local materialization. Promotion is implemented through read/write streams and checksum verification rather than POSIX `rename()`. Database transactions still cannot include object storage: operation-owned keys are compensated on ordinary failures, pending objects are removed only after committed resolution, and cleanup never targets another operation's UUID.

The PrinsFrank parser still needs a local filename. `KnowledgeDocumentProcessor` resolves the document's logical disk/key through `KnowledgeFileService`, which streams it into a secure short-lived system temporary file, invokes `PdfTextExtractor`, and removes the temporary in `finally`, including parser failures. Normalization, section detection, chunking, Jobs, and Livewire never receive a physical storage path.

An unequivocal `ASSOCIATED` batch item dispatches the unique `ProcessKnowledgeDocument` Job after database persistence unless the document is already `READY`. Manual resolution does the same only after successful association. `PENDING`, `DUPLICATE`, and `ERROR` do not dispatch. The Job is unique per document for ten minutes and the processor independently skips `READY` documents without force.

Future S3 activation requires installing/configuring Laravel's S3 Flysystem adapter, defining the private disk credentials/bucket/region, setting `KNOWLEDGE_FILESYSTEM_DISK`, verifying queue-worker PHP extensions and network permissions, and testing object visibility/consistency. No AWS SDK configuration, IAM policy, bucket, public URL, or provider-specific branch is implemented in this stage.

## Document Origins

`knowledge_sources` is the single source-of-truth for document provenance. `ingestion_batches.source_id` records the origin selected for an upload, and associated `knowledge_documents.source_id` retains it through processing and chunk retrieval; chunks do not duplicate the foreign key because their document relationship provides it.

The existing source schema was extended incrementally with `collection`, `country`, `normalized_name`, and `normalized_collection`. `organization` remains available for legacy structured imports. Existing records are backfilled with `collection = organization`, and UI labels fall back to `organization` when required. If legacy rows differ only by case, the migration consolidates their batch/document foreign keys onto the oldest row before adding the unique normalized name/collection pair. This prevents case-only duplicates while preserving distinct collections from the same institution.

Administrative routes, protected by the existing `auth` and `admin` middleware, are:

- `GET /admin/knowledge/sources` — searchable, paginated list with active/inactive filter;
- `GET /admin/knowledge/sources/create` — create origin;
- `GET /admin/knowledge/sources/{source}` — provenance detail and recent documents;
- `GET /admin/knowledge/sources/{source}/edit` — edit origin.

The upload screen labels this field “Origem dos documentos” and offers a Livewire quick-create modal. Successful creation immediately selects the new source without resetting `TemporaryUploadedFile` instances. Only active sources can be used for new Admin/API batches. Deactivation is non-destructive: historical batches and documents retain their relationship. Filename matching and manual pending-item resolution are source-aware, preventing a PDF from being attached to a logical document belonging to another origin.

The quick-create modal uses explicit bordered controls, visible focus states, a scrollable mobile-safe body, and a fixed action footer so entered provenance data remains distinguishable from read-only text at all viewport sizes.

Initial managed types are regulatory, clinical, pharmacological, scientific, marketing/commercialization, and other. They describe provenance at a practical administrative level and are not intended as a complete evidence taxonomy.

## Documentation Rule

Every relevant implementation or architecture change must update or add technical documentation under `docs/`. Prefer concise docs that make the project easier to operate, review, test, and continue.

## Ingestion Evidence, Audit, and Reanalysis

Every pending item retains its immutable original on the configured private knowledge disk. Authenticated administrators can stream that object through scoped endpoints using `Content-Disposition: inline` or `attachment`; neither route accepts a path or exposes the logical object key. The downloaded bytes are the stored bytes, not a reconstructed PDF.

Before reanalysis, `KnowledgePendingItemReanalysisService` locks and reloads the item, resolves its registered disk/key through `KnowledgeFileService`, recalculates SHA-256 by stream, and blocks processing on missing or divergent content. It then materializes a short-lived local copy only at the PDF-parser boundary, invokes the existing preliminary identification, matcher, `KnowledgeDocumentLifecycleService`, and pending association service. A second lock rejects stale state before metadata is changed. Previous preliminary identification is retained in `preliminary_identification_history`.

Batch reanalysis uses the unique queued `ReanalyzePendingKnowledgeBatch` job and processes current PENDING IDs individually. Administrative resolution without incorporation uses the terminal `IGNORED` item status, requires a controlled reason, preserves the private object, and is counted in the new `ingestion_batches.ignored_items` counter. `COMPLETED` continues to mean that batch execution ended; the UI explicitly reports remaining intervention.

The minimal persistent audit trail lives in `ingestion_batch_items.metadata.audit_history`. Entries include an event code, timestamp, authenticated actor when available, and controlled details. It records only new actions that actually occur; no historical event is fabricated for legacy items. Events currently cover reanalysis requests/results, integrity failures, association/duplicate outcomes, and non-incorporation decisions.

Administrative file routes (all protected by `auth` and `admin`) are:

- `GET /admin/knowledge/batches/{batch}/items/{item}/file` — authorized inline PDF stream;
- `GET /admin/knowledge/batches/{batch}/items/{item}/download` — authorized original-byte download.

Known limitation: `DUPLICATE` and legacy `ERROR` items intentionally did not retain new uploaded bytes. Their evidence is limited to persisted metadata, SHA-256, and—when linked—the canonical document object. A dedicated append-only audit table may be justified later if querying event history across millions of items becomes necessary.

The document list and detail screen present the three independent lifecycle fields with explicit labels: “Arquivo PDF”, “Texto e chunks”, and “Embeddings”. Raw values are retained as technical tooltip information, while visible text explains the operational meaning. A zero chunk count is not shown as a completed result while content is pending; the UI says that chunks have not yet been generated.

Pending-item reanalysis persists backend progress in `metadata.reanalysis_progress`. The value records a state (`queued`, `processing`, `completed`, or `failed`), a pipeline-milestone percentage, a controlled label, and its update timestamp. Percentages represent completed phases—queue, integrity, PDF identification, matching, and association—not an estimated remaining duration. The global pending screen polls every two seconds, renders that persisted progress as the card background and a labeled bar, disables review while work is active, and automatically removes cards whose item status is no longer `PENDING`.

The pending screen also aggregates backend activity into “Na fila”, “Executando”, “Revisão necessária”, and “Falhas”. It explicitly distinguishes an active run from a finished run. Pending work without a database update for `KNOWLEDGE_PROGRESS_STALE_MINUTES` (five minutes by default) produces a visible worker-health warning instead of appearing to run indefinitely.

ANVISA generic leaflets commonly place the legal marker “Medicamento Genérico, Lei nº 9.787/1999” immediately below the generic product name. The deterministic extractor treats that sentence as a regulatory marker, never as an active ingredient. In this structure the normalized product name itself is the active ingredient (including explicit combinations such as losartan + hydrochlorothiazide), and the evidence records the dedicated `generic_product_name_is_active_ingredient` pattern.

## Visual Identity

The official production SVG set is stored under `public/brand/`: horizontal, transparent horizontal, dark vertical, symbol, and app icon. The transparent horizontal logo is used on the login and Knowledge Admin headers, while `bulai-icone-app.svg` is used by the browser and PWA manifest. The vectors preserve their supplied navy and orange variants, capsule/chat symbol, BULAI wordmark, and “Bulas Inteligentes” signature. Embedded C2PA metadata was removed for delivery size only; vector artwork was not changed.


## OCR Fallback and AI Provider Layer

### Status

| Item | Status |
| --- | --- |
| Quality gate + OCR fallback (Laravel + Python service) | implemented |
| `DocumentOcrClient` HTTP driver, config, controlled errors | implemented |
| AI contracts, manager, `openai_compatible` and `fake` drivers, DTOs | infrastructure prepared (not used by any business flow) |
| Embedding versioning metadata (`EmbeddingMetadata`) | infrastructure prepared (not persisted yet) |
| Embedding generation, `GenerateDocumentEmbeddings` job, RAG, LLM classification | future work |
| Dedicated `ProcessDocumentOcr` job | future work (OCR currently runs inside `ProcessKnowledgeDocument`) |

### Flow

`PDF → PdfTextExtractor → DocumentExtractionQualityEvaluator → (acceptable) normalization | (rejected) DocumentOcrClient → normalization → sections → chunks`

- `DocumentTextExtractionService` (`app/Services/Knowledge`) wraps `PdfTextExtractor` (unchanged) and returns a `DocumentExtractionResult`. `KnowledgeDocumentProcessor` consumes it.
- `HeuristicExtractionQualityEvaluator` is deterministic (no LLM). It returns `score`, `is_acceptable`, `reasons` and `metrics` using: no pages/text, character count, characters per page, empty-page ratio, short-line fragmentation, invalid-character ratio, alphabetic ratio and word count. Each reason subtracts a fixed penalty; `NO_PAGES`/`NO_TEXT` are always rejected. Thresholds: `config/document_ocr.php` (`DOCUMENT_QUALITY_*`).
- `HttpDocumentOcrClient` posts the PDF (validated `%PDF-` signature and size) to `POST {DOCUMENT_OCR_URL}/v1/documents/parse` with `X-Correlation-Id` and optional bearer `DOCUMENT_OCR_TOKEN`. Timeout, connect timeout and limited retries (connection errors, 429, 5xx; never on timeout) are configurable. Failures become `DocumentOcrException` codes: `OCR_DISABLED`, `OCR_NOT_CONFIGURED`, `OCR_INVALID_FILE`, `OCR_PAYLOAD_TOO_LARGE`, `OCR_UNAVAILABLE`, `OCR_TIMEOUT`, `OCR_RATE_LIMITED`, `OCR_REJECTED`, `OCR_INVALID_RESPONSE`. Messages never contain URL, token or document text; logs carry only codes, correlation id, counts and durations.
- The response is converted to `ExtractedPdf`/`ExtractedPage` (pages sorted and validated, optional page confidence, engine metadata in `ExtractedPdf::$metadata`). Blocks/bboxes stay in the Python response and are not used by Laravel yet.
- Failure policy: OCR failure with usable text keeps the text extraction; OCR failure with unusable text fails the document as `OCR_UNAVAILABLE` (previous chunks are preserved; reprocess with `--force`). OCR disabled or unconfigured preserves the previous `TEXT_EXTRACTION_FAILED` behavior.

### Audit metadata

`knowledge_documents.metadata.processing.extraction`: `extraction_method` (`pdf_text`|`ocr`), `extractor`, `ocr_engine`, `ocr_version`, `quality_score`, `quality_score_after_ocr`, `quality_reasons`, `fallback_reason`, `ocr_error_code`, `pages_processed`, `processing_duration` (ms). Chunk metadata `extraction_method` now holds `pdf_text` or `ocr` (previously the library name).

### Python service (`services/document-ocr`)

FastAPI, `POST /v1/documents/parse` and `GET /health`, optional bearer token, size/MIME/page limits. `app.engine.DocumentOcrEngine` is the engine contract; `PaddleOcrEngine` (CPU, `pypdfium2` rendering) is the initial implementation and is imported lazily. The HTTP contract does not mention PaddleOCR. Docker: `docker compose up -d --build document-ocr` (2 CPUs / 3 GB limits, no GPU, no NVIDIA toolkit required). Tests (`pytest`) use a fake engine; the PaddleOCR engine itself was not exercised by automated tests.

### AI provider layer

`Application → AI contracts → AiProviderManager → drivers → external/local APIs`

- Contracts (`app/Services/Ai`): `TextGenerationProvider`, `EmbeddingProvider`, `VisionProvider`. A driver may implement only some capabilities; each is resolved independently by `AI_TEXT_DRIVER`, `AI_EMBEDDING_DRIVER`, `AI_VISION_DRIVER`. Unset/unknown drivers raise `AI_NOT_CONFIGURED`.
- New drivers are registered with `AiProviderManager::extend($capability, $driver, $closure)` without touching consumers.
- `openai_compatible` driver talks to `{AI_*_BASE_URL}/chat/completions` and `/embeddings` (OpenAI, gateways, Ollama, vLLM, llama.cpp...) using `AI_*_API_KEY`, `AI_*_MODEL`, `AI_*_TIMEOUT`, `AI_*_RETRIES`. Vendor JSON never leaves `app/Services/Ai/OpenAiCompatible`; consumers see DTOs in `app/Data/Ai`.
- `fake` driver: deterministic, for tests and offline development.
- Errors are `AiProviderException` codes (`AI_RATE_LIMITED`, `AI_TIMEOUT`, `AI_UNAVAILABLE`, `AI_AUTH_FAILED`, `AI_REJECTED`, `AI_INVALID_RESPONSE`, `AI_INVALID_REQUEST`, `AI_NOT_CONFIGURED`) with a correlation id; keys, URLs and prompts are never in messages. `ai_request_completed`/`ai_request_failed` logs record provider, capability, operation, model, latency, token counts and success, never content or secrets. Monetary cost is not computed (no price table).
- `EmbeddingMetadata` (`provider`, `model`, `dimensions`, `generated_at`, `content_hash`) defines embedding validity as content hash + provider + model; `isObsoleteFor()` flags stale embeddings. Nothing generates or stores embeddings yet; `embedding_status` stays `PENDING`.
- Long AI calls must run in queued jobs, never in controllers or Livewire components.

### Operations

```bash
docker compose up -d --build document-ocr
curl http://127.0.0.1:8090/health
php artisan knowledge:process-document 123 --force --sync
cd services/document-ocr && pytest
```

### Tests

`tests/Unit/Services/Knowledge/{HeuristicExtractionQualityEvaluatorTest,HttpDocumentOcrClientTest,DocumentTextExtractionServiceTest}.php`, `tests/Unit/Services/Ai/AiProviderLayerTest.php`, OCR cases in `tests/Feature/Knowledge/KnowledgeDocumentProcessingTest.php`, `services/document-ocr/tests/test_api.py`. No test calls a real external API.


## Local Classification Layer (llamafile) and Service Health

Responsibilities are separated: **(1) extraction** (`PdfTextExtractor` / OCR), **(2) ingestion classification** (heuristic first, local model as fallback — this section), **(3) retrieval** (embeddings/RAG, future), **(4) main application LLM** (future). The local llamafile model belongs only to layer 2; it never answers users and does not take part in RAG.

### Status

| Item | Status |
| --- | --- |
| Heuristic candidates/evidence/score exposed for classification | implemented |
| Score gate (`ACCEPTED_HEURISTIC` / local model / `MANUAL_REVIEW`) | implemented |
| `DocumentClassificationProvider` + llamafile driver + strict DTO | implemented |
| `services/local-ai` container (llamafile, CPU), `scripts/install-local-model.sh`, `scripts/setup-local-ai.sh` | implemented; the image was built and, with a 1 MB test GGUF (not the default Qwen model), the states `MODEL_NOT_INSTALLED` → `READY`, a real chat completion and the Docker healthcheck were verified manually; on 2026-10-18 the default Qwen2.5-1.5B Q4_K_M model was downloaded (SHA-256 verified), reached `READY` and answered classification requests in ~8–14 s per document on CPU only (16 logical cores, 15 GB RAM, WSL2, base commit `ecdd93f` plus uncommitted work); accuracy on a labelled corpus was not measured |
| `ClassifyPendingDocument` queue job | implemented |
| Visible health of local AI/OCR in the Admin | implemented (polling; see `docs/realtime-options.md`) |
| Accuracy of the local model on the real corpus | not measured |
| `ProcessDocumentOcr`, `GenerateDocumentEmbeddings` jobs | future (OCR runs inside the processing and classification jobs) |

### Flow

`PDF → text extraction → quality gate → [OCR] → heuristic (candidates, evidence, score) → regulatory validation → score gate`

- `score >= DOCUMENT_CLASSIFICATION_AUTO_ACCEPT_SCORE` (85): heuristic accepted; model not called.
- `AI_MIN_SCORE (40) <= score < 85`: `KnowledgeDocumentClassificationService` calls the classifier.
- `score < 40`: `MANUAL_REVIEW`; model not called.
- Thresholds are configuration (`config/document_classification.php`), to be tuned by experiments, not scientific constants.
- Heavy work never runs in the upload request. `KnowledgeBatchIngestionService` still identifies with the text extractor only; when OCR or the classifier is enabled it dispatches `ClassifyPendingDocument` for each PENDING PDF (after commit). The job reuses `KnowledgePendingItemReanalysisService::reanalyze`, which now calls `identifyPath(..., deep: true)` (OCR + classification). Manual "reanalyze" from the Admin therefore also uses the deep path.

### Classifier contract

`DocumentClassificationRequest` carries only: `heuristic_score`, name/ingredient candidates, registration, audience candidates, short evidence excerpts (page header, identification section, composition; ≤ 8 excerpts, ≤ 400 chars each) and ambiguous fields — never the PDF or full text. `LlamafileDocumentClassificationProvider` is the `llamafile` driver of the generic OpenAI-compatible client (`ai.classifier.drivers.llamafile`, `LOCAL_AI_URL`), temperature 0, JSON-schema response format.

`DocumentClassificationResponse::fromModelOutput` is strict: exactly the keys `product_name`, `active_ingredient`, `document_type` (`bula_paciente`|`bula_profissional`|null), `confidence` (0–1 number), `evidence` (≤ 5 strings). Malformed/incomplete JSON, extra keys, wrong types or out-of-range confidence raise `AI_INVALID_RESPONSE`; nothing is repaired.

### Acceptance rules for a model answer

1. `confidence >= DOCUMENT_CLASSIFICATION_AI_MIN_CONFIDENCE` (0.85), otherwise `AI_LOW_CONFIDENCE` (nothing merged).
2. Values must be grounded in the heuristic candidates or the evidence excerpts sent, and must not contradict values the heuristic already found; otherwise `AI_REJECTED`.
3. A `document_type` is accepted only if it matches an audience candidate found by the heuristic; names must match whole words of a candidate or excerpt (≥ 4 characters). Accepted values fill only fields the heuristic left empty/ambiguous and the result is re-validated against the ANVISA catalog. It is applied **only if** the catalog status is `CONFIRMED` and the document is otherwise complete (`AI_ACCEPTED`); otherwise the suggestion is only recorded (`AI_SUGGESTED`) and fields/ambiguities stay as the heuristic left them (the item stays PENDING).
4. Provider failure/timeout/invalid output: `AI_UNAVAILABLE` (with controlled `error_code`); heuristic result kept; item stays PENDING. Classifier not configured: `AI_DISABLED`. If OCR text scores lower than the native text, the native extraction is kept (`ocr_error_code=OCR_WORSE_THAN_TEXT`). `ClassifyPendingDocument` has its own timeout (OCR + classifier timeouts + margin; keep `DB_QUEUE_RETRY_AFTER` above it) and a `failed()` handler that marks the item progress as failed so it can be re-queued.

Audit data: `metadata.preliminary_identification.classification` (`decision`, `heuristic_score`, `ai_used`, `model`, `confidence`, `duration_ms`, `error_code`, `reason`, `result`) and `.extraction` (see OCR section). Candidate lists and prompts are not logged.

### `services/local-ai` container

`supervisor` (Python stdlib) starts llamafile (`-m /models/<file> -c <ctx> -t <threads>`, bound to 127.0.0.1 inside the container), reports state and proxies only `POST /v1/chat/completions` and `GET /v1/models`:

- `GET /status` (always 200) and `GET /health` (200 only when `READY`): `SERVICE_AVAILABLE`, `MODEL_NOT_INSTALLED`, `MODEL_LOADING`, `READY`, `ERROR`. `READY` requires upstream health **and** a successful 1-token inference probe.
- Missing model → `MODEL_NOT_INSTALLED` (no crash loop); the process starts as soon as the file appears. Crashes restart with exponential backoff; load timeout → `ERROR`.
- Config: `LOCAL_AI_THREADS`, `LOCAL_AI_CONTEXT`, `LOCAL_AI_LOAD_TIMEOUT`, `LOCAL_AI_REQUEST_TIMEOUT`. Compose: 4 CPUs / 4 GB, `restart: unless-stopped`, healthcheck, no GPU, port published only on `127.0.0.1:${LOCAL_AI_HOST_PORT:-8088}`.
- The model is never in Git (`services/local-ai/models/*` ignored) and is mounted read-only; it is not downloaded at container start.

### Model installation

`scripts/install-local-model.sh [--force]`: skips when the file exists with the right SHA-256; downloads to `<file>.part` (https only, redirects, 3 attempts), checks HTTP status, size (when advertised) and SHA-256, removes partial files on any failure, then promotes atomically and writes `models/model.json` (`model`, `filename`, `source`, `sha256`, `quantization`, `installed_at`; no secrets). Exit codes: 0 ok, 1 download/size failure, 2 invalid usage/config, 3 checksum mismatch. Default model: Qwen2.5-1.5B-Instruct Q4_K_M (≈1.1 GB, Apache-2.0, multilingual incl. Portuguese, follows short instructions, produces JSON) — chosen for CPU/low RAM, not benchmarked against alternatives. Override with `LOCAL_AI_MODEL_URL/FILENAME/SHA256/NAME`. `scripts/setup-local-ai.sh` installs, starts the container, waits for `READY` and sends a test request.

```bash
cp .env.example .env
./scripts/install-local-model.sh
docker compose up -d
docker compose ps
curl -s http://127.0.0.1:8088/status
curl -s http://127.0.0.1:8088/v1/chat/completions -H 'Content-Type: application/json' \
  -d '{"model":"local","max_tokens":20,"messages":[{"role":"user","content":"Responda apenas: ok"}]}'
```

### Service health in the Admin

`ServiceHealthChecker` (5 s cache) probes `LOCAL_AI_URL/status` and `DOCUMENT_OCR_URL/health`; failures become `UNREACHABLE`, never exceptions. The `admin.knowledge.service-health` Livewire component is rendered in the shared Admin shell header (every page, `wire:poll.10s`) with colored dot + text badge per service. The same data is available as JSON at `GET /admin/knowledge/services/health` (admin-only). Transport options (polling vs SSE vs WebSocket) are analysed in `docs/realtime-options.md`.

### Tests

`tests/Unit/Services/Ai/{DocumentClassificationResponseTest,LlamafileClassificationProviderTest}.php`, `tests/Feature/Knowledge/{KnowledgeDocumentClassificationTest,KnowledgeBackgroundClassificationTest}.php`, `tests/Feature/Admin/ServiceHealthTest.php`, `services/local-ai/tests/test_supervisor.py`. No test calls a real model or external API.

## Automatic Analysis Audit and Visibility

Purpose: make what OCR and the local AI did on each pending file visible and auditable on the admin review page.

- **Audit trail:** every deep analysis (`KnowledgePendingItemReanalysisService::reanalyze`, used by `ClassifyPendingDocument` and the "Reanalisar" button) appends an `automatic_analysis` event to `ingestion_batch_items.metadata.audit_history` with the extraction metadata (method, quality before/after OCR, engine, pages, duration, OCR error code), the classification (decision, heuristic score, thresholds in force, model, confidence, duration, error code, reason, AI answer with its evidence quotes) and the resulting fields. No PDF text or prompt is stored. Earlier results also remain in `preliminary_identification_history`.
- **Presenter:** `app/Services/Knowledge/AutomaticAnalysisReport.php` turns the stored data into labelled steps (text reading → heuristic → local AI → decision) and the list of previous runs.
- **UI:** section "Análise automática" on `admin/knowledge/.../review` (`livewire/shared/analysis-run.blade.php`). It warns when OCR/AI never ran, shows queue progress with 3 s polling while a job is `queued`/`processing`, and lists earlier runs.
- **Fix found while testing with the real model:** llamafile ignores the OpenAI `response_format: json_schema` and the model answered outside the contract (`AI_INVALID_RESPONSE`). The classifier now sends `json_object` + `schema` (grammar-enforced) when `ai.classifier.drivers.llamafile.structured_output = grammar`, and the prompt names the exact keys. `openai_compatible` keeps `json_schema`.
- **Tests:** `KnowledgeBackgroundClassificationTest` (audit entries, review page rendering, not-yet-analyzed warning), `LlamafileClassificationProviderTest`.
- **Limitations:** the Reanalisar button still runs the analysis inside the web request (OCR + AI may take tens of seconds); the queue path (`ClassifyPendingDocument`) is the non-blocking one.

### Human decision on the AI suggestion (comparison board)

- **Why:** the automatic rule never lets the AI contradict the heuristic, so a correct AI answer (e.g. "omeprazol sódico") was discarded when the heuristic had a wrong value ("10mL de solução diluente"). The rule is kept for unattended processing; the administrator now decides.
- **Data:** `classification.heuristic` (heuristic fields before the AI ran), `classification.result` (AI answer), `classification.checks` (per field: value appears in the text sent to the AI; conflicts with the heuristic) and `classification.forced`.
- **Quadro comparativo** (review page): per field heuristic × AI × relation (Igual / Diverge / Só a IA / Só a heurística) plus the grounding flag; evidence quotes in a collapsible block; technical details per step collapsed.
- **Actions** (`KnowledgeAiSuggestionReviewService`, Livewire `Review`): apply marked fields, accept all, reject the AI analysis, undo. The admin chooses fields, never types values; only values stored by the analysis can be applied. Applying updates the item's identification and pre-fills "Novo documento"; values are not catalog-confirmed. Events `ai_suggestion_accepted`, `ai_suggestion_rejected`, `ai_decision_reverted` go to `audit_history` with actor, previous and applied values; `metadata.ai_review` holds the current decision and is cleared by a new analysis (history is kept in the audit trail).
- **On-demand AI:** "Consultar IA agora" queues `ClassifyPendingDocument(forceAi: true)` (`KnowledgePendingItemReanalysisService::queueItem`), bypassing the score gate; progress and result appear through the existing 3 s polling. Refused when the classifier is not configured or the item is already queued/processing.
- **Tests:** `KnowledgeAiSuggestionReviewTest`, forced case in `KnowledgeDocumentClassificationTest`.
- **Limitations:** accepted values are not re-validated against the ANVISA catalog automatically; a new analysis discards the current decision.

### Stored prompt (audit)

- `classification.prompt` is saved with every AI consultation (also when it fails): `user` (the payload sent: heuristic score, candidates, registration, audience candidates, evidence excerpts, ambiguous fields), `sha256` of that payload and, when the provider answered, `system` (instruction), `temperature`, `max_tokens`, `structured_output` and `raw_response` (first 4000 characters of the model output). It is also copied into the `automatic_analysis` audit event of each run.
- It only contains short excerpts already extracted from the PDF (≤ ~400 characters each), never the file. It lives in `ingestion_batch_items.metadata` (admin-only), **not** in application logs, which still carry only decision, score, model, confidence and timing.
- Disable with `DOCUMENT_CLASSIFICATION_STORE_PROMPT=false` (`config/document_classification.php`). Review page: collapsible "Prompt enviado à IA e resposta bruta" with copy buttons.
- Limitation: when the answer is rejected as invalid (`AI_INVALID_RESPONSE`) the raw answer is not kept, because the provider exception carries only a controlled error code.

### Active ingredient is always sought (interaction checks depend on it)

Resolution chain, each step recorded with its origin:
1. **PDF heuristic** (`AnvisaLeafletMetadataExtractor`). A candidate that starts with a quantity ("50 mg de…", "10mL de…") is discarded: it is not a substance name.
2. **ANVISA catalog by registration number** (`KnowledgeDocumentRegulatoryValidator`): when the PDF has no active ingredient, the catalog value fills it (`regulatory_validation.filled_from_catalog`). The lookup is no longer blocked by an ambiguous *other* field; only an ambiguous registration blocks it. The catalog record now also exposes regulatory category, therapeutic class and registration expiry.
3. **Local AI**, consulted whenever the ingredient is still missing, at any heuristic score (`gap_fill`): it does not change the score decision (ACCEPTED_HEURISTIC / MANUAL_REVIEW) and its outcome is kept in `classification.gap_fill_outcome`. If the answer passes the ingredient's own checks (confidence ≥ 0.85, grounded in the excerpts sent, no conflict, plausible name) the ingredient is kept even when another field (e.g. document type) made the whole answer rejected (`ingredient_filled_by_ai`). It never alters automatic-association eligibility.
4. **Administrator**: the board offers "apply" per field and per source (AI or catalog).

UI (review page): **Dados do medicamento** panel (product, active ingredients as badges with origin, type, registration, process, holder, regulatory category, therapeutic class, registration status/expiry, each with its origin PDF / Catálogo ANVISA / IA), a red alert when the ingredient is missing, and a three-source comparison board (heuristic × catalog × AI) with checkboxes (`catalog:campo`, `ai:campo`; the first source selected for a field wins). The "heuristic" column shows only what the PDF brought. The AI prompt now asks for at most 3 evidence quotes of 120 characters and `max_tokens` is 700 (a 400-token cap truncated the JSON on long quotes); footnote asterisks are stripped from AI names.

Operational note: the ANVISA catalog must be imported (`php artisan knowledge:import-anvisa-medicines <DADOS_ABERTOS_MEDICAMENTOS.csv>`); with an empty catalog the validation is "NOT_AVAILABLE" and step 2 does nothing.

Tests: `KnowledgeDocumentRegulatoryValidatorTest`, `AnvisaLeafletMetadataExtractorTest`, `KnowledgeDocumentClassificationTest`, `KnowledgeAiSuggestionReviewTest`, `DocumentClassificationResponseTest`.
Limitations: AI-filled ingredients are not catalog-confirmed (shown as such); substance splitting for display is a simple separator split; the 1.5B model still produces wrong ingredient names that only the plausibility/grounding checks and the human catch.

## Importação do catálogo ANVISA pelo Admin

**Propósito.** Permitir que o administrador envie o `DADOS_ABERTOS_MEDICAMENTOS.csv` (portal de dados abertos da ANVISA, conjunto "Medicamentos registrados") pela interface, sem usar `knowledge:import-anvisa-medicines`. O catálogo alimenta a validação regulatória (`KnowledgeDocumentRegulatoryValidator`) e o preenchimento do princípio ativo.

**Arquivos.**
- `app/Livewire/Admin/Knowledge/Catalog/Index.php` e `resources/views/livewire/admin/knowledge/catalog/index.blade.php`: situação do catálogo, formulário, progresso (`wire:poll.3s` apenas enquanto há importação ativa) e histórico das 10 últimas.
- `app/Jobs/ImportAnvisaCatalog.php`: `tries=1`, timeout `KNOWLEDGE_ANVISA_CATALOG_JOB_TIMEOUT_SECONDS` (600); reutiliza `AnvisaMedicineCsvImporter` (novo parâmetro opcional `$tracking`, que reaproveita a linha da importação e grava o progresso a cada 750 linhas); apaga o arquivo ao final; `failed()` grava `FAILED` + `error_reference` e registra o detalhe só no log.
- Migration `add_progress_fields_to_anvisa_catalog_imports_table`: `uploaded_by`, `stored_path` (limpo após a importação) e `error_reference`. Status novo `QUEUED` (histórico anterior continua válido).
- `config/knowledge.php` (`anvisa_catalog.max_kilobytes`, padrão 51200 / `KNOWLEDGE_ANVISA_CATALOG_MAX_KILOBYTES`) e `status-badge` (rótulo "Na fila").

**Rota.** `admin.knowledge.catalog.index` → `GET /admin/knowledge/catalog` (grupo `auth` + `admin`).

**Fila e arquivos.** O CSV é guardado em `anvisa-catalog/` no disco privado `knowledge.filesystem_disk`. Só uma importação ativa por vez (`QUEUED`/`PROCESSING` com atualização nas últimas 2 h; linhas abandonadas deixam de bloquear). Arquivo já importado (mesma assinatura SHA-256) não é reenviado à fila. Exige `queue:work` e `DB_QUEUE_RETRY_AFTER` maior que o timeout do job.

**Testes.** `tests/Feature/Knowledge/AnvisaCatalogAdminPageTest.php` (envio, job, recusas, concorrência, estado vazio, falha segura, acesso admin) e `AnvisaMedicineCatalogTest.php`.

**Limitações.** Progresso mostrado em linhas lidas (sem percentual, pois o total não é conhecido). Falha deixa o histórico com código de referência; o detalhe está em `storage/logs`. Não há cancelamento de importação em andamento.

## Admin UX Refresh (review page, navigation, cards)

- **Navigation (`components/knowledge-admin/shell.blade.php`):** icons and a hover hint on every item, new item "Catálogo ANVISA", "Ir para o conteúdo" skip link, shadowed header. Menu order follows the workflow: Visão geral → Adicionar documentos → Importações → Pendências → Documentos → Origens → Catálogo ANVISA. (The design-system test forbids `stroke-*` classes/attributes, so icons use `stroke="currentColor"` only.)
- **Pending review page (`livewire/admin/knowledge/pending/review.blade.php`):**
  - Top summary card: steps (Conferir os dados → Escolher o destino → Concluído), medicine / active ingredient / registration with catalog status, and a **"Próximo passo"** box that adapts (analysis running, ingredient missing, divergences, or all consistent) with the main action **"Criar documento com estes dados e finalizar"** plus "Associar a documento existente" and "Não incorporar".
  - Four tabs (Alpine, URL hash kept, sticky bar): Dados do medicamento · Análise (OCR e IA) (badge with the number of divergences) · Resolver pendência / Resultado · Arquivo e histórico. This replaces one very long page.
  - "Resolver pendência" is a three-option chooser (criar / associar / não incorporar) showing one form at a time.
  - After finishing (associate, create or not incorporate) the summary becomes **"Pendência concluída"** with "Abrir o documento", **"Próxima pendência (N restantes)"** (same batch first) and "Voltar à fila".
  - Card height limits: values `line-clamp-2` with full text in `title`, scroll areas (`max-h-72`/`max-h-96`) for document search, history and previous runs, history shows 6 events with "Mostrar todos (N)", collapsed technical/prompt blocks.
- **Pending queue (`pending/index.blade.php`):** each card shows medicine, active ingredient (or "Sem princípio ativo") and the automatic decision as chips; text is truncated.
- **Catalog page:** see "ANVISA catalog import page" above (route `admin.knowledge.catalog.index`).
- **Tests:** `KnowledgePendingReviewFlowTest`, `AnvisaCatalogAdminPageTest`, `AdminDesignSystemTest` (unchanged rules).
- **Operational:** after pulling these changes run `php artisan migrate`, `npm run build` (new Tailwind classes) and restart the queue worker.
- **Limitations:** tab state is client-side only; the UI was verified by tests, not visually in a browser during this change.

## Production Infrastructure and CI/CD (KingHost)

Full operational guide: `docs/deployment/kinghost.md`.

- **Stack:** `compose.prod.yaml` (independent from the development `compose.yaml`): `web` (Nginx, only service publishing a port, `127.0.0.1:8080`), `app` (PHP 8.4-FPM), `worker` (single `queue:work`), `postgres` (pgvector/PG17, non-superuser app role), `document-ocr`, `local-ai`. Hardened containers (non-root, `cap_drop ALL`, read-only FS for app/worker/web), memory/CPU limits for 8 GB, internal `db`/`ai` networks without egress (OCR/IA cannot reach PostgreSQL), PHP-FPM reachable only through a Unix socket shared with Nginx.
- **Images:** `docker/production/php/Dockerfile` (multi-stage: Composer `--no-dev`, Vite build, runtime; labels `source`/`revision`), `docker/production/nginx/Dockerfile` (assets copied from the same app image), OCR image built with `PREFETCH_MODELS=1` (models baked in). Tags `sha-<commit>`; the VPS pulls by digest. No `.env`/credentials in images (`.dockerignore`).
- **Application changes:** `bootstrap/app.php` reads `TRUSTED_PROXIES` (test: `tests/Feature/Infrastructure/TrustedProxiesTest.php`); `services/document-ocr` limits concurrency (`OCR_MAX_CONCURRENCY`, default 1) and runs OCR outside the event loop so `/health` stays responsive (test in `services/document-ocr/tests/test_api.py`).
- **Trust boundary:** `scripts/deploy/bulai-deploy` (root, validated arguments, image digests + OCI labels, approved compose installed by an administrator in `/opt/bulai/trusted`, compose-hash drift check), `scripts/deploy/bulai-ci-entry` (SSH forced command), `scripts/deploy/sudoers-bulai-ci`, `scripts/deploy/install-on-vps.sh`. The CI identity cannot run Docker, change the compose or read secrets.
- **Workflows:** `.github/workflows/ci.yml` (PHPUnit on Postgres, Pint, composer/npm audit, Vite build, Python service tests, ShellCheck, deploy-script tests, Compose/Caddy/workflow validation, gitleaks, image build + Trivy) and `deploy.yml` (manual `workflow_dispatch` only: `authorize` validates a green commit of `main` and the actor; `release`/`operate` run in the `production` Environment so human approval precedes any image publication, with a guard that refuses to run when `PRODUCTION_PROTECTION` is not declared; build and VPS call share one job so published digests are exactly the deployed ones; `rollback` and `status` go through the same Environment and guards). `.github/CODEOWNERS` protects the boundary files.
- **Tests:** `scripts/deploy/tests/run.sh` (67 assertions against stubbed `docker`/`curl`/`sudo`); local end-to-end runs against real Docker are listed in the deployment guide.
- **Known limitations:** nothing was executed on the VPS; rollback does not revert migrations (expand/contract rule); restoring the database is manual; no external uptime monitoring.

### UID/GID hardening for bind mounts (VPS compatibility fix)

Container IDs are now high and unrelated to host accounts (app/worker 10010, postgres 10020, nginx 10030, ocr 10001, local-ai 10002): on the KingHost VPS `999` is `caddy` and `101` is `messagebus`/`lxd`, which would have given those accounts access to the PostgreSQL data directory and the PHP-FPM socket. PostgreSQL runs as 10020 with minimal read-only `/etc/passwd`/`/etc/group` mounts (`docker/production/postgres/{passwd,group}`); Nginx runs as 10030 with its disk buffer in `/srv/bulai/shared/web-cache`; the socket directory is `2750 10010:10030`. `install-on-vps.sh` refuses to continue when any of these IDs exists on the host (it never edits accounts), migrates the ownership of an already-initialised database, and refuses userns-remap/rootless Docker; `bulai-deploy` checks owner/mode of every shared directory. See `docs/deployment/kinghost.md` ("UIDs, bind mounts e contas do host"). Tests: `scripts/deploy/tests/run.sh` (80 assertions, including ID consistency across compose, Dockerfiles, installer and deploy script).

## Gestão de usuários e endurecimento do login

**Propósito.** Permitir que o administrador crie e mantenha acessos pela interface, sem `db:seed`, com rastreabilidade, e endurecer o login (limite de tentativas, conta desativada, troca obrigatória de senha provisória).

**Arquivos.**
- `app/Services/Users/UserAccountService.php` (`final`): `create` (devolve `[User, senha provisória]`), `update`, `setActive`, `issueTemporaryPassword`, `changePassword` e `completeFirstAccess` (os dois últimos compartilham `storeOwnPassword`). Travas e gravações em `DB::transaction` com `lockForUpdate` nas linhas de ADMIN ativos (sempre travadas antes da linha-alvo, para evitar deadlock): ninguém se desativa nem se rebaixa, nunca fica zero ADMIN ativo, e-mail repetido ignorando caixa vira `ValidationException` (também o `23505` do índice único). Senha provisória: `Str::password(16, symbols: false)`.
- `app/Livewire/Admin/Users/{Index,Form,Show}.php` + `resources/views/livewire/admin/users/*.blade.php`: lista em cartões (busca `q` na URL, filtros de situação e perfil, paginação de 15), formulário único de criar/editar e detalhe com histórico. O `boot()` de cada componente confere o perfil ADMIN (defesa em profundidade).
- `resources/views/components/knowledge-admin/temporary-password-modal.blade.php`: janela da senha provisória com "Copiar" e aviso de que não será mostrada novamente.
- `app/Http/Controllers/Auth/AccountController.php` + `resources/views/auth/account.blade.php`: "Minha conta", fora do shell do admin (troca voluntária, com senha atual).
- `app/Http/Controllers/Auth/FirstAccessController.php` + `resources/views/auth/first-access.blade.php`: "Primeiro acesso", fora do shell (ver "Primeiro acesso" abaixo).
- `app/AdminNavigation.php`: fonte única do menu do admin (rota, rótulo, padrão de rota ativa, ícone e dica), usada pelo `shell` e pelo resumo de áreas do primeiro acesso.
- `app/Http/Middleware/EnsureUserIsActive.php`, `EnsureTemporaryPasswordIsChanged.php`; `app/Models/UserAuditEvent.php`; `app/Models/User.php` (casts, `isActive()`, `homeRouteName()`, `hasSetOwnPassword()`, `creator()`, `auditEvents()`); `app/UserRole.php` (`options()`, `label()`).
- `app/Providers/AppServiceProvider.php`: limitador `login`, `Password::defaults()` e `Livewire::addPersistentMiddleware()`.
- `lang/pt_BR/auth.php` (`inactive`) e `lang/pt_BR/validation.php` (`confirmed`, `current_password`, `different`, `password.*`).
- `database/factories/UserFactory.php` (estados `inactive()` e `mustChangePassword()`; o padrão continua ativo e sem troca pendente) e `database/seeders/DatabaseSeeder.php`.

**Rotas.** `admin.users.index|create|show|edit` → `/admin/users[...]` (grupo admin); `first-access` → `GET /first-access` e `first-access.password.update` → `PUT /first-access/password` (`auth` + `EnsureUserIsActive`); `account` → `GET /account` e `account.password.update` → `PUT /account/password` (`auth` + `EnsureUserIsActive` + `EnsureTemporaryPasswordIsChanged`); `login.store` com `throttle:login`.

**Tabelas.**
- `users` (migration `add_account_state_to_users_table`): `active` (bool, default `true`, índice), `must_change_password` (bool, default `false`), `created_by` (FK `users`, `nullOnDelete`), `last_login_at`. Antes de alterar o esquema, normaliza os e-mails (`Str::lower(trim())`) e aborta, sem mudar nada, se dois cadastros colidirem ignorando caixa (a mensagem lista só os ids). O `users_email_unique` é mantido. O `down()` remove as colunas, mas não desfaz a normalização.
- `user_audit_events` (só inclusão): `user_id` (FK, `restrictOnDelete`), `actor_id` (FK anulável, `nullOnDelete`), `event`, `details` (jsonb), `ip`, `created_at`. Eventos: `created` (e-mail e perfil), `updated` (campos alterados com de/para, nunca senha), `role_changed` (de/para), `deactivated`, `reactivated`, `temporary_password_issued`, `password_changed` (`details` vazio na Minha conta; `{"via": "first_access"}` no primeiro acesso, mostrado no histórico como "Ao substituir a senha provisória."). Rótulos pt-BR na página do usuário.

**Senha provisória.** Gerada pelo serviço, gravada só como hash (cast `hashed`), marca `must_change_password`. É exibida uma vez: fica numa propriedade `protected` do componente (não entra no snapshot do Livewire nem na sessão) e some na requisição seguinte. Nunca vai para `session()->flash`, log ou auditoria (a sessão fica no banco sem criptografia em produção).

**Derrubar acesso.** Desativar, trocar o perfil ou gerar nova senha provisória gira o `remember_token` e apaga as linhas de `sessions` com o `user_id` da pessoa (quando `SESSION_DRIVER=database`). Na troca de senha pela própria pessoa, a sessão atual é preservada e as demais são apagadas. Com outro driver, a rede de segurança é o `EnsureUserIsActive` e o perfil relido do banco a cada requisição.

**Middleware persistente do Livewire.** `Livewire::addPersistentMiddleware([EnsureUserIsActive, EnsureUserIsAdmin, EnsureTemporaryPasswordIsChanged])`: no POST do endpoint de update, o Livewire refaz a rota original (memo `path`/`method` do snapshot), resolve o middleware com `Router::gatherRouteMiddleware()` — que transforma o alias `admin` na classe `EnsureUserIsAdmin` — e reaplica somente os que estão na lista, comparando por nome de classe. Por isso o alias não precisou mudar. Prova: `tests/Feature/Auth/LivewirePersistentMiddlewareTest.php` abre a tela de Origens por HTTP, faz uma ação válida e repete a ação depois de rebaixar (403), desativar (volta ao login, sessão encerrada) ou exigir troca de senha (vai ao primeiro acesso). Sem o registro no provider, os três testes falham com 200 (verificado).

**Primeiro acesso.** Quem tem `must_change_password` vai a `/first-access` no login (mesmo com URL pretendida do admin), em `/` e em qualquer rota do admin ou da Minha conta (`EnsureTemporaryPasswordIsChanged`). Sem troca pendente, `GET` e `PUT` de `/first-access` só redirecionam ao destino do perfil — a rota nunca troca senha sem a pendência, porque não pede a senha atual. O formulário pede só "Nova senha" + confirmação (`Password::defaults()`) e recusa a nova igual à provisória (`Hash::check` contra o hash atual: "A nova senha deve ser diferente da senha provisória."). Ao salvar, `UserAccountService::completeFirstAccess()` limpa a pendência, gira o `remember_token`, apaga as outras sessões (mantém a atual) e audita `password_changed` com `{"via": "first_access"}`; o ADMIN segue para `/admin/documents` e o USER para `/account`, com `accountFeedback` ("Senha definida com sucesso."), exibido na Minha conta e no `shell`.

A tela tem dois modos, decididos por `User::hasSetOwnPassword()` (existe `password_changed` na auditoria da pessoa?), sem coluna nova: **boas-vindas** (nunca definiu a própria senha) — título com o nome, apresentação curta do BULAI e, para ADMIN, o resumo das áreas do menu lido de `App\AdminNavigation::items()` (mesma fonte do `shell`; mudar o menu atualiza a tela); para USER, o que ele pode fazer em Minha conta e o aviso de que a consulta de bulas ainda vai chegar — e **nova senha** (já definiu antes e recebeu nova senha provisória) — título "Defina sua nova senha" e um texto curto, sem a apresentação.

**Operação.** Rodar `php artisan migrate` (expand: colunas novas com default; compatível com a versão anterior do código). Se a migration abortar por e-mails duplicados, unificar ou alterar os cadastros indicados e rodar de novo. O admin do seed (`bulai-deploy seed-admin`) volta a ficar ativo e sem troca obrigatória ao rodar o seeder.

**Testes.** `tests/Feature/Admin/UserManagementAdminTest.php` (acesso, criação com senha exibida uma vez, duplicidade, edição auditada, travas, sessões, nova senha provisória, filtros, escape, histórico), `tests/Feature/Auth/AuthenticationTest.php` (limite, caixa do e-mail, `last_login_at`, conta desativada, destino por perfil), `tests/Feature/Auth/AccountTest.php`, `tests/Feature/Auth/FirstAccessTest.php`, `tests/Feature/Auth/AccountStateMiddlewareTest.php`, `tests/Feature/Auth/LivewirePersistentMiddlewareTest.php`, `tests/Feature/Auth/AdminSeederTest.php`, `tests/Feature/Infrastructure/UserAccountStateMigrationTest.php` e `AdminDesignSystemTest` (inclui `auth/account.blade.php`, `auth/first-access.blade.php` e as strings de `app/AdminNavigation.php`).

**Limitações.** Sem convite nem redefinição de senha por e-mail (a senha provisória é entregue pelo administrador); sem exclusão definitiva de usuário; sem perfil de curadoria; sem gestão de tokens da API pela interface; o limite de login conta todas as tentativas (inclusive as bem-sucedidas) e usa o cache da aplicação; a troca de senha em `/account` não tem limite próprio de tentativas; quem usava o sistema antes da trilha de auditoria e recebe nova senha provisória vê o modo de boas-vindas no primeiro acesso (não há `password_changed` registrado); datas exibidas no fuso da aplicação (UTC).

## Janela de confirmação do admin (`confirm-modal`)

**Propósito.** Trocar a caixa nativa do navegador (`wire:confirm`, "127.0.0.1 diz… OK/Cancelar") por uma janela no visual do BULAI, com título, consequência explicada e botão com o verbo da ação.

**Arquivos.**
- `resources/views/components/knowledge-admin/confirm-modal.blade.php` (novo): props `title`, `message`, `confirm-label`, `action`, `tone` (`primary` | `danger`), `loading`, `loading-target` (padrão: `action`). O botão que abre a janela vem no slot, sem `wire:click`; `action` é a mesma expressão que iria no `wire:click` (`clearFiles` vira `clearFiles()`).
- Telas: `livewire/admin/users/show.blade.php` (gerar nova senha provisória; desativar/reativar), `livewire/admin/users/index.blade.php` (desativar/reativar em cada cartão), `livewire/admin/documents/show.blade.php` (processar/reprocessar), `livewire/admin/knowledge/upload.blade.php` (limpar seleção). Nenhum componente Livewire mudou.

**Funcionamento.** Tudo no cliente (Alpine que vem com o Livewire 4): abrir e cancelar não fazem requisição. O botão de confirmar executa `run(() => $wire.<ação>)`: uma trava `busy` e o `wire:loading.attr="disabled"` do `button` (mesmo `loading-target` nos três botões da janela) impedem clique duplo; Esc, clique fora e Cancelar são ignorados enquanto a ação roda; a janela fecha no `finally`, inclusive se a promessa do Livewire for rejeitada (ação cancelada ou erro), para não travar. Acessibilidade: `role="dialog"`, `aria-modal`, `aria-labelledby`/`aria-describedby`; foco em "Cancelar" depois do quadro em que o `x-show` exibe a janela (o Alpine mostra o elemento num `requestAnimationFrame`); `x-trap.noscroll.noreturn.noautofocus` prende o foco (o focus-trap recalcula os focáveis a cada Tab) e trava a rolagem; ao fechar, o foco volta ao botão de origem, se ele ainda existir (a lista pode remover o cartão, e "Limpar seleção" some com a lista).

**Ids e chaves.** Os ids são `confirmacao-` + 12 caracteres do `md5` da expressão da ação (ex.: `toggleActive(5)`), então cada cartão da lista tem os seus. O componente não usa `wire:key`: com `smart_wire_keys` (padrão do Livewire 4), um `wire:key` fora de laço vira prefixo da chave dos componentes Livewire renderizados depois — no teste, o `service-health` do cabeçalho passou a ter `confirmacao-…` na chave, o que o remontaria quando a ação muda (ex.: `processDocument(false)` → `processDocument(true)`).

**Testes.** `tests/Feature/Admin/ConfirmModalAdminTest.php` lê cada janela pelo DOM (`Dom\HTMLDocument`, PHP 8.4), resolvendo título e mensagem pelos ids de `aria-labelledby`/`aria-describedby` — ids repetidos trariam o texto de outra janela (verificado: com id fixo, dois testes falham) — e confere título, rótulo e ação nas quatro telas. `AdminDesignSystemTest::test_confirmations_use_the_confirm_modal_instead_of_the_native_browser_dialog` reprova `wire:confirm` em qualquer arquivo de `resources/views`. `KnowledgeDocumentProcessingAdminTest` passou a conferir o título novo ("Processar documento?").

**Operação.** `npm run build` (classes novas no CSS, por exemplo `max-w-md` e `contents`).

**Limitações.** Abrir, Esc, foco e trava de rolagem dependem de JavaScript e não têm teste automatizado (o projeto não tem testes de navegador). Depois de "Gerar nova senha provisória", o foco volta ao botão de origem, atrás da janela da senha, que não gerencia foco (comportamento anterior do `modal`). Sem transição de entrada/saída, como o `modal`.

### PHP base image update (8.4.26 → 8.4.27)

`docker/production/php/Dockerfile` now pins `php:8.4.27-fpm-bookworm@sha256:df5a5113…` (was 8.4.26) and runs `apt-get upgrade` in `php-base`. Trivy (fixed vulnerabilities only) on the base: 8.4.26 had 70 HIGH/CRITICAL (1 CRITICAL, `linux-libc-dev` CVE-2026-98621; 69 HIGH); 8.4.27 has 3 HIGH; the final `app` image has 0 HIGH/CRITICAL with a fix available. Remaining CRITICALs have no fix in Debian bookworm (`linux-libc-dev` kernel headers, `libsqlite3-0`, `libxml2` fix deferred, `zlib1g` will-not-fix) and are accepted risk: the CI gate blocks CRITICAL with a fix (`ignore-unfixed`). Base images are pinned by digest, so they only move when the digest is changed in a PR.

## Heuristic, OCR and AI refinements (Glifage case)

Real-PDF regression (`Metformina_Glifage_bula_profissional.pdf`): the native text was fine, but the heuristic produced `product_name = "cloridrato de metformina* ....500 mg"` and `active_ingredient = "*equivalente a 390 mg de metformina"`, and the AI repeated them. Causes and fixes:

- **Cover title block** (`LeafletTextHeuristics::titleBlock`): a standalone `®`/`™` line is merged into the previous line; the first lines of pages 1–3 are read as brand (with ® or first short line) + generic name (salt prefixes such as *cloridrato de*, *sulfato de*, or typical substance endings) + holder (`S/A`, `Ltda`…). Candidates `cover_brand` (weight 60) and `cover_generic_name` (55); repeated identically on two pages adds +15 to the score. The product name keeps no trailing ®/™.
- **Composition "formula" lines**: `substância* ..... 500 mg` yields the substance (`composition_formula_line`, 45); `(*equivalente a 390 mg de metformina)` yields the base only as a low-weight candidate (`equivalent_base`, 20). Dotted leaders, footnote marks, trailing doses, lines starting with `*`/`(`/`equivalente` and class acronyms (`IBP`) are never names. The `title_followed_by_parenthesized_ingredient` pattern no longer fires on composition lines.
- **Molecular formulas** (`ChemicalFormulaDetector`): looks (first 15 pages) for `Fórmula molecular: …` or sequences of valid element symbols with counts (`C4H11N5·HCl`, `C17H19N3O3S`, subscripts accepted); acronyms (`HbA1c`, `AUC0-t`) are rejected. A single formula becomes `fields.molecular_formula` (shown in the medicine panel) and its context is sent to the AI as a `formula_context` excerpt.
- **More text for the AI:** excerpts now include `cover_block` (14 lines), the full `composition` block (≤1200 chars), `identification_section` (≤900), holder/registration lines and formula context (up to 16 excerpts). `max_tokens` 700 → 1200; `LOCAL_AI_CONTEXT` 2048 → 8192 (measured ≈400 MB RSS on the local-ai container; prod limit unchanged). Overlong evidence quotes from the model are shortened to 400 chars instead of invalidating a correct answer. New decision `AI_CONFIRMED` when the AI agrees with every heuristic field and adds nothing (was shown as "rejected").
- **OCR actually works now:** PaddleOCR 3.x crashed on CPU with oneDNN (`ConvertPirAttribute2RuntimeAttribute not support …`), so no real OCR ever ran. The service now runs with `enable_mkldnn=False` and the light PP-OCRv5 mobile models (`PP-OCRv5_mobile_det` + `latin_PP-OCRv5_mobile_rec`; ≈4 s for a cover page vs ≈30–70 s with the "medium" models), 150 dpi, `OCR_*` env overrides (`OCR_DET_MODEL`, `OCR_REC_MODEL`, `OCR_ENABLE_MKLDNN`, `OCR_CPU_THREADS`, `OCR_RENDER_DPI`). `python -m app.warmup` pre-downloads exactly these models at image build.
- **Targeted OCR supplement:** `POST /v1/documents/parse` accepts `page_limit`. When the heuristic is weak (score below the auto-accept threshold, product/ingredient missing or ambiguity), `KnowledgeDocumentIdentificationService` OCRs only the first `DOCUMENT_OCR_SUPPLEMENT_PAGES` pages (default 3, 0 = off), merges the lines the OCR saw and the native text lacks, re-runs the heuristic and adopts the result only if the score does not drop. The outcome is recorded in `extraction.ocr_supplement` and shown on the review page.
- **Tests:** `LeafletHeuristicsTest`, `KnowledgeOcrSupplementTest`, `DocumentTextExtractionServiceTest`, `HttpDocumentOcrClientTest`, `DocumentClassificationResponseTest`, `KnowledgeDocumentClassificationTest`, `KnowledgeAiSuggestionReviewTest`, `services/document-ocr/tests`.
- **Measured on ~50 distinct local PDFs (before → after):** several leaflets gained product/ingredient (e.g. paracetamol 20 → 55), garbage ingredients such as `< 39°C` and `IBP` disappeared, a real formula (`C17H19N3O3S`) was detected; no field got worse.
- **Limitations:** the catalog-less leaflets without a registration number stay below the auto-accept score (the AI is consulted); formulas are only found when the leaflet prints them; OCR of 3 pages still takes ≈20–25 s on 2 CPUs; the new OCR models must be rebuilt into the production image (`PREFETCH_MODELS=1`).

### OCR image pipeline (`.github/workflows/ocr-image.yml`)

The main CI does not build the OCR image (it is heavy), which is how the PaddleOCR/oneDNN crash went unnoticed. This workflow builds it like production (`PREFETCH_MODELS=1`, models baked in), starts the container with production-like limits (`--cap-drop ALL`, 2 GB, 2 CPUs), runs a REAL OCR of an image-only PDF (`services/document-ocr/smoke/ocr_smoke.py`, expects "glifage", "metformina", "merck"), checks that calls without the token get 401, and scans with Trivy (CRITICAL with a fix). It runs on PRs/pushes to `main` that touch `services/document-ocr/**`, and weekly (Monday 06:00 UTC) as a **canary** that builds WITHOUT version pins to catch breaking Paddle releases early. It never publishes: publication stays in `deploy.yml` after Environment approval (the OCR layers are cached, so the deploy only rebuilds when the OCR changed).

- `services/document-ocr/constraints.txt` pins the exact validated versions (paddlepaddle 3.3.1, paddleocr 3.7.0, paddlex 3.7.2…). Regenerate after a successful canary: `docker exec <ocr-container> pip freeze | grep -vi '^bulai-document-ocr' > services/document-ocr/constraints.txt` (keep the 2 comment lines).
- The Dockerfile installs dependencies first (read from `pyproject.toml`) so changing service code no longer reinstalls Paddle; `USE_CONSTRAINTS=0` disables the pins (canary).
- Local run: `docker build --build-arg PREFETCH_MODELS=1 -t bulai-ocr services/document-ocr`, start it, then `python services/document-ocr/smoke/ocr_smoke.py http://127.0.0.1:8090 [TOKEN]` (needs Pillow). Verified locally: OCR of the test PDF in 3.6 s, 401 without token, Trivy 0 CRITICAL.
- Limitation: the weekly canary only reports (failed run); bumping the pins is manual. The canary mode was not run locally (it is a long dependency resolution); its first scheduled run will exercise it.

### Queue and services page, per-document reanalysis, SSE (`/admin/knowledge/queue`)

Problem: pending files stayed "not analyzed", the queue was invisible, the worker could be dead without the UI saying so, a stuck OCR/AI could not be restarted and reanalysis was all-or-nothing. **Redis is not used**: queue, cache and sessions are on the `database` driver (phpredis only exists in Laravel's default config); adding it needs approval.

- **Worker heartbeat** (`App\Services\Queue\WorkerHeartbeat`, wired in `AppServiceProvider` on `Looping`/`JobProcessing`/`JobProcessed`/`JobFailed`): the cached heartbeat tells `running | idle | stopped` (stopped = no signal for 30 s, or 900 s while a job is running).
- **`QueueStatusService::snapshot()`**: jobs waiting/running (`jobs` table), `failed_jobs`, pending items with `reanalysis_progress` queued/processing, items stale for `KNOWLEDGE_PROGRESS_STALE_MINUTES`, and whether each has a job in the queue. `removeWaitingJobs($itemId)` drops not-yet-started jobs of one item.
- **Page "Fila e serviços"** (`Livewire\Admin\Knowledge\QueueStatus`, nav entry, ADMIN only): worker state with the command to start it, counters, one card per service (state + "working now for N s" + restart count) with a confirmed **Restart** button, per-item **Reenfileirar / Cancelar** and **Reenfileirar parados**. Requeue releases a unique-job lock left behind by a dead worker (`KnowledgePendingItemReanalysisService::queueItem(..., replaceActive: true)`); cancel marks the item `failed` ("Análise cancelada pelo administrador") and audits `reanalysis_cancelled`; the item stays PENDING.
- **Per-document reanalysis**: "Reanalisar" on each card in Pendências (`Pending\Index::reanalyzeItem`) and the review page's "Reanalisar" now queue a single `ClassifyPendingDocument` (no longer synchronous). "Reanalisar fila" still queues every pending batch.
- **Service activity/restart**: `ServiceHealth` gained `busy`, `activeRequests`, `currentSeconds`, `restarts`, read from `/status` of both services (OCR falls back to `/health` on older images). `ServiceRestarter` calls `POST /admin/restart` with `Authorization: Bearer` (OCR: `DOCUMENT_OCR_TOKEN`; local AI: `LOCAL_AI_ADMIN_TOKEN`, `config('ai.local_ai.admin_token')`). Laravel never touches Docker: the OCR process exits and `restart: unless-stopped` brings it back; the local AI restarts only its llamafile subprocess. Both have a watchdog (`OCR_REQUEST_TIMEOUT` 900 s → exit 3; `LOCAL_AI_HANG_TIMEOUT` 240 s) — see `services/*/README.md`.
- **SSE**: `GET /admin/knowledge/stream` + `resources/js/app.js` (details and limits in `docs/realtime-options.md`); Livewire polling (5 s) is the fallback. Run `npm run build` to ship the client.
- **Tests**: `tests/Feature/Admin/QueueStatusTest.php` (page access, heartbeat, snapshot/stale, requeue/cancel, single-document reanalysis, restart + token, activity parsing, SSE stream/403/429), `services/*/tests`.
- **Production notes / limitations**: `docker/production/nginx/default.conf` has a no-buffering location for the stream. OCR restart works in production with the existing token; **local-AI restart needs a `LOCAL_AI_ADMIN_TOKEN` in `app.env` and `local-ai` env, which touches the deploy trust boundary (`init-secrets`, preflight, installer) and was left for a separate approved change** — until then the button shows "Reinício remoto não configurado". Each SSE stream holds one PHP-FPM worker (pool of 6): keep `KNOWLEDGE_STREAM_MAX_PER_USER` low or add a dedicated pool if many admins connect.
