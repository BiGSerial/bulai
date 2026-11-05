<?php

namespace Database\Factories;

use App\Models\AiUsageLog;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AiUsageLog>
 */
class AiUsageLogFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-5-5',
            'run_id' => (string) Str::uuid(),
            'capability' => 'text',
            'input_tokens' => fake()->numberBetween(100, 5000),
            'output_tokens' => fake()->numberBetween(10, 1000),
            'latency_ms' => fake()->numberBetween(200, 8000),
            'status' => AiUsageLog::STATUS_SUCCESS,
            'error_code' => null,
            'http_status' => 200,
            'provider_request_id' => 'req_'.Str::random(12),
            'estimated_cost_usd' => null,
            'pricing_reference' => null,
        ];
    }

    public function failed(string $errorCode = 'AI_UNAVAILABLE', int $httpStatus = 529): static
    {
        return $this->state(fn (): array => [
            'status' => AiUsageLog::STATUS_FAILED,
            'error_code' => $errorCode,
            'http_status' => $httpStatus,
            'input_tokens' => null,
            'output_tokens' => null,
        ]);
    }
}
