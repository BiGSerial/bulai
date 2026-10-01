<?php

namespace Tests\Feature\Knowledge;

use App\Models\Knowledge\IngestionToken;
use App\Models\Knowledge\KnowledgeDocument;
use App\Models\Knowledge\KnowledgeSource;
use App\Services\Knowledge\KnowledgeSourceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class KnowledgeSourceIngestionTest extends TestCase
{
    use RefreshDatabase;

    public function test_batch_ingestion_associates_every_document_with_the_selected_origin(): void
    {
        Storage::fake('local');
        Queue::fake();
        $source = $this->source();
        $first = $this->document($source, 'primeiro.pdf', 'first');
        $second = $this->document($source, 'segundo.pdf', 'second');
        [, $token] = IngestionToken::issue('source test', IngestionToken::SCOPE_KNOWLEDGE_INGEST, now()->addHour());

        $this->withToken($token)->postJson('/api/internal/knowledge/batches', [
            'batch' => ['name' => 'Lote ANVISA', 'source_id' => $source->id],
            'items' => [
                ['file' => $this->pdf('primeiro.pdf', 'primeiro')],
                ['file' => $this->pdf('segundo.pdf', 'segundo')],
            ],
        ])->assertCreated()->assertJsonPath('associated_items', 2);

        $this->assertSame($source->id, $first->refresh()->source_id);
        $this->assertSame($source->id, $second->refresh()->source_id);
        $this->assertDatabaseHas('ingestion_batches', ['source_id' => $source->id, 'total_items' => 2]);
    }

    public function test_batch_endpoint_rejects_unknown_and_inactive_origins(): void
    {
        $source = $this->source();
        $source->forceFill(['active' => false])->save();
        [, $token] = IngestionToken::issue('source test', IngestionToken::SCOPE_KNOWLEDGE_INGEST, now()->addHour());

        $this->withToken($token)->postJson('/api/internal/knowledge/batches', [
            'batch' => ['name' => 'Inválido', 'source_id' => 999999],
            'items' => [['file' => $this->pdf('um.pdf', 'um')]],
        ])->assertUnprocessable()->assertJsonValidationErrors('batch.source_id');

        $this->withToken($token)->postJson('/api/internal/knowledge/batches', [
            'batch' => ['name' => 'Inativo', 'source_id' => $source->id],
            'items' => [['file' => $this->pdf('dois.pdf', 'dois')]],
        ])->assertUnprocessable()->assertJsonValidationErrors('batch.source_id');
    }

    public function test_document_from_another_origin_is_not_associated_by_filename(): void
    {
        Storage::fake('local');
        $anvisa = $this->source();
        $fda = app(KnowledgeSourceService::class)->create(['name' => 'FDA', 'collection' => 'Drugs@FDA', 'type' => KnowledgeSource::TYPE_REGULATORY]);
        $this->document($fda, 'mesmo.pdf', 'fda-document');
        [, $token] = IngestionToken::issue('source test', IngestionToken::SCOPE_KNOWLEDGE_INGEST, now()->addHour());

        $this->withToken($token)->postJson('/api/internal/knowledge/batches', [
            'batch' => ['name' => 'ANVISA', 'source_id' => $anvisa->id],
            'items' => [['file' => $this->pdf('mesmo.pdf', 'anvisa-file')]],
        ])->assertCreated()->assertJsonPath('pending_items', 1)->assertJsonPath('items.0.knowledge_document_id', null);
    }

    private function source(): KnowledgeSource
    {
        return app(KnowledgeSourceService::class)->create([
            'name' => 'ANVISA',
            'collection' => 'Bulário Eletrônico',
            'type' => KnowledgeSource::TYPE_REGULATORY,
        ]);
    }

    private function document(KnowledgeSource $source, string $filename, string $externalKey): KnowledgeDocument
    {
        return KnowledgeDocument::query()->create([
            'source_id' => $source->id,
            'external_key' => $externalKey,
            'title' => $externalKey,
            'document_type' => 'bula',
            'normalized_filename' => $filename,
            'metadata' => [],
        ]);
    }

    private function pdf(string $filename, string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($filename, "%PDF-1.4\n{$content}\n%%EOF");
    }
}
