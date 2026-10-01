<?php

namespace Tests\Feature\Knowledge;

use App\Data\Knowledge\ExtractedPage;
use App\Data\Knowledge\ExtractedPdf;
use App\Livewire\Admin\Knowledge\Pending\Review;
use App\Models\Knowledge\KnowledgeDocument;
use App\Models\Knowledge\KnowledgeSource;
use App\Models\User;
use App\Services\Knowledge\KnowledgeBatchIngestionService;
use App\Services\Knowledge\KnowledgeFileService;
use App\Services\Knowledge\PdfTextExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class KnowledgePreliminaryIdentificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_confident_anvisa_identity_reaches_lifecycle_and_creates_document(): void
    {
        Storage::fake('local');
        Queue::fake();
        $this->fakeExtraction($this->atroventPdf());
        $source = $this->source();

        $batch = app(KnowledgeBatchIngestionService::class)->ingest(['name' => 'ANVISA', 'source_id' => $source->id], [
            ['file' => $this->pdf('bula_1790735935676.pdf', 'real-like-bytes'), 'metadata' => ['ingestion_channel' => 'knowledge_admin']],
        ]);

        $item = $batch->items->sole();
        $document = KnowledgeDocument::query()->sole();
        $this->assertSame('ASSOCIATED', $item->status);
        $this->assertSame('automatic_creation', $item->resolution_method);
        $this->assertSame('103670004', $document->source_document_id);
        $this->assertSame('Atrovent® N e Atrovent® — Bula do profissional de saúde', $document->title);
        $this->assertSame(100, $item->metadata['preliminary_identification']['score']);
    }

    public function test_official_identifier_associates_existing_document_before_creating_another(): void
    {
        Storage::fake('local');
        $this->fakeExtraction($this->atroventPdf());
        $source = $this->source();
        $document = $this->document($source, '103670004');

        $batch = app(KnowledgeBatchIngestionService::class)->ingest(['name' => 'ANVISA', 'source_id' => $source->id], [
            ['file' => $this->pdf('opaque.pdf', 'new-bytes')],
        ]);

        $this->assertSame($document->id, $batch->items->sole()->knowledge_document_id);
        $this->assertSame('source_document_id', $batch->items->sole()->resolution_method);
        $this->assertDatabaseCount('knowledge_documents', 1);
    }

    public function test_insufficient_identity_remains_pending_and_is_visible_and_prefilled_for_review(): void
    {
        Storage::fake('local');
        $this->fakeExtraction(new ExtractedPdf([new ExtractedPage(1, "NOVALGINA\ndipirona monoidratada\nAPRESENTAÇÕES\nINFORMAÇÕES AO PACIENTE")]));
        $source = $this->source();
        $batch = app(KnowledgeBatchIngestionService::class)->ingest(['name' => 'Incompleto', 'source_id' => $source->id], [
            ['file' => $this->pdf('sem-registro.pdf', 'bytes')],
        ]);

        $item = $batch->items->sole();
        $this->assertSame('PENDING', $item->status);
        $this->assertSame(55, $item->metadata['preliminary_identification']['score']);
        $this->assertDatabaseCount('knowledge_documents', 0);

        Livewire::actingAs(User::factory()->admin()->create())->test(Review::class, ['batch' => $batch, 'item' => $item])
            ->assertSee('Informações candidatas identificadas')->assertSee('NOVALGINA')
            ->assertSet('newDocumentTitle', 'NOVALGINA — Bula do paciente')->assertSet('newDocumentType', 'bula_paciente');
    }

    public function test_parser_failure_and_textless_pdf_do_not_create_documents(): void
    {
        Storage::fake('local');
        $source = $this->source();
        $this->app->instance(PdfTextExtractor::class, new class implements PdfTextExtractor
        {
            public function extract(string $absolutePath): ExtractedPdf
            {
                throw new RuntimeException('corrupt internal path /srv/private.pdf');
            }
        });

        $failed = app(KnowledgeBatchIngestionService::class)->ingest(['name' => 'Corrompido', 'source_id' => $source->id], [['file' => $this->pdf('corrupt.pdf', 'bad')]]);
        $this->assertSame('PENDING', $failed->items->sole()->status);
        $this->assertSame('TEXT_IDENTIFICATION_FAILED', $failed->items->sole()->metadata['preliminary_identification']['error_code']);
        $this->assertDatabaseCount('knowledge_documents', 0);

        $this->fakeExtraction(new ExtractedPdf([new ExtractedPage(1, '')]));
        $textless = app(KnowledgeBatchIngestionService::class)->ingest(['name' => 'Sem texto', 'source_id' => $source->id], [['file' => $this->pdf('image.pdf', 'image')]]);
        $this->assertSame('PENDING', $textless->items->sole()->status);
        $this->assertDatabaseCount('knowledge_documents', 0);
    }

    public function test_same_identity_with_different_pdf_respects_current_version_policy(): void
    {
        Storage::fake('local');
        $this->fakeExtraction($this->atroventPdf());
        $source = $this->source();
        $service = app(KnowledgeBatchIngestionService::class);
        $service->ingest(['name' => 'Primeiro', 'source_id' => $source->id], [['file' => $this->pdf('a.pdf', 'v1')]]);
        $second = $service->ingest(['name' => 'Segundo', 'source_id' => $source->id], [['file' => $this->pdf('b.pdf', 'v2')]]);

        $this->assertDatabaseCount('knowledge_documents', 1);
        $this->assertSame('PENDING', $second->items->sole()->status);
        $this->assertSame('document_already_has_different_file', $second->items->sole()->reason);
    }

    public function test_mixed_batch_associates_existing_creates_new_and_quarantines_ambiguous_pdf(): void
    {
        Storage::fake('local');
        $source = $this->source();
        $existing = $this->document($source, '103670004');
        $this->fakeExtractions([
            $this->atroventPdf(),
            $this->identifiedPdf('NOVO MEDICAMENTO', 'princípio ativo novo', '1.2345.6789'),
            new ExtractedPdf([new ExtractedPage(1, "PRODUTO INCERTO\nsubstância\nAPRESENTAÇÕES\nBula para o profissional"), new ExtractedPage(2, "Registro: 1.1111.1111\nRegistro: 1.2222.2222")]),
        ]);

        $batch = app(KnowledgeBatchIngestionService::class)->ingest(['name' => 'Misto', 'source_id' => $source->id], [
            ['file' => $this->pdf('existing.pdf', 'one')], ['file' => $this->pdf('new.pdf', 'two')], ['file' => $this->pdf('ambiguous.pdf', 'three')],
        ]);

        $this->assertSame(['ASSOCIATED', 'ASSOCIATED', 'PENDING'], $batch->items->pluck('status')->all());
        $this->assertSame($existing->id, $batch->items->first()->knowledge_document_id);
        $this->assertSame(['registration_number'], $batch->items->last()->metadata['preliminary_identification']['ambiguous_fields']);
        $this->assertDatabaseCount('knowledge_documents', 2);
    }

    public function test_known_sha_is_handled_as_duplicate_without_running_preliminary_parser(): void
    {
        Storage::fake('local');
        $source = $this->source();
        $document = $this->document($source, 'known-id');
        app(KnowledgeFileService::class)->attachPdf($document, $this->pdf('original.pdf', 'same'));
        $this->app->instance(PdfTextExtractor::class, new class implements PdfTextExtractor
        {
            public function extract(string $absolutePath): ExtractedPdf
            {
                throw new RuntimeException('Parser should not run for known SHA.');
            }
        });

        $batch = app(KnowledgeBatchIngestionService::class)->ingest(['name' => 'Duplicado', 'source_id' => $source->id], [['file' => $this->pdf('copy.pdf', 'same')]]);

        $this->assertSame('DUPLICATE', $batch->items->sole()->status);
        $this->assertArrayNotHasKey('preliminary_identification', $batch->items->sole()->metadata);
        $this->assertDatabaseCount('knowledge_documents', 1);
    }

    private function fakeExtraction(ExtractedPdf $pdf): void
    {
        $this->app->instance(PdfTextExtractor::class, new class($pdf) implements PdfTextExtractor
        {
            public function __construct(private readonly ExtractedPdf $pdf) {}

            public function extract(string $absolutePath): ExtractedPdf
            {
                return $this->pdf;
            }
        });
    }

    /** @param list<ExtractedPdf> $pdfs */
    private function fakeExtractions(array $pdfs): void
    {
        $this->app->instance(PdfTextExtractor::class, new class($pdfs) implements PdfTextExtractor
        {
            /** @param list<ExtractedPdf> $pdfs */
            public function __construct(private array $pdfs) {}

            public function extract(string $absolutePath): ExtractedPdf
            {
                return array_shift($this->pdfs);
            }
        });
    }

    private function atroventPdf(): ExtractedPdf
    {
        $pages = explode("\n===PAGE===\n", file_get_contents(base_path('tests/Fixtures/knowledge/anvisa-atrovent-extract.txt')));

        return new ExtractedPdf(array_map(fn (string $text, int $index): ExtractedPage => new ExtractedPage($index + 1, $text), $pages, array_keys($pages)));
    }

    private function identifiedPdf(string $product, string $ingredient, string $registration): ExtractedPdf
    {
        return new ExtractedPdf([
            new ExtractedPage(1, "{$product}\n{$ingredient}\nAPRESENTAÇÕES\nBula para o profissional"),
            new ExtractedPage(2, "Registro: {$registration}\nRegistrado por: Laboratório Teste S.A."),
        ]);
    }

    private function source(): KnowledgeSource
    {
        return KnowledgeSource::query()->create(['name' => 'ANVISA', 'collection' => 'Bulário Eletrônico', 'organization' => 'Bulário Eletrônico', 'type' => 'regulatory']);
    }

    private function document(KnowledgeSource $source, string $sourceDocumentId): KnowledgeDocument
    {
        return KnowledgeDocument::query()->create(['source_id' => $source->id, 'external_key' => 'existing-atrovent', 'source_document_id' => $sourceDocumentId,
            'title' => 'Atrovent', 'document_type' => 'bula_profissional', 'metadata' => [], 'content_status' => 'PENDING', 'file_status' => 'PENDING', 'embedding_status' => 'PENDING']);
    }

    private function pdf(string $name, string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, '%PDF-1.4 '.$content);
    }
}
