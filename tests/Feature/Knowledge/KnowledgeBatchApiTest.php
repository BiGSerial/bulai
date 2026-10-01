<?php

namespace Tests\Feature\Knowledge;

use App\Models\Knowledge\IngestionBatch;
use App\Models\Knowledge\IngestionBatchItem;
use App\Models\Knowledge\IngestionToken;
use App\Models\Knowledge\KnowledgeDocument;
use App\Models\Knowledge\KnowledgeSource;
use App\Services\Knowledge\KnowledgeBatchIngestionService;
use App\Services\Knowledge\KnowledgeFileService;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class KnowledgeBatchApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_batch_with_multiple_pdfs_associates_each_by_external_key(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        [, $plainToken] = $this->token();

        $docA = $this->document('doc-a');
        $docB = $this->document('doc-b');

        $response = $this
            ->withToken($plainToken)
            ->postJson('/api/internal/knowledge/batches', [
                'batch' => ['name' => 'Lote diário ANVISA'],
                'items' => [
                    ['external_key' => 'doc-a', 'file' => $this->pdf('doc-a.pdf', 'content-a')],
                    ['external_key' => 'doc-b', 'file' => $this->pdf('doc-b.pdf', 'content-b')],
                ],
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('total_items', 2)
            ->assertJsonPath('associated_items', 2)
            ->assertJsonPath('pending_items', 0)
            ->assertJsonPath('error_items', 0)
            ->assertJsonPath('items.0.status', 'ASSOCIATED')
            ->assertJsonPath('items.0.resolution_method', 'external_key')
            ->assertJsonPath('items.1.status', 'ASSOCIATED');

        $docA->refresh();
        $docB->refresh();

        $this->assertSame('READY', $docA->file_status);
        $this->assertSame(hash('sha256', $this->pdfContent('content-a')), $docA->sha256);
        $this->assertSame('READY', $docB->file_status);
        Storage::disk('local')->assertExists($docA->storage_path);
        Storage::disk('local')->assertDirectoryEmpty('knowledge/staging');
        Storage::disk('public')->assertDirectoryEmpty('/');
    }

    public function test_known_sha256_is_detected_as_duplicate_without_reattaching(): void
    {
        Storage::fake('local');
        [, $plainToken] = $this->token();
        $document = $this->document('doc-a');
        app(KnowledgeFileService::class)->attachPdf($document, $this->pdf('original.pdf', 'same-bytes'));
        $document->refresh();

        $response = $this
            ->withToken($plainToken)
            ->postJson('/api/internal/knowledge/batches', [
                'batch' => ['name' => 'Lote reenvio'],
                'items' => [
                    ['file' => $this->pdf('resend.pdf', 'same-bytes')],
                ],
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('duplicate_items', 1)
            ->assertJsonPath('items.0.status', 'DUPLICATE')
            ->assertJsonPath('items.0.knowledge_document_id', $document->id)
            ->assertJsonPath('items.0.file_attached', false);

        $this->assertArrayNotHasKey('storage_path', $response->json('items.0'));

        $this->assertSame($this->pdfContent('same-bytes'), Storage::disk('local')->get($document->storage_path));
        Storage::disk('local')->assertDirectoryEmpty('knowledge/pending');
    }

    public function test_item_without_any_match_is_left_pending_and_quarantined(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        [, $plainToken] = $this->token();

        $response = $this
            ->withToken($plainToken)
            ->postJson('/api/internal/knowledge/batches', [
                'batch' => ['name' => 'Lote sem match'],
                'items' => [
                    ['file' => $this->pdf('unknown-drug.pdf', 'unmatched-content')],
                ],
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('pending_items', 1)
            ->assertJsonPath('items.0.status', 'PENDING')
            ->assertJsonPath('items.0.reason', 'no_match_found')
            ->assertJsonPath('items.0.knowledge_document_id', null);

        $batchId = $response->json('batch_id');
        $itemId = $response->json('items.0.id');
        $item = IngestionBatchItem::query()->findOrFail($itemId);
        $expectedPath = $item->storage_path;

        $this->assertMatchesRegularExpression("#^knowledge/pending/{$batchId}/[0-9a-f-]+\\.pdf$#", $expectedPath);
        $this->assertArrayNotHasKey('storage_path', $response->json('items.0'));
        Storage::disk('local')->assertExists($expectedPath);
        $this->assertSame($this->pdfContent('unmatched-content'), Storage::disk('local')->get($expectedPath));
        Storage::disk('public')->assertDirectoryEmpty('/');
    }

    public function test_source_document_id_is_scoped_to_the_provided_source(): void
    {
        $anvisa = KnowledgeSource::query()->create(['name' => 'ANVISA', 'organization' => 'Governo']);
        $other = KnowledgeSource::query()->create(['name' => 'Outra Fonte', 'organization' => 'Privada']);

        $anvisaDoc = $this->documentForSource($anvisa->id, 'anvisa-doc', 'shared-123');
        $this->documentForSource($other->id, 'other-doc', 'shared-123');

        [, $plainToken] = $this->token();

        $response = $this
            ->withToken($plainToken)
            ->postJson('/api/internal/knowledge/batches', [
                'batch' => ['name' => 'Lote escopado', 'source_id' => $anvisa->id],
                'items' => [
                    ['source_document_id' => 'shared-123', 'file' => $this->pdf('bula.pdf', 'conteudo-anvisa')],
                ],
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('items.0.status', 'ASSOCIATED')
            ->assertJsonPath('items.0.resolution_method', 'source_document_id')
            ->assertJsonPath('items.0.knowledge_document_id', $anvisaDoc->id);
    }

    public function test_source_document_id_is_not_resolved_without_a_batch_source(): void
    {
        $anvisa = KnowledgeSource::query()->create(['name' => 'ANVISA', 'organization' => 'Governo']);
        $this->documentForSource($anvisa->id, 'anvisa-doc', 'shared-999');
        [, $plainToken] = $this->token();

        $response = $this
            ->withToken($plainToken)
            ->postJson('/api/internal/knowledge/batches', [
                'batch' => ['name' => 'Lote sem fonte'],
                'items' => [
                    ['source_document_id' => 'shared-999', 'file' => $this->pdf('nao-relacionado.pdf', 'conteudo-livre')],
                ],
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('items.0.status', 'PENDING')
            ->assertJsonPath('items.0.reason', 'no_match_found')
            ->assertJsonPath('items.0.knowledge_document_id', null);
    }

    public function test_ambiguous_source_document_id_is_not_associated_automatically(): void
    {
        Storage::fake('local');
        $source = KnowledgeSource::query()->firstOrCreate([
            'name' => 'ANVISA',
            'organization' => 'Agência Nacional de Vigilância Sanitária',
        ]);
        $this->document('doc-a', sourceDocumentId: 'src-123');
        $this->document('doc-b', sourceDocumentId: 'src-123');
        [, $plainToken] = $this->token();

        $response = $this
            ->withToken($plainToken)
            ->postJson('/api/internal/knowledge/batches', [
                'batch' => ['name' => 'Lote ambíguo', 'source_id' => $source->id],
                'items' => [
                    ['source_document_id' => 'src-123', 'file' => $this->pdf('ambiguous.pdf', 'ambiguous-content')],
                ],
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('items.0.status', 'PENDING')
            ->assertJsonPath('items.0.reason', 'ambiguous_source_document_id')
            ->assertJsonPath('items.0.knowledge_document_id', null);

        $item = IngestionBatchItem::query()->findOrFail($response->json('items.0.id'));
        Storage::disk('local')->assertExists($item->storage_path);
    }

    public function test_already_associated_document_is_not_silently_overwritten(): void
    {
        Storage::fake('local');
        [, $plainToken] = $this->token();
        $document = $this->document('doc-a');
        app(KnowledgeFileService::class)->attachPdf($document, $this->pdf('original.pdf', 'first-bytes'));
        $document->refresh();
        $originalSha = $document->sha256;

        $response = $this
            ->withToken($plainToken)
            ->postJson('/api/internal/knowledge/batches', [
                'batch' => ['name' => 'Lote reenvio conflitante'],
                'items' => [
                    ['external_key' => 'doc-a', 'file' => $this->pdf('replacement.pdf', 'different-bytes')],
                ],
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('items.0.status', 'PENDING')
            ->assertJsonPath('items.0.reason', 'document_already_has_different_file');

        $document->refresh();
        $this->assertSame($originalSha, $document->sha256);
        $this->assertSame($this->pdfContent('first-bytes'), Storage::disk('local')->get($document->storage_path));

        $quarantinePath = IngestionBatchItem::query()
            ->findOrFail($response->json('items.0.id'))
            ->storage_path;
        $this->assertNotNull($quarantinePath);
        $this->assertStringStartsWith("knowledge/pending/{$response->json('batch_id')}/", $quarantinePath);
        Storage::disk('local')->assertExists($quarantinePath);
        $this->assertSame($this->pdfContent('different-bytes'), Storage::disk('local')->get($quarantinePath));
    }

    public function test_invalid_file_in_batch_does_not_block_the_other_items(): void
    {
        Storage::fake('local');
        [, $plainToken] = $this->token();
        $document = $this->document('doc-a');

        $response = $this
            ->withToken($plainToken)
            ->postJson('/api/internal/knowledge/batches', [
                'batch' => ['name' => 'Lote com item inválido'],
                'items' => [
                    ['external_key' => 'doc-a', 'file' => $this->pdf('doc-a.pdf', 'valid-content')],
                    ['file' => UploadedFile::fake()->create('notes.txt', 1, 'text/plain')],
                ],
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('total_items', 2)
            ->assertJsonPath('associated_items', 1)
            ->assertJsonPath('error_items', 1)
            ->assertJsonPath('items.0.status', 'ASSOCIATED')
            ->assertJsonPath('items.1.status', 'ERROR')
            ->assertJsonPath('items.1.reason', 'invalid_mime_type')
            ->assertJsonPath('items.1.file_attached', false);

        $this->assertArrayNotHasKey('storage_path', $response->json('items.1'));

        $document->refresh();
        $this->assertSame('READY', $document->file_status);
        Storage::disk('local')->assertDirectoryEmpty('knowledge/pending');
    }

    public function test_duplicate_and_error_items_do_not_leave_orphaned_files(): void
    {
        Storage::fake('local');
        [, $plainToken] = $this->token();
        $document = $this->document('doc-a');
        app(KnowledgeFileService::class)->attachPdf($document, $this->pdf('original.pdf', 'known-bytes'));

        $this
            ->withToken($plainToken)
            ->postJson('/api/internal/knowledge/batches', [
                'batch' => ['name' => 'Lote duplicado e inválido'],
                'items' => [
                    ['file' => $this->pdf('resend.pdf', 'known-bytes')],
                    ['file' => UploadedFile::fake()->create('notes.txt', 1, 'text/plain')],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('duplicate_items', 1)
            ->assertJsonPath('error_items', 1);

        Storage::disk('local')->assertDirectoryEmpty('knowledge/pending');
        $this->assertCount(
            1,
            Storage::disk('local')->allFiles("knowledge/originals/{$document->id}"),
        );
    }

    public function test_pending_file_write_failure_becomes_error_without_a_pending_row(): void
    {
        [, $plainToken] = $this->token();

        $failingDisk = \Mockery::mock(Filesystem::class);
        $failingDisk->shouldReceive('putFileAs')->andReturn(false);
        $failingDisk->shouldReceive('delete')->zeroOrMoreTimes()->andReturn(true);
        Storage::shouldReceive('disk')->with('local')->andReturn($failingDisk);

        $response = $this
            ->withToken($plainToken)
            ->postJson('/api/internal/knowledge/batches', [
                'batch' => ['name' => 'Lote falha de escrita'],
                'items' => [
                    ['file' => $this->pdf('sem-match.pdf', 'conteudo-x')],
                ],
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('items.0.status', 'ERROR')
            ->assertJsonPath('pending_items', 0)
            ->assertJsonPath('error_items', 1);

        $this->assertSame(0, IngestionBatchItem::query()->where('status', 'PENDING')->count());
    }

    public function test_pending_database_failure_removes_the_quarantined_file(): void
    {
        Storage::fake('local');

        $service = new class(app(KnowledgeFileService::class)) extends KnowledgeBatchIngestionService
        {
            protected function persistPendingStoragePath(IngestionBatchItem $item, string $storagePath): void
            {
                throw new RuntimeException('simulated database failure');
            }
        };

        $batch = $service->ingest(
            ['name' => 'Lote falha de banco'],
            [['file' => $this->pdf('sem-match.pdf', 'conteudo-y')]],
        );

        $this->assertSame('ERROR', $batch->items->first()->status);
        Storage::disk('local')->assertDirectoryEmpty('knowledge/pending');
    }

    public function test_failure_after_definitive_write_rolls_back_database_cleans_owned_file_and_sanitizes_response(): void
    {
        Storage::fake('local');
        [, $plainToken] = $this->token();
        $document = $this->document('doc-rollback');
        $service = new class(app(KnowledgeFileService::class)) extends KnowledgeBatchIngestionService
        {
            protected function storeAssociatedItem(IngestionBatch $batch, array $attributes): IngestionBatchItem
            {
                throw new RuntimeException('SQLSTATE secret-host /private/internal/path');
            }
        };
        app()->instance(KnowledgeBatchIngestionService::class, $service);

        $response = $this
            ->withToken($plainToken)
            ->postJson('/api/internal/knowledge/batches', [
                'batch' => ['name' => 'Lote rollback'],
                'items' => [
                    ['external_key' => 'doc-rollback', 'file' => $this->pdf('rollback.pdf', 'rollback-bytes')],
                ],
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('items.0.status', 'ERROR')
            ->assertJsonPath('items.0.reason', 'PROCESSING_ERROR')
            ->assertJsonPath('items.0.file_attached', false)
            ->assertJsonMissing(['SQLSTATE secret-host /private/internal/path']);

        $this->assertNotNull($response->json('items.0.error_id'));
        $this->assertArrayNotHasKey('storage_path', $response->json('items.0'));

        $document->refresh();
        $this->assertNull($document->storage_path);
        $this->assertNull($document->sha256);
        Storage::disk('local')->assertDirectoryEmpty("knowledge/originals/{$document->id}");
        Storage::disk('local')->assertDirectoryEmpty('knowledge/staging');
    }

    public function test_retry_after_success_is_duplicate_and_keeps_only_the_original_bytes(): void
    {
        Storage::fake('local');
        [, $plainToken] = $this->token();
        $document = $this->document('doc-retry');

        $firstResponse = $this
            ->withToken($plainToken)
            ->postJson('/api/internal/knowledge/batches', [
                'batch' => ['name' => 'Primeira tentativa'],
                'items' => [
                    ['external_key' => 'doc-retry', 'file' => $this->pdf('retry.pdf', 'retry-bytes')],
                ],
            ]);
        $retryResponse = $this
            ->withToken($plainToken)
            ->postJson('/api/internal/knowledge/batches', [
                'batch' => ['name' => 'Retry'],
                'items' => [
                    ['external_key' => 'doc-retry', 'file' => $this->pdf('retry.pdf', 'retry-bytes')],
                ],
            ]);

        $firstResponse
            ->assertCreated()
            ->assertJsonPath('items.0.status', 'ASSOCIATED')
            ->assertJsonPath('items.0.file_attached', true);
        $retryResponse
            ->assertCreated()
            ->assertJsonPath('items.0.status', 'DUPLICATE')
            ->assertJsonPath('items.0.file_attached', false);

        $document->refresh();
        $this->assertCount(1, Storage::disk('local')->allFiles("knowledge/originals/{$document->id}"));
        $this->assertSame($this->pdfContent('retry-bytes'), Storage::disk('local')->get($document->storage_path));
        Storage::disk('local')->assertDirectoryEmpty('knowledge/staging');
        Storage::disk('local')->assertDirectoryEmpty('knowledge/pending');
    }

    public function test_batch_endpoint_rejects_requests_without_a_valid_token(): void
    {
        $response = $this->postJson('/api/internal/knowledge/batches', [
            'batch' => ['name' => 'Lote sem token'],
            'items' => [
                ['file' => $this->pdf('doc.pdf', 'content')],
            ],
        ]);

        $response->assertUnauthorized();
    }

    public function test_batch_items_are_traceable_by_batch_id(): void
    {
        Storage::fake('local');
        [, $plainToken] = $this->token();
        $this->document('doc-a');

        $response = $this
            ->withToken($plainToken)
            ->postJson('/api/internal/knowledge/batches', [
                'batch' => ['name' => 'Lote rastreável', 'description' => 'Curadoria diária'],
                'items' => [
                    ['external_key' => 'doc-a', 'file' => $this->pdf('doc-a.pdf', 'content')],
                ],
            ]);

        $batchId = $response->json('batch_id');
        $batch = IngestionBatch::query()->findOrFail($batchId);

        $this->assertSame('Lote rastreável', $batch->name);
        $this->assertSame('COMPLETED', $batch->status);
        $this->assertSame(1, $batch->items()->count());
        $this->assertSame($batchId, $batch->items()->firstOrFail()->ingestion_batch_id);
    }

    public function test_batch_counters_are_consistent_with_mixed_item_results(): void
    {
        Storage::fake('local');
        [, $plainToken] = $this->token();

        $associatedDoc = $this->document('doc-associated');
        $duplicateDoc = $this->document('doc-duplicate');
        app(KnowledgeFileService::class)->attachPdf($duplicateDoc, $this->pdf('original.pdf', 'dup-bytes'));

        $response = $this
            ->withToken($plainToken)
            ->postJson('/api/internal/knowledge/batches', [
                'batch' => ['name' => 'Lote misto'],
                'items' => [
                    ['external_key' => 'doc-associated', 'file' => $this->pdf('a.pdf', 'assoc-bytes')],
                    ['file' => $this->pdf('b.pdf', 'dup-bytes')],
                    ['file' => $this->pdf('c.pdf', 'no-match-bytes')],
                    ['file' => UploadedFile::fake()->create('d.txt', 1, 'text/plain')],
                ],
            ]);

        $response->assertCreated();

        $batch = IngestionBatch::query()->findOrFail($response->json('batch_id'));
        $items = $batch->items()->get();

        $this->assertSame(4, $batch->total_items);
        $this->assertSame($items->count(), $batch->total_items);
        $this->assertSame($items->where('status', IngestionBatchItem::STATUS_ASSOCIATED)->count(), $batch->associated_items);
        $this->assertSame($items->where('status', IngestionBatchItem::STATUS_DUPLICATE)->count(), $batch->duplicate_items);
        $this->assertSame($items->where('status', IngestionBatchItem::STATUS_PENDING)->count(), $batch->pending_items);
        $this->assertSame($items->where('status', IngestionBatchItem::STATUS_ERROR)->count(), $batch->error_items);
        $this->assertSame(
            $batch->total_items,
            $batch->associated_items + $batch->duplicate_items + $batch->pending_items + $batch->error_items,
        );

        $this->assertSame(1, $batch->associated_items);
        $this->assertSame(1, $batch->duplicate_items);
        $this->assertSame(1, $batch->pending_items);
        $this->assertSame(1, $batch->error_items);
    }

    private function token(): array
    {
        return IngestionToken::issue('test token', IngestionToken::SCOPE_KNOWLEDGE_INGEST, now()->addDay());
    }

    private function document(string $externalKey, ?string $sourceDocumentId = null): KnowledgeDocument
    {
        $source = KnowledgeSource::query()->firstOrCreate([
            'name' => 'ANVISA',
            'organization' => 'Agência Nacional de Vigilância Sanitária',
        ]);

        return $this->documentForSource($source->id, $externalKey, $sourceDocumentId);
    }

    private function documentForSource(int $sourceId, string $externalKey, ?string $sourceDocumentId = null): KnowledgeDocument
    {
        return KnowledgeDocument::query()->create([
            'source_id' => $sourceId,
            'external_key' => $externalKey,
            'source_document_id' => $sourceDocumentId,
            'title' => 'Bula Profissional - '.$externalKey,
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
