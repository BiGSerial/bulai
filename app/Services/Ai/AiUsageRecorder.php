<?php

namespace App\Services\Ai;

use App\Data\Ai\AiUsageRecord;
use DateTimeInterface;
use Illuminate\Container\Attributes\Bind;

/** Persistência das métricas de consumo; atrás de interface para permitir testes sem banco. */
#[Bind(EloquentAiUsageRecorder::class)]
interface AiUsageRecorder
{
    public function record(AiUsageRecord $record): void;

    /**
     * Consumo bem-sucedido do provedor desde o instante informado.
     *
     * @return array{tokens: int, cost_usd: float}
     */
    public function totalsSince(string $provider, DateTimeInterface $since): array;
}
