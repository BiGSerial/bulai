<?php

namespace App\Services\Ai\Fake;

use App\Data\Ai\AiUsageRecord;
use App\Services\Ai\AiUsageRecorder;
use DateTimeInterface;

/** Recorder em memória para testes sem banco. */
final class InMemoryAiUsageRecorder implements AiUsageRecorder
{
    /** @var list<AiUsageRecord> */
    public array $records = [];

    public int $tokensConsumed = 0;

    public float $costConsumed = 0.0;

    public function record(AiUsageRecord $record): void
    {
        $this->records[] = $record;
    }

    public function totalsSince(string $provider, DateTimeInterface $since): array
    {
        return ['tokens' => $this->tokensConsumed, 'cost_usd' => $this->costConsumed];
    }
}
