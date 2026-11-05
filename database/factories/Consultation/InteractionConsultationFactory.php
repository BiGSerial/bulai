<?php

namespace Database\Factories\Consultation;

use App\Models\Consultation\InteractionConsultation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Use via `InteractionConsultationFactory::new()`: o modelo não declara HasFactory.
 *
 * @extends Factory<InteractionConsultation>
 */
class InteractionConsultationFactory extends Factory
{
    protected $model = InteractionConsultation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'user_id' => User::factory(),
            'status' => InteractionConsultation::COMPLETED,
            'medicines' => [
                ['id' => null, 'name' => 'MEDICAMENTO A', 'ingredients' => [['id' => 1, 'name' => 'Alfa']]],
                ['id' => null, 'name' => 'MEDICAMENTO B', 'ingredients' => [['id' => 2, 'name' => 'Beta']]],
            ],
            'pipeline' => ['retrieval_mode' => 'lexical', 'generation_enabled' => true, 'text_provider' => 'anthropic', 'embedding_provider' => null, 'prompt_version' => 'interaction-explanation-v1', 'code_version' => null],
            'run_id' => (string) Str::uuid(),
            'started_at' => now()->subSeconds(5),
            'completed_at' => now(),
            'duration_ms' => fake()->numberBetween(500, 9000),
            'expires_at' => now()->addDays(90),
        ];
    }

    public function failed(string $errorCode = 'CONSULTATION_PIPELINE_FAILED'): static
    {
        return $this->state(fn (): array => ['status' => InteractionConsultation::FAILED, 'error_code' => $errorCode]);
    }
}
