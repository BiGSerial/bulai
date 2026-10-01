<?php

namespace App\Data\Knowledge;

final readonly class KnowledgeChunkDraft
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public int $index,
        public ?string $section,
        public int $pageStart,
        public int $pageEnd,
        public string $content,
        public array $metadata,
    ) {}
}
