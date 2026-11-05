<?php

namespace App\Data\Knowledge;

final readonly class RetrievalResult
{
    /**
     * @param  list<EvidenceReference>  $references
     * @param  array<string, mixed>  $configuration  parâmetros efetivamente usados (modo, modelo de embedding, k de RRF...)
     */
    public function __construct(
        public array $references,
        public string $mode,
        public int $durationMs,
        public array $configuration = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->references === [];
    }
}
