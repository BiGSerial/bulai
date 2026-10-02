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

No OCR, embedding generation, vector retrieval, LLM, or external service is used.

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

Image-only PDFs and PDFs with insufficient text fail with `TEXT_EXTRACTION_FAILED`. OCR is intentionally absent. Invalid/unsupported PDFs produce the generic `PROCESSING_ERROR`; technical exceptions remain in logs with an `error_id`.

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

The document detail in Knowledge Admin displays content status, processing time, controlled error/reference, chunk count, and paginated chunks with section and page range. Process/reprocess actions require explicit Livewire confirmation and only dispatch the Job.

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
- admin route redirect for guests and `403` for authenticated `USER` role.
- configured admin seed creation and skip behavior when credentials are absent.
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
- OCR remains intentionally absent; text-only PDF extraction and deterministic chunking are implemented.
- Knowledge Admin supports manual pending-item association and creation of logical documents, but does not yet provide general editing of document metadata.
- Admin creation depends on explicit environment variables; there is no user-management UI yet.
- The PWA baseline does not provide offline authenticated document access.

## Administrative Upload and Portable Knowledge Storage

`GET /admin/knowledge/upload` is an authenticated, admin-only Livewire 4 screen for selecting an active `knowledge_source` and uploading multiple PDFs. It calls the same `KnowledgeBatchIngestionService` used by `POST /api/internal/knowledge/batches`; the component does not perform matching, hashing, storage promotion, duplicate detection, or filesystem cleanup. There is no application-level quantity cap; `KNOWLEDGE_INGESTION_MAX_BATCH_KILOBYTES` limits the sum of all PDFs to 102400 KiB (100 MB) and `KNOWLEDGE_INGESTION_MAX_FILE_KILOBYTES` protects each temporary upload at the same default ceiling. The UI uses Livewire temporary uploads, server-side PDF MIME/extension validation, browser upload progress, sanitized errors, and links to the resulting batch and pending-review queue.

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
