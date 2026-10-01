<?php

namespace Tests\Feature\Knowledge;

use App\Models\Knowledge\IngestionToken;
use App\Models\Knowledge\KnowledgeDocument;
use App\Models\Knowledge\KnowledgeSource;
use App\Services\Knowledge\KnowledgeFileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
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
        Storage::disk('local')->assertExists($document->storage_path);
        Storage::disk('local')->assertDirectoryEmpty('knowledge/staging');
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

    public function test_pdf_attachment_does_not_overwrite_an_already_attached_document(): void
    {
        Storage::fake('local');
        [, $plainToken] = IngestionToken::issue('test token', IngestionToken::SCOPE_KNOWLEDGE_INGEST, now()->addDay());
        $document = $this->document();

        $this
            ->withToken($plainToken)
            ->postJson("/api/internal/knowledge/{$document->id}/file", [
                'file' => UploadedFile::fake()->createWithContent('first.pdf', "%PDF-1.4\nfirst\n%%EOF"),
            ])
            ->assertOk();

        $response = $this
            ->withToken($plainToken)
            ->postJson("/api/internal/knowledge/{$document->id}/file", [
                'file' => UploadedFile::fake()->createWithContent('second.pdf', "%PDF-1.4\nsecond\n%%EOF"),
            ]);

        $response->assertUnprocessable();

        $document->refresh();
        $this->assertSame(hash('sha256', "%PDF-1.4\nfirst\n%%EOF"), $document->sha256);
        $this->assertSame('first.pdf', $document->original_filename);
    }

    public function test_stale_document_instance_cannot_overwrite_or_delete_a_concurrent_attachment(): void
    {
        Storage::fake('local');
        $document = $this->document();
        $staleDocument = KnowledgeDocument::query()->findOrFail($document->id);
        $service = app(KnowledgeFileService::class);

        $attachedDocument = $service->attachPdf(
            $document,
            UploadedFile::fake()->createWithContent('winner.pdf', "%PDF-1.4\nwinner\n%%EOF"),
        );
        $winningPath = $attachedDocument->storage_path;

        try {
            $service->attachPdf(
                $staleDocument,
                UploadedFile::fake()->createWithContent('loser.pdf', "%PDF-1.4\nloser\n%%EOF"),
            );
            $this->fail('The stale attachment attempt should have been rejected.');
        } catch (ValidationException) {
            $document->refresh();

            $this->assertSame($winningPath, $document->storage_path);
            $this->assertSame(hash('sha256', "%PDF-1.4\nwinner\n%%EOF"), $document->sha256);
            $this->assertSame("%PDF-1.4\nwinner\n%%EOF", Storage::disk('local')->get($winningPath));
            $this->assertCount(1, Storage::disk('local')->allFiles("knowledge/originals/{$document->id}"));
        }
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
