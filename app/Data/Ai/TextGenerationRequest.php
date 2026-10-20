<?php

namespace App\Data\Ai;

final readonly class TextGenerationRequest
{
    /** @param array<string, scalar|null> $metadata Rótulos de rastreabilidade; nunca enviados ao provider. */
    public function __construct(
        public string $prompt,
        public ?string $system = null,
        public ?float $temperature = null,
        public ?int $maxOutputTokens = null,
        public ?string $model = null,
        public array $metadata = [],
    ) {}
}
