<?php

namespace Tests\Unit\Services\Knowledge;

use App\Services\Knowledge\PrinsFrankPdfTextExtractor;
use PHPUnit\Framework\TestCase;
use PrinsFrank\PdfParser\Exception\PdfParserException;
use PrinsFrank\PdfParser\PdfParser;

class PrinsFrankPdfTextExtractorTest extends TestCase
{
    public function test_extracts_text_in_page_order_with_one_based_numbers(): void
    {
        $document = (new PrinsFrankPdfTextExtractor(new PdfParser))
            ->extract(dirname(__DIR__, 3).'/Fixtures/knowledge/multipage-text.pdf');

        $this->assertCount(3, $document->pages);
        $this->assertSame([1, 2, 3], array_column($document->pages, 'number'));
        $this->assertStringContainsString('Pagina um', $document->pages[0]->text);
        $this->assertStringContainsString('Pagina dois', $document->pages[1]->text);
        $this->assertStringContainsString('Pagina tres', $document->pages[2]->text);
        $this->assertStringContainsString('500 mg', $document->pages[0]->text);
        $this->assertStringContainsString('20 mg/mL', $document->pages[0]->text);
        $this->assertStringContainsString('0,5 mg', $document->pages[1]->text);
        $this->assertStringContainsString('5%', $document->pages[1]->text);
    }

    public function test_pdf_without_text_returns_an_empty_page(): void
    {
        $document = (new PrinsFrankPdfTextExtractor(new PdfParser))
            ->extract(dirname(__DIR__, 3).'/Fixtures/knowledge/no-text.pdf');

        $this->assertCount(1, $document->pages);
        $this->assertSame('', $document->pages[0]->text);
    }

    public function test_invalid_pdf_throws_a_parser_exception(): void
    {
        $this->expectException(PdfParserException::class);

        (new PrinsFrankPdfTextExtractor(new PdfParser))
            ->extract(dirname(__DIR__, 3).'/Fixtures/knowledge/invalid.pdf');
    }
}
