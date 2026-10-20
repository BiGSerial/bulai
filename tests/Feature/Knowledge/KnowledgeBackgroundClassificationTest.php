<?php

namespace Tests\Feature\Knowledge;

use App\Data\Knowledge\ExtractedPage;
use App\Data\Knowledge\ExtractedPdf;
use App\Jobs\ClassifyPendingDocument;
use App\Livewire\Admin\Knowledge\Pending\Review;
use App\Models\Knowledge\IngestionBatch;
use App\Models\Knowledge\IngestionBatchItem;
use App\Models\Knowledge\IngestionToken;
use App\Models\Knowledge\KnowledgeSource;
use App\Models\User;
use App\Services\Ai\AiProviderManager;
use App\Services\Ai\Fake\FakeDocumentClassificationProvider;
use App\Services\Knowledge\DocumentOcrClient;
use App\Services\Knowledge\DocumentOcrException;
use App\Services\Knowledge\KnowledgePendingItemReanalysisService;
use App\Services\Knowledge\PdfTextExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

final class KnowledgeBackgroundClassificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_pdf_is_classified_in_the_queue_not_in_the_upload_request(): void
    {
        Storage::fake('local');
        Queue::fake();
        config()->set('ai.classifier.default', 'fake');

        $this->upload();

        $item = IngestionBatchItem::query()->sole();
        $this->assertSame('PENDING', $item->status);
        Queue::assertPushed(ClassifyPendingDocument::class, fn (ClassifyPendingDocument $job): bool => $job->itemId === $item->id && $job->batchId === $item->ingestion_batch_id);
    }

    public function test_nothing_is_queued_when_ocr_and_classifier_are_both_disabled(): void
    {
        Storage::fake('local');
        Queue::fake();

        $this->upload();

        Queue::assertNotPushed(ClassifyPendingDocument::class);
    }

    public function test_job_runs_deep_identification_and_ocr_text_reaches_the_heuristic(): void
    {
        Storage::fake('knowledge');
        config()->set('document_ocr.enabled', true);
        $text = (string) file_get_contents(base_path('tests/Fixtures/knowledge/anvisa-atrovent-extract.txt'));
        $this->app->instance(PdfTextExtractor::class, $this->textExtractor(new ExtractedPdf([new ExtractedPage(1, '')])));
        $this->app->instance(DocumentOcrClient::class, $this->ocr(new ExtractedPdf([new ExtractedPage(1, $text)], ['engine' => 'paddleocr'])));
        [$batch, $item] = $this->pendingItem();

        (new ClassifyPendingDocument($batch->id, $item->id))->handle(app(KnowledgePendingItemReanalysisService::class));

        $item->refresh();
        $this->assertSame('ocr', data_get($item->metadata, 'preliminary_identification.extraction.extraction_method'));
        $this->assertSame('ACCEPTED_HEURISTIC', data_get($item->metadata, 'preliminary_identification.classification.decision'));
        $this->assertSame('ASSOCIATED', $item->status);
    }

    public function test_unavailable_ocr_and_classifier_leave_the_item_pending_with_reasons_recorded(): void
    {
        Storage::fake('knowledge');
        config()->set('document_ocr.enabled', true);
        config()->set('ai.classifier.default', 'test');
        $provider = new FakeDocumentClassificationProvider(null, 'AI_TIMEOUT');
        app(AiProviderManager::class)->extend('classifier', 'test', fn () => $provider);
        $this->app->instance(PdfTextExtractor::class, $this->textExtractor(new ExtractedPdf([new ExtractedPage(1, '')])));
        $this->app->instance(DocumentOcrClient::class, $this->ocr(null, DocumentOcrException::UNAVAILABLE));
        [$batch, $item] = $this->pendingItem();

        (new ClassifyPendingDocument($batch->id, $item->id))->handle(app(KnowledgePendingItemReanalysisService::class));

        $item->refresh();
        $this->assertSame('PENDING', $item->status);
        $this->assertSame('OCR_UNAVAILABLE', data_get($item->metadata, 'preliminary_identification.extraction.ocr_error_code'));
        $this->assertSame('MANUAL_REVIEW', data_get($item->metadata, 'preliminary_identification.classification.decision'));
        $this->assertSame('AI_UNAVAILABLE', data_get($item->metadata, 'preliminary_identification.classification.gap_fill_outcome'));
        $this->assertSame(1, $provider->calls, 'Sem princípio ativo, a IA é consultada mesmo com pontuação baixa.');
    }

    public function test_classifier_timeout_in_the_job_keeps_the_ambiguous_item_pending(): void
    {
        Storage::fake('knowledge');
        config()->set('ai.classifier.default', 'test');
        $provider = new FakeDocumentClassificationProvider(null, 'AI_TIMEOUT');
        app(AiProviderManager::class)->extend('classifier', 'test', fn () => $provider);
        $text = "Bula do paciente\nIDENTIFICAÇÃO DO MEDICAMENTO\nProdutoX\n";
        $this->app->instance(PdfTextExtractor::class, $this->textExtractor(new ExtractedPdf([new ExtractedPage(1, $text)])));
        [$batch, $item] = $this->pendingItem();

        (new ClassifyPendingDocument($batch->id, $item->id))->handle(app(KnowledgePendingItemReanalysisService::class));

        $item->refresh();
        $this->assertSame('PENDING', $item->status);
        $this->assertSame('AI_UNAVAILABLE', data_get($item->metadata, 'preliminary_identification.classification.decision'));
        $this->assertSame('AI_TIMEOUT', data_get($item->metadata, 'preliminary_identification.classification.error_code'));
        $this->assertSame(1, $provider->calls);
    }

    public function test_each_analysis_is_audited_and_visible_on_the_review_page(): void
    {
        Storage::fake('knowledge');
        config()->set('document_ocr.enabled', true);
        config()->set('ai.classifier.default', 'test');
        app(AiProviderManager::class)->extend('classifier', 'test', fn () => new FakeDocumentClassificationProvider(null, 'AI_INVALID_RESPONSE'));
        $text = "Bula do paciente\nIDENTIFICAÇÃO DO MEDICAMENTO\nProdutoX\n";
        $this->app->instance(PdfTextExtractor::class, $this->textExtractor(new ExtractedPdf([new ExtractedPage(1, $text)])));
        [$batch, $item] = $this->pendingItem();
        $service = app(KnowledgePendingItemReanalysisService::class);

        $service->reanalyze($batch->id, $item->id);
        $service->reanalyze($batch->id, $item->id);

        $runs = collect(data_get($item->refresh()->metadata, 'audit_history'))->where('event', 'automatic_analysis');
        $this->assertCount(2, $runs);
        $this->assertSame('AI_UNAVAILABLE', $runs->first()['details']['classification']['decision']);
        $this->assertArrayHasKey('thresholds', $runs->first()['details']['classification']);

        Livewire::actingAs(User::factory()->admin()->create())->test(Review::class, ['batch' => $batch, 'item' => $item])
            ->assertSee('Análise automática')
            ->assertSee('IA indisponível')
            ->assertSee('A IA respondeu fora do formato esperado.')
            ->assertSee('Execuções anteriores (1)');
    }

    public function test_review_page_warns_when_ocr_and_ai_have_not_run_yet(): void
    {
        Storage::fake('knowledge');
        [$batch, $item] = $this->pendingItem();

        Livewire::actingAs(User::factory()->admin()->create())->test(Review::class, ['batch' => $batch, 'item' => $item])
            ->assertSee('o OCR e a IA ainda não rodaram');
    }

    private function upload(): void
    {
        [, $token] = IngestionToken::issue('t', IngestionToken::SCOPE_KNOWLEDGE_INGEST, now()->addDay());
        $this->withToken($token)->postJson('/api/internal/knowledge/batches', [
            'batch' => ['name' => 'Lote'],
            'items' => [['file' => UploadedFile::fake()->createWithContent('x.pdf', "%PDF-1.4\nsem correspondência\n%%EOF")]],
        ])->assertCreated();
    }

    /** @return array{IngestionBatch, IngestionBatchItem} */
    private function pendingItem(): array
    {
        $source = KnowledgeSource::query()->create(['name' => 'ANVISA', 'organization' => 'ANVISA', 'collection' => 'Bulário Eletrônico', 'active' => true]);
        $batch = IngestionBatch::query()->create(['name' => 'Lote', 'source_id' => $source->id, 'status' => 'COMPLETED', 'total_items' => 1, 'pending_items' => 1]);
        $bytes = '%PDF-1.4 bytes';
        $key = "knowledge/pending/{$batch->id}/f.pdf";
        $item = $batch->items()->create(['original_filename' => 'bula.pdf', 'normalized_filename' => 'bula.pdf', 'sha256' => hash('sha256', $bytes), 'mime_type' => 'application/pdf',
            'file_size' => strlen($bytes), 'storage_disk' => 'knowledge', 'storage_key' => $key, 'storage_path' => $key, 'status' => 'PENDING', 'reason' => 'no_match_found', 'metadata' => []]);
        Storage::disk('knowledge')->put($key, $bytes);

        return [$batch, $item];
    }

    private function textExtractor(ExtractedPdf $pdf): PdfTextExtractor
    {
        return new class($pdf) implements PdfTextExtractor
        {
            public function __construct(private readonly ExtractedPdf $pdf) {}

            public function extract(string $absolutePath): ExtractedPdf
            {
                return $this->pdf;
            }
        };
    }

    private function ocr(?ExtractedPdf $pdf, ?string $failWith = null): DocumentOcrClient
    {
        return new class($pdf, $failWith) implements DocumentOcrClient
        {
            public function __construct(private readonly ?ExtractedPdf $pdf, private readonly ?string $failWith) {}

            public function parse(string $localFile): ExtractedPdf
            {
                return $this->failWith !== null ? throw new DocumentOcrException($this->failWith) : $this->pdf;
            }
        };
    }
}
