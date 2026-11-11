<?php

namespace Tests\Feature\Knowledge;

use App\Jobs\LinkKnowledgeDocumentIngredients;
use App\Livewire\Admin\Documents\Index;
use App\Livewire\Admin\Documents\Show;
use App\Models\Knowledge\ActiveIngredient;
use App\Models\Knowledge\ActiveIngredientAlias;
use App\Models\Knowledge\AnvisaMedicine;
use App\Models\Knowledge\KnowledgeDocument;
use App\Models\Knowledge\KnowledgeProcessingEvent;
use App\Models\Knowledge\KnowledgeSource;
use App\Models\User;
use App\Services\Knowledge\KnowledgeDocumentIngredientLinker;
use App\Services\Knowledge\ProcessingTimeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Vínculo bula ↔ princípio ativo como ação própria: bulas antigas (sem metadados) ligadas pelo nome do produto no
 * catálogo ANVISA, botão na página do documento e ação em massa na lista. É por esse vínculo que o Perguntar acha a bula.
 */
final class KnowledgeIngredientLinkActionsTest extends TestCase
{
    use RefreshDatabase;

    private KnowledgeSource $source;

    private ActiveIngredient $enalapril;

    protected function setUp(): void
    {
        parent::setUp();
        $this->source = KnowledgeSource::query()->create(['name' => 'ANVISA', 'type' => 'document', 'active' => true]);
        $this->enalapril = ActiveIngredient::query()->create(['normalized_name' => 'enalapril', 'canonical_name' => 'Enalapril']);
        $medicine = AnvisaMedicine::factory()->create(['product_name' => 'RENITEC', 'normalized_product_name' => 'renitec', 'active_ingredient' => 'MALEATO DE ENALAPRIL', 'normalized_active_ingredient' => 'maleato de enalapril']);
        DB::table('anvisa_medicine_ingredients')->insert(['medicine_id' => $medicine->id, 'ingredient_id' => $this->enalapril->id, 'component_text' => 'maleato de enalapril', 'method' => 'rule', 'confidence' => 0.9, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_a_leaflet_without_metadata_is_linked_through_the_product_name_in_the_anvisa_catalog(): void
    {
        $document = $this->document('RENITEC® — Bula do profissional de saúde');

        $this->assertSame(1, app(KnowledgeDocumentIngredientLinker::class)->link($document));

        $link = DB::table('knowledge_document_ingredients')->where('document_id', $document->id)->sole();
        $this->assertSame([$this->enalapril->id, 'catalog'], [(int) $link->ingredient_id, $link->method]);
        $this->assertSame(0, app(KnowledgeDocumentIngredientLinker::class)->link($this->document('XYZINEXISTENTE — Bula do paciente')), 'produto fora do catálogo continua sem vínculo');
    }

    public function test_a_leaflet_with_a_generic_title_is_linked_by_the_product_name_in_its_first_lines(): void
    {
        ActiveIngredientAlias::query()->create(['ingredient_id' => $this->enalapril->id, 'alias' => 'enalapril', 'normalized_alias' => 'enalapril', 'language' => 'pt', 'origin' => 'curated', 'review_status' => 'approved']);
        $document = $this->document('Bula 1790900554429');
        DB::table('knowledge_chunks')->where('document_id', $document->id)->delete();
        DB::table('knowledge_chunks')->insert([
            ['document_id' => $document->id, 'chunk_index' => 0, 'section' => 'CAPA', 'page_start' => 1, 'page_end' => 1, 'content' => "500mg\n\nMedicamento genérico, Lei nº 9.787 de 1999", 'created_at' => now(), 'updated_at' => now()],
            ['document_id' => $document->id, 'chunk_index' => 1, 'section' => 'APRESENTAÇÕES', 'page_start' => 1, 'page_end' => 1, 'content' => "enalapril\n\nTexto longo qualquer da bula, com mais de oitenta caracteres numa mesma linha, que nunca é candidata a nome de produto.", 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->assertSame(1, app(KnowledgeDocumentIngredientLinker::class)->link($document));

        $link = DB::table('knowledge_document_ingredients')->where('document_id', $document->id)->sole();
        $this->assertSame([$this->enalapril->id, 'content'], [(int) $link->ingredient_id, $link->method]);
    }

    public function test_a_three_letter_abbreviation_in_the_metadata_does_not_create_an_active_ingredient(): void
    {
        $document = $this->document('Omeprazol — Bula');
        $document->forceFill(['metadata' => ['active_ingredient' => 'IBP']])->save();

        app(KnowledgeDocumentIngredientLinker::class)->link($document);

        $this->assertDatabaseMissing('active_ingredients', ['normalized_name' => 'ibp']);
    }

    public function test_document_page_warns_when_nothing_is_linked_and_links_on_demand(): void
    {
        $admin = User::factory()->admin()->create();
        $document = $this->document('RENITEC® — Bula do profissional de saúde');

        Livewire::actingAs($admin)->test(Show::class, ['document' => $document])
            ->assertSee('Nenhum princípio ativo ligado a esta bula.')
            ->assertSee('data-testid="link-ingredients"', false)
            ->assertSee('Revincular princípios ativos')
            ->call('linkIngredients')
            ->assertSee('1 princípio ativo ligado. O Perguntar já encontra esta bula.')
            ->assertDontSee('data-testid="link-ingredients"', false)
            ->assertSee('Enalapril');

        $event = app(ProcessingTimeline::class)->for($document->id)->last();
        $this->assertSame([KnowledgeProcessingEvent::STEP_LINK, 'done', 1], [$event->step, $event->state, $event->detail['ingredients']]);

        $unknown = $this->document('XYZINEXISTENTE — Bula do paciente');
        Livewire::actingAs($admin)->test(Show::class, ['document' => $unknown])
            ->call('linkIngredients')
            ->assertSee('Nenhum princípio ativo encontrado para esta bula')
            ->assertSee('data-testid="link-ingredients"', false);
    }

    public function test_the_admin_fixes_a_wrong_link_and_the_automatic_link_keeps_the_correction(): void
    {
        $admin = User::factory()->admin()->create();
        $document = $this->document('RENITEC® — Bula do profissional de saúde');
        app(KnowledgeDocumentIngredientLinker::class)->link($document);

        $page = Livewire::actingAs($admin)->test(Show::class, ['document' => $document])
            ->assertSee('Princípios ativos (1)')->assertSee('automático · catalog')
            ->call('detachIngredient', $this->enalapril->id)->assertSee('Enalapril não está mais ligado a esta bula.')
            ->set('ingredientSearch', 'dipi')->assertSee('Criar o princípio ativo “dipi” e ligar')
            ->set('ingredientSearch', 'Dipirona Monoidratada')->call('createIngredient')
            ->assertSee('Dipirona ligado a esta bula.');
        $dipirona = ActiveIngredient::query()->where('normalized_name', 'dipirona')->sole();

        $page->set('ingredientSearch', 'enala')->assertSee('Enalapril')->call('attachIngredient', $this->enalapril->id)->call('detachIngredient', $this->enalapril->id);
        app(KnowledgeDocumentIngredientLinker::class)->link($document->fresh());

        $this->assertSame([$dipirona->id => 'manual'], DB::table('knowledge_document_ingredients')->where('document_id', $document->id)->pluck('method', 'ingredient_id')->all(), 'o tirado à mão não volta e o ligado à mão fica');
        $this->assertSame([$this->enalapril->id], $document->fresh()->metadata['ingredient_links']['excluded']);
    }

    public function test_documents_show_the_medicine_name_and_its_identity_can_be_corrected(): void
    {
        $admin = User::factory()->admin()->create();
        $document = $this->document('Bula 1793933368611');
        $document->chunks()->update(['content' => "IDENTIFICAÇÃO DO MEDICAMENTO\nRENITEC\nmaleato de enalapril"]);

        Livewire::actingAs($admin)->test(Index::class)->assertSee('Medicamento não identificado')->assertSee('Sem princípio ativo ligado');
        Livewire::actingAs($admin)->test(Show::class, ['document' => $document])
            ->assertSee('Identificação')->assertSee('IDENTIFICAÇÃO DO MEDICAMENTO')
            ->call('editIdentity')->assertSet('identity.product_name', 'RENITEC')
            ->set('identity.product_name', '')->set('identity.title', '')->call('saveIdentity')->assertHasErrors(['identity.product_name', 'identity.title'])
            ->set('identity.product_name', 'RENITEC')->set('identity.title', 'RENITEC — Bula do profissional de saúde')->set('identity.document_type', 'bula_paciente')
            ->call('saveIdentity')->assertHasNoErrors()->assertSee('Identificação salva. Princípios ativos ligados: 1.')->assertSet('editingIdentity', false);

        $document->refresh();
        $this->assertSame(['RENITEC', 'bula_paciente', 'RENITEC — Bula do profissional de saúde'], [$document->metadata['product_name'], $document->document_type, $document->title]);
        Livewire::actingAs($admin)->test(Index::class)->set('search', 'enalapril')->assertSee('RENITEC')->assertSee('Enalapril')->assertDontSee('Medicamento não identificado');
    }

    public function test_the_admin_opens_the_stored_pdf_and_gets_a_name_suggested_from_the_text(): void
    {
        Storage::fake('knowledge');
        config(['knowledge.filesystem_disk' => 'knowledge']);
        $admin = User::factory()->admin()->create();
        $document = $this->document('Bula 1790900554429');
        $document->chunks()->update(['content' => "500mg\nRENITEC\nUSO ORAL"]);
        Storage::disk('knowledge')->put("knowledge/originals/{$document->id}/bula.pdf", '%PDF-1.4 bula');
        $document->forceFill(['storage_disk' => 'knowledge', 'storage_key' => "knowledge/originals/{$document->id}/bula.pdf", 'original_filename' => 'bula_1790900554429.pdf'])->save();

        Livewire::actingAs($admin)->test(Show::class, ['document' => $document])
            ->assertSee('Pelo texto, parece ser RENITEC')->assertSee(route('admin.knowledge.documents.pdf', $document))->assertSee('data-testid="pdf-viewer"', false)
            ->call('editIdentity')->assertSet('identity.product_name', 'RENITEC');
        $this->actingAs($admin)->get(route('admin.knowledge.documents.pdf', $document))->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertStreamedContent('%PDF-1.4 bula');
        $this->actingAs(User::factory()->create())->get(route('admin.knowledge.documents.pdf', $document))->assertForbidden();
        $this->actingAs($admin)->get(route('admin.knowledge.documents.pdf', $this->document('Sem arquivo')))->assertNotFound();
    }

    public function test_documents_list_queues_the_link_of_every_ready_document_without_ingredients(): void
    {
        Queue::fake([LinkKnowledgeDocumentIngredients::class]);
        $admin = User::factory()->admin()->create();
        $unlinked = $this->document('RENITEC® — Bula do profissional de saúde');
        $linked = $this->document('Outra — Bula');
        $linked->ingredients()->attach($this->enalapril->id, ['method' => 'metadata', 'confidence' => 0.9]);
        $this->document('Processando — Bula', contentStatus: 'PROCESSING');

        Livewire::actingAs($admin)->test(Index::class)
            ->assertSee('Vincular 1 documento sem princípio ativo')
            ->call('linkUnlinkedDocuments')
            ->assertSee('Vínculo de 1 documento enfileirado.');

        Queue::assertPushed(LinkKnowledgeDocumentIngredients::class, 1);
        Queue::assertPushed(LinkKnowledgeDocumentIngredients::class, fn (LinkKnowledgeDocumentIngredients $job): bool => $job->documentId === $unlinked->id);

        (new LinkKnowledgeDocumentIngredients($unlinked->id))->handle(app(KnowledgeDocumentIngredientLinker::class), app(ProcessingTimeline::class));
        $this->assertSame(['Enalapril'], $unlinked->ingredients()->pluck('canonical_name')->all());
        $this->assertSame('done', app(ProcessingTimeline::class)->for($unlinked->id)->last()->state);
    }

    private function document(string $title, string $contentStatus = 'READY'): KnowledgeDocument
    {
        $document = KnowledgeDocument::query()->create([
            'source_id' => $this->source->id, 'external_key' => uniqid('doc_'), 'title' => $title, 'document_type' => 'bula_profissional',
            'language' => 'pt-BR', 'metadata' => [], 'content_status' => $contentStatus, 'file_status' => 'READY', 'embedding_status' => 'READY',
        ]);
        $document->chunks()->create(['chunk_index' => 0, 'section' => 'S', 'content' => 'texto', 'metadata' => []]);

        return $document;
    }
}
