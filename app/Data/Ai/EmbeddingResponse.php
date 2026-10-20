<?php

namespace App\Data\Ai;

final readonly class EmbeddingResponse
{
    /**
     * @param  list<float>  $vector
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public array $vector,
        public string $model,
        public ?int $inputTokens,
        public array $metadata = [],
    ) {}

    public function dimensions(): int
    {
        return count($this->vector);
    }
}
