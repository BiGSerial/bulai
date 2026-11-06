<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\Documents\Index as DocumentsIndex;
use App\Livewire\Admin\Knowledge\Batches\Progress;
use App\Livewire\Admin\Knowledge\Batches\Show as BatchShow;
use App\Livewire\Admin\Knowledge\Pending\Index as PendingIndex;
use App\Livewire\Admin\Knowledge\Pending\Review;
use App\Models\Knowledge\IngestionBatch;
use App\Models\Knowledge\IngestionBatchItem;
use App\Models\Knowledge\KnowledgeDocument;
use App\Models\Knowledge\KnowledgeSource;
use App\Models\User;
use App\Services\Knowledge\KnowledgePendingItemAssociationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class KnowledgeAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_knowledge_admin_requires_an_authenticated_admin_session(): void
    {
        $this->get('/admin/knowledge')->assertRedirect('/login');
        $this->withToken('api-token')->get('/admin/knowledge')->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get('/admin/knowledge')->assertForbidden();
        $this->actingAs(User::factory()->admin()->create())->get('/admin/knowledge')->assertOk()->assertSee('Navegação principal');
    }

    public function test_dashboard_displays_counts_from_the_database(): void
    {
        $source = $this->source();
        $this->document($source, ['storage_path' => 'knowledge/originals/1/file.pdf']);
        $this->document($source, ['external_key' => 'without-file']);
        $batch = $this->batch($source);
        $this->item($batch);
        $this->item($batch, ['status' => 'ERROR', 'original_filename' => 'failed.pdf']);

        $this->actingAs(User::factory()->admin()->create())->get('/admin/knowledge')
            ->assertOk()->assertSee('1 com PDF · 1 sem PDF')
            ->assertSee('Precisa de atenção')->assertSee('arquivos aguardando sua revisão')->assertSee('arquivos com erro nas importações')
            ->assertSee('Operação')->assertSee('Uso do aplicativo')->assertSee('Inteligência artificial')->assertSee('Pessoas');
    }

    public function test_document_search_supports_title_and_external_key_and_uses_pagination(): void
    {
        $admin = User::factory()->admin()->create();
        $source = $this->source();
        $this->document($source, ['title' => 'Dipirona exclusiva', 'external_key' => 'anvisa-dipi']);
        $this->document($source, ['title' => 'Ibuprofeno exclusivo', 'external_key' => 'anvisa-ibu']);
        // 25 por página: com 26 documentos a lista tem uma segunda página.
        foreach (range(1, 24) as $number) {
            $this->document($source, ['external_key' => "page-{$number}", 'title' => "Documento {$number}"]);
        }

        Livewire::actingAs($admin)->test(DocumentsIndex::class)->set('search', 'Dipirona')->assertSee('Dipirona exclusiva')->assertDontSee('Ibuprofeno exclusivo');
        Livewire::actingAs($admin)->test(DocumentsIndex::class)->set('search', 'anvisa-ibu')->assertSee('Ibuprofeno exclusivo');
        Livewire::actingAs($admin)->test(DocumentsIndex::class)->assertViewHas('documents', fn ($documents): bool => $documents->hasPages());
    }

    public function test_batch_and_global_pending_pages_show_only_actionable_items(): void
    {
        $admin = User::factory()->admin()->create();
        $batch = $this->batch($this->source(), ['pending_items' => 1, 'duplicate_items' => 1, 'total_items' => 2]);
        $this->item($batch, ['original_filename' => 'visivel.pdf']);
        $this->item($batch, ['original_filename' => 'resolvido.pdf', 'status' => 'DUPLICATE']);

        Livewire::actingAs($admin)->test(BatchShow::class, ['batch' => $batch])->assertSee('visivel.pdf')->assertSee('resolvido.pdf')->assertSee('Revisar');
        Livewire::actingAs($admin)->test(PendingIndex::class)->assertSee('visivel.pdf')->assertDontSee('resolvido.pdf')->assertSee('Revisar');
    }

    public function test_review_selection_requires_confirmation_and_success_refreshes_the_interface(): void
    {
        Storage::fake('local');
        $admin = User::factory()->admin()->create();
        $source = $this->source();
        $batch = $this->batch($source, ['total_items' => 1, 'pending_items' => 1]);
        $content = '%PDF-1.4 manual review';
        $item = $this->item($batch, ['sha256' => hash('sha256', $content), 'file_size' => strlen($content)]);
        Storage::disk('local')->put($item->storage_path, $content);
        $document = $this->document($source, ['title' => 'Destino manual']);

        $component = Livewire::actingAs($admin)->test(Review::class, ['batch' => $batch, 'item' => $item])
            ->set('documentSearch', 'Destino')->assertSee('Destino manual')
            ->call('selectDocument', $document->id)->assertSee('Confirme a associação');
        $this->assertSame('PENDING', $item->refresh()->status);

        $component->call('associate')->assertSee('Documento associado com sucesso.')->assertSee('Associado')->assertDontSee('Associar documento');
        $this->assertSame('ASSOCIATED', $item->refresh()->status);
        $this->assertNotNull($document->refresh()->storage_path);
        $this->assertSame(0, $batch->refresh()->pending_items);

        $definitiveFiles = Storage::disk('local')->allFiles("knowledge/originals/{$document->id}");
        $component->call('selectDocument', $document->id)->call('associate')->assertSee('já foi resolvido');
        $this->assertSame($definitiveFiles, Storage::disk('local')->allFiles("knowledge/originals/{$document->id}"));
        $this->assertSame(0, $batch->refresh()->pending_items);
    }

    public function test_review_controls_conflicts_errors_and_private_metadata(): void
    {
        $admin = User::factory()->admin()->create();
        $source = $this->source();
        $batch = $this->batch($source);
        $item = $this->item($batch, ['metadata' => ['label' => 'Lote legado', 'private_path' => '/srv/private/secret.pdf']]);
        $document = $this->document($source);
        $service = $this->mock(KnowledgePendingItemAssociationService::class);
        $service->shouldReceive('associate')->once()->andReturn(['status' => 'ERROR', 'document_id' => null, 'file_attached' => false, 'error_code' => 'PROCESSING_ERROR', 'error_id' => 'safe-123', 'http_status' => 500]);

        Livewire::actingAs($admin)->test(Review::class, ['batch' => $batch, 'item' => $item])
            ->assertSee('Lote legado')->assertDontSee('/srv/private/secret.pdf')->assertDontSee('storage_path')
            ->call('selectDocument', $document->id)->call('associate')
            ->assertSee('Não foi possível concluir a operação.')->assertSee('safe-123')->assertDontSee('SQLSTATE');
    }

    #[DataProvider('controlledAssociationResults')]
    public function test_review_translates_controlled_service_results(string $status, ?string $errorCode, string $message): void
    {
        $admin = User::factory()->admin()->create();
        $source = $this->source();
        $batch = $this->batch($source);
        $item = $this->item($batch);
        $document = $this->document($source);
        $service = $this->mock(KnowledgePendingItemAssociationService::class);
        $service->shouldReceive('associate')->once()->andReturn([
            'status' => $status,
            'document_id' => $document->id,
            'file_attached' => false,
            'error_code' => $errorCode,
            'error_id' => null,
            'http_status' => $status === 'DUPLICATE' ? 200 : 409,
        ]);

        Livewire::actingAs($admin)->test(Review::class, ['batch' => $batch, 'item' => $item])
            ->call('selectDocument', $document->id)->call('associate')->assertSee($message);
    }

    /** @return array<string, array{string, ?string, string}> */
    public static function controlledAssociationResults(): array
    {
        return [
            'already resolved' => ['ALREADY_RESOLVED', 'ASSOCIATED', 'já foi resolvido'],
            'document occupied' => ['CONFLICT', 'DOCUMENT_ALREADY_HAS_FILE', 'já possui um arquivo'],
            'duplicate' => ['DUPLICATE', null, 'marcado como duplicado'],
            'missing pending file' => ['CONFLICT', 'PENDING_FILE_MISSING', 'não está disponível'],
            'invalid pending file' => ['CONFLICT', 'PENDING_FILE_INVALID', 'não corresponde mais'],
        ];
    }

    public function test_review_has_one_primary_action_and_offers_the_next_pending_item_after_a_decision(): void
    {
        Storage::fake('local');
        $admin = User::factory()->admin()->create();
        $source = $this->source();
        $batch = $this->batch($source, ['total_items' => 2, 'pending_items' => 2]);
        $content = '%PDF-1.4 primeiro';
        $item = $this->item($batch, ['original_filename' => 'primeiro.pdf', 'sha256' => hash('sha256', $content), 'file_size' => strlen($content), 'metadata' => ['preliminary_identification' => ['fields' => ['product_name' => 'Sinvastatina', 'active_ingredient' => 'SINVASTATINA', 'title' => 'Sinvastatina — Bula', 'document_type' => 'bula_paciente']]]]);
        Storage::disk('local')->put($item->storage_path, $content);
        $next = $this->item($batch, ['original_filename' => 'segundo.pdf']);

        $pending = Livewire::actingAs($admin)->test(Review::class, ['batch' => $batch, 'item' => $item]);
        $html = $pending->html();
        $this->assertSame(1, substr_count($html, 'Criar documento e processar</span>') + substr_count($html, '>Criar documento e processar</button>'), 'A ação primária aparece uma única vez fora do menu.');
        $pending->assertSee('Sinvastatina')->assertSee('primeiro.pdf')->assertSee('Mais')->assertSee('Associar a documento existente')->assertSee('Não incorporar');

        $pending->call('createDocument')->assertSee('Documento associado com sucesso.')->assertSee('Próxima pendência (1 restante)')->assertSee('Ficar aqui')->assertDontSee('Associar documento');
        $this->assertSame('ASSOCIATED', $item->refresh()->status);
        $this->assertSame('PENDING', $next->refresh()->status);
    }

    public function test_review_tabs_switch_in_the_browser_without_a_livewire_property(): void
    {
        $admin = User::factory()->admin()->create();
        $batch = $this->batch($this->source(), ['total_items' => 1, 'pending_items' => 1]);
        $item = $this->item($batch);

        $html = Livewire::actingAs($admin)->test(Review::class, ['batch' => $batch, 'item' => $item])->html();

        $this->assertStringNotContainsString("\$set('tab'", $html);
        $this->assertStringContainsString("x-on:click=\"tab = 'resolver'\"", $html);
    }

    public function test_review_creates_the_professional_leaflet_when_the_patient_leaflet_shares_the_registration(): void
    {
        Storage::fake('local');
        $admin = User::factory()->admin()->create();
        $source = $this->source();
        $patient = KnowledgeDocument::query()->create(['source_id' => $source->id, 'source_document_id' => '118190190', 'title' => 'Nimesulida — Bula do paciente', 'document_type' => 'bula_paciente', 'external_key' => 'source:'.$source->id.':'.hash('sha256', '118190190'), 'metadata' => [], 'content_status' => 'READY', 'file_status' => 'READY', 'embedding_status' => 'READY']);
        $batch = $this->batch($source, ['total_items' => 1, 'pending_items' => 1]);
        $content = '%PDF-1.4 profissional';
        $item = $this->item($batch, ['sha256' => hash('sha256', $content), 'file_size' => strlen($content), 'metadata' => ['preliminary_identification' => ['fields' => ['title' => 'Nimesulida — Bula do profissional', 'document_type' => 'bula_profissional', 'registration_number_normalized' => '118190190']]]]);
        Storage::disk('local')->put($item->storage_path, $content);

        Livewire::actingAs($admin)->test(Review::class, ['batch' => $batch, 'item' => $item])->call('createDocument')->assertHasNoErrors();

        $this->assertSame('ASSOCIATED', $item->refresh()->status);
        $this->assertNotSame($patient->id, $item->knowledge_document_id);
    }

    public function test_batch_page_shows_progress_in_its_own_polled_fragment(): void
    {
        $admin = User::factory()->admin()->create();
        $batch = $this->batch($this->source(), ['status' => 'PROCESSING', 'total_items' => 4, 'associated_items' => 1, 'pending_items' => 1]);
        $this->item($batch, ['original_filename' => 'em-revisao.pdf']);

        $page = Livewire::actingAs($admin)->test(BatchShow::class, ['batch' => $batch])->assertSee('em-revisao.pdf')->assertSee('Revisar pendências')->assertSee('Incorporados');
        $this->assertSame(1, substr_count($page->html(), 'wire:poll.4s'), 'Só o fragmento de andamento da importação faz polling nesta tela.');
        Livewire::actingAs($admin)->test(Progress::class, ['batch' => $batch])->assertSee('Processando os arquivos… 50%')->assertSeeHtml('wire:poll.4s');
    }

    private function source(): KnowledgeSource
    {
        return KnowledgeSource::query()->firstOrCreate(['name' => 'ANVISA', 'organization' => 'ANVISA']);
    }

    /** @param array<string, mixed> $attributes */
    private function batch(KnowledgeSource $source, array $attributes = []): IngestionBatch
    {
        return IngestionBatch::query()->create(array_merge(['name' => 'Lote teste', 'source_id' => $source->id, 'status' => 'COMPLETED', 'metadata' => []], $attributes));
    }

    /** @param array<string, mixed> $attributes */
    private function item(IngestionBatch $batch, array $attributes = []): IngestionBatchItem
    {
        return IngestionBatchItem::query()->create(array_merge(['ingestion_batch_id' => $batch->id, 'original_filename' => 'pending.pdf', 'storage_path' => "knowledge/pending/{$batch->id}/pending.pdf", 'status' => 'PENDING', 'metadata' => []], $attributes));
    }

    /** @param array<string, mixed> $attributes */
    private function document(KnowledgeSource $source, array $attributes = []): KnowledgeDocument
    {
        return KnowledgeDocument::query()->create(array_merge(['source_id' => $source->id, 'external_key' => 'doc-'.fake()->unique()->uuid(), 'title' => 'Documento alvo', 'document_type' => 'bula', 'metadata' => [], 'content_status' => 'READY', 'file_status' => 'PENDING', 'embedding_status' => 'PENDING'], $attributes));
    }
}
