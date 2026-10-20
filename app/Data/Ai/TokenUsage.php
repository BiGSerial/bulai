<?php

namespace App\Data\Ai;

final readonly class TokenUsage
{
    public function __construct(
        public ?int $inputTokens,
        public ?int $outputTokens,
    ) {}
}
