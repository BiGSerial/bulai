<?php

namespace App\Data\Knowledge;

final readonly class DocumentIdentification
{
    /** @param array<string, string|null> $fields @param array<string, array{value: string, page: int, pattern: string}> $evidence @param list<string> $ambiguousFields */
    public function __construct(public string $extractor, public array $fields, public array $evidence, public array $ambiguousFields, public int $score, public bool $automaticCreationEligible, public int $pagesAnalyzed, public ?string $errorCode = null, public array $regulatoryValidation = [], public array $candidates = [], public array $excerpts = [], public array $classification = [], public array $extraction = []) {}

    /** @param array<string, mixed> $changes Propriedades a substituir (nomes iguais aos do construtor). */
    public function with(array $changes): self
    {
        return new self(...[...get_object_vars($this), ...$changes]);
    }

    /**
     * @param  array<string, mixed>  $classification
     * @param  array<string, string|null>|null  $fields
     * @param  list<string>|null  $ambiguousFields
     */
    public function withClassification(array $classification, ?array $fields = null, ?array $ambiguousFields = null, ?bool $eligible = null): self
    {
        return $this->with(array_filter([
            'classification' => $classification, 'fields' => $fields, 'ambiguousFields' => $ambiguousFields, 'automaticCreationEligible' => $eligible,
        ], fn (mixed $value): bool => $value !== null));
    }

    /** @return array<string, mixed> */
    public function metadata(): array
    {
        return ['extractor' => $this->extractor, 'fields' => array_filter($this->fields, fn (?string $value): bool => $value !== null),
            'evidence' => $this->evidence, 'ambiguous_fields' => $this->ambiguousFields, 'score' => $this->score,
            'automatic_creation_eligible' => $this->automaticCreationEligible, 'pages_analyzed' => $this->pagesAnalyzed,
            'error_code' => $this->errorCode, 'regulatory_validation' => $this->regulatoryValidation,
            'classification' => $this->classification === [] ? null : $this->classification,
            'extraction' => $this->extraction === [] ? null : $this->extraction];
    }

    public function sourceDocumentId(): ?string
    {
        return $this->ambiguousFields === [] ? ($this->fields['registration_number_normalized'] ?? null) : null;
    }
}
