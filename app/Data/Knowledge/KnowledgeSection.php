<?php

namespace App\Data\Knowledge;

final readonly class KnowledgeSection
{
    /** @param list<KnowledgeTextBlock> $blocks */
    public function __construct(
        public ?string $name,
        public ?string $originalTitle,
        public array $blocks,
    ) {}
}
