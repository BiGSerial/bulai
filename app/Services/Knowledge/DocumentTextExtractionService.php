<?php

namespace App\Services\Knowledge;

use App\Data\Knowledge\DocumentExtractionResult;
use Illuminate\Support\Facades\Log;

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

    private function elapsed(int $startedAt): int
    {
        return (int) ((hrtime(true) - $startedAt) / 1_000_000);
    }
}
