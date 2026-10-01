<?php

namespace App\Data\Knowledge;

final readonly class ExtractedPage
{
    public function __construct(
        public int $number,
        public string $text,
    ) {}
}
