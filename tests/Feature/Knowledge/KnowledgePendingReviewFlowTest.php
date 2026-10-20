<?php

namespace Tests\Feature\Knowledge;

use App\Livewire\Admin\Knowledge\Pending\Review;
use App\Models\Knowledge\IngestionBatch;
use App\Models\Knowledge\IngestionBatchItem;
use App\Models\Knowledge\KnowledgeSource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

final class KnowledgePendingReviewFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_item_shows_a_guided_summary_and_how_to_finish(): void
    {
        [$batch, $item] = $this->items();

        Livewire::actingAs(User::factory()->admin()->create())->test(Review::class, ['batch' => $batch, 'item' => $item])
            ->assertSee('Próximo passo')
            ->assertSee('Criar documento com estes dados e finalizar')
            ->assertSee('Associar a documento existente')
            ->assertSee('Não incorporar')
            ->assertSee('Conferir os dados')
            ->assertSee('Princípio ativo');
    }

    public function test_finishing_from_the_summary_creates_the_document_and_offers_the_next_pending_item(): void
    {
        [$batch, $item, $other] = $this->items();

        Livewire::actingAs(User::factory()->admin()->create())->test(Review::class, ['batch' => $batch, 'item' => $item])
            ->set('newDocumentTitle', 'Omeprazol — Bula do paciente')
            ->call('createDocument')
            ->assertSee('Pendência concluída')
            ->assertSee('Abrir o documento')
            ->assertSee('Próxima pendência (1 restante)')
            ->assertSee(route('admin.knowledge.pending.review', [$batch, $other]), false);

        $this->assertSame('ASSOCIATED', $item->refresh()->status);
    }

    public function test_ignoring_also_ends_in_the_completion_card_with_a_way_back_to_the_queue(): void
    {
        [$batch, $item] = $this->items(withOther: false);

        Livewire::actingAs(User::factory()->admin()->create())->test(Review::class, ['batch' => $batch, 'item' => $item])
            ->set('ignoreReason', 'not_leaflet')
            ->call('ignore')
            ->assertSee('não será incorporado à base')
            ->assertSee('Voltar à fila')
            ->assertDontSee('Próxima pendência');
    }

    public function test_long_histories_are_collapsed_behind_a_toggle(): void
    {
        [$batch, $item] = $this->items();
        $metadata = $item->metadata;
        $metadata['audit_history'] = array_map(fn (int $number): array => ['event' => 'reanalysis_requested', 'occurred_at' => now()->toISOString(), 'actor_name' => 'Admin '.$number], range(1, 9));
        $item->forceFill(['metadata' => $metadata])->save();

        Livewire::actingAs(User::factory()->admin()->create())->test(Review::class, ['batch' => $batch, 'item' => $item->refresh()])
            ->assertSee('Mostrar todos (9)');
    }

    public function test_navigation_exposes_the_anvisa_catalog_page_and_explains_each_item(): void
    {
        $this->actingAs(User::factory()->admin()->create())->get(route('admin.knowledge.pending.index'))
            ->assertOk()
            ->assertSee('Catálogo ANVISA')
            ->assertSee('Lista oficial de medicamentos usada para validar bulas')
            ->assertSee('Ir para o conteúdo');
    }

    /** @return array{IngestionBatch, IngestionBatchItem, IngestionBatchItem|null} */
    private function items(bool $withOther = true): array
    {
        $source = KnowledgeSource::query()->create(['name' => 'ANVISA', 'organization' => 'ANVISA', 'active' => true]);
        $batch = IngestionBatch::query()->create(['name' => 'Lote', 'source_id' => $source->id, 'status' => 'COMPLETED', 'total_items' => 2, 'pending_items' => 2]);
        Storage::fake('knowledge');
        $make = function (string $name) use ($batch): IngestionBatchItem {
            $key = "knowledge/pending/{$batch->id}/{$name}";
            Storage::disk('knowledge')->put($key, 'x');

            return $batch->items()->create(['original_filename' => $name, 'normalized_filename' => $name, 'sha256' => hash('sha256', 'x'), 'mime_type' => 'application/pdf', 'file_size' => 1,
                'storage_disk' => 'knowledge', 'storage_key' => $key, 'storage_path' => $key, 'status' => 'PENDING', 'reason' => 'no_match_found',
                'metadata' => ['preliminary_identification' => ['score' => 90, 'fields' => ['product_name' => 'omeprazol', 'active_ingredient' => 'omeprazol sódico', 'document_type' => 'bula_paciente', 'title' => 'Omeprazol — Bula do paciente']]]]);
        };

        return [$batch, $make('a.pdf'), $withOther ? $make('b.pdf') : null];
    }
}
