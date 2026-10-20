<?php

namespace App\Data\Ai;

final readonly class EmbeddingBatchRequest
{
    /** @param list<string> $inputs */
    public function __construct(
        public array $inputs,
        public ?string $model = null,
    ) {}
}
