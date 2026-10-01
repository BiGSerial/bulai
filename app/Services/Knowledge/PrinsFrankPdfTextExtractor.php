<?php

namespace App\Services\Knowledge;

use App\Data\Knowledge\ExtractedPage;
use App\Data\Knowledge\ExtractedPdf;
use PrinsFrank\PdfParser\PdfParser;

final class PrinsFrankPdfTextExtractor implements PdfTextExtractor
{
    public function __construct(private readonly PdfParser $parser) {}

    public function extract(string $absolutePath): ExtractedPdf
    {
        $document = $this->parser->parseFile($absolutePath, useInMemoryStream: false);
        $pages = [];

        foreach ($document->getPages() as $index => $page) {
            $pages[] = new ExtractedPage(number: $index + 1, text: $page->getText());
        }

        return new ExtractedPdf($pages);
    }
}
