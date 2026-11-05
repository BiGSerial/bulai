<?php

namespace Tests\Feature\Infrastructure;

use App\Data\Ai\AiUsageRecord;
use App\Models\AiUsageLog;
use App\Services\Ai\AiUsageRecorder;
use App\Services\Ai\EloquentAiUsageRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AnthropicUsageLogPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_eloquent_recorder_persists_metrics_and_sums_successful_usage(): void
    {
        $recorder = app(AiUsageRecorder::class);
        $this->assertInstanceOf(EloquentAiUsageRecorder::class, $recorder);

        $recorder->record(new AiUsageRecord('anthropic', 'claude-sonnet-5-5', 'run-1', 'text', 1000, 500, 1200, 'success', null, 200, 'req_1', 0.007, '2026-11-03'));
        $recorder->record(new AiUsageRecord('anthropic', 'claude-sonnet-5-5', null, 'text', null, null, 50, 'failed', 'AI_UNAVAILABLE', 529));
        AiUsageLog::factory()->failed()->create();

        $this->assertDatabaseHas('ai_usage_logs', ['run_id' => 'run-1', 'provider_request_id' => 'req_1', 'status' => 'success']);
        $totals = $recorder->totalsSince('anthropic', now()->subDay());
        $this->assertSame(1500, $totals['tokens']);
        $this->assertEqualsWithDelta(0.007, $totals['cost_usd'], 0.000001);
    }
}
