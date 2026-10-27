<?php

namespace App\Services\Knowledge;

use App\Data\Knowledge\DocumentExtractionResult;
use App\Data\Knowledge\ExtractedPage;
use App\Data\Knowledge\ExtractedPdf;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Orquestra a extração: texto nativo primeiro (PdfTextExtractor), avaliação de qualidade
 * e OCR somente como fallback quando a extração nativa não é confiável.
 */
final class DocumentTextExtractionService
{
    public function __construct(
        private readonly PdfTextExtractor $textExtractor,
        private readonly DocumentExtractionQualityEvaluator $qualityEvaluator,
        private readonly DocumentOcrClient $ocrClient,
    ) {}

    public function extract(string $absolutePath): DocumentExtractionResult
    {
        $startedAt = hrtime(true);
        $document = $this->textExtractor->extract($absolutePath);
        $quality = $this->qualityEvaluator->evaluate($document);
        $extractor = explode("\0", $this->textExtractor::class)[0];

        if ($quality->isAcceptable) {
            return new DocumentExtractionResult($document, DocumentExtractionResult::METHOD_PDF_TEXT, $extractor, $quality, durationMs: $this->elapsed($startedAt));
        }

        $fallbackReason = implode(',', $quality->reasons);

        try {
            $ocrDocument = $this->ocrClient->parse($absolutePath);
        } catch (DocumentOcrException $exception) {
            Log::warning('document_extraction_ocr_unavailable', [
                'error_code' => $exception->errorCode,
                'correlation_id' => $exception->correlationId,
                'quality_score' => $quality->score,
                'fallback_reason' => $fallbackReason,
            ]);

            return new DocumentExtractionResult($document, DocumentExtractionResult::METHOD_PDF_TEXT, $extractor, $quality, fallbackReason: $fallbackReason, ocrErrorCode: $exception->errorCode, durationMs: $this->elapsed($startedAt));
        }

        $ocrQuality = $this->qualityEvaluator->evaluate($ocrDocument);
        if ($ocrQuality->score < $quality->score) {
            return new DocumentExtractionResult($document, DocumentExtractionResult::METHOD_PDF_TEXT, $extractor, $quality, $ocrQuality, $fallbackReason, 'OCR_WORSE_THAN_TEXT', $this->elapsed($startedAt));
        }

        Log::info('document_extraction_ocr_used', [
            'quality_score_before' => $quality->score,
            'quality_score_after' => $ocrQuality->score,
            'engine' => $ocrDocument->metadata['engine'] ?? null,
            'pages' => count($ocrDocument->pages),
        ]);

        return new DocumentExtractionResult($ocrDocument, DocumentExtractionResult::METHOD_OCR, $extractor, $quality, $ocrQuality, $fallbackReason, durationMs: $this->elapsed($startedAt));
    }

    /**
     * OCR só das primeiras páginas, mesclado ao texto nativo: as linhas que o OCR enxergou e o texto nativo não têm
     * (ex.: capa em imagem, marca em logotipo) são acrescentadas ao fim de cada página. O texto nativo nunca é substituído.
     *
     * @return array{document: ExtractedPdf, added_lines: int, engine: ?string}|null null quando o OCR não está disponível ou não acrescentou nada
     */
    public function supplementFirstPages(ExtractedPdf $document, string $absolutePath, int $pages): ?array
    {
        if ($pages < 1 || ! $this->ocrClient instanceof PageLimitedDocumentOcrClient) {
            return null;
        }

        try {
            $ocr = $this->ocrClient->parseFirstPages($absolutePath, $pages);
        } catch (DocumentOcrException $exception) {
            Log::warning('document_extraction_ocr_supplement_unavailable', ['error_code' => $exception->errorCode, 'correlation_id' => $exception->correlationId]);

            return null;
        }

        $byNumber = [];
        foreach ($ocr->pages as $ocrPage) {
            $byNumber[$ocrPage->number] = $ocrPage;
        }
        $added = 0;
        $merged = [];
        foreach ($document->pages as $page) {
            $ocrPage = $byNumber[$page->number] ?? null;
            if ($ocrPage === null) {
                $merged[] = $page;

                continue;
            }
            $known = [];
            foreach (preg_split('/\R/u', $page->text) ?: [] as $line) {
                $known[$this->lineKey($line)] = true;
            }
            $extra = [];
            foreach (preg_split('/\R/u', $ocrPage->text) ?: [] as $line) {
                $key = $this->lineKey($line);
                if ($key !== '' && ! isset($known[$key])) {
                    $known[$key] = true;
                    $extra[] = trim($line);
                }
            }
            $added += count($extra);
            $merged[] = $extra === [] ? $page : new ExtractedPage($page->number, rtrim($page->text)."\n".implode("\n", $extra), $page->confidence);
        }

        if ($added === 0) {
            return null;
        }

        return ['document' => new ExtractedPdf($merged, [...$document->metadata, 'ocr_supplement_pages' => count($ocr->pages)]), 'added_lines' => $added, 'engine' => $ocr->metadata['engine'] ?? null];
    }

    private function lineKey(string $line): string
    {
        return preg_replace('/[^a-z0-9]+/', '', Str::ascii(mb_strtolower($line))) ?? '';
    }

    private function elapsed(int $startedAt): int
    {
        return (int) ((hrtime(true) - $startedAt) / 1_000_000);
    }
}
