<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\Documents\Index;
use App\Models\Knowledge\KnowledgeDocument;
use App\Models\Knowledge\KnowledgeSource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class KnowledgeDocumentAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_document_index_lists_document_statuses_chunks_and_hash(): void
    {
        $admin = User::factory()->admin()->create();
        $document = $this->document([
            'title' => 'Bula Profissional - Ibuprofeno',
            'sha256' => str_repeat('a', 64),
        ]);
        $document->chunks()->create([
            'chunk_index' => 0,
            'section' => 'Indicações',
            'content' => 'Conteúdo curado.',
            'metadata' => [],
        ]);

        $response = $this->actingAs($admin)->get('/admin/documents');

        $response
            ->assertOk()
            ->assertSee('Bula Profissional - Ibuprofeno')
            ->assertSee('READY')
            ->assertSee('PENDING')
            ->assertSee('aaaaaaaaaaaaaaaa');
    }

    public function test_admin_document_index_filters_documents_reactively(): void
    {
        $admin = User::factory()->admin()->create();
        $this->document([
            'title' => 'Bula Profissional - Ibuprofeno',
            'external_key' => 'anvisa_ibuprofeno',
            'file_status' => 'READY',
        ]);
        $this->document([
            'title' => 'Bula Profissional - Dipirona',
            'external_key' => 'anvisa_dipirona',
            'file_status' => 'PENDING',
        ]);

        Livewire::actingAs($admin);

        Livewire::test(Index::class)
            ->set('search', 'dipirona')
            ->set('fileStatus', 'PENDING')
            ->assertSee('Bula Profissional - Dipirona')
            ->assertDontSee('Bula Profissional - Ibuprofeno');
    }

    public function test_admin_document_index_can_clear_reactive_filters(): void
    {
        $admin = User::factory()->admin()->create();
        $this->document([
            'title' => 'Bula Profissional - Ibuprofeno',
            'external_key' => 'anvisa_ibuprofeno',
            'file_status' => 'READY',
        ]);

        Livewire::actingAs($admin);

        Livewire::test(Index::class)
            ->set('search', 'sem resultado')
            ->set('fileStatus', 'FAILED')
            ->call('clearFilters')
            ->assertSet('search', '')
            ->assertSet('fileStatus', '')
            ->assertSee('Bula Profissional - Ibuprofeno');
    }

    public function test_admin_document_show_renders_details_and_chunks(): void
    {
        $admin = User::factory()->admin()->create();
        $document = $this->document([
            'title' => 'Bula Profissional - Dipirona',
            'external_key' => 'anvisa_dipirona',
            'original_filename' => 'dipirona.pdf',
            'sha256' => str_repeat('b', 64),
        ]);
        $document->chunks()->create([
            'chunk_index' => 0,
            'section' => 'Contraindicações',
            'page_start' => 4,
            'page_end' => 5,
            'content' => 'Não utilizar em caso de alergia à dipirona.',
            'metadata' => [],
        ]);

        $response = $this->actingAs($admin)->get("/admin/documents/{$document->id}");

        $response
            ->assertOk()
            ->assertSee('anvisa_dipirona')
            ->assertSee('dipirona.pdf')
            ->assertSee(str_repeat('b', 64))
            ->assertSee('Contraindicações')
            ->assertSee('Não utilizar em caso de alergia à dipirona.');
    }

    public function test_admin_document_show_escapes_chunk_content(): void
    {
        $admin = User::factory()->admin()->create();
        $document = $this->document();
        $document->chunks()->create([
            'chunk_index' => 0,
            'section' => 'Segurança',
            'content' => '<script>alert("xss")</script>',
            'metadata' => [],
        ]);

        $response = $this->actingAs($admin)->get("/admin/documents/{$document->id}");

        $response
            ->assertOk()
            ->assertSee('<script>alert("xss")</script>')
            ->assertDontSee('<script>alert("xss")</script>', false);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function document(array $attributes = []): KnowledgeDocument
    {
        $source = KnowledgeSource::query()->firstOrCreate([
            'name' => 'ANVISA',
            'organization' => 'Agência Nacional de Vigilância Sanitária',
        ]);

        return KnowledgeDocument::query()->create(array_merge([
            'source_id' => $source->id,
            'external_key' => 'anvisa_ibuprofeno',
            'title' => 'Bula Profissional - Ibuprofeno',
            'document_type' => 'bula',
            'language' => 'pt-BR',
            'metadata' => [],
            'content_status' => 'READY',
            'file_status' => 'PENDING',
            'embedding_status' => 'PENDING',
        ], $attributes));
    }
}
