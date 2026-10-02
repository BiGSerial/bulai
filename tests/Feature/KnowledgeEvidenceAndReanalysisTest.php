<?php

namespace Tests\Feature;

use App\Data\Knowledge\ExtractedPage;
use App\Data\Knowledge\ExtractedPdf;
use App\Jobs\ReanalyzePendingKnowledgeBatch;
use App\Livewire\Admin\Knowledge\Batches\Show;
use App\Livewire\Admin\Knowledge\Pending\Index as PendingIndex;
use App\Livewire\Admin\Knowledge\Pending\Review;
use App\Models\Knowledge\IngestionBatch;
use App\Models\Knowledge\IngestionBatchItem;
use App\Models\Knowledge\KnowledgeSource;
use App\Models\User;
use App\Services\Knowledge\KnowledgePendingItemReanalysisService;
use App\Services\Knowledge\PdfTextExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class KnowledgeEvidenceAndReanalysisTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_preview_and_download_exact_private_original_but_others_cannot(): void
    {
        Storage::fake('knowledge');
        [$batch, $item, $bytes] = $this->pendingItem();
        $preview = route('admin.knowledge.items.file', [$batch, $item]);
        $download = route('admin.knowledge.items.download', [$batch, $item]);

        $this->get($preview)->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get($preview)->assertForbidden();
        $this->actingAs(User::factory()->admin()->create())->get($preview)->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertStreamedContent($bytes);
        $response = $this->get($download)->assertOk()->assertDownload('bula.pdf');
        $this->assertSame($bytes, $response->streamedContent());
        $this->assertSame($item->sha256, hash('sha256', $response->streamedContent()));
        $this->assertStringNotContainsString('knowledge/pending', (string) $response->headers->get('Content-Disposition'));
    }

    public function test_missing_evidence_is_controlled_without_exposing_storage_key(): void
    {
        Storage::fake('knowledge');
        [$batch, $item] = $this->pendingItem(false);
        $this->actingAs(User::factory()->admin()->create())->get(route('admin.knowledge.items.file', [$batch, $item]))
            ->assertNotFound()->assertSee('não está disponível')->assertDontSee($item->storage_key);
    }

    public function test_integrity_mismatch_blocks_reanalysis_and_persists_audit_event(): void
    {
        Storage::fake('knowledge');
        [$batch, $item] = $this->pendingItem();
        Storage::disk('knowledge')->put($item->storage_key, 'changed');
        $result = app(KnowledgePendingItemReanalysisService::class)->reanalyze($batch->id, $item->id, User::factory()->admin()->create());
        $this->assertSame('PENDING_FILE_INVALID', $result['error_code']);
        $this->assertSame('PENDING', $item->refresh()->status);
        $this->assertSame('integrity_check_failed', data_get($item->metadata, 'audit_history.0.event'));
    }

    public function test_admin_can_resolve_without_incorporation_while_preserving_evidence_and_counters(): void
    {
        Storage::fake('knowledge');
        [$batch, $item, $bytes] = $this->pendingItem();
        Livewire::actingAs(User::factory()->admin()->create())->test(Review::class, ['batch' => $batch, 'item' => $item])
            ->set('ignoreReason', 'out_of_scope')->set('ignoreNotes', 'Fora do recorte.')->call('ignore')->assertSee('evidência foram preservados');
        $this->assertSame(IngestionBatchItem::STATUS_IGNORED, $item->refresh()->status);
        $this->assertSame(0, $batch->refresh()->pending_items);
        $this->assertSame(1, $batch->ignored_items);
        $this->assertSame($bytes, Storage::disk('knowledge')->get($item->storage_key));
        $this->assertSame('not_incorporated', data_get($item->metadata, 'audit_history.0.event'));
    }

    public function test_batch_reanalysis_is_queued_from_the_admin_interface(): void
    {
        Queue::fake();
        [$batch] = $this->pendingItem();
        $batch->forceFill(['pending_items' => 1])->save();
        $admin = User::factory()->admin()->create();
        Livewire::actingAs($admin)->test(Show::class, ['batch' => $batch])->call('reanalyzePending');
        Queue::assertPushed(ReanalyzePendingKnowledgeBatch::class, fn ($job): bool => $job->batchId === $batch->id && $job->actorId === $admin->id);
        $item = $batch->items()->firstOrFail();
        $this->assertSame('queued', data_get($item->metadata, 'reanalysis_progress.state'));
        $this->assertSame(5, data_get($item->metadata, 'reanalysis_progress.percent'));
    }

    public function test_pending_queue_shows_backend_progress_and_hides_review_during_processing(): void
    {
        [, $item] = $this->pendingItem(false);
        $metadata = $item->metadata;
        $metadata['reanalysis_progress'] = ['state' => 'processing', 'percent' => 35, 'label' => 'Lendo e identificando o PDF', 'updated_at' => now()->toISOString()];
        $item->forceFill(['metadata' => $metadata])->save();

        Livewire::actingAs(User::factory()->admin()->create())->test(PendingIndex::class)
            ->assertSee('Processamento em andamento')
            ->assertSee('1 item sendo executado')
            ->assertSee('Executando')
            ->assertSee('Lendo e identificando o PDF')
            ->assertSee('35%')
            ->assertSee('Processando')
            ->assertDontSee('Revisar');
    }

    public function test_pending_queue_warns_when_backend_progress_is_stale(): void
    {
        [, $item] = $this->pendingItem(false);
        $metadata = $item->metadata;
        $metadata['reanalysis_progress'] = ['state' => 'queued', 'percent' => 5, 'label' => 'Aguardando processamento', 'updated_at' => now()->subMinutes(10)->toISOString()];
        $item->forceFill(['metadata' => $metadata, 'updated_at' => now()->subMinutes(10)])->save();

        Livewire::actingAs(User::factory()->admin()->create())->test(PendingIndex::class)
            ->assertSee('Processamento sem atualização')
            ->assertSee('worker da fila está ativo')
            ->assertSee('1 item aguardando');
    }

    public function test_reanalysis_reuses_current_lifecycle_and_is_idempotent(): void
    {
        Storage::fake('knowledge');
        Queue::fake();
        [$batch, $item] = $this->pendingItem();
        $batch->source->forceFill(['collection' => 'Bulário Eletrônico'])->save();
        $text = file_get_contents(base_path('tests/Fixtures/knowledge/anvisa-atrovent-extract.txt'));
        $this->app->instance(PdfTextExtractor::class, new class((string) $text) implements PdfTextExtractor
        {
            public function __construct(private readonly string $text) {}

            public function extract(string $absolutePath): ExtractedPdf
            {
                return new ExtractedPdf([new ExtractedPage(1, $this->text)]);
            }
        });

        $service = app(KnowledgePendingItemReanalysisService::class);
        $first = $service->reanalyze($batch->id, $item->id, User::factory()->admin()->create());
        $second = $service->reanalyze($batch->id, $item->id);

        $this->assertSame(IngestionBatchItem::STATUS_ASSOCIATED, $first['status']);
        $this->assertSame('ALREADY_RESOLVED', $second['status']);
        $this->assertSame('reanalysis', $item->refresh()->resolution_method);
        $this->assertNotNull($item->knowledge_document_id);
        $this->assertDatabaseCount('knowledge_documents', 1);
        $this->assertSame(0, $batch->refresh()->pending_items);
    }

    /** @return array{IngestionBatch, IngestionBatchItem, string} */
    private function pendingItem(bool $write = true): array
    {
        $source = KnowledgeSource::query()->create(['name' => 'ANVISA', 'organization' => 'ANVISA', 'active' => true]);
        $batch = IngestionBatch::query()->create(['name' => 'Lote legado', 'source_id' => $source->id, 'status' => 'COMPLETED', 'total_items' => 1, 'pending_items' => 1]);
        $bytes = '%PDF-1.4 evidence bytes';
        $key = "knowledge/pending/{$batch->id}/evidence.pdf";
        $item = $batch->items()->create(['original_filename' => 'bula.pdf', 'normalized_filename' => 'bula.pdf', 'sha256' => hash('sha256', $bytes), 'mime_type' => 'application/pdf', 'file_size' => strlen($bytes), 'storage_disk' => 'knowledge', 'storage_key' => $key, 'storage_path' => $key, 'status' => 'PENDING', 'reason' => 'no_match_found', 'metadata' => ['ingestion_channel' => 'knowledge_admin']]);
        if ($write) {
            Storage::disk('knowledge')->put($key, $bytes);
        }

        return [$batch, $item, $bytes];
    }
}
