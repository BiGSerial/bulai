<?php

namespace Tests\Feature\Knowledge;

use App\Livewire\Admin\Documents\Create as CreateDocument;
use App\Livewire\Admin\Knowledge\Pending\Review;
use App\Models\Knowledge\IngestionBatch;
use App\Models\Knowledge\KnowledgeDocument;
use App\Models\Knowledge\KnowledgeSource;
use App\Models\User;
use App\Services\Knowledge\KnowledgeBatchIngestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class KnowledgeDocumentLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_structured_new_document_is_created_and_associated_automatically(): void
    {
        Storage::fake('local');
        Queue::fake();
        $source = $this->source();

        $batch = app(KnowledgeBatchIngestionService::class)->ingest(
            ['name' => 'Carga estruturada', 'source_id' => $source->id],
            [[
                'file' => $this->pdf('dipirona.pdf', 'dipirona-content'),
                'source_document_id' => 'ANVISA-123',
                'metadata' => ['title' => 'Dipirona Monoidratada', 'document_type' => 'bula_paciente'],
            ]],
        );

        $document = KnowledgeDocument::query()->sole();
        $this->assertSame('ASSOCIATED', $batch->items->sole()->status);
        $this->assertSame('automatic_creation', $batch->items->sole()->resolution_method);
        $this->assertSame($source->id, $document->source_id);
        $this->assertSame('ANVISA-123', $document->source_document_id);
        $this->assertSame(hash('sha256', '%PDF-1.4 dipirona-content'), $document->sha256);
    }

    public function test_unidentified_pdf_remains_pending_without_creating_a_document(): void
    {
        Storage::fake('local');
        $source = $this->source();

        $batch = app(KnowledgeBatchIngestionService::class)->ingest(
            ['name' => 'Carga opaca', 'source_id' => $source->id],
            [['file' => $this->pdf('bula_1790735935676.pdf', 'unknown'), 'metadata' => ['ingestion_channel' => 'knowledge_admin']]],
        );

        $this->assertSame('PENDING', $batch->items->sole()->status);
        $this->assertSame('no_match_found', $batch->items->sole()->reason);
        $this->assertDatabaseCount('knowledge_documents', 0);
    }

    public function test_repeated_structured_identity_does_not_create_duplicate_documents(): void
    {
        Storage::fake('local');
        $source = $this->source();
        $service = app(KnowledgeBatchIngestionService::class);
        $payload = ['source_document_id' => 'ANVISA-456', 'metadata' => ['title' => 'Losartana', 'document_type' => 'bula']];

        $service->ingest(['name' => 'Primeiro', 'source_id' => $source->id], [[...$payload, 'file' => $this->pdf('a.pdf', 'first')]]);
        $second = $service->ingest(['name' => 'Segundo', 'source_id' => $source->id], [[...$payload, 'file' => $this->pdf('b.pdf', 'second')]]);

        $this->assertDatabaseCount('knowledge_documents', 1);
        $this->assertSame('PENDING', $second->items->sole()->status);
        $this->assertSame('document_already_has_different_file', $second->items->sole()->reason);
    }

    public function test_admin_can_create_and_associate_document_from_pending_item(): void
    {
        Storage::fake('local');
        Queue::fake();
        $source = $this->source();
        $batch = IngestionBatch::query()->create(['name' => 'Manual', 'source_id' => $source->id, 'status' => 'COMPLETED', 'total_items' => 1, 'pending_items' => 1]);
        $content = '%PDF-1.4 pending';
        $item = $batch->items()->create(['original_filename' => 'dipirona-paciente.pdf', 'normalized_filename' => 'dipirona-paciente.pdf', 'sha256' => hash('sha256', $content), 'mime_type' => 'application/pdf', 'file_size' => strlen($content), 'storage_path' => "knowledge/pending/{$batch->id}/pending.pdf", 'storage_disk' => 'local', 'storage_key' => "knowledge/pending/{$batch->id}/pending.pdf", 'status' => 'PENDING', 'reason' => 'no_match_found', 'metadata' => []]);
        Storage::disk('local')->put($item->storage_key, $content);

        Livewire::actingAs(User::factory()->admin()->create())->test(Review::class, ['batch' => $batch, 'item' => $item])
            ->set('creatingDocument', true)->set('newDocumentTitle', 'Dipirona Monoidratada')
            ->set('newDocumentType', 'bula_paciente')->set('newDocumentSourceId', 'REG-789')
            ->call('createDocument')->assertSee('Documento associado com sucesso.')->assertSee('Associado');

        $document = KnowledgeDocument::query()->sole();
        $this->assertSame($source->id, $document->source_id);
        $this->assertSame('REG-789', $document->source_document_id);
        $this->assertSame($document->id, $item->refresh()->knowledge_document_id);
        Storage::disk('local')->assertMissing("knowledge/pending/{$batch->id}/pending.pdf");
        Storage::disk('local')->assertExists($document->storage_key);
    }

    public function test_admin_can_create_a_logical_document_without_a_pdf(): void
    {
        $source = $this->source();

        Livewire::actingAs(User::factory()->admin()->create())->test(CreateDocument::class)
            ->set('sourceId', $source->id)->set('title', 'Metformina — Bula profissional')
            ->set('documentType', 'bula_profissional')->set('sourceDocumentId', 'ANVISA-MET-1')
            ->call('save')->assertRedirect();

        $document = KnowledgeDocument::query()->sole();
        $this->assertSame($source->id, $document->source_id);
        $this->assertSame('PENDING', $document->file_status);
        $this->assertNull($document->storage_key);
    }

    private function source(): KnowledgeSource
    {
        return KnowledgeSource::query()->create(['name' => 'ANVISA', 'collection' => 'Bulário Eletrônico', 'organization' => 'Bulário Eletrônico', 'type' => 'regulatory']);
    }

    private function pdf(string $name, string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, '%PDF-1.4 '.$content);
    }
}
