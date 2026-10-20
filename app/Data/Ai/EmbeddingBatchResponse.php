<?php

namespace App\Data\Ai;

final readonly class EmbeddingBatchResponse
{
    /**
     * @param  list<EmbeddingResponse>  $embeddings  Na mesma ordem dos inputs da requisição.
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public array $embeddings,
        public string $model,
        public ?int $inputTokens,
        public array $metadata = [],
    ) {}
}
