<?php

namespace App\Services\Ai;

use App\Data\Ai\AiUsageRecord;
use App\Models\AiUsageLog;
use DateTimeInterface;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Grava em ai_usage_logs. Falha de persistência nunca derruba a geração de texto. */
final class EloquentAiUsageRecorder implements AiUsageRecorder
{
    public function record(AiUsageRecord $record): void
    {
        try {
            AiUsageLog::query()->create([
                'provider' => $record->provider,
                'model' => $record->model,
                'run_id' => $record->runId,
                'capability' => $record->capability,
                'input_tokens' => $record->inputTokens,
                'output_tokens' => $record->outputTokens,
                'latency_ms' => $record->latencyMs,
                'status' => $record->status,
                'error_code' => $record->errorCode,
                'http_status' => $record->httpStatus,
                'provider_request_id' => $record->providerRequestId,
                'estimated_cost_usd' => $record->estimatedCostUsd,
                'pricing_reference' => $record->pricingReference,
            ]);
        } catch (Throwable) {
            Log::warning('ai_usage_record_failed', ['provider' => $record->provider, 'capability' => $record->capability]);
        }
    }

    public function totalsSince(string $provider, DateTimeInterface $since): array
    {
        $row = AiUsageLog::query()
            ->where('provider', $provider)
            ->where('status', AiUsageLog::STATUS_SUCCESS)
            ->where('created_at', '>=', $since)
            ->selectRaw('COALESCE(SUM(COALESCE(input_tokens, 0) + COALESCE(output_tokens, 0)), 0) as tokens, COALESCE(SUM(estimated_cost_usd), 0) as cost')
            ->first();

        return ['tokens' => (int) ($row?->tokens ?? 0), 'cost_usd' => (float) ($row?->cost ?? 0)];
    }
}
