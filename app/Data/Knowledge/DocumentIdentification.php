<?php

namespace App\Data\Knowledge;

final readonly class DocumentIdentification
{
    /** @param array<string, string|null> $fields @param array<string, array{value: string, page: int, pattern: string}> $evidence @param list<string> $ambiguousFields */
    public function __construct(public string $extractor, public array $fields, public array $evidence, public array $ambiguousFields, public int $score, public bool $automaticCreationEligible, public int $pagesAnalyzed, public ?string $errorCode = null) {}

    /** @return array<string, mixed> */
    public function metadata(): array
    {
        return ['extractor' => $this->extractor, 'fields' => array_filter($this->fields, fn (?string $value): bool => $value !== null),
            'evidence' => $this->evidence, 'ambiguous_fields' => $this->ambiguousFields, 'score' => $this->score,
            'automatic_creation_eligible' => $this->automaticCreationEligible, 'pages_analyzed' => $this->pagesAnalyzed, 'error_code' => $this->errorCode];
    }

    public function sourceDocumentId(): ?string
    {
        return $this->ambiguousFields === [] ? ($this->fields['registration_number_normalized'] ?? null) : null;
    }
}
