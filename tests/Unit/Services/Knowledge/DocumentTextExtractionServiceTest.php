<?php

namespace Tests\Unit\Services\Knowledge;

use App\Data\Knowledge\ExtractedPage;
use App\Data\Knowledge\ExtractedPdf;
use App\Services\Knowledge\DocumentOcrClient;
use App\Services\Knowledge\DocumentOcrException;
use App\Services\Knowledge\DocumentTextExtractionService;
use App\Services\Knowledge\HeuristicExtractionQualityEvaluator;
use App\Services\Knowledge\PdfTextExtractor;
use Tests\TestCase;

final class DocumentTextExtractionServiceTest extends TestCase
{
    public function test_good_text_extraction_does_not_call_ocr(): void
    {
        $ocr = $this->ocr(new ExtractedPdf([new ExtractedPage(1, 'ocr')]));

        $result = $this->service($this->text($this->goodText()), $ocr)->extract('/fake.pdf');

        $this->assertSame(0, $ocr->calls);
        $this->assertSame('pdf_text', $result->method);
        $this->assertNull($result->fallbackReason);
        $this->assertSame('pdf_text', $result->metadata()['extraction_method']);
        $this->assertSame(1.0, $result->metadata()['quality_score']);
    }

    public function test_image_only_pdf_triggers_ocr_and_records_audit_metadata(): void
    {
        $ocr = $this->ocr(new ExtractedPdf([new ExtractedPage(1, 'a'), new ExtractedPage(2, $this->goodText()->pages[0]->text)], ['engine' => 'paddleocr', 'engine_version' => '3.0']));

        $result = $this->service($this->text(new ExtractedPdf([new ExtractedPage(1, ''), new ExtractedPage(2, '')])), $ocr)->extract('/fake.pdf');

        $this->assertSame(1, $ocr->calls);
        $this->assertSame('ocr', $result->method);
        $this->assertSame([1, 2], array_map(fn ($page) => $page->number, $result->document->pages));
        $metadata = $result->metadata();
        $this->assertSame('paddleocr', $metadata['ocr_engine']);
        $this->assertSame('3.0', $metadata['ocr_version']);
        $this->assertSame('NO_TEXT', $metadata['fallback_reason']);
        $this->assertSame(0.0, $metadata['quality_score']);
        $this->assertSame(2, $metadata['pages_processed']);
        $this->assertArrayHasKey('processing_duration', $metadata);
        $this->assertArrayHasKey('quality_score_after_ocr', $metadata);
    }

    public function test_poor_text_extraction_triggers_fallback(): void
    {
        $ocr = $this->ocr($this->goodText());

        $result = $this->service($this->text(new ExtractedPdf([new ExtractedPage(1, 'Pouco texto.')])), $ocr)->extract('/fake.pdf');

        $this->assertSame(1, $ocr->calls);
        $this->assertSame('ocr', $result->method);
        $this->assertStringContainsString('LOW_CHARACTER_COUNT', $result->fallbackReason);
    }

    public function test_ocr_failure_keeps_text_extraction_with_controlled_error(): void
    {
        $poor = new ExtractedPdf([new ExtractedPage(1, 'Pouco texto.')]);

        $result = $this->service($this->text($poor), $this->ocr(null, DocumentOcrException::UNAVAILABLE))->extract('/fake.pdf');

        $this->assertSame('pdf_text', $result->method);
        $this->assertSame($poor, $result->document);
        $this->assertTrue($result->ocrFailed());
        $this->assertSame('OCR_UNAVAILABLE', $result->metadata()['ocr_error_code']);
    }

    private function service(PdfTextExtractor $text, DocumentOcrClient $ocr): DocumentTextExtractionService
    {
        return new DocumentTextExtractionService($text, new HeuristicExtractionQualityEvaluator, $ocr);
    }

    private function goodText(): ExtractedPdf
    {
        return new ExtractedPdf([new ExtractedPage(1, str_repeat("Este medicamento é indicado para o tratamento de dores e febre em adultos.\nUsar conforme orientação médica.\n", 12))]);
    }

    private function text(ExtractedPdf $document): PdfTextExtractor
    {
        return new class($document) implements PdfTextExtractor
        {
            public function __construct(private readonly ExtractedPdf $document) {}

            public function extract(string $absolutePath): ExtractedPdf
            {
                return $this->document;
            }
        };
    }

    private function ocr(?ExtractedPdf $document, ?string $failWith = null): object
    {
        return new class($document, $failWith) implements DocumentOcrClient
        {
            public int $calls = 0;

            public function __construct(private readonly ?ExtractedPdf $document, private readonly ?string $failWith) {}

            public function parse(string $localFile): ExtractedPdf
            {
                $this->calls++;
                if ($this->failWith !== null) {
                    throw new DocumentOcrException($this->failWith);
                }

                return $this->document;
            }
        };
    }
}
