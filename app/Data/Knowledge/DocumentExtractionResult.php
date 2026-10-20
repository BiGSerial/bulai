<?php

namespace App\Data\Knowledge;

final readonly class DocumentExtractionResult
{
    public const METHOD_PDF_TEXT = 'pdf_text';

    public const METHOD_OCR = 'ocr';

    /**
     * @param  ?string  $fallbackReason  Motivo do acionamento do OCR (códigos da avaliação de qualidade).
     * @param  ?string  $ocrErrorCode  Preenchido quando o OCR foi tentado e falhou.
     */
    public function __construct(
        public ExtractedPdf $document,
        public string $method,
        public string $extractor,
        public ExtractionQuality $quality,
        public ?ExtractionQuality $ocrQuality = null,
        public ?string $fallbackReason = null,
        public ?string $ocrErrorCode = null,
        public int $durationMs = 0,
    ) {}

    public function ocrFailed(): bool
    {
        return $this->ocrErrorCode !== null;
    }

    /** @return array<string, mixed> */
    public function metadata(): array
    {
        $ocr = $this->method === self::METHOD_OCR;

        return array_filter([
            'extraction_method' => $this->method,
            'extractor' => $this->extractor,
            'ocr_engine' => $ocr ? ($this->document->metadata['engine'] ?? null) : null,
            'ocr_version' => $ocr ? ($this->document->metadata['engine_version'] ?? null) : null,
            'quality_score' => $this->quality->score,
            'quality_score_after_ocr' => $this->ocrQuality?->score,
            'quality_reasons' => $this->quality->reasons,
            'fallback_reason' => $this->fallbackReason,
            'ocr_error_code' => $this->ocrErrorCode,
            'pages_processed' => count($this->document->pages),
            'processing_duration' => $this->durationMs,
        ], fn (mixed $value): bool => $value !== null);
    }
}
