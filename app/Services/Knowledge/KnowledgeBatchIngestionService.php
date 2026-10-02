<?php

namespace App\Services\Knowledge;

use App\Jobs\ProcessKnowledgeDocument;
use App\Models\Knowledge\IngestionBatch;
use App\Models\Knowledge\IngestionBatchItem;
use App\Models\Knowledge\KnowledgeDocument;
use App\Models\Knowledge\KnowledgeSource;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Resolves each PDF in a batch manifest to an existing knowledge document
 * and attaches it, without ever invalidating the other items in the batch.
 *
 * @phpstan-type ManifestItem array{file: UploadedFile, external_key?: string|null, source_document_id?: string|null, original_filename?: string|null, metadata?: array<string, mixed>|null}
 */
class KnowledgeBatchIngestionService
{
    private readonly KnowledgeDocumentLifecycleService $documentLifecycle;

    private readonly KnowledgeDocumentIdentificationService $identificationService;

    public function __construct(private readonly KnowledgeFileService $fileService, ?KnowledgeDocumentLifecycleService $documentLifecycle = null, ?KnowledgeDocumentIdentificationService $identificationService = null)
    {
        $this->documentLifecycle = $documentLifecycle ?? app(KnowledgeDocumentLifecycleService::class);
        $this->identificationService = $identificationService ?? app(KnowledgeDocumentIdentificationService::class);
    }

    /**
     * @param  array{name: string, description?: string|null, source_id?: int|null}  $batchPayload
     * @param  list<ManifestItem>  $items
     */
    public function ingest(array $batchPayload, array $items): IngestionBatch
    {
        if (isset($batchPayload['source_id']) && ! KnowledgeSource::query()->active()->whereKey($batchPayload['source_id'])->exists()) {
            throw ValidationException::withMessages([
                'batch.source_id' => 'Selecione uma origem ativa válida.',
            ]);
        }

        $startedAt = hrtime(true);
        Log::info('knowledge_upload_started', ['files' => count($items)]);
        $batch = IngestionBatch::query()->create([
            'name' => $batchPayload['name'],
            'description' => $batchPayload['description'] ?? null,
            'source_id' => $batchPayload['source_id'] ?? null,
            'status' => IngestionBatch::STATUS_PROCESSING,
            'total_items' => count($items),
        ]);

        $counts = [
            IngestionBatchItem::STATUS_ASSOCIATED => 0,
            IngestionBatchItem::STATUS_DUPLICATE => 0,
            IngestionBatchItem::STATUS_PENDING => 0,
            IngestionBatchItem::STATUS_ERROR => 0,
        ];

        foreach ($items as $manifestItem) {
            $item = $this->processItem($batch, $manifestItem);
            $counts[$item->status]++;
        }

        $batch->forceFill([
            'status' => IngestionBatch::STATUS_COMPLETED,
            'associated_items' => $counts[IngestionBatchItem::STATUS_ASSOCIATED],
            'duplicate_items' => $counts[IngestionBatchItem::STATUS_DUPLICATE],
            'pending_items' => $counts[IngestionBatchItem::STATUS_PENDING],
            'error_items' => $counts[IngestionBatchItem::STATUS_ERROR],
        ])->save();

        $this->dispatchAssociatedDocuments($batch);
        Log::info('knowledge_upload_completed', [
            'batch_id' => $batch->id,
            'files' => count($items),
            'bytes' => array_sum(array_map(fn (array $item): int => (int) $item['file']->getSize(), $items)),
            'duration_ms' => (int) ((hrtime(true) - $startedAt) / 1_000_000),
        ]);

        return $batch->refresh()->load('items');
    }

