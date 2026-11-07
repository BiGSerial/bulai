<?php

namespace Tests\Feature\Knowledge;

use App\Jobs\LinkKnowledgeDocumentIngredients;
use App\Livewire\Admin\Documents\Index;
use App\Livewire\Admin\Documents\Show;
use App\Models\Knowledge\ActiveIngredient;
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
