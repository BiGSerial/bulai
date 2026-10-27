<?php

namespace Tests\Feature\Knowledge;

use App\Data\Ai\DocumentClassificationResponse;
use App\Data\Knowledge\DocumentIdentification;
use App\Models\Knowledge\AnvisaMedicine;
use App\Services\Ai\AiProviderManager;
use App\Services\Ai\Fake\FakeDocumentClassificationProvider;
use App\Services\Knowledge\KnowledgeDocumentClassificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class KnowledgeDocumentClassificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_high_confidence_heuristic_is_accepted_without_calling_the_local_model(): void
    {
        $provider = $this->useProvider(null);

        $result = $this->refine($this->identification(score: 100, eligible: true));

        $this->assertSame(0, $provider->calls);
        $this->assertSame('ACCEPTED_HEURISTIC', $result->classification['decision']);
        $this->assertFalse($result->classification['ai_used']);
        $this->assertTrue($result->automaticCreationEligible);
    }

    public function test_forced_request_consults_the_model_even_when_the_heuristic_is_confident_and_records_both_sides(): void
    {
        $provider = $this->useProvider($this->answer(productName: 'Novalgina'));

        $result = app(KnowledgeDocumentClassificationService::class)->refine($this->identification(score: 100, eligible: true), forceAi: true);

        $this->assertSame(1, $provider->calls);
        $this->assertTrue($result->classification['forced']);
        $this->assertTrue($result->classification['ai_used']);
        $this->assertSame('Novalgina', $result->classification['heuristic']['product_name']);
        $this->assertSame(['grounded' => true, 'conflict' => false], $result->classification['checks']['product_name']);
    }

    public function test_the_prompt_payload_is_stored_for_audit_even_when_the_model_fails_and_can_be_disabled(): void
    {
        $this->useProvider(null, 'AI_TIMEOUT');

        $failed = $this->refine($this->identification(score: 60));

        $prompt = $failed->classification['prompt'];
        $this->assertSame(['NOVALGINA'], ((array) $prompt['user']['candidates'])['product_name']);
        $this->assertSame(64, strlen($prompt['sha256']));
        $this->assertNull($prompt['raw_response']);

        config()->set('document_classification.store_prompt', false);
        $this->assertArrayNotHasKey('prompt', $this->refine($this->identification(score: 60))->classification);
    }

    public function test_missing_active_ingredient_always_consults_the_model_but_keeps_the_score_decision(): void
    {
        $provider = $this->useProvider(new DocumentClassificationResponse('Novalgina', 'dipirona monoidratada', null, 0.95, ['cabeçalho'], 'qwen'));

        $confident = $this->refine($this->identification(score: 100, eligible: true, fields: ['active_ingredient' => null]));

        $this->assertSame(1, $provider->calls);
        $this->assertTrue($confident->classification['gap_fill']);
        $this->assertTrue($confident->classification['ai_used']);
        $this->assertSame('dipirona monoidratada', $confident->classification['result']['active_ingredient']);
        $this->assertContains($confident->classification['decision'], ['ACCEPTED_HEURISTIC', 'AI_ACCEPTED']);
    }

    public function test_failed_gap_fill_does_not_change_the_confident_decision_and_records_the_outcome(): void
    {
        $this->useProvider(null, 'AI_TIMEOUT');

        $result = $this->refine($this->identification(score: 100, eligible: true, fields: ['active_ingredient' => null]));

        $this->assertSame('ACCEPTED_HEURISTIC', $result->classification['decision']);
        $this->assertSame('AI_UNAVAILABLE', $result->classification['gap_fill_outcome']);
        $this->assertTrue($result->automaticCreationEligible);
    }

    public function test_low_score_without_active_ingredient_still_asks_the_model_and_falls_back_to_manual_review(): void
    {
        $provider = $this->useProvider($this->answer(productName: 'Novalgina'));

        $result = $this->refine($this->identification(score: 20, fields: ['active_ingredient' => null]));

        $this->assertSame(1, $provider->calls);
        $this->assertSame('MANUAL_REVIEW', $result->classification['decision']);
    }

    public function test_an_active_ingredient_that_starts_with_a_quantity_is_rejected_and_catalog_values_are_kept_out_of_the_heuristic_snapshot(): void
    {
        $this->useProvider(new DocumentClassificationResponse('Novalgina', '50 mg de dipirona', null, 0.95, ['cabeçalho'], 'qwen'));
        $identification = $this->identification(score: 60, fields: ['active_ingredient' => null])->with(['regulatoryValidation' => ['filled_from_catalog' => ['title']]]);

        $result = app(KnowledgeDocumentClassificationService::class)->refine($identification);

        $this->assertSame('AI_REJECTED', $result->classification['decision']);
        $this->assertSame('implausible_active_ingredient', $result->classification['reason']);
        $this->assertArrayNotHasKey('title', $result->classification['heuristic']);
    }

    public function test_a_valid_active_ingredient_is_kept_even_when_another_field_makes_the_ai_answer_rejected(): void
    {
        $this->useProvider(new DocumentClassificationResponse('Novalgina', 'dipirona', 'bula_profissional', 0.95, ['cabeçalho'], 'qwen'));
        $identification = $this->identification(score: 60, fields: ['active_ingredient' => null])
            ->with(['candidates' => ['product_name' => ['NOVALGINA'], 'active_ingredient' => ['dipirona'], 'registration_number' => ['178170001'], 'audience' => ['patient']]]);

        $result = $this->refine($identification);

        $this->assertSame('AI_REJECTED', $result->classification['decision']);
        $this->assertSame('conflict_with_heuristic_document_type', $result->classification['reason']);
        $this->assertTrue($result->classification['ingredient_filled_by_ai']);
        $this->assertSame('dipirona', $result->fields['active_ingredient']);
        $this->assertSame('bula_paciente', $result->fields['document_type'], 'O tipo conflitante não é aplicado.');
    }

    public function test_ai_that_agrees_with_the_heuristic_and_adds_nothing_is_a_confirmation_not_a_rejection(): void
    {
        $this->useProvider($this->answer(productName: 'Novalgina'));

        $result = $this->refine($this->identification(score: 60, fields: ['product_name' => 'Novalgina', 'title' => 'Novalgina — Bula do paciente']));

        $this->assertSame('AI_CONFIRMED', $result->classification['decision']);
        $this->assertNull($result->classification['reason'] ?? null);
        $this->assertSame('Novalgina', $result->fields['product_name']);
    }

    public function test_intermediate_score_calls_the_model_and_a_grounded_answer_resolves_the_ambiguity(): void
    {
        $provider = $this->useProvider($this->answer(productName: 'Novalgina'));

        $result = $this->refine($this->identification(score: 60));

        $this->assertSame(1, $provider->calls);
        $this->assertSame('AI_SUGGESTED', $result->classification['decision']);
        $this->assertSame(60, $result->classification['heuristic_score']);
        $this->assertSame('Novalgina', $result->classification['result']['product_name']);
        $this->assertNull($result->fields['product_name'], 'Sem confirmação regulatória a sugestão não altera os campos.');
        $this->assertSame(['product_name'], $result->ambiguousFields);
        $this->assertFalse($result->automaticCreationEligible);
    }

    public function test_ai_resolution_is_eligible_only_when_the_regulatory_catalog_confirms_the_product(): void
    {
        AnvisaMedicine::factory()->create(['registration_number' => '178170001', 'product_name' => 'Novalgina', 'normalized_product_name' => 'novalgina', 'active_ingredient' => 'dipirona monoidratada', 'normalized_active_ingredient' => 'dipirona monoidratada']);
        $this->useProvider($this->answer(productName: 'Novalgina'));

        $result = $this->refine($this->identification(score: 60));

        $this->assertSame('AI_ACCEPTED', $result->classification['decision']);
        $this->assertSame('Novalgina', $result->fields['product_name']);
        $this->assertSame('Novalgina — Bula do paciente', $result->fields['title']);
        $this->assertSame([], $result->ambiguousFields);
        $this->assertSame('CONFIRMED', $result->regulatoryValidation['status']);
        $this->assertTrue($result->automaticCreationEligible);
    }

    public function test_low_heuristic_score_goes_to_manual_review_without_calling_the_model(): void
    {
        $provider = $this->useProvider($this->answer());

        $result = $this->refine($this->identification(score: 20));

        $this->assertSame(0, $provider->calls);
        $this->assertSame('MANUAL_REVIEW', $result->classification['decision']);
    }

    public function test_thresholds_are_configurable(): void
    {
        $provider = $this->useProvider($this->answer());
        config()->set('document_classification.ai_min_score', 10);

        $this->refine($this->identification(score: 20));

        $this->assertSame(1, $provider->calls);
    }

    public function test_low_confidence_answer_is_not_promoted(): void
    {
        $this->useProvider($this->answer(confidence: 0.5));

        $result = $this->refine($this->identification(score: 60));

        $this->assertSame('AI_LOW_CONFIDENCE', $result->classification['decision']);
        $this->assertNull($result->fields['product_name']);
        $this->assertSame(['product_name'], $result->ambiguousFields);
        $this->assertFalse($result->automaticCreationEligible);
    }

    public function test_ungrounded_or_conflicting_answers_are_rejected(): void
    {
        $this->useProvider($this->answer(productName: 'Aspirina'));
        $ungrounded = $this->refine($this->identification(score: 60));
        $this->assertSame('AI_REJECTED', $ungrounded->classification['decision']);
        $this->assertSame('ungrounded_product_name', $ungrounded->classification['reason']);

        $this->useProvider($this->answer(documentType: 'bula_profissional'));
        $ungroundedType = $this->refine($this->identification(score: 60, fields: ['document_type' => null, 'audience' => null]));
        $this->assertSame('ungrounded_document_type', $ungroundedType->classification['reason']);

        $conflict = $this->refine($this->identification(score: 60, fields: ['document_type' => 'bula_paciente']));
        $this->assertSame('conflict_with_heuristic_document_type', $conflict->classification['reason']);
    }

    public function test_unavailable_or_timed_out_model_keeps_the_heuristic_result_pending(): void
    {
        foreach (['AI_TIMEOUT', 'AI_UNAVAILABLE', 'AI_INVALID_RESPONSE'] as $code) {
            $this->useProvider(null, $code);

            $result = $this->refine($this->identification(score: 60));

            $this->assertSame('AI_UNAVAILABLE', $result->classification['decision']);
            $this->assertSame($code, $result->classification['error_code']);
            $this->assertNull($result->fields['product_name']);
            $this->assertFalse($result->automaticCreationEligible);
        }
    }

    public function test_without_a_configured_classifier_the_item_stays_for_review(): void
    {
        config()->set('ai.classifier.default', null);

        $result = $this->refine($this->identification(score: 60));

        $this->assertSame('AI_DISABLED', $result->classification['decision']);
    }

    private function useProvider(?DocumentClassificationResponse $response, ?string $failWith = null): FakeDocumentClassificationProvider
    {
        $provider = new FakeDocumentClassificationProvider($response, $failWith);
        app(AiProviderManager::class)->extend('classifier', 'test', fn () => $provider);
        config()->set('ai.classifier.default', 'test');

        return $provider;
    }

    private function answer(?string $productName = null, ?string $documentType = null, float $confidence = 0.95): DocumentClassificationResponse
    {
        return new DocumentClassificationResponse($productName, null, $documentType, $confidence, ['cabeçalho'], 'qwen');
    }

    private function refine(DocumentIdentification $identification): DocumentIdentification
    {
        return app(KnowledgeDocumentClassificationService::class)->refine($identification);
    }

    /** @param array<string, string|null> $fields */
    private function identification(int $score, bool $eligible = false, array $fields = []): DocumentIdentification
    {
        $fields = [...['product_name' => null, 'active_ingredient' => 'dipirona monoidratada', 'registration_number' => '178170001', 'registration_number_normalized' => '178170001',
            'holder' => null, 'audience' => 'patient', 'document_type' => 'bula_paciente', 'title' => null], ...$fields];
        if ($score >= 85) {
            $fields['product_name'] = 'Novalgina';
            $fields['title'] = 'Novalgina — Bula do paciente';
        }

        return new DocumentIdentification('anvisa', $fields, [], $score >= 85 ? [] : ['product_name'], $score, $eligible, 2,
            candidates: ['product_name' => ['NOVALGINA'], 'active_ingredient' => [], 'registration_number' => ['178170001'], 'audience' => ['patient']],
            excerpts: [['page' => 1, 'type' => 'page_header', 'text' => 'NOVALGINA comprimidos']]);
    }
}
