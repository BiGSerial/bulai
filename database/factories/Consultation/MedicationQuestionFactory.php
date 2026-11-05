<?php

namespace Database\Factories\Consultation;

use App\Models\Consultation\MedicationQuestion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Pergunta respondida com uma evidência e contexto de tratamento. Use via `MedicationQuestionFactory::new()`.
 *
 * @extends Factory<MedicationQuestion>
 */
class MedicationQuestionFactory extends Factory
{
    protected $model = MedicationQuestion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'user_id' => User::factory(),
            'medicine_id' => null,
            'medicine_name' => 'OMEPRAZOL',
            'question' => 'Posso tomar em jejum?',
            'status' => 'answered',
            'answer' => 'A bula orienta tomar o comprimido pela manhã, em jejum.',
            'evidences' => [[
                'chunk_id' => 20, 'document_id' => 2, 'document_title' => 'Omeprazol — Bula', 'document_type' => 'bula_paciente', 'source' => 'ANVISA',
                'section' => 'COMO DEVO USAR', 'page_start' => 3, 'page_end' => 3, 'excerpt' => 'O comprimido deve ser tomado pela manhã em jejum.',
                'score' => 0.31, 'method' => 'lexical', 'document_version' => 'def456', 'supports_claims' => [0],
            ]],
            'treatment_context' => ['consented' => true, 'in_use' => true, 'posology' => ['dosage' => '20 mg', 'frequency' => '1x ao dia', 'prescriber' => null], 'allergy_alerts' => [], 'interactions' => [['medicine_id' => 5, 'medicine' => 'CLOPIDOGREL', 'severity' => 'major', 'severity_label' => 'Maior', 'divergent' => false]], 'unresolved' => [], 'highest_severity' => 'major', 'medications_count' => 2],
            'generation' => ['status' => 'generated', 'prompt_version' => 'interaction-explanation-v1:qa', 'run_id' => (string) Str::uuid(), 'provider' => 'anthropic', 'model' => 'claude-sonnet-5-5', 'input_tokens' => 700, 'output_tokens' => 80, 'truncated' => false, 'claims_accepted' => 1, 'claims_rejected' => 0, 'rejection_reasons' => []],
            'retrieval' => ['mode' => 'lexical', 'duration_ms' => 9, 'limit' => 4],
            'pipeline' => ['retrieval_mode' => 'lexical', 'text_provider' => 'anthropic'],
            'duration_ms' => fake()->numberBetween(300, 6000),
            'expires_at' => now()->addDays(90),
        ];
    }
}
