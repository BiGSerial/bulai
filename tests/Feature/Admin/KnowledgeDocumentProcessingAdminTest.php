<?php

namespace Tests\Feature\Admin;

use App\Jobs\ProcessKnowledgeDocument;
use App\Livewire\Admin\Documents\Show;
use App\Models\Knowledge\KnowledgeDocument;
use App\Models\Knowledge\KnowledgeSource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class KnowledgeDocumentProcessingAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_inspect_traceable_chunks_without_private_paths(): void
    {
        $admin = User::factory()->admin()->create();
        $document = $this->document([
            'content_status' => 'READY',
            'storage_path' => 'knowledge/originals/1/private-file.pdf',
            'processed_at' => now(),
            'metadata' => ['processing' => ['pages' => 2, 'chunks' => 1]],
        ]);
        $document->chunks()->create([
            'chunk_index' => 0,
            'section' => 'CONTRAINDICAÇÕES',
            'page_start' => 4,
            'page_end' => 5,
            'content' => 'Não utilizar em caso de hipersensibilidade.',
            'metadata' => ['section_original' => '7. CONTRAINDICAÇÕES'],
        ]);

        $response = $this->actingAs($admin)->get(route('admin.knowledge.documents.show', $document));

        $response->assertOk()
            ->assertSee('Trechos (1)')
            ->assertSee('CONTRAINDICAÇÕES')
            ->assertSee('páginas 4-5')
            ->assertSee('Não utilizar em caso de hipersensibilidade.')
            ->assertDontSee('knowledge/originals/1/private-file.pdf');
    }

    public function test_admin_action_dispatches_processing_and_reprocessing_with_confirmation_markup(): void
    {
        Queue::fake([ProcessKnowledgeDocument::class]);
        $admin = User::factory()->admin()->create();
        $document = $this->document(['storage_path' => 'knowledge/originals/1/file.pdf']);

        Livewire::actingAs($admin)->test(Show::class, ['document' => $document])
            ->assertSee('Processar documento')
            ->assertSee('Iniciar o processamento deste documento?')
            ->call('processDocument')
            ->assertSee('Processamento enfileirado.');

        Queue::assertPushed(ProcessKnowledgeDocument::class, fn (ProcessKnowledgeDocument $job): bool => $job->documentId === $document->id && ! $job->force);
    }

    public function test_admin_shows_only_controlled_processing_error(): void
    {
        $admin = User::factory()->admin()->create();
        $document = $this->document([
            'content_status' => 'FAILED',
            'metadata' => ['processing' => ['error_code' => 'TEXT_EXTRACTION_FAILED', 'error_id' => 'safe-reference']],
        ]);

        $response = $this->actingAs($admin)->get(route('admin.knowledge.documents.show', $document));

        $response->assertOk()
            ->assertSee('TEXT_EXTRACTION_FAILED')
            ->assertDontSee('SQLSTATE')
            ->assertDontSee('/srv/private');
    }

    /** @param array<string, mixed> $attributes */
    private function document(array $attributes = []): KnowledgeDocument
    {
        $source = KnowledgeSource::query()->create(['name' => 'ANVISA', 'organization' => 'ANVISA']);

        return KnowledgeDocument::query()->create(array_merge([
            'source_id' => $source->id,
            'external_key' => 'admin-processing-document',
            'title' => 'Bula para processamento',
            'document_type' => 'bula',
            'metadata' => [],
            'content_status' => 'PENDING',
            'file_status' => 'READY',
            'embedding_status' => 'PENDING',
        ], $attributes));
    }
}
