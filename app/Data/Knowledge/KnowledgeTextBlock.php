<?php

namespace App\Data\Knowledge;

final readonly class KnowledgeTextBlock
{
    public function __construct(
        public int $page,
        public string $text,
    ) {}
}
