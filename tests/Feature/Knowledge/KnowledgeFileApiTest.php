<?php

namespace Tests\Feature\Knowledge;

use App\Models\Knowledge\IngestionToken;
use App\Models\Knowledge\KnowledgeDocument;
use App\Models\Knowledge\KnowledgeSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class KnowledgeFileApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_pdf_can_be_attached_later_and_stored_privately(): void
    {
        Storage::fake('local');
        [, $plainToken] = IngestionToken::issue('test token', IngestionToken::SCOPE_KNOWLEDGE_INGEST, now()->addDay());
        $document = $this->document();

        $file = UploadedFile::fake()->createWithContent('ibuprofeno.pdf', "%PDF-1.4\nconteudo\n%%EOF");

        $response = $this
            ->withToken($plainToken)
            ->postJson("/api/internal/knowledge/{$document->id}/file", ['file' => $file]);

        $response
            ->assertOk()
            ->assertJsonPath('document_id', $document->id)
            ->assertJsonPath('file_status', 'READY');

        $document->refresh();

        $this->assertSame('ibuprofeno.pdf', $document->original_filename);
        $this->assertSame(hash('sha256', "%PDF-1.4\nconteudo\n%%EOF"), $document->sha256);
        Storage::disk('local')->assertExists("knowledge/originals/{$document->id}/original.pdf");
    }

    public function test_non_pdf_file_is_rejected(): void
    {
        [, $plainToken] = IngestionToken::issue('test token', IngestionToken::SCOPE_KNOWLEDGE_INGEST, now()->addDay());
        $document = $this->document();

        $response = $this
            ->withToken($plainToken)
            ->postJson("/api/internal/knowledge/{$document->id}/file", [
                'file' => UploadedFile::fake()->create('notes.txt', 1, 'text/plain'),
            ]);

        $response->assertUnprocessable();
    }

    public function test_duplicate_pdf_hash_is_rejected(): void
    {
        Storage::fake('local');
        [, $plainToken] = IngestionToken::issue('test token', IngestionToken::SCOPE_KNOWLEDGE_INGEST, now()->addDay());
        $first = $this->document('doc-1');
        $second = $this->document('doc-2');

        $this
            ->withToken($plainToken)
            ->postJson("/api/internal/knowledge/{$first->id}/file", [
                'file' => UploadedFile::fake()->createWithContent('first.pdf', "%PDF-1.4\nsame\n%%EOF"),
            ])
            ->assertOk();

        $this
            ->withToken($plainToken)
            ->postJson("/api/internal/knowledge/{$second->id}/file", [
                'file' => UploadedFile::fake()->createWithContent('second.pdf', "%PDF-1.4\nsame\n%%EOF"),
            ])
            ->assertUnprocessable();
    }

    private function document(string $externalKey = 'anvisa_ibuprofeno_bula_2026'): KnowledgeDocument
    {
        $source = KnowledgeSource::query()->firstOrCreate([
            'name' => 'ANVISA',
            'organization' => 'Agência Nacional de Vigilância Sanitária',
        ]);

        return KnowledgeDocument::query()->create([
            'source_id' => $source->id,
            'external_key' => $externalKey,
            'title' => 'Bula Profissional - Ibuprofeno',
            'document_type' => 'bula',
            'language' => 'pt-BR',
            'metadata' => [],
            'content_status' => 'READY',
            'file_status' => 'PENDING',
            'embedding_status' => 'PENDING',
        ]);
    }
}
