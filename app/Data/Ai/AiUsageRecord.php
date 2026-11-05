<?php

namespace App\Data\Ai;

/** Métrica de uma requisição a um provedor de IA. Não carrega prompt, resposta nem credenciais. */
final readonly class AiUsageRecord
{
    public function __construct(
        public string $provider,
        public string $model,
        public ?string $runId,
        public string $capability,
        public ?int $inputTokens,
        public ?int $outputTokens,
        public int $latencyMs,
        public string $status,
        public ?string $errorCode = null,
        public ?int $httpStatus = null,
        public ?string $providerRequestId = null,
        public ?float $estimatedCostUsd = null,
        public ?string $pricingReference = null,
    ) {}
}
