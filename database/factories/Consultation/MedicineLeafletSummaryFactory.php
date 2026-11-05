<?php

namespace Database\Factories\Consultation;

use App\Models\Consultation\MedicineLeafletSummary;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Bula humanizada gerada com duas seções e uma evidência. Use via `MedicineLeafletSummaryFactory::new()`.
 *
 * @extends Factory<MedicineLeafletSummary>
 */
class MedicineLeafletSummaryFactory extends Factory
{
    protected $model = MedicineLeafletSummary::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'ingredient_key' => (string) fake()->unique()->numberBetween(1, 100000),
            'prompt_version' => 'humanized-leaflet-v1',
            'documents_fingerprint' => hash('sha256', (string) Str::uuid()),
            'status' => MedicineLeafletSummary::STATUS_GENERATED,
            'sections' => [
                'para_que_serve' => ['status' => 'generated', 'claims' => [['text' => 'É indicado para azia e úlcera.', 'evidence_chunk_ids' => [30]]]],
                'como_usar' => ['status' => 'generated', 'claims' => [['text' => 'Tomar pela manhã em jejum.', 'evidence_chunk_ids' => [30]]]],
            ],
            'evidences' => [[
                'chunk_id' => 30, 'document_id' => 3, 'document_title' => 'Omeprazol — Bula', 'document_type' => 'bula_paciente', 'source' => 'ANVISA',
                'section' => 'INDICAÇÕES', 'page_start' => 1, 'page_end' => 1, 'excerpt' => 'Indicado para azia e úlcera. Tomar pela manhã em jejum.',
                'score' => 0.5, 'method' => 'lexical', 'document_version' => 'ghi789', 'supports_claims' => [0, 1],
            ]],
            'generation' => ['status' => 'generated', 'prompt_version' => 'humanized-leaflet-v1', 'run_id' => (string) Str::uuid(), 'provider' => 'anthropic', 'model' => 'claude-sonnet-5-5', 'input_tokens' => 3000, 'output_tokens' => 600, 'truncated' => false, 'claims_accepted' => 2, 'claims_rejected' => 0, 'rejection_reasons' => []],
            'retrieval' => ['mode' => 'lexical', 'duration_ms' => 40],
            'pipeline' => ['retrieval_mode' => 'lexical', 'text_provider' => 'anthropic'],
            'duration_ms' => fake()->numberBetween(2000, 15000),
            'views' => 3,
        ];
    }
}