    /**
     * @param  ManifestItem  $manifestItem
     */
    private function processItem(IngestionBatch $batch, array $manifestItem): IngestionBatchItem
    {
        /** @var UploadedFile $file */
        $file = $manifestItem['file'];
        $externalKey = $manifestItem['external_key'] ?? null;
        $sourceDocumentId = $manifestItem['source_document_id'] ?? null;
        $originalFilename = $manifestItem['original_filename'] ?? $file->getClientOriginalName();
        $metadata = $manifestItem['metadata'] ?? [];
        $ownedDefinitivePath = null;
        $ownedPendingPath = null;
        $sha256 = null;
        $requiresPreliminaryIdentity = ($externalKey === null && $sourceDocumentId === null)
            || ! isset($metadata['title'], $metadata['document_type']);

        try {
            if ($file->getMimeType() === 'application/pdf') {
                $sha256 = $this->fileService->hashUploadedFile($file);
                if (! KnowledgeDocument::query()->where('sha256', $sha256)->exists()) {
                    $identification = $this->identificationService->identify($batch->source, $file);
                    if ($identification !== null) {
                        $metadata['preliminary_identification'] = [
                            ...$identification->metadata(),
                            'used_for_lifecycle' => $requiresPreliminaryIdentity,
                        ];
                        $sourceDocumentId ??= $identification->sourceDocumentId();
                        foreach (['title', 'document_type', 'active_ingredient', 'product_name', 'registration_number', 'holder'] as $field) {
                            $metadata[$field] ??= $identification->fields[$field] ?? null;
                        }
                    }
                }
            }

            return DB::transaction(function () use ($batch, $file, $externalKey, $sourceDocumentId, $originalFilename, $metadata, $sha256, &$ownedDefinitivePath, &$ownedPendingPath): IngestionBatchItem {
                if ($file->getMimeType() !== 'application/pdf') {
                    return $this->storeItem($batch, [
                        'external_key' => $externalKey,
                        'source_document_id' => $sourceDocumentId,
                        'original_filename' => $originalFilename,
                        'status' => IngestionBatchItem::STATUS_ERROR,
                        'reason' => 'invalid_mime_type',
                        'metadata' => $metadata,
                    ]);
                }

                $sha256 ??= $this->fileService->hashUploadedFile($file);
                $normalizedFilename = KnowledgeFileService::normalizeFilename($originalFilename);

                [$document, $method, $ambiguousReason] = $this->resolveDocument(
                    $externalKey,
                    $sha256,
                    $sourceDocumentId,
                    $normalizedFilename,
                    $batch->source_id,
                );

                if ($document === null && $ambiguousReason === null) {
                    $document = $this->documentLifecycle->createAutomatically($batch, $externalKey, $sourceDocumentId, $metadata);
                    $method = $document === null ? null : IngestionBatchItem::METHOD_AUTOMATIC_CREATION;
                }

                $baseAttributes = [
                    'external_key' => $externalKey,
                    'source_document_id' => $sourceDocumentId,
                    'original_filename' => $originalFilename,
                    'normalized_filename' => $normalizedFilename,
                    'sha256' => $sha256,
                    'mime_type' => $file->getMimeType(),
                    'file_size' => $file->getSize(),
                    'metadata' => $metadata,
                ];

                if ($document === null) {
                    return $this->storePendingItem($batch, [
                        ...$baseAttributes,
                        'reason' => $ambiguousReason ?? 'no_match_found',
                    ], $file, $ownedPendingPath);
                }

                if ($batch->source_id !== null && $document->source_id !== $batch->source_id) {
                    return $this->storePendingItem($batch, [
                        ...$baseAttributes,
                        'reason' => 'document_source_mismatch',
                    ], $file, $ownedPendingPath);
                }

                if ($method === IngestionBatchItem::METHOD_SHA256) {
                    return $this->storeItem($batch, [
                        ...$baseAttributes,
                        'knowledge_document_id' => $document->id,
                        'resolution_method' => $method,
                        'status' => IngestionBatchItem::STATUS_DUPLICATE,
                        'reason' => 'sha256_already_attached',
                    ]);
                }

                $document = KnowledgeDocument::query()
                    ->whereKey($document->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($document->sha256 === $sha256) {
                    return $this->storeItem($batch, [
                        ...$baseAttributes,
                        'knowledge_document_id' => $document->id,
                        'resolution_method' => $method,
                        'status' => IngestionBatchItem::STATUS_DUPLICATE,
                        'reason' => 'sha256_already_attached',
                    ]);
                }

                $conflictingDocument = KnowledgeDocument::query()
                    ->where('sha256', $sha256)
                    ->whereKeyNot($document->getKey())
                    ->exists();

                if ($conflictingDocument) {
                    return $this->storePendingItem($batch, [
                        ...$baseAttributes,
                        'knowledge_document_id' => $document->id,
                        'resolution_method' => $method,
                        'reason' => 'sha256_conflicts_with_other_document',
                    ], $file, $ownedPendingPath);
                }

                if ($document->storage_key !== null || $document->storage_path !== null) {
                    if ($document->sha256 === $sha256) {
                        return $this->storeItem($batch, [
                            ...$baseAttributes,
                            'knowledge_document_id' => $document->id,
                            'resolution_method' => $method,
                            'status' => IngestionBatchItem::STATUS_DUPLICATE,
                            'reason' => 'sha256_already_attached',
                        ]);
                    }

                    return $this->storePendingItem($batch, [
                        ...$baseAttributes,
                        'knowledge_document_id' => $document->id,
                        'resolution_method' => $method,
                        'reason' => 'document_already_has_different_file',
                    ], $file, $ownedPendingPath);
                }

                $this->fileService->assertDocumentCanReceivePdf($document, $sha256);
                $ownedDefinitivePath = $this->fileService->writeDefinitivePdf($document, $file);
                $document = $this->fileService->persistAttachment($document, $file, $sha256, $ownedDefinitivePath);

                return $this->storeAssociatedItem($batch, [
                    ...$baseAttributes,
                    'knowledge_document_id' => $document->id,
                    'resolution_method' => $method,
                    'status' => IngestionBatchItem::STATUS_ASSOCIATED,
                    'storage_path' => $document->storage_path,
                    'storage_disk' => $document->storage_disk,
                    'storage_key' => $document->storage_key,
                ]);
            });
        } catch (Throwable $exception) {
            $this->fileService->deleteOwnedFile($ownedDefinitivePath);
            $this->fileService->deleteOwnedFile($ownedPendingPath);
            $errorId = Str::uuid()->toString();

            Log::error('Knowledge batch item processing failed.', [
                'error_id' => $errorId,
                'batch_id' => $batch->id,
                'external_key' => $externalKey,
                'source_document_id' => $sourceDocumentId,
                'exception' => $exception,
            ]);
            Log::warning('knowledge_upload_failed', [
                'error_id' => $errorId,
                'batch_id' => $batch->id,
                'external_key' => $externalKey,
            ]);

            return $this->storeItem($batch, [
                'external_key' => $externalKey,
                'source_document_id' => $sourceDocumentId,
                'original_filename' => $originalFilename,
                'metadata' => [
                    ...$metadata,
                    'processing_error_id' => $errorId,
                ],
                'status' => IngestionBatchItem::STATUS_ERROR,
                'reason' => 'PROCESSING_ERROR',
            ]);
        }
    }

    /**
     * @return array{0: ?KnowledgeDocument, 1: ?string, 2: ?string}
     */
    public function resolveDocument(?string $externalKey, string $sha256, ?string $sourceDocumentId, string $normalizedFilename, ?int $sourceId): array
    {
        if ($externalKey !== null) {
            $document = KnowledgeDocument::query()->where('external_key', $externalKey)->first();

            if ($document !== null) {
                return [$document, IngestionBatchItem::METHOD_EXTERNAL_KEY, null];
            }
        }

        $document = KnowledgeDocument::query()->where('sha256', $sha256)->first();

        if ($document !== null) {
            return [$document, IngestionBatchItem::METHOD_SHA256, null];
        }

        if ($sourceDocumentId !== null && $sourceId !== null) {
            $candidates = KnowledgeDocument::query()
                ->where('source_id', $sourceId)
                ->where('source_document_id', $sourceDocumentId)
                ->get();

            if ($candidates->count() === 1) {
                return [$candidates->first(), IngestionBatchItem::METHOD_SOURCE_DOCUMENT_ID, null];
            }

            if ($candidates->count() > 1) {
                return [null, null, 'ambiguous_source_document_id'];
            }
        }

        if ($normalizedFilename !== '') {
            $candidates = KnowledgeDocument::query()
                ->where('normalized_filename', $normalizedFilename)
                ->when($sourceId !== null, fn ($query) => $query->where('source_id', $sourceId))
                ->get();

            if ($candidates->count() === 1) {
                return [$candidates->first(), IngestionBatchItem::METHOD_NORMALIZED_FILENAME, null];
            }

            if ($candidates->count() > 1) {
                return [null, null, 'ambiguous_normalized_filename'];
            }
        }

        return [null, null, null];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function storeItem(IngestionBatch $batch, array $attributes): IngestionBatchItem
    {
        return $batch->items()->create($attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function storeAssociatedItem(IngestionBatch $batch, array $attributes): IngestionBatchItem
    {
        return $this->storeItem($batch, $attributes);
    }

    /**
     * Persists a PENDING item and quarantines its PDF privately so a future
     * manual-review step can move it onto the resolved document. The file is
     * written before the item is finalized; if the write fails the item is
     * never marked as a valid PENDING result, and if the database update
     * fails after a successful write, the quarantined file is removed.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function storePendingItem(IngestionBatch $batch, array $attributes, UploadedFile $file, ?string &$ownedPath): IngestionBatchItem
    {
        $item = $this->storeItem($batch, [
            ...$attributes,
            'status' => IngestionBatchItem::STATUS_PENDING,
        ]);

        $path = $this->fileService->quarantinePendingPdf($item, $file);
        $ownedPath = $path;

        try {
            $this->persistPendingStoragePath($item, $path);
        } catch (Throwable $exception) {
            $this->fileService->deleteOwnedFile($path);

            throw $exception;
        }

        return $item->refresh();
    }

    /**
     * Extracted for testability: lets tests simulate a database failure
     * after the quarantine file has already been written to disk.
     */
    protected function persistPendingStoragePath(IngestionBatchItem $item, string $storagePath): void
    {
        $item->forceFill([
            'storage_path' => $storagePath,
            'storage_disk' => $this->fileService->diskName(),
            'storage_key' => $storagePath,
        ])->save();
    }

    private function dispatchAssociatedDocuments(IngestionBatch $batch): void
    {
        $documentIds = $batch->items()
            ->where('status', IngestionBatchItem::STATUS_ASSOCIATED)
            ->whereNotNull('knowledge_document_id')
            ->distinct()
            ->pluck('knowledge_document_id');

        KnowledgeDocument::query()
            ->whereKey($documentIds)
            ->where('content_status', '!=', KnowledgeDocument::STATUS_READY)
            ->each(function (KnowledgeDocument $document) use ($batch): void {
                ProcessKnowledgeDocument::dispatch($document->id)->afterCommit();
                Log::info('knowledge_document_processing_dispatched', [
                    'batch_id' => $batch->id,
                    'document_id' => $document->id,
                ]);
            });
    }
}
