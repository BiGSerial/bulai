<?php

namespace Tests\Feature\Knowledge;

use App\Jobs\ClassifyPendingDocument;
use App\Livewire\Admin\Knowledge\Pending\Review;
use App\Models\Knowledge\IngestionBatch;
use App\Models\Knowledge\IngestionBatchItem;
use App\Models\Knowledge\KnowledgeSource;
use App\Models\User;
use App\Services\Ai\AiProviderManager;
use App\Services\Ai\Fake\FakeDocumentClassificationProvider;
use App\Services\Knowledge\KnowledgeAiSuggestionReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

final class KnowledgeAiSuggestionReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_comparison_table_shows_heuristic_and_ai_values_side_by_side(): void
    {
        [$batch, $item] = $this->rejectedItem();

        Livewire::actingAs(User::factory()->admin()->create())->test(Review::class, ['batch' => $batch, 'item' => $item])
            ->assertSee('Quadro comparativo')
            ->assertSee('10mL de solução diluente')
            ->assertSee('omeprazol sódico')
            ->assertSee('Divergem')
            ->assertSee('Concordam')
            ->assertSee('Aparece no texto enviado à IA');
    }

    public function test_review_page_shows_the_stored_prompt_and_raw_response(): void
    {
        [$batch, $item] = $this->rejectedItem(withPrompt: true);

        Livewire::actingAs(User::factory()->admin()->create())->test(Review::class, ['batch' => $batch, 'item' => $item])
            ->assertSee('Prompt enviado à IA e resposta bruta')
            ->assertSee('Instrução do sistema')
            ->assertSee('Você identifica bulas')
            ->assertSee('Resposta bruta da IA');
    }

    public function test_review_page_explains_when_no_prompt_was_stored(): void
    {
        [$batch, $item] = $this->rejectedItem();

        Livewire::actingAs(User::factory()->admin()->create())->test(Review::class, ['batch' => $batch, 'item' => $item])
            ->assertSee('O prompt não foi gravado nesta análise');
    }

    public function test_board_shows_the_catalog_column_and_admin_can_apply_the_catalog_value(): void
    {
        [$batch, $item] = $this->rejectedItem(catalog: true);

        Livewire::actingAs(User::factory()->admin()->create())->test(Review::class, ['batch' => $batch, 'item' => $item])
            ->assertSee('Catálogo ANVISA')
            ->assertSee('OMEPRAZOL SÓDICO')
            ->set('selectedAiFields', ['catalog:active_ingredient', 'ai:active_ingredient'])
            ->call('applyAiSuggestion')
            ->assertSee('Catálogo ANVISA (aceito por você)');

        $item->refresh();
        $this->assertSame('OMEPRAZOL SÓDICO', data_get($item->metadata, 'preliminary_identification.fields.active_ingredient'), 'A primeira fonte escolhida para o campo prevalece.');
        $this->assertSame(['active_ingredient' => 'catalog'], $item->metadata['ai_review']['sources']);
    }

    public function test_medicine_panel_alerts_when_the_active_ingredient_is_missing(): void
    {
        [$batch, $item] = $this->rejectedItem();
        $metadata = $item->metadata;
        unset($metadata['preliminary_identification']['fields']['active_ingredient']);
        $item->forceFill(['metadata' => $metadata])->save();

        Livewire::actingAs(User::factory()->admin()->create())->test(Review::class, ['batch' => $batch, 'item' => $item->refresh()])
            ->assertSee('Dados do medicamento')
            ->assertSee('Princípio ativo não identificado')
            ->assertSee('interações medicamentosas');
    }

    public function test_medicine_panel_lists_substances_with_their_origin_and_catalog_data(): void
    {
        [$batch, $item] = $this->rejectedItem(catalog: true);
        $metadata = $item->metadata;
        $metadata['preliminary_identification']['fields']['active_ingredient'] = 'amoxicilina + clavulanato de potássio';
        $metadata['preliminary_identification']['regulatory_validation']['filled_from_catalog'] = ['active_ingredient'];
        $item->forceFill(['metadata' => $metadata])->save();

        Livewire::actingAs(User::factory()->admin()->create())->test(Review::class, ['batch' => $batch, 'item' => $item->refresh()])
            ->assertSee('amoxicilina')
            ->assertSee('clavulanato de potássio')
            ->assertSee('Origem: Catálogo ANVISA')
            ->assertSee('HORMONIOS TIREOIDEANOS')
            ->assertSee('01/2033');
    }

    public function test_panel_shows_the_molecular_formula_and_the_ocr_supplement_note(): void
    {
        [$batch, $item] = $this->rejectedItem();
        $metadata = $item->metadata;
        $metadata['preliminary_identification']['fields']['molecular_formula'] = 'C4H11N5·HCl';
        $metadata['preliminary_identification']['extraction'] = ['extraction_method' => 'pdf_text', 'ocr_supplement' => ['pages' => 3, 'added_lines' => 4, 'engine' => 'paddleocr', 'score_before' => 55, 'score_after' => 75, 'adopted' => true]];
        $item->forceFill(['metadata' => $metadata])->save();

        Livewire::actingAs(User::factory()->admin()->create())->test(Review::class, ['batch' => $batch, 'item' => $item->refresh()])
            ->assertSee('Fórmula molecular')
            ->assertSee('C4H11N5·HCl')
            ->assertSee('Texto nativo + OCR da capa')
            ->assertSee('o OCR leu as 3 primeiras páginas e acrescentou 4 linhas')
            ->assertSee('resultado adotado');
    }

    public function test_admin_can_accept_selected_ai_fields_and_it_is_audited_and_prefills_the_new_document(): void
    {
        [$batch, $item] = $this->rejectedItem();
        $admin = User::factory()->admin()->create();

        Livewire::actingAs($admin)->test(Review::class, ['batch' => $batch, 'item' => $item])
            ->set('selectedAiFields', ['active_ingredient', 'document_type'])
            ->call('applyAiSuggestion')
            ->assertSee('Você aceitou valores da IA')
            ->assertSet('newDocumentType', 'bula_profissional');

        $item->refresh();
        $this->assertSame('omeprazol sódico', data_get($item->metadata, 'preliminary_identification.fields.active_ingredient'));
        $this->assertSame('omeprazol sódico', $item->metadata['active_ingredient']);
        $this->assertSame('10mL de solução diluente', data_get($item->metadata, 'ai_review.previous.active_ingredient'));
        $event = collect($item->metadata['audit_history'])->firstWhere('event', 'ai_suggestion_accepted');
        $this->assertSame($admin->id, $event['actor_id']);
        $this->assertSame(['active_ingredient', 'document_type'], $event['details']['fields']);
    }

    public function test_only_fields_present_in_the_stored_suggestion_can_be_applied(): void
    {
        [$batch, $item] = $this->rejectedItem();

        $result = app(KnowledgeAiSuggestionReviewService::class)->accept($batch->id, $item->id, ['title', 'sha256', 'registration_number_normalized'], User::factory()->admin()->create());

        $this->assertSame('NOTHING_SELECTED', $result['status']);
        $this->assertNull($item->refresh()->metadata['ai_review'] ?? null);
    }

    public function test_admin_can_reject_the_ai_analysis_and_revert_the_decision(): void
    {
        [$batch, $item] = $this->rejectedItem();
        $component = Livewire::actingAs(User::factory()->admin()->create())->test(Review::class, ['batch' => $batch, 'item' => $item]);

        $component->call('rejectAiSuggestion')->assertSee('Você rejeitou a análise da IA');
        $this->assertSame('REJECTED', $item->refresh()->metadata['ai_review']['status']);
        $this->assertSame('10mL de solução diluente', data_get($item->metadata, 'preliminary_identification.fields.active_ingredient'));

        $component->call('revertAiDecision');
        $this->assertArrayNotHasKey('ai_review', $item->refresh()->metadata);
        $this->assertSame(['ai_suggestion_rejected', 'ai_decision_reverted'], collect($item->metadata['audit_history'])->pluck('event')->all());
    }

    public function test_reverting_an_accepted_decision_restores_the_heuristic_values(): void
    {
        [$batch, $item] = $this->rejectedItem();
        $admin = User::factory()->admin()->create();
        $reviews = app(KnowledgeAiSuggestionReviewService::class);

        $reviews->accept($batch->id, $item->id, ['active_ingredient'], $admin);
        $this->assertTrue($reviews->revert($batch->id, $item->id, $admin));

        $item->refresh();
        $this->assertSame('10mL de solução diluente', data_get($item->metadata, 'preliminary_identification.fields.active_ingredient'));
        $this->assertSame('10mL de solução diluente', $item->metadata['active_ingredient']);
    }

    public function test_decisions_are_refused_once_the_item_is_no_longer_pending(): void
    {
        [$batch, $item] = $this->rejectedItem();
        $item->forceFill(['status' => IngestionBatchItem::STATUS_IGNORED])->save();

        $result = app(KnowledgeAiSuggestionReviewService::class)->accept($batch->id, $item->id, ['active_ingredient'], User::factory()->admin()->create());

        $this->assertSame('ALREADY_RESOLVED', $result['status']);
    }

    public function test_admin_can_request_a_forced_ai_analysis_which_is_queued(): void
    {
        Queue::fake();
        $provider = new FakeDocumentClassificationProvider;
        app(AiProviderManager::class)->extend('classifier', 'test', fn () => $provider);
        config()->set('ai.classifier.default', 'test');
        [$batch, $item] = $this->rejectedItem();
        $admin = User::factory()->admin()->create();

        Livewire::actingAs($admin)->test(Review::class, ['batch' => $batch, 'item' => $item])
            ->call('requestAiAnalysis')
            ->assertSee('Análise da IA enfileirada')
            ->call('requestAiAnalysis')
            ->assertSee('já está em análise');

        Queue::assertPushed(ClassifyPendingDocument::class, 1);
        Queue::assertPushed(ClassifyPendingDocument::class, fn (ClassifyPendingDocument $job): bool => $job->forceAi && $job->actorId === $admin->id);
        $this->assertSame('queued', $item->refresh()->metadata['reanalysis_progress']['state']);
    }

    public function test_requesting_the_ai_without_a_configured_classifier_is_refused_without_queueing(): void
    {
        Queue::fake();
        config()->set('ai.classifier.default', null);
        [$batch, $item] = $this->rejectedItem();

        Livewire::actingAs(User::factory()->admin()->create())->test(Review::class, ['batch' => $batch, 'item' => $item])
            ->call('requestAiAnalysis')
            ->assertSee('não está configurada');

        Queue::assertNothingPushed();
    }

    /** @return array{IngestionBatch, IngestionBatchItem} */
    private function rejectedItem(bool $withPrompt = false, bool $catalog = false): array
    {
        $source = KnowledgeSource::query()->create(['name' => 'ANVISA', 'organization' => 'ANVISA', 'active' => true]);
        $batch = IngestionBatch::query()->create(['name' => 'Lote', 'source_id' => $source->id, 'status' => 'COMPLETED', 'total_items' => 1, 'pending_items' => 1]);
        $heuristic = ['product_name' => 'omeprazol sódico 40mg', 'active_ingredient' => '10mL de solução diluente', 'document_type' => 'bula_paciente', 'title' => 'omeprazol sódico 40mg — Bula do paciente'];
        $item = $batch->items()->create(['original_filename' => 'bula.pdf', 'normalized_filename' => 'bula.pdf', 'sha256' => str_repeat('a', 64), 'mime_type' => 'application/pdf',
            'file_size' => 10, 'storage_disk' => 'knowledge', 'storage_key' => 'k.pdf', 'storage_path' => 'k.pdf', 'status' => 'PENDING', 'reason' => 'no_match_found', 'metadata' => [
                'preliminary_identification' => ['score' => 55, 'fields' => $heuristic, 'classification' => [
                    'decision' => 'AI_REJECTED', 'reason' => 'conflict_with_heuristic_active_ingredient', 'heuristic_score' => 55, 'ai_used' => true, 'confidence' => 0.95, 'model' => 'local',
                    'heuristic' => $heuristic,
                    ...($withPrompt ? ['prompt' => ['system' => 'Você identifica bulas de medicamentos.', 'user' => ['heuristic_score' => 55, 'candidates' => ['product_name' => ['omeprazol']]], 'sha256' => str_repeat('b', 64), 'raw_response' => '{"product_name":"omeprazol"}', 'temperature' => 0]] : []),
                    'result' => ['product_name' => 'omeprazol sódico 40mg', 'active_ingredient' => 'omeprazol sódico', 'document_type' => 'bula_profissional', 'evidence' => ['Cada frasco-ampola contém: omeprazol sódico']],
                    'checks' => ['product_name' => ['grounded' => true, 'conflict' => false], 'active_ingredient' => ['grounded' => true, 'conflict' => true], 'document_type' => ['grounded' => false, 'conflict' => true]],
                ], ...($catalog ? ['regulatory_validation' => ['status' => 'CONFIRMED', 'catalog' => ['registration_number' => '100890416', 'product_name' => 'OMEPRAZOL', 'active_ingredient' => 'OMEPRAZOL SÓDICO', 'registration_holder' => 'MERCK S/A',
                    'registration_status' => 'Ativo', 'regulatory_category' => 'Genérico', 'therapeutic_class' => 'HORMONIOS TIREOIDEANOS', 'registration_expires_on' => '012033']]] : [])],
            ]]);

        return [$batch, $item];
    }
}
