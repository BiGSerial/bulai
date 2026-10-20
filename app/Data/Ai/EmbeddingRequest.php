<?php

namespace App\Data\Ai;

final readonly class EmbeddingRequest
{
    public function __construct(
        public string $input,
        public ?string $model = null,
    ) {}
}
