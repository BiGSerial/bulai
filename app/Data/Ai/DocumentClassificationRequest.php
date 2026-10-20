<?php

namespace App\Data\Ai;

use App\Data\Knowledge\DocumentIdentification;

/** Entrada mínima do classificador: evidências da heurística, nunca o PDF ou o texto integral. */
final readonly class DocumentClassificationRequest
{
    /**
     * @param  array<string, list<string>>  $candidates
     * @param  list<string>  $audienceCandidates
     * @param  list<array{page: int, type: string, text: string}>  $evidence
     * @param  list<string>  $ambiguousFields
     */
    public function __construct(
        public int $heuristicScore,
        public array $candidates,
        public ?string $registration,
        public array $audienceCandidates,
        public array $evidence,
        public array $ambiguousFields,
    ) {}

    public static function fromIdentification(DocumentIdentification $identification): self
    {
        $candidates = $identification->candidates;
        $registrations = $candidates['registration_number'] ?? [];

        return new self(
            $identification->score,
            array_filter([
                'product_name' => $candidates['product_name'] ?? [],
                'active_ingredient' => $candidates['active_ingredient'] ?? [],
            ], fn (array $values): bool => $values !== []),
            $identification->fields['registration_number_normalized'] ?? ($registrations[0] ?? null),
            $candidates['audience'] ?? [],
            $identification->excerpts,
            array_values(array_unique([...$identification->ambiguousFields, ...(blank($identification->fields['active_ingredient'] ?? null) ? ['active_ingredient'] : [])])),
        );
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'heuristic_score' => $this->heuristicScore,
            'candidates' => (object) $this->candidates,
            'registration' => $this->registration,
            'audience_candidates' => $this->audienceCandidates,
            'evidence' => $this->evidence,
            'ambiguous_fields' => $this->ambiguousFields,
        ];
    }
}
