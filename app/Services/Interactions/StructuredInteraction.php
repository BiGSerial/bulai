<?php

namespace App\Services\Interactions;

/** Interação registrada na base estruturada para um par de princípios ativos (todas as variantes agregadas). */
final readonly class StructuredInteraction
{
    /**
     * @param  list<array{drug_a: string, drug_b: string, severity: string}>  $variants
     * @param  list<array{source: string, file: string|null, imported_at: string|null}>  $provenance
     */
    public function __construct(
        public InteractionSeverity $severity,
        public array $variants,
        public array $provenance,
        public bool $divergentSeverities,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'severity' => $this->severity->value,
            'variants' => $this->variants,
            'provenance' => $this->provenance,
            'divergent_severities' => $this->divergentSeverities,
        ];
    }
}
