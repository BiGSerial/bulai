<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\Knowledge\Sources\Form;
use App\Livewire\Admin\Knowledge\Sources\Index;
use App\Livewire\Admin\Knowledge\Upload;
use App\Models\Knowledge\KnowledgeDocument;
use App\Models\Knowledge\KnowledgeSource;
use App\Models\User;
use App\Services\Knowledge\KnowledgeSourceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

class KnowledgeSourceAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_source_management_requires_an_admin_session(): void
    {
        $this->get('/admin/knowledge/sources')->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get('/admin/knowledge/sources')->assertForbidden();
        $this->actingAs(User::factory()->admin()->create())->get('/admin/knowledge/sources')->assertOk()->assertSee('Origens dos documentos');
    }

    public function test_admin_creates_and_edits_a_document_origin(): void
    {
        $admin = User::factory()->admin()->create();

        Livewire::actingAs($admin)->test(Form::class)
            ->set('name', 'ANVISA')
            ->set('collection', 'Bulário Eletrônico')
            ->set('type', KnowledgeSource::TYPE_REGULATORY)
            ->set('url', 'https://www.gov.br/anvisa/')
            ->set('country', 'Brasil')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();

        $source = KnowledgeSource::query()->sole();
        $this->assertSame('ANVISA', $source->name);
        $this->assertSame('Bulário Eletrônico', $source->collection);
        $this->assertSame('anvisa', $source->normalized_name);
        $this->assertTrue($source->active);

        Livewire::actingAs($admin)->test(Form::class, ['source' => $source])
            ->set('collection', 'Dados Abertos')
            ->set('description', 'Dataset público')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Dados Abertos', $source->refresh()->collection);
        $this->assertSame('Dataset público', $source->description);
    }

    public function test_source_form_validates_required_fields_url_and_case_insensitive_duplicates(): void
    {
        $admin = User::factory()->admin()->create();
        app(KnowledgeSourceService::class)->create([
            'name' => 'ANVISA',
            'collection' => 'Bulário Eletrônico',
            'type' => KnowledgeSource::TYPE_REGULATORY,
        ]);

        Livewire::actingAs($admin)->test(Form::class)
            ->set('url', 'não é uma url')
            ->call('save')
            ->assertHasErrors(['name', 'collection', 'url'])
            ->assertSee('Informe uma URL válida');

        Livewire::actingAs($admin)->test(Form::class)
            ->set('name', ' anvisa ')
            ->set('collection', 'bulário eletrônico')
            ->set('type', KnowledgeSource::TYPE_REGULATORY)
            ->call('save')
            ->assertHasErrors(['name'])
            ->assertSee('já estão cadastradas');

        $this->assertDatabaseCount('knowledge_sources', 1);
    }

    public function test_deactivation_preserves_historical_documents_and_hides_source_from_upload(): void
    {
        $admin = User::factory()->admin()->create();
        $source = $this->source('ANVISA', 'Bulário Eletrônico');
        $other = $this->source('FDA', 'Drugs@FDA');
        $document = $this->document($source);

        Livewire::actingAs($admin)->test(Index::class)
            ->assertSee('ANVISA — Bulário Eletrônico')
            ->call('toggleActive', $source->id)
            ->assertSee('Inativa');

        $this->assertFalse($source->refresh()->active);
        $this->assertSame($source->id, $document->refresh()->source_id);
        $this->assertSame('ANVISA', $document->source->name);

        Livewire::actingAs($admin)->test(Upload::class)
            ->assertDontSee('ANVISA — Bulário Eletrônico')
            ->assertSee('FDA — Drugs@FDA');
        $this->assertTrue($other->active);
    }

    public function test_quick_source_creation_selects_it_without_losing_uploaded_pdfs(): void
    {
        $admin = User::factory()->admin()->create();
        $file = UploadedFile::fake()->createWithContent('bula.pdf', "%PDF-1.4\nconteúdo\n%%EOF");

        Livewire::actingAs($admin)->test(Upload::class)
            ->set('files', [$file])
            ->call('openSourceModal')
            ->set('newSourceName', 'ANVISA')
            ->set('newSourceCollection', 'Bulário Eletrônico')
            ->set('newSourceType', KnowledgeSource::TYPE_REGULATORY)
            ->call('createSource')
            ->assertHasNoErrors()
            ->assertSet('sourceModalOpen', false)
            ->assertCount('files', 1)
            ->assertSet('sourceId', KnowledgeSource::query()->sole()->id)
            ->assertSee('ANVISA — Bulário Eletrônico');
    }

    private function source(string $name, string $collection): KnowledgeSource
    {
        return app(KnowledgeSourceService::class)->create([
            'name' => $name,
            'collection' => $collection,
            'type' => KnowledgeSource::TYPE_REGULATORY,
        ]);
    }

    private function document(KnowledgeSource $source): KnowledgeDocument
    {
        return KnowledgeDocument::query()->create([
            'source_id' => $source->id,
            'external_key' => 'historical-document',
            'title' => 'Documento histórico',
            'document_type' => 'bula',
            'metadata' => [],
        ]);
    }
}
