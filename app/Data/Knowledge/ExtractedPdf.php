<?php

namespace App\Data\Knowledge;

final readonly class ExtractedPdf
{
    /** @param list<ExtractedPage> $pages */
    public function __construct(public array $pages) {}

    public function characterCount(): int
    {
        return array_sum(array_map(fn (ExtractedPage $page): int => mb_strlen($page->text), $this->pages));
    }
}
