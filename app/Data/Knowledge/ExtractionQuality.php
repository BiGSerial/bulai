<?php

namespace App\Data\Knowledge;

final readonly class ExtractionQuality
{
    /**
     * @param  list<string>  $reasons  Códigos controlados (ex.: NO_TEXT, LOW_CHARACTER_COUNT).
     * @param  array<string, int|float>  $metrics
     */
    public function __construct(
        public float $score,
        public bool $isAcceptable,
        public array $reasons,
        public array $metrics,
    ) {}

    /** @return array{score: float, is_acceptable: bool, reasons: list<string>, metrics: array<string, int|float>} */
    public function toArray(): array
    {
        return [
            'score' => $this->score,
            'is_acceptable' => $this->isAcceptable,
            'reasons' => $this->reasons,
            'metrics' => $this->metrics,
        ];
    }
}
