<?php

namespace Database\Factories\Consultation;

use App\Models\Consultation\InteractionPairResult;
use App\Services\Interactions\InteractionSeverity;
use App\Services\Interactions\PairVerdict;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Par com interação documentada, explicação validada e uma evidência. Use via `InteractionPairResultFactory::new()`.
 *
 * @extends Factory<InteractionPairResult>
 */
class InteractionPairResultFactory extends Factory
{
    protected $model = InteractionPairResult::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'consultation_id' => InteractionConsultationFactory::new(),
            'position' => 0,
            'medicine_a_id' => null,
            'medicine_b_id' => null,
            'label_a' => 'MEDICAMENTO A',
            'label_b' => 'MEDICAMENTO B',
            'ingredients_a' => [['id' => 1, 'name' => 'Alfa']],
            'ingredients_b' => [['id' => 2, 'name' => 'Beta']],
            'verdict' => PairVerdict::InteractionFound,
            'severity' => InteractionSeverity::Major,
            'severity_source' => 'ddinter',
            'structured' => ['severity' => 'major', 'variants' => [['drug_a' => 'Alfa', 'drug_b' => 'Beta', 'severity' => 'major']], 'provenance' => [['source' => 'DDInter', 'file' => 'ddinter.csv', 'imported_at' => null]], 'divergent_severities' => false],
            'explanation' => 'A bula de Alfa informa que o uso com Beta aumenta o risco de sangramento.',
            'evidences' => [[
                'chunk_id' => 10, 'document_id' => 1, 'document_title' => 'Alfa — Bula', 'document_type' => 'bula_profissional', 'source' => 'ANVISA',
                'section' => 'INTERAÇÕES MEDICAMENTOSAS', 'page_start' => 4, 'page_end' => 4, 'excerpt' => 'O uso concomitante com beta aumenta o risco de sangramento.',
                'score' => 0.42, 'method' => 'lexical', 'document_version' => 'abc123', 'supports_claims' => [0],
            ]],
            'limitations' => ['A gravidade segue a classificação da base DDInter; não é uma avaliação clínica individual.'],
            'generation' => ['status' => 'generated', 'prompt_version' => 'interaction-explanation-v1', 'run_id' => (string) Str::uuid(), 'provider' => 'anthropic', 'model' => 'claude-sonnet-5-5', 'input_tokens' => 900, 'output_tokens' => 120, 'truncated' => false, 'claims_accepted' => 2, 'claims_rejected' => 1, 'rejection_reasons' => ['not_grounded_in_cited_evidence' => 1]],
            'retrieval' => ['mode' => 'lexical', 'duration_ms' => 12, 'directions' => []],
            'timings' => ['lookup_ms' => 5, 'retrieval_ms' => 12, 'generation_ms' => 2200],
            'error_code' => null,
        ];
    }

    public function notFound(): static
    {
        return $this->state(fn (): array => [
            'verdict' => PairVerdict::NotFoundInSources, 'severity' => null, 'severity_source' => null, 'structured' => null, 'explanation' => null,
            'evidences' => [], 'generation' => null, 'timings' => ['lookup_ms' => 3, 'retrieval_ms' => 8],
            'limitations' => ['Nenhuma interação foi encontrada nas fontes consultadas.'],
        ]);
    }
}
