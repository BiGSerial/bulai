<?php

namespace App\Data\Knowledge;

final readonly class IngredientResolution
{
    public const RESOLVED = 'resolved';

    public const AMBIGUOUS = 'ambiguous';

    public const UNRESOLVED = 'unresolved';

    public function __construct(
        public string $status,
        public ?int $ingredientId = null,
        public ?string $method = null,
        public ?float $confidence = null,
    ) {}

    public function isResolved(): bool
    {
        return $this->status === self::RESOLVED;
    }
}
