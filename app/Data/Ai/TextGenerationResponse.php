<?php

namespace App\Data\Ai;

final readonly class TextGenerationResponse
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public string $content,
        public string $model,
        public ?TokenUsage $usage,
        public array $metadata = [],
    ) {}
}
