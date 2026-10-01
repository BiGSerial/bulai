<?php

namespace App\Services\Knowledge;

use App\Jobs\ProcessKnowledgeDocument;
use App\Models\Knowledge\IngestionBatch;
use App\Models\Knowledge\IngestionBatchItem;
use App\Models\Knowledge\KnowledgeDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class KnowledgePendingItemAssociationService
{
    private readonly KnowledgeDocumentLifecycleService $documentLifecycle;

    public function __construct(private readonly KnowledgeFileService $fileService, ?KnowledgeDocumentLifecycleService $documentLifecycle = null)
    {
        $this->documentLifecycle = $documentLifecycle ?? app(KnowledgeDocumentLifecycleService::class);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{status: string, document_id: ?int, file_attached: bool, error_code: ?string, error_id: ?string, http_status: int}
     */
    public function createAndAssociate(int $batchId, int $itemId, array $attributes): array
    {
        $batch = IngestionBatch::query()->findOrFail($batchId);
        if ($batch->source_id === null) {
            return $this->conflictResult('SOURCE_REQUIRED');
        }

        $document = $this->documentLifecycle->createManual($batch->source_id, [
            ...$attributes,
            'ingestion_batch_id' => $batchId,
            'metadata' => [...($attributes['metadata'] ?? []), 'created_from_pending_item_id' => $itemId],
        ]);
        $result = $this->associate($batchId, $itemId, $document->id);

        if ($result['status'] !== IngestionBatchItem::STATUS_ASSOCIATED) {
            $document->refresh();
            if ($document->storage_key === null && ! IngestionBatchItem::query()->where('knowledge_document_id', $document->id)->exists()) {
                $document->delete();
            }
        }

        return $result;
    }

    /**
     * @return array{status: string, document_id: ?int, file_attached: bool, error_code: ?string, error_id: ?string, http_status: int}
     */
    public function associate(int $batchId, int $itemId, int $documentId): array
    {
        $ownedDefinitivePath = null;
        $pendingObjectToDelete = null;
        $documentToProcess = null;

        try {
            $result = DB::transaction(function () use ($batchId, $itemId, $documentId, &$ownedDefinitivePath, &$pendingObjectToDelete, &$documentToProcess): array {
                $item = IngestionBatchItem::query()
                    ->where('ingestion_batch_id', $batchId)
                    ->whereKey($itemId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($item->status !== IngestionBatchItem::STATUS_PENDING) {
                    return $this->alreadyResolvedResult($item);
                }

                $pendingStorage = $this->fileService->itemStorage($item);
                if ($pendingStorage === null || ! $this->fileService->objectExists($pendingStorage['disk'], $pendingStorage['key'])) {
                    Log::warning('Pending knowledge file is missing during manual association.', [
                        'batch_id' => $batchId,
                        'item_id' => $itemId,
                    ]);

                    return $this->conflictResult('PENDING_FILE_MISSING');
                }

                if ($item->sha256 === null || $this->fileService->hashObject($pendingStorage['disk'], $pendingStorage['key']) !== $item->sha256) {
                    Log::warning('Pending knowledge file checksum is invalid during manual association.', [
                        'batch_id' => $batchId,
                        'item_id' => $itemId,
                    ]);

                    return $this->conflictResult('PENDING_FILE_INVALID');
                }

                $document = KnowledgeDocument::query()
                    ->whereKey($documentId)
                    ->lockForUpdate()
                    ->firstOrFail();

                $batchSourceId = IngestionBatch::query()->whereKey($batchId)->value('source_id');
                if ($batchSourceId !== null && $document->source_id !== $batchSourceId) {
                    return $this->conflictResult('DOCUMENT_SOURCE_MISMATCH', $document->id);
                }

                $duplicate = KnowledgeDocument::query()
                    ->where('sha256', $item->sha256)
                    ->first();

                if ($duplicate !== null) {
                    $pendingObjectToDelete = $pendingStorage;
                    $this->markDuplicate($item, $duplicate);
                    $this->recalculateBatchCounters($batchId);

                    return $this->result(
                        status: IngestionBatchItem::STATUS_DUPLICATE,
                        documentId: $duplicate->id,
                        fileAttached: false,
                    );
                }

                if ($document->storage_key !== null || $document->storage_path !== null) {
                    return $this->conflictResult('DOCUMENT_ALREADY_HAS_FILE', $document->id);
                }

                $this->fileService->assertDocumentCanReceivePdf($document, $item->sha256);
                $ownedDefinitivePath = $this->fileService->promotePendingObject($document, $pendingStorage['disk'], $pendingStorage['key']);
                $document = $this->fileService->persistPendingAttachment($document, $item, $ownedDefinitivePath);
                $this->persistAssociation($item, $document);
                $this->recalculateBatchCounters($batchId);
                $pendingObjectToDelete = $pendingStorage;
                $documentToProcess = $document->content_status !== KnowledgeDocument::STATUS_READY ? $document->id : null;

                return $this->result(
                    status: IngestionBatchItem::STATUS_ASSOCIATED,
                    documentId: $document->id,
                    fileAttached: true,
                );
            });
        } catch (Throwable $exception) {
            $this->fileService->deleteOwnedFile($ownedDefinitivePath);
            $errorId = Str::uuid()->toString();

            Log::error('Manual knowledge item association failed.', [
                'error_id' => $errorId,
                'batch_id' => $batchId,
                'item_id' => $itemId,
                'document_id' => $documentId,
                'exception' => $exception,
            ]);

            return $this->result(
                status: 'ERROR',
                documentId: null,
                fileAttached: false,
                errorCode: 'PROCESSING_ERROR',
                errorId: $errorId,
                httpStatus: 500,
            );
        }

        if ($pendingObjectToDelete !== null) {
            $this->deleteResolvedPendingFile($pendingObjectToDelete['disk'], $pendingObjectToDelete['key'], $batchId, $itemId);
        }

        if ($documentToProcess !== null) {
            ProcessKnowledgeDocument::dispatch($documentToProcess)->afterCommit();
            Log::info('knowledge_document_processing_dispatched', [
                'batch_id' => $batchId,
                'item_id' => $itemId,
                'document_id' => $documentToProcess,
            ]);
        }

        return $result;
    }

    protected function persistAssociation(IngestionBatchItem $item, KnowledgeDocument $document): void
    {
        $item->forceFill([
            'knowledge_document_id' => $document->id,
            'storage_path' => $document->storage_path,
            'storage_disk' => $document->storage_disk,
            'storage_key' => $document->storage_key,
            'status' => IngestionBatchItem::STATUS_ASSOCIATED,
            'resolution_method' => IngestionBatchItem::METHOD_MANUAL,
            'reason' => null,
            'metadata' => [
                ...($item->metadata ?? []),
                'manual_resolution' => [
                    'document_id' => $document->id,
                    'resolved_at' => now()->toISOString(),
                ],
            ],
        ])->save();
    }

    private function markDuplicate(IngestionBatchItem $item, KnowledgeDocument $document): void
    {
        $item->forceFill([
            'knowledge_document_id' => $document->id,
            'storage_path' => null,
            'storage_disk' => null,
            'storage_key' => null,
            'status' => IngestionBatchItem::STATUS_DUPLICATE,
            'resolution_method' => IngestionBatchItem::METHOD_SHA256,
            'reason' => 'sha256_already_attached',
            'metadata' => [
                ...($item->metadata ?? []),
                'manual_resolution' => [
                    'document_id' => $document->id,
                    'resolved_at' => now()->toISOString(),
                ],
            ],
        ])->save();
    }

    private function recalculateBatchCounters(int $batchId): void
    {
        $batch = IngestionBatch::query()->whereKey($batchId)->lockForUpdate()->firstOrFail();

        $batch->forceFill([
            'total_items' => $batch->items()->count(),
            'associated_items' => $batch->items()->where('status', IngestionBatchItem::STATUS_ASSOCIATED)->count(),
            'duplicate_items' => $batch->items()->where('status', IngestionBatchItem::STATUS_DUPLICATE)->count(),
            'pending_items' => $batch->items()->where('status', IngestionBatchItem::STATUS_PENDING)->count(),
            'error_items' => $batch->items()->where('status', IngestionBatchItem::STATUS_ERROR)->count(),
        ])->save();
    }

    private function deleteResolvedPendingFile(string $diskName, string $key, int $batchId, int $itemId): void
    {
        try {
            $disk = $this->fileService->disk($diskName);

            if ($disk->delete($key) || ! $disk->exists($key)) {
                return;
            }

            Log::warning('Resolved pending knowledge file could not be deleted.', [
                'batch_id' => $batchId,
                'item_id' => $itemId,
            ]);
        } catch (Throwable $exception) {
            Log::warning('Resolved pending knowledge file cleanup failed.', [
                'batch_id' => $batchId,
                'item_id' => $itemId,
                'exception' => $exception,
            ]);
        }
    }

    /**
     * @return array{status: string, document_id: ?int, file_attached: bool, error_code: ?string, error_id: ?string, http_status: int}
     */
    private function alreadyResolvedResult(IngestionBatchItem $item): array
    {
        return $this->result(
            status: 'ALREADY_RESOLVED',
            documentId: $item->knowledge_document_id,
            fileAttached: $item->status === IngestionBatchItem::STATUS_ASSOCIATED,
            errorCode: $item->status,
            httpStatus: 409,
        );
    }

    /**
     * @return array{status: string, document_id: ?int, file_attached: bool, error_code: ?string, error_id: ?string, http_status: int}
     */
    private function conflictResult(string $errorCode, ?int $documentId = null): array
    {
        return $this->result(
            status: 'CONFLICT',
            documentId: $documentId,
            fileAttached: false,
            errorCode: $errorCode,
            httpStatus: 409,
        );
    }

    /**
     * @return array{status: string, document_id: ?int, file_attached: bool, error_code: ?string, error_id: ?string, http_status: int}
     */
    private function result(
        string $status,
        ?int $documentId,
        bool $fileAttached,
        ?string $errorCode = null,
        ?string $errorId = null,
        int $httpStatus = 200,
    ): array {
        return [
            'status' => $status,
            'document_id' => $documentId,
            'file_attached' => $fileAttached,
            'error_code' => $errorCode,
            'error_id' => $errorId,
            'http_status' => $httpStatus,
        ];
    }
}
