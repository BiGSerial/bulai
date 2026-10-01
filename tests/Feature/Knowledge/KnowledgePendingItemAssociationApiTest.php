<?php

namespace Tests\Feature\Knowledge;

use App\Jobs\ProcessKnowledgeDocument;
use App\Models\Knowledge\IngestionBatch;
use App\Models\Knowledge\IngestionBatchItem;
use App\Models\Knowledge\IngestionToken;
use App\Models\Knowledge\KnowledgeDocument;
use App\Models\Knowledge\KnowledgeSource;
use App\Services\Knowledge\KnowledgeFileService;
use App\Services\Knowledge\KnowledgePendingItemAssociationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

class KnowledgePendingItemAssociationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_item_is_associated_from_quarantine_and_batch_counters_are_recalculated(): void
    {
        Storage::fake('local');
        Queue::fake();
        [, $plainToken] = $this->token();
        [$batch, $item] = $this->pendingItem($plainToken, 'manual-content');
        $document = $this->document('manual-target');
        $document->forceFill(['content_status' => KnowledgeDocument::STATUS_PENDING])->save();
        $pendingPath = $item->storage_path;

        $response = $this->associate($plainToken, $batch, $item, $document);

        $response->assertOk()->assertExactJson([
            'batch_id' => $batch->id,
            'item_id' => $item->id,
            'document_id' => $document->id,
            'status' => 'ASSOCIATED',
            'file_attached' => true,
            'error_code' => null,
            'error_id' => null,
        ]);

        $document->refresh();
        $item->refresh();
        $batch->refresh();

        $this->assertSame('READY', $document->file_status);
        $this->assertSame($item->sha256, $document->sha256);
        $this->assertSame('pending.pdf', $document->original_filename);
        $this->assertSame('application/pdf', $document->mime_type);
        $this->assertSame('ASSOCIATED', $item->status);
        $this->assertSame('manual', $item->resolution_method);
        $this->assertSame($document->id, $item->metadata['manual_resolution']['document_id']);
        $this->assertNotNull($item->metadata['manual_resolution']['resolved_at']);
        $this->assertSame(1, $batch->associated_items);
        $this->assertSame(0, $batch->pending_items);
        Storage::disk('local')->assertMissing($pendingPath);
        Storage::disk('local')->assertExists($document->storage_path);
        Storage::disk('local')->assertDirectoryEmpty('knowledge/staging');
        $this->assertCount(1, Storage::disk('local')->allFiles("knowledge/originals/{$document->id}"));
        Queue::assertPushed(ProcessKnowledgeDocument::class, fn (ProcessKnowledgeDocument $job): bool => $job->documentId === $document->id);
    }

    public function test_batch_item_and_document_identifiers_are_validated_and_scoped(): void
    {
        Storage::fake('local');
        [, $plainToken] = $this->token();
        [$batch, $item] = $this->pendingItem($plainToken, 'scope-a');
        [$otherBatch, $otherItem] = $this->pendingItem($plainToken, 'scope-b');
        $document = $this->document('scope-target');

        $this->withToken($plainToken)
            ->postJson("/api/internal/knowledge/batches/999999/items/{$item->id}/associate", ['document_id' => $document->id])
            ->assertNotFound();
        $this->withToken($plainToken)
            ->postJson("/api/internal/knowledge/batches/{$batch->id}/items/999999/associate", ['document_id' => $document->id])
            ->assertNotFound();
        $this->withToken($plainToken)
            ->postJson("/api/internal/knowledge/batches/{$batch->id}/items/{$otherItem->id}/associate", ['document_id' => $document->id])
            ->assertNotFound();
        $this->withToken($plainToken)
            ->postJson("/api/internal/knowledge/batches/{$otherBatch->id}/items/{$otherItem->id}/associate", ['document_id' => 999999])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('document_id');
    }

    public function test_missing_pending_file_returns_controlled_conflict_and_keeps_item_pending(): void
    {
        Storage::fake('local');
        [, $plainToken] = $this->token();
        [$batch, $item] = $this->pendingItem($plainToken, 'missing-content');
        $document = $this->document('missing-target');
        Storage::disk('local')->delete($item->storage_path);

        $response = $this->associate($plainToken, $batch, $item, $document);

        $response
            ->assertConflict()
            ->assertJsonPath('status', 'CONFLICT')
            ->assertJsonPath('error_code', 'PENDING_FILE_MISSING')
            ->assertJsonPath('file_attached', false);
        $this->assertSame('PENDING', $item->refresh()->status);
        $this->assertNull($document->refresh()->storage_path);
    }

    public function test_changed_pending_file_checksum_is_rejected_without_modifying_state(): void
    {
        Storage::fake('local');
        [, $plainToken] = $this->token();
        [$batch, $item] = $this->pendingItem($plainToken, 'original-checksum');
        $document = $this->document('checksum-target');
        Storage::disk('local')->put($item->storage_path, $this->pdfContent('tampered-checksum'));

        $response = $this->associate($plainToken, $batch, $item, $document);

        $response
            ->assertConflict()
            ->assertJsonPath('error_code', 'PENDING_FILE_INVALID')
            ->assertJsonPath('file_attached', false);
        $this->assertSame('PENDING', $item->refresh()->status);
        $this->assertNull($document->refresh()->storage_path);
        Storage::disk('local')->assertExists($item->storage_path);
    }

    public function test_non_pending_item_states_are_not_resolved_again(): void
    {
        Storage::fake('local');
        [, $plainToken] = $this->token();
        $document = $this->document('state-target');

        foreach (['ASSOCIATED', 'DUPLICATE', 'ERROR'] as $status) {
            [$batch, $item] = $this->pendingItem($plainToken, 'state-'.$status);
            $item->forceFill([
                'status' => $status,
                'knowledge_document_id' => $status === 'ASSOCIATED' ? $document->id : null,
            ])->save();

            $response = $this->associate($plainToken, $batch, $item, $document);

            $response
                ->assertConflict()
                ->assertJsonPath('status', 'ALREADY_RESOLVED')
                ->assertJsonPath('error_code', $status);
            $this->assertSame($status, $item->refresh()->status);
        }
    }

    public function test_document_attached_after_item_discovery_is_not_overwritten_or_removed(): void
    {
        Storage::fake('local');
        [, $plainToken] = $this->token();
        [$batch, $item] = $this->pendingItem($plainToken, 'pending-choice');
        $document = $this->document('occupied-target');
        app(KnowledgeFileService::class)->attachPdf($document, $this->pdf('existing.pdf', 'existing-content'));
        $document->refresh();
        $existingPath = $document->storage_path;

        $response = $this->associate($plainToken, $batch, $item, $document);

        $response
            ->assertConflict()
            ->assertJsonPath('error_code', 'DOCUMENT_ALREADY_HAS_FILE')
            ->assertJsonPath('file_attached', false);
        $this->assertSame('PENDING', $item->refresh()->status);
        $this->assertSame($existingPath, $document->refresh()->storage_path);
        $this->assertSame($this->pdfContent('existing-content'), Storage::disk('local')->get($existingPath));
        Storage::disk('local')->assertExists($item->storage_path);
        $this->assertCount(1, Storage::disk('local')->allFiles("knowledge/originals/{$document->id}"));
    }

    public function test_known_sha_marks_item_duplicate_without_retaining_uploaded_copy(): void
    {
        Storage::fake('local');
        [, $plainToken] = $this->token();
        [$batch, $item] = $this->pendingItem($plainToken, 'duplicate-content');
        $existingDocument = $this->document('duplicate-existing');
        $selectedDocument = $this->document('duplicate-selected');
        app(KnowledgeFileService::class)->attachPdf($existingDocument, $this->pdf('existing.pdf', 'duplicate-content'));
        $existingDocument->refresh();
        $existingPath = $existingDocument->storage_path;
        $pendingPath = $item->storage_path;

        $response = $this->associate($plainToken, $batch, $item, $selectedDocument);

        $response
            ->assertOk()
            ->assertJsonPath('status', 'DUPLICATE')
            ->assertJsonPath('document_id', $existingDocument->id)
            ->assertJsonPath('file_attached', false);
        $this->assertSame('DUPLICATE', $item->refresh()->status);
        $this->assertSame(1, $batch->refresh()->duplicate_items);
        $this->assertSame(0, $batch->pending_items);
        $this->assertNull($selectedDocument->refresh()->storage_path);
        Storage::disk('local')->assertMissing($pendingPath);
        Storage::disk('local')->assertExists($existingPath);
        $this->assertCount(1, Storage::disk('local')->allFiles("knowledge/originals/{$existingDocument->id}"));
    }

    public function test_failure_during_promotion_preserves_pending_file_and_database_state(): void
    {
        Storage::fake('local');
        [, $plainToken] = $this->token();
        [$batch, $item] = $this->pendingItem($plainToken, 'promotion-failure');
        $document = $this->document('promotion-target');
        $pendingPath = $item->storage_path;
        $fileService = new class extends KnowledgeFileService
        {
            public function promotePendingObject(KnowledgeDocument $document, string $pendingDiskName, string $pendingPath): string
            {
                throw new RuntimeException('secret promotion path /srv/private');
            }
        };
        app()->instance(KnowledgePendingItemAssociationService::class, new KnowledgePendingItemAssociationService($fileService));

        $response = $this->associate($plainToken, $batch, $item, $document);

        $response
            ->assertInternalServerError()
            ->assertJsonPath('status', 'ERROR')
            ->assertJsonPath('error_code', 'PROCESSING_ERROR')
            ->assertJsonMissing(['secret promotion path /srv/private']);
        $this->assertNotNull($response->json('error_id'));
        $this->assertArrayNotHasKey('storage_path', $response->json());
        $this->assertSame('PENDING', $item->refresh()->status);
        $this->assertNull($document->refresh()->storage_path);
        Storage::disk('local')->assertExists($pendingPath);
        Storage::disk('local')->assertDirectoryEmpty("knowledge/originals/{$document->id}");
    }

    public function test_failure_after_promotion_rolls_back_and_cleans_only_the_owned_file(): void
    {
        Storage::fake('local');
        [, $plainToken] = $this->token();
        [$batch, $item] = $this->pendingItem($plainToken, 'rollback-content');
        $document = $this->document('rollback-target');
        $pendingPath = $item->storage_path;
        $unrelatedPath = "knowledge/originals/{$document->id}/preexisting-audit.pdf";
        Storage::disk('local')->put($unrelatedPath, 'must-remain');
        $service = new class(app(KnowledgeFileService::class)) extends KnowledgePendingItemAssociationService
        {
            protected function persistAssociation(IngestionBatchItem $item, KnowledgeDocument $document): void
            {
                throw new RuntimeException('SQLSTATE secret-db internal/path');
            }
        };
        app()->instance(KnowledgePendingItemAssociationService::class, $service);

        $response = $this->associate($plainToken, $batch, $item, $document);

        $response
            ->assertInternalServerError()
            ->assertJsonPath('error_code', 'PROCESSING_ERROR')
            ->assertJsonMissing(['SQLSTATE secret-db internal/path']);
        $this->assertSame('PENDING', $item->refresh()->status);
        $this->assertNull($document->refresh()->storage_path);
        Storage::disk('local')->assertExists($pendingPath);
        Storage::disk('local')->assertExists($unrelatedPath);
        $this->assertSame([$unrelatedPath], Storage::disk('local')->allFiles("knowledge/originals/{$document->id}"));
        Storage::disk('local')->assertDirectoryEmpty('knowledge/staging');
    }

    public function test_retry_after_success_is_idempotent_and_does_not_change_files_or_counters(): void
    {
        Storage::fake('local');
        [, $plainToken] = $this->token();
        [$batch, $item] = $this->pendingItem($plainToken, 'retry-manual');
        $document = $this->document('retry-target');

        $firstResponse = $this->associate($plainToken, $batch, $item, $document);
        $document->refresh();
        $definitivePath = $document->storage_path;
        $retryResponse = $this->associate($plainToken, $batch, $item, $document);

        $firstResponse->assertOk()->assertJsonPath('status', 'ASSOCIATED');
        $retryResponse
            ->assertConflict()
            ->assertJsonPath('status', 'ALREADY_RESOLVED')
            ->assertJsonPath('document_id', $document->id)
            ->assertJsonPath('file_attached', true);
        $this->assertSame($definitivePath, $document->refresh()->storage_path);
        $this->assertSame(1, $batch->refresh()->associated_items);
        $this->assertSame(0, $batch->pending_items);
        $this->assertCount(1, Storage::disk('local')->allFiles("knowledge/originals/{$document->id}"));
    }

    public function test_endpoint_requires_valid_knowledge_ingestion_scope(): void
    {
        Storage::fake('local');
        [, $plainToken] = $this->token();
        [$batch, $item] = $this->pendingItem($plainToken, 'auth-content');
        $document = $this->document('auth-target');
        [, $wrongScopeToken] = IngestionToken::issue('wrong scope', 'other:scope', now()->addDay());
        $url = $this->associationUrl($batch, $item);

        $this->withHeaders(['Authorization' => ''])
            ->postJson($url, ['document_id' => $document->id])
            ->assertUnauthorized();
        $this->withToken($wrongScopeToken)->postJson($url, ['document_id' => $document->id])->assertUnauthorized();
    }

    /**
     * @return array{IngestionBatch, IngestionBatchItem}
     */
    private function pendingItem(string $plainToken, string $content): array
    {
        $response = $this
            ->withToken($plainToken)
            ->postJson('/api/internal/knowledge/batches', [
                'batch' => ['name' => 'Lote pendente '.$content],
                'items' => [
                    ['file' => $this->pdf('pending.pdf', $content)],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('items.0.status', 'PENDING');

        return [
            IngestionBatch::query()->findOrFail($response->json('batch_id')),
            IngestionBatchItem::query()->findOrFail($response->json('items.0.id')),
        ];
    }

    private function associate(string $plainToken, IngestionBatch $batch, IngestionBatchItem $item, KnowledgeDocument $document): TestResponse
    {
        return $this
            ->withToken($plainToken)
            ->postJson($this->associationUrl($batch, $item), ['document_id' => $document->id]);
    }

    private function associationUrl(IngestionBatch $batch, IngestionBatchItem $item): string
    {
        return "/api/internal/knowledge/batches/{$batch->id}/items/{$item->id}/associate";
    }

    private function token(): array
    {
        return IngestionToken::issue('manual review token', IngestionToken::SCOPE_KNOWLEDGE_INGEST, now()->addDay());
    }

    private function document(string $externalKey): KnowledgeDocument
    {
        $source = KnowledgeSource::query()->firstOrCreate([
            'name' => 'ANVISA manual review',
            'organization' => 'Agência Nacional de Vigilância Sanitária',
        ]);

        return KnowledgeDocument::query()->create([
            'source_id' => $source->id,
            'external_key' => $externalKey,
            'title' => 'Documento '.$externalKey,
            'document_type' => 'bula',
            'language' => 'pt-BR',
            'metadata' => [],
            'content_status' => 'READY',
            'file_status' => 'PENDING',
            'embedding_status' => 'PENDING',
        ]);
    }

    private function pdf(string $filename, string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($filename, $this->pdfContent($content));
    }

    private function pdfContent(string $content): string
    {
        return "%PDF-1.4\n{$content}\n%%EOF";
    }
}
