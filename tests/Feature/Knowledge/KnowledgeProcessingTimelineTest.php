<?php

namespace Tests\Feature\Knowledge;

use App\Data\Knowledge\ExtractedPage;
use App\Data\Knowledge\ExtractedPdf;
use App\Jobs\ProcessKnowledgeDocument;
use App\Models\Knowledge\KnowledgeDocument;
use App\Models\Knowledge\KnowledgeProcessingEvent;
use App\Models\Knowledge\KnowledgeSource;
use App\Models\User;
use App\Services\Knowledge\CorrelatedDocumentOcrClient;
use App\Services\Knowledge\DocumentOcrClient;
use App\Services\Knowledge\DocumentOcrException;
use App\Services\Knowledge\KnowledgeDocumentProcessor;
use App\Services\Knowledge\ProcessingTimeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/** Linha do tempo do processamento: uma linha por etapa, visível na tela do documento e consumível pela Fila/SSE. */
final class KnowledgeProcessingTimelineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['knowledge.chunking.min_characters' => 0]); // fixtures de poucas palavras: aqui vale a rastreabilidade, não a limpeza
    }

    public function test_native_text_processing_records_every_step_with_embedding_progress_and_the_ingredient_link(): void
    {
        Storage::fake('local');
        config(['ai.embedding.default' => 'fake']);
        $document = $this->documentWithFixture('multipage-text.pdf');

        (new ProcessKnowledgeDocument($document->id))->handle(app(KnowledgeDocumentProcessor::class));

        $this->assertSame('READY', $document->refresh()->content_status);
        $events = app(ProcessingTimeline::class)->for($document->id);
        // O fixture é curto e cai abaixo da qualidade mínima: o OCR é tentado, mas está desligado (etapa pulada, não falha).
        $this->assertSame(['read_native', 'ocr', 'normalize', 'chunk', 'link', 'embed'], $events->pluck('step')->all());
        $this->assertSame(['done', 'skipped', 'done', 'done', 'done', 'done'], $events->pluck('state')->all());
        $this->assertSame([3, 'fallback_ocr'], [$events[0]->detail['pages'], $events[0]->detail['method']]);
        $this->assertGreaterThan(0, $events[0]->detail['quality_score']);
        $this->assertSame(DocumentOcrException::DISABLED, $events[1]->detail['error_code']);
        $this->assertSame(3, $events[3]->detail['chunks']);
        $this->assertSame(['batch' => 1, 'batches' => 1, 'chunks_done' => 3, 'chunks' => 3, 'indexed' => 3], array_intersect_key($events[5]->detail, array_flip(['batch', 'batches', 'chunks_done', 'chunks', 'indexed'])));
        $this->assertNotNull($events[5]->duration_ms);
        $this->assertSame(0, $events[4]->detail['ingredients']);
        $this->assertSame(6, $document->processingEvents()->count());
    }

    public function test_ocr_fallback_records_the_ocr_step_with_the_correlation_id_sent_to_the_service(): void
    {
        Storage::fake('local');
        config()->set('document_ocr.enabled', true);
        $seen = new \stdClass;
        $this->app->instance(DocumentOcrClient::class, new class($seen) implements CorrelatedDocumentOcrClient
        {
            public function __construct(private readonly \stdClass $seen) {}

            public function parse(string $localFile, ?string $correlationId = null): ExtractedPdf
            {
                $this->seen->correlation_id = $correlationId;

                return new ExtractedPdf([new ExtractedPage(1, "COMPOSIÇÃO\nCada comprimido contém 500 mg de dipirona monoidratada."), new ExtractedPage(2, "INDICAÇÕES\nIndicado para dor e febre.")], ['engine' => 'paddleocr', 'duration_ms' => 4321]);
            }
        });
        $document = $this->documentWithFixture('no-text.pdf');

        app(KnowledgeDocumentProcessor::class)->process($document->id);

        $events = app(ProcessingTimeline::class)->for($document->id);
        $this->assertSame(['read_native', 'ocr', 'normalize', 'chunk'], $events->pluck('step')->all());
        $this->assertSame('fallback_ocr', $events[0]->detail['method']);
        $ocr = $events[1];
        $this->assertNotNull($seen->correlation_id);
        $this->assertSame($seen->correlation_id, $ocr->detail['correlation_id'], 'o id enviado em X-Correlation-Id é a chave para casar o progresso do /status com o documento');
        $this->assertSame(['done', 2, 'paddleocr', 4321, true], [$ocr->state, $ocr->detail['pages'], $ocr->detail['engine'], $ocr->detail['duration_ms'], $ocr->detail['adopted']]);
        $this->assertStringContainsString('2 páginas · qualidade', $ocr->summary());
        $this->assertStringContainsString('paddleocr', $ocr->summary());
    }

    public function test_failures_mark_the_running_step_and_reprocessing_starts_a_fresh_timeline(): void
    {
        Storage::fake('local');
        $document = $this->documentWithFixture('no-text.pdf');

        $this->assertSame('TEXT_EXTRACTION_FAILED', app(KnowledgeDocumentProcessor::class)->process($document->id)['error_code']);
        $failed = app(ProcessingTimeline::class)->for($document->id);
        $this->assertSame(['read_native', 'ocr', 'normalize'], $failed->pluck('step')->all());
        $this->assertSame(['done', 'skipped', 'failed'], $failed->pluck('state')->all(), 'OCR desligado é pulado; a validação do texto falha dentro da normalização');
        $this->assertSame('TEXT_EXTRACTION_FAILED', $failed[2]->detail['error_code']);

        config()->set('document_ocr.enabled', true);
        $this->app->instance(DocumentOcrClient::class, new class implements DocumentOcrClient
        {
            public function parse(string $localFile): ExtractedPdf
            {
                throw new DocumentOcrException(DocumentOcrException::UNAVAILABLE, 'corr-1');
            }
        });
        // O processador é resolvido depois do fake: o serviço de extração guarda o cliente de OCR injetado na construção.
        $this->assertSame('OCR_UNAVAILABLE', app(KnowledgeDocumentProcessor::class)->process($document->id, force: true)['error_code']);
        $events = app(ProcessingTimeline::class)->for($document->id);
        $this->assertSame(['read_native', 'ocr'], $events->pluck('step')->all(), 'reprocessar apaga a execução anterior');
        $this->assertSame(['failed', DocumentOcrException::UNAVAILABLE, 'corr-1'], [$events[1]->state, $events[1]->detail['error_code'], $events[1]->detail['correlation_id']]);
        $this->assertNotNull($events[1]->finished_at);
    }

    public function test_active_lists_only_running_steps_with_their_document(): void
    {
        $running = KnowledgeProcessingEvent::factory()->running()->create(['step' => 'ocr', 'detail' => ['correlation_id' => 'abc', 'reason' => 'NO_TEXT']]);
        KnowledgeProcessingEvent::factory()->create(['document_id' => $running->document_id, 'step' => 'read_native']);
        KnowledgeProcessingEvent::factory()->create(['state' => 'failed']);

        $active = app(ProcessingTimeline::class)->active();

        $this->assertCount(1, $active);
        $this->assertSame(['ocr', 'running', $running->document_id, 'abc'], [$active[0]->step, $active[0]->state, $active[0]->document->id, $active[0]->detail['correlation_id']]);
        $this->assertTrue($active[0]->relationLoaded('document'));
    }

    public function test_document_page_shows_the_timeline_and_refreshes_while_a_step_is_running(): void
    {
        $event = KnowledgeProcessingEvent::factory()->running()->create(['step' => 'embed', 'detail' => ['batch' => 2, 'batches' => 5, 'chunks_done' => 32, 'chunks' => 80]]);
        KnowledgeProcessingEvent::factory()->create(['document_id' => $event->document_id, 'step' => 'ocr', 'duration_ms' => 108_000, 'detail' => ['pages' => 11, 'engine' => 'paddleocr', 'quality_after' => 0.9]]);
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get('/admin/documents/'.$event->document_id);

        $response->assertOk()
            ->assertSee('Linha do tempo do processamento')
            ->assertSee('Índice de busca (embeddings)')
            ->assertSee('lote 2 de 5')
            ->assertSee('32 de 80 trechos')
            ->assertSee('11 páginas · qualidade 0,90 · paddleocr')
            ->assertSee('108 s')
            ->assertSee('wire:poll.3s', false)
            ->assertSee('data-testid="timeline-live"', false);
    }

    public function test_a_timeline_storage_failure_does_not_prevent_the_document_from_becoming_ready(): void
    {
        Storage::fake('local');
        $document = $this->documentWithFixture('multipage-text.pdf');
        Log::spy();
        KnowledgeProcessingEvent::creating(fn () => throw new RuntimeException('disco cheio'));

        $result = app(KnowledgeDocumentProcessor::class)->process($document->id);

        $this->assertSame('READY', $result['status']);
        $this->assertSame('READY', $document->refresh()->content_status);
        $this->assertSame(0, $document->processingEvents()->count());
        Log::shouldHaveReceived('warning')->with('knowledge_processing_timeline_failed', Mockery::type('array'))->atLeast()->once();
    }

    private function documentWithFixture(string $fixture): KnowledgeDocument
    {
        $source = KnowledgeSource::query()->firstOrCreate(['name' => 'ANVISA', 'organization' => 'ANVISA']);
        $document = KnowledgeDocument::query()->create([
            'source_id' => $source->id,
            'external_key' => 'fixture-'.fake()->unique()->uuid(),
            'title' => 'Bula textual',
            'document_type' => 'bula',
            'metadata' => [],
            'content_status' => 'PENDING',
            'file_status' => 'PENDING',
            'embedding_status' => 'PENDING',
        ]);
        $path = "knowledge/originals/{$document->id}/fixture.pdf";
        $contents = file_get_contents(base_path("tests/Fixtures/knowledge/{$fixture}"));
        Storage::disk('local')->put($path, $contents);
        $document->forceFill(['storage_path' => $path, 'file_size' => strlen($contents), 'file_status' => 'READY', 'sha256' => hash('sha256', $contents)])->save();

        return $document;
    }
}
