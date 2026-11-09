<?php

namespace App\Services\Ai\Anthropic;

use App\Data\Ai\AiUsageRecord;
use App\Data\Ai\TokenUsage;
use App\Models\AiUsageLog;
use App\Services\Ai\AiCostEstimator;
use App\Services\Ai\AiProviderException;
use App\Services\Ai\AiUsageRecorder;
use Throwable;

/**
 * Custos estimados, limites de consumo e registro de métricas por requisição.
 * Preços e limites vêm exclusivamente de config('ai.text.drivers.anthropic'); nada é embutido na lógica.
 */
final class AnthropicUsageTracker
{
    public const PROVIDER = 'anthropic';

    /** @param array<string, mixed> $config Configuração do driver (limits, pricing). */
    public function __construct(private readonly AiUsageRecorder $recorder, private readonly array $config) {}

    /**
     * Bloqueia antes de chamar a API quando o limite de entrada ou algum orçamento já foi atingido.
     *
     * @throws AiProviderException
     */
    public function guard(int $inputCharacters, string $correlationId): void
    {
        $limits = (array) ($this->config['limits'] ?? []);

        $maxInput = (int) ($limits['max_input_tokens'] ?? 0);
        if ($maxInput > 0 && $this->estimateInputTokens($inputCharacters) > $maxInput) {
            throw new AiProviderException(AiProviderException::INPUT_LIMIT_EXCEEDED, $correlationId);
        }

        foreach ([['daily', now()->startOfDay()], ['monthly', now()->startOfMonth()]] as [$period, $since]) {
            $tokenLimit = (int) ($limits["{$period}_tokens"] ?? 0);
            $usdLimit = (float) ($limits["{$period}_usd"] ?? 0);
            if ($tokenLimit <= 0 && $usdLimit <= 0) {
                continue;
            }

            $totals = $this->recorder->totalsSince(self::PROVIDER, $since);
            if (($tokenLimit > 0 && $totals['tokens'] >= $tokenLimit) || ($usdLimit > 0 && $totals['cost_usd'] >= $usdLimit)) {
                throw new AiProviderException(AiProviderException::BUDGET_EXCEEDED, $correlationId);
            }
        }
    }

    public function estimateInputTokens(int $characters): int
    {
        $charsPerToken = max(0.1, (float) ($this->config['limits']['chars_per_token'] ?? 3));

        return (int) ceil($characters / $charsPerToken);
    }

    /** USD estimados a partir da tabela configurada (entrada, cache e saída); null quando falta preço ou uso. */
    public function estimateCost(string $model, ?TokenUsage $usage): ?float
    {
        return AiCostEstimator::estimate((array) ($this->config['pricing'] ?? []), $model, $usage);
    }

    public function pricingReference(): ?string
    {
        return AiCostEstimator::reference((array) ($this->config['pricing'] ?? []));
    }

    public function recordSuccess(string $model, ?string $runId, ?TokenUsage $usage, int $startedAt, ?string $requestId, int $httpStatus = 200, string $capability = 'text', ?string $responseModel = null): ?float
    {
        $cost = $this->estimateCost($model, $usage);
        $this->persist(new AiUsageRecord(
            self::PROVIDER, $model, $runId, $capability, $usage?->inputTokens, $usage?->outputTokens, $this->elapsedMs($startedAt),
            AiUsageLog::STATUS_SUCCESS, null, $httpStatus, $requestId, $cost, $cost === null ? null : $this->pricingReference(),
            $usage, $responseModel,
        ));

        return $cost;
    }

    public function recordFailure(string $model, ?string $runId, AiProviderException $exception, int $startedAt, string $capability = 'text'): void
    {
        $this->persist(new AiUsageRecord(
            self::PROVIDER, $model, $runId, $capability, null, null, $this->elapsedMs($startedAt),
            AiUsageLog::STATUS_FAILED, $exception->errorCode, $exception->httpStatus, $exception->providerRequestId,
        ));
    }

    private function persist(AiUsageRecord $record): void
    {
        try {
            $this->recorder->record($record);
        } catch (Throwable) {
            // Métrica nunca derruba a geração.
        }
    }

    private function elapsedMs(int $startedAt): int
    {
        return (int) ((hrtime(true) - $startedAt) / 1_000_000);
    }
}
