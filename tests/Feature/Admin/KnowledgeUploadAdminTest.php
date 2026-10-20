<?php

namespace Tests\Feature\Admin;

use App\Jobs\ProcessKnowledgeDocument;
use App\Livewire\Admin\Knowledge\Upload;
use App\Models\Knowledge\IngestionBatchItem;
use App\Models\Knowledge\KnowledgeDocument;
use App\Models\Knowledge\KnowledgeSource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Features\SupportTesting\Testable;
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
            ->assertSee('Importação concluída')
            ->assertSee('Ver importação')
            ->assertSee('Revisar pendências')
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

    public function test_admin_upload_has_no_item_count_limit_and_enforces_aggregate_size(): void
    {
        Storage::fake('local');
        Queue::fake();
        $admin = User::factory()->admin()->create();
        $source = $this->source();
        $files = collect(range(1, 16))->map(fn (int $number): UploadedFile => $this->pdf("documento-{$number}.pdf", "conteudo-{$number}"))->all();

        Livewire::actingAs($admin)->test(Upload::class)
            ->set('sourceId', $source->id)
            ->set('files', $files)
            ->call('ingest')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('ingestion_batches', ['total_items' => 16]);

        config()->set('knowledge.ingestion.max_batch_kilobytes', 1);
        Livewire::actingAs($admin)->test(Upload::class)
            ->set('sourceId', $source->id)
            ->set('files', [$this->pdf('grande-a.pdf', str_repeat('a', 700)), $this->pdf('grande-b.pdf', str_repeat('b', 700))])
            ->call('ingest')
            ->assertHasErrors(['files'])
            ->assertSee('O tamanho total dos PDFs não pode ultrapassar o limite de 100 MB por importação.');
    }

    public function test_removing_a_selected_file_keeps_the_others_deletes_its_temporary_upload_and_clears_list_errors(): void
    {
        $admin = User::factory()->admin()->create();
        $component = Livewire::actingAs($admin)->test(Upload::class)
            ->set('files', [$this->pdf('dipirona.pdf', 'a'), UploadedFile::fake()->create('planilha.pdf', 10, 'text/plain'), $this->pdf('paracetamol.pdf', 'c')])
            ->call('ingest')
            ->assertHasErrors(['sourceId', 'files.1']);
        [$firstFile, $wrongFile, $lastFile] = $component->get('files');

        $component->call('removeFile', $wrongFile->getFilename());

        $component->assertHasNoErrors(['files', 'files.*'])
            ->assertHasErrors(['sourceId'])
            ->assertSee('2 arquivos')
            ->assertDontSee('planilha.pdf');
        $this->assertSame(['dipirona.pdf', 'paracetamol.pdf'], $this->selectedFilenames($component));
        $this->assertFalse($wrongFile->exists());
        $this->assertTrue($firstFile->exists());
        $this->assertTrue($lastFile->exists());
    }

    public function test_clearing_the_selection_empties_the_list_and_deletes_every_temporary_upload(): void
    {
        $admin = User::factory()->admin()->create();
        $component = Livewire::actingAs($admin)->test(Upload::class)
            ->set('files', [$this->pdf('dipirona.pdf', 'a'), $this->pdf('paracetamol.pdf', 'b')]);
        [$firstFile, $secondFile] = $component->get('files');

        $component->call('clearFiles');

        $component->assertSet('files', [])->assertDontSee('dipirona.pdf');
        $this->assertFalse($firstFile->exists());
        $this->assertFalse($secondFile->exists());
    }

    public function test_removing_with_an_unknown_or_already_removed_identifier_keeps_the_selection(): void
    {
        $admin = User::factory()->admin()->create();
        $component = Livewire::actingAs($admin)->test(Upload::class)
            ->set('files', [$this->pdf('dipirona.pdf', 'a'), $this->pdf('paracetamol.pdf', 'b'), $this->pdf('ibuprofeno.pdf', 'c')]);
        [$removedFile, $secondFile, $thirdFile] = $component->get('files');
        $component->call('removeFile', $removedFile->getFilename());

        $component->call('removeFile', 0)
            ->call('removeFile', 1)
            ->call('removeFile', 'arquivo-inexistente.pdf')
            ->call('removeFile', $removedFile->getFilename());

        $component->assertHasNoErrors();
        $this->assertSame(['paracetamol.pdf', 'ibuprofeno.pdf'], $this->selectedFilenames($component));
        $this->assertTrue($secondFile->exists());
        $this->assertTrue($thirdFile->exists());
    }

    public function test_importing_after_a_removal_only_imports_the_remaining_files(): void
    {
        Storage::fake('local');
        Queue::fake();
        $admin = User::factory()->admin()->create();
        $source = $this->source();
        $component = Livewire::actingAs($admin)->test(Upload::class)
            ->set('sourceId', $source->id)
            ->set('files', [$this->pdf('dipirona.pdf', 'a'), $this->pdf('escolhido-por-engano.pdf', 'b'), $this->pdf('paracetamol.pdf', 'c')]);
        $component->call('removeFile', $component->get('files')[1]->getFilename());

        $component->call('ingest');

        $component->assertHasNoErrors()->assertSee('Importação concluída');
        $this->assertDatabaseHas('ingestion_batches', ['total_items' => 2]);
        $this->assertSame(['dipirona.pdf', 'paracetamol.pdf'], IngestionBatchItem::query()->orderBy('original_filename')->pluck('original_filename')->all());
    }

    /**
     * @return list<string>
     */
    private function selectedFilenames(Testable $component): array
    {
        return array_map(fn (TemporaryUploadedFile $file): string => $file->getClientOriginalName(), $component->get('files'));
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
