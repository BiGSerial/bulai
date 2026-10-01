<?php

namespace Tests\Feature\Admin;

use App\Jobs\ProcessKnowledgeDocument;
use App\Livewire\Admin\Knowledge\Upload;
use App\Models\Knowledge\KnowledgeDocument;
use App\Models\Knowledge\KnowledgeSource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class KnowledgeUploadAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_upload_page_requires_an_admin_session(): void
    {
        $this->get('/admin/knowledge/upload')->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get('/admin/knowledge/upload')->assertForbidden();
        $this->actingAs(User::factory()->admin()->create())->get('/admin/knowledge/upload')->assertOk()->assertSee('Adicionar documentos');
    }

    public function test_admin_uploads_multiple_pdfs_through_the_batch_service_and_sees_summary(): void
    {
        Storage::fake('local');
        Queue::fake();
        $admin = User::factory()->admin()->create();
        $source = $this->source();
        $document = $this->document($source, 'dipirona.pdf');

        Livewire::actingAs($admin)->test(Upload::class)
            ->set('sourceId', $source->id)
            ->set('files', [$this->pdf('dipirona.pdf', 'associado'), $this->pdf('sem-cadastro.pdf', 'pendente')])
            ->call('ingest')
            ->assertHasNoErrors()
            ->assertSee('Ingestão concluída')
            ->assertSee('Ver batch')
            ->assertSee('Revisar pendentes')
            ->assertSet('summary.ASSOCIATED', 1)
            ->assertSet('summary.PENDING', 1);

        $this->assertNotNull($document->refresh()->storage_key);
        $this->assertSame('local', $document->storage_disk);
        $this->assertStringStartsWith("knowledge/originals/{$document->id}/", $document->storage_key);
        $this->assertFalse(str_starts_with($document->storage_key, '/'));
        Queue::assertPushed(ProcessKnowledgeDocument::class, fn (ProcessKnowledgeDocument $job): bool => $job->documentId === $document->id);
    }

    public function test_upload_validates_source_pdf_type_and_limits_without_exposing_paths(): void
    {
        Storage::fake('local');
        $admin = User::factory()->admin()->create();
        $source = $this->source();

        Livewire::actingAs($admin)->test(Upload::class)
            ->set('files', [UploadedFile::fake()->create('malicioso.php.pdf', 10, 'text/plain')])
            ->call('ingest')
            ->assertHasErrors(['sourceId', 'files.0'])
            ->assertSee('Selecione a origem dos documentos.')
            ->assertDontSee(storage_path())
            ->assertDontSee('storage_path');

        $this->assertDatabaseCount('ingestion_batches', 0);
    }

    private function source(): KnowledgeSource
    {
        return KnowledgeSource::query()->create(['name' => 'ANVISA', 'organization' => 'ANVISA', 'active' => true]);
    }

    private function document(KnowledgeSource $source, string $filename): KnowledgeDocument
    {
        return KnowledgeDocument::query()->create([
            'source_id' => $source->id,
            'external_key' => 'doc-'.fake()->uuid(),
            'title' => 'Documento alvo',
            'document_type' => 'bula',
            'normalized_filename' => $filename,
            'metadata' => [],
            'content_status' => KnowledgeDocument::STATUS_PENDING,
            'file_status' => KnowledgeDocument::STATUS_PENDING,
            'embedding_status' => KnowledgeDocument::STATUS_PENDING,
        ]);
    }

    private function pdf(string $name, string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n{$content}\n%%EOF");
    }
}
