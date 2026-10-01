<?php

namespace Tests\Feature\Knowledge;

use App\Jobs\ProcessKnowledgeDocument;
use App\Models\Knowledge\KnowledgeDocument;
use App\Models\Knowledge\KnowledgeSource;
use App\Services\Knowledge\KnowledgeChunker;
use App\Services\Knowledge\KnowledgeDocumentProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class KnowledgeDocumentProcessingTest extends TestCase
{
    use RefreshDatabase;

    public function test_textual_multipage_pdf_creates_ordered_traceable_chunks(): void
    {
        Storage::fake('local');
        $document = $this->documentWithFixture('multipage-text.pdf');

        $result = app(KnowledgeDocumentProcessor::class)->process($document->id);

        $this->assertSame('READY', $result['status']);
        $this->assertSame('READY', $document->refresh()->content_status);
        $this->assertNotNull($document->content_hash);
        $this->assertNotNull($document->processed_at);
        $chunks = $document->chunks()->get();
        $this->assertCount(3, $chunks);
        $this->assertSame([0, 1, 2], $chunks->pluck('chunk_index')->all());
        $this->assertSame([1, 2, 3], $chunks->pluck('page_start')->all());
        $this->assertSame(['COMPOSIÇÃO', 'INDICAÇÕES', 'POSOLOGIA E MODO DE USAR'], $chunks->pluck('section')->all());
        $this->assertStringContainsString('500 mg', $chunks[0]->content);
        $this->assertStringContainsString('20 mg/mL', $chunks[0]->content);
        $this->assertStringContainsString('0,5 mg', $chunks[1]->content);
        $this->assertStringContainsString('5%', $chunks[1]->content);
        $this->assertNull($chunks[0]->embedding);
    }

    public function test_reprocessing_replaces_chunks_without_duplication(): void
    {
        Storage::fake('local');
        $document = $this->documentWithFixture('multipage-text.pdf');
        $processor = app(KnowledgeDocumentProcessor::class);
        $processor->process($document->id);
        $firstHash = $document->refresh()->content_hash;

        $result = $processor->process($document->id, force: true);

        $this->assertSame('READY', $result['status']);
        $this->assertSame(3, $document->chunks()->count());
        $this->assertSame($firstHash, $document->refresh()->content_hash);
    }

    public function test_ready_document_is_skipped_without_force(): void
    {
        Storage::fake('local');
        $document = $this->documentWithFixture('multipage-text.pdf');
        $processor = app(KnowledgeDocumentProcessor::class);
        $processor->process($document->id);

        $result = $processor->process($document->id);

        $this->assertSame('SKIPPED', $result['status']);
        $this->assertSame(3, $document->chunks()->count());
    }

    public function test_pdf_without_text_fails_without_deleting_previous_chunks(): void
    {
        Storage::fake('local');
        $document = $this->documentWithFixture('no-text.pdf');
        $document->chunks()->create(['chunk_index' => 0, 'content' => 'Chunk válido anterior.', 'metadata' => []]);

        $result = app(KnowledgeDocumentProcessor::class)->process($document->id, force: true);

        $this->assertSame('FAILED', $result['status']);
        $this->assertSame('TEXT_EXTRACTION_FAILED', $result['error_code']);
        $this->assertSame('FAILED', $document->refresh()->content_status);
        $this->assertSame('Chunk válido anterior.', $document->chunks()->sole()->content);
    }

    public function test_missing_and_invalid_pdfs_fail_with_controlled_errors(): void
    {
        Storage::fake('local');
        $missing = $this->document(['storage_path' => null]);
        $invalid = $this->documentWithFixture('invalid.pdf', 'invalid-document');

        $missingResult = app(KnowledgeDocumentProcessor::class)->process($missing->id);
        $invalidResult = app(KnowledgeDocumentProcessor::class)->process($invalid->id);

        $this->assertSame('DOCUMENT_FILE_MISSING', $missingResult['error_code']);
        $this->assertSame('PROCESSING_ERROR', $invalidResult['error_code']);
        $this->assertSame('FAILED', $missing->refresh()->content_status);
        $this->assertSame('FAILED', $invalid->refresh()->content_status);
        $this->assertArrayNotHasKey('exception', $invalid->metadata['processing']);
    }

    public function test_chunking_failure_preserves_existing_chunks(): void
    {
        Storage::fake('local');
        $document = $this->documentWithFixture('multipage-text.pdf');
        $document->chunks()->create(['chunk_index' => 0, 'content' => 'Conteúdo anterior.', 'metadata' => []]);
        app()->instance(KnowledgeChunker::class, new class extends KnowledgeChunker
        {
            public function chunk(array $sections): array
            {
                throw new RuntimeException('internal chunking details');
            }
        });

        $result = app(KnowledgeDocumentProcessor::class)->process($document->id, force: true);

        $this->assertSame('PROCESSING_ERROR', $result['error_code']);
        $this->assertSame('Conteúdo anterior.', $document->chunks()->sole()->content);
        $this->assertSame('FAILED', $document->refresh()->content_status);
    }

    public function test_configured_page_limit_rejects_the_document_before_replacing_chunks(): void
    {
        Storage::fake('local');
        config()->set('knowledge.processing.max_pages', 2);
        $document = $this->documentWithFixture('multipage-text.pdf');
        $document->chunks()->create(['chunk_index' => 0, 'content' => 'Conteúdo preservado.', 'metadata' => []]);

        $result = app(KnowledgeDocumentProcessor::class)->process($document->id, force: true);

        $this->assertSame('PDF_PAGE_LIMIT_EXCEEDED', $result['error_code']);
        $this->assertSame('Conteúdo preservado.', $document->chunks()->sole()->content);
    }

    public function test_job_runs_the_processor_and_command_dispatches_the_job(): void
    {
        Storage::fake('local');
        $document = $this->documentWithFixture('multipage-text.pdf');
        $job = new ProcessKnowledgeDocument($document->id);

        $job->handle(app(KnowledgeDocumentProcessor::class));

        $this->assertSame('READY', $document->refresh()->content_status);
        Queue::fake([ProcessKnowledgeDocument::class]);
        $this->artisan('knowledge:process-document', ['document' => $document->id, '--force' => true])
            ->expectsOutput('Processamento enfileirado.')
            ->assertSuccessful();
        Queue::assertPushed(ProcessKnowledgeDocument::class, fn (ProcessKnowledgeDocument $queuedJob): bool => $queuedJob->documentId === $document->id && $queuedJob->force);
    }

    private function documentWithFixture(string $fixture, ?string $externalKey = null): KnowledgeDocument
    {
        $document = $this->document(['external_key' => $externalKey ?? 'fixture-'.fake()->unique()->uuid()]);
        $path = "knowledge/originals/{$document->id}/fixture.pdf";
        $contents = file_get_contents(base_path("tests/Fixtures/knowledge/{$fixture}"));
        Storage::disk('local')->put($path, $contents);
        $document->forceFill(['storage_path' => $path, 'file_size' => strlen($contents), 'file_status' => 'READY', 'sha256' => hash('sha256', $contents)])->save();

        return $document;
    }

    /** @param array<string, mixed> $attributes */
    private function document(array $attributes = []): KnowledgeDocument
    {
        $source = KnowledgeSource::query()->firstOrCreate(['name' => 'ANVISA', 'organization' => 'ANVISA']);

        return KnowledgeDocument::query()->create(array_merge([
            'source_id' => $source->id,
            'external_key' => 'document-'.fake()->unique()->uuid(),
            'title' => 'Bula textual',
            'document_type' => 'bula',
            'metadata' => [],
            'content_status' => 'PENDING',
            'file_status' => 'PENDING',
            'embedding_status' => 'PENDING',
        ], $attributes));
    }
}
