<?php

namespace App\Services\Knowledge;

use App\Data\Knowledge\ExtractedPage;
use App\Data\Knowledge\ExtractedPdf;

final class KnowledgeTextNormalizer
{
    public function normalize(ExtractedPdf $document): ExtractedPdf
    {
        return new ExtractedPdf(array_map(
            fn (ExtractedPage $page): ExtractedPage => new ExtractedPage($page->number, $this->normalizeText($page->text), $page->confidence),
            $document->pages,
        ), $document->metadata);
    }

    public function normalizeText(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? $text;
        $text = preg_replace('/[\t ]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/ *\n */u', "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;

        return trim($text);
    }
}
