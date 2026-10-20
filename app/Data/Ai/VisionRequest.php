<?php

namespace App\Data\Ai;

final readonly class VisionRequest
{
    /**
     * @param  list<VisionImage>  $images
     * @param  array<string, scalar|null>  $metadata
     */
    public function __construct(
        public string $prompt,
        public array $images,
        public ?string $model = null,
        public ?int $maxOutputTokens = null,
        public array $metadata = [],
    ) {}
}
