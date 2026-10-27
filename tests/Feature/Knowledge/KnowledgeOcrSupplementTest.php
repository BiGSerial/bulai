<?php

namespace Tests\Feature\Knowledge;

use App\Data\Knowledge\DocumentIdentification;
use App\Data\Knowledge\ExtractedPage;
use App\Data\Knowledge\ExtractedPdf;
use App\Models\Knowledge\KnowledgeSource;
use App\Services\Knowledge\DocumentOcrClient;
use App\Services\Knowledge\DocumentOcrException;
use App\Services\Knowledge\KnowledgeDocumentIdentificationService;
use App\Services\Knowledge\PageLimitedDocumentOcrClient;
use App\Services\Knowledge\PdfTextExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** OCR complementar das primeiras páginas quando a heurística, mesmo com texto nativo, não está confiante. */
final class KnowledgeOcrSupplementTest extends TestCase
{
    use RefreshDatabase;

    public function test_weak_native_cover_is_completed_by_ocr_and_the_better_result_is_adopted(): void
    {
        $ocr = $this->bind($this->coverlessNativeText(), $this->limitedOcr(new ExtractedPdf([new ExtractedPage(1, "GLIFAGE®\ncloridrato de metformina\nMerck S/A\nBula para o profissional de saúde")], ['engine' => 'paddleocr'])));

        $result = $this->identify();

        $this->assertSame(3, $ocr->requestedPages);
        $this->assertSame('GLIFAGE', $result->fields['product_name']);
        $this->assertSame('cloridrato de metformina', $result->fields['active_ingredient']);
        $supplement = $result->extraction['ocr_supplement'];
        $this->assertTrue($supplement['adopted']);
        $this->assertGreaterThan($supplement['score_before'], $supplement['score_after']);
        $this->assertSame('paddleocr', $supplement['engine']);
        $this->assertSame('pdf_text', $result->extraction['extraction_method'], 'O texto nativo continua sendo o método principal.');
    }

    public function test_supplement_is_discarded_when_it_makes_the_identification_worse(): void
    {
        $native = new ExtractedPdf([new ExtractedPage(1, "GLIFAGE\n®\ncloridrato de metformina\nMerck S/A\nRegistro MS 1.0089.0416"), ...$this->filler()]);
        $this->bind($native, $this->limitedOcr(new ExtractedPdf([new ExtractedPage(1, 'Registro MS 1.0089.0999')])));

        $result = $this->identify();

        $supplement = $result->extraction['ocr_supplement'];
        $this->assertFalse($supplement['adopted'], 'O OCR criou ambiguidade de registro: pontuação pior, resultado descartado.');
        $this->assertLessThan($supplement['score_before'], $supplement['score_after']);
        $this->assertSame('100890416', $result->fields['registration_number_normalized'], 'Vale o resultado do texto nativo.');
    }

    public function test_no_supplement_when_the_heuristic_is_confident_or_ocr_is_unavailable(): void
    {
        $confident = $this->bind(new ExtractedPdf([new ExtractedPage(1, "GLIFAGE\n®\ncloridrato de metformina\nMerck S/A\nBula para o profissional de saúde\nRegistro MS 1.0089.0416"), ...$this->filler()]), $this->limitedOcr(new ExtractedPdf([new ExtractedPage(1, 'x')])));
        $this->identify();
        $this->assertNull($confident->requestedPages, 'Pontuação alta: o OCR extra não é chamado.');

        $this->bind($this->coverlessNativeText(), $this->limitedOcr(null, DocumentOcrException::UNAVAILABLE));
        $this->assertArrayNotHasKey('ocr_supplement', $this->identify()->extraction);
    }

    public function test_supplement_can_be_disabled_by_configuration(): void
    {
        config()->set('document_ocr.supplement_pages', 0);
        $ocr = $this->bind($this->coverlessNativeText(), $this->limitedOcr(new ExtractedPdf([new ExtractedPage(1, 'GLIFAGE®')])));

        $this->identify();

        $this->assertNull($ocr->requestedPages);
    }

    private function identify(): DocumentIdentification
    {
        $source = KnowledgeSource::query()->firstOrCreate(['name' => 'ANVISA', 'organization' => 'ANVISA'], ['collection' => 'Bulário Eletrônico', 'active' => true]);
        $file = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($file, '%PDF-1.4 fake');

        try {
            return app(KnowledgeDocumentIdentificationService::class)->identifyPath($source, $file, deep: true);
        } finally {
            @unlink($file);
        }
    }

    private function coverlessNativeText(): ExtractedPdf
    {
        $composition = (string) file_get_contents(base_path('tests/Fixtures/knowledge/anvisa-glifage-page2.txt'));

        return new ExtractedPdf([new ExtractedPage(1, 'Bula para o profissional de saúde'), new ExtractedPage(2, str_replace(["Glifage\n®\ncloridrato de metformina \n", 'APRESENTAÇÕES'], ['', 'APRESENTAÇÕES'], $composition)), ...$this->filler()]);
    }

    /** @return list<ExtractedPage> Texto de corpo suficiente para a qualidade nativa ser aceitável (sem OCR integral). */
    private function filler(): array
    {
        $text = str_repeat('Como agente antidiabético associado ao regime alimentar para o tratamento do diabetes mellitus tipo dois em adultos e crianças. ', 6);

        return [new ExtractedPage(3, $text), new ExtractedPage(4, $text)];
    }

    private function bind(ExtractedPdf $native, object $ocr): object
    {
        $this->app->instance(PdfTextExtractor::class, new class($native) implements PdfTextExtractor
        {
            public function __construct(private readonly ExtractedPdf $pdf) {}

            public function extract(string $absolutePath): ExtractedPdf
            {
                return $this->pdf;
            }
        });
        $this->app->instance(DocumentOcrClient::class, $ocr);
        config()->set('document_ocr.enabled', true);

        return $ocr;
    }

    private function limitedOcr(?ExtractedPdf $document, ?string $failWith = null): object
    {
        return new class($document, $failWith) implements PageLimitedDocumentOcrClient
        {
            public ?int $requestedPages = null;

            public function __construct(private readonly ?ExtractedPdf $document, private readonly ?string $failWith) {}

            public function parse(string $localFile): ExtractedPdf
            {
                return $this->parseFirstPages($localFile, 0);
            }

            public function parseFirstPages(string $localFile, int $pages): ExtractedPdf
            {
                $this->requestedPages = $pages;
                if ($this->failWith !== null) {
                    throw new DocumentOcrException($this->failWith);
                }

                return $this->document;
            }
        };
    }
}
