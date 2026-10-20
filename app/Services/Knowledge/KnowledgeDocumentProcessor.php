<?php

namespace App\Services\Knowledge;

use App\Data\Knowledge\DocumentExtractionResult;
use App\Data\Knowledge\ExtractedPdf;
use App\Data\Knowledge\KnowledgeChunkDraft;
use App\Models\Knowledge\KnowledgeDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class KnowledgeDocumentProcessor
{
    public function __construct(
        private readonly DocumentTextExtractionService $extractor,
        private readonly KnowledgeTextNormalizer $normalizer,
        private readonly KnowledgeSectionDetector $sectionDetector,
        private readonly KnowledgeChunker $chunker,
        private readonly KnowledgeFileService $fileService,
    ) {}

    /** @return array{status: string, chunks: int, error_code: ?string, error_id: ?string} */
    public function process(int $documentId, bool $force = false): array
    {
        $startedAt = hrtime(true);

        try {
            $snapshot = DB::transaction(function () use ($documentId, $force): ?array {
                $document = KnowledgeDocument::query()->whereKey($documentId)->lockForUpdate()->firstOrFail();
                if ($document->content_status === KnowledgeDocument::STATUS_READY && ! $force) {
                    return null;
                }

                $document->forceFill([
                    'content_status' => KnowledgeDocument::STATUS_PROCESSING,
                    'metadata' => $this->processingMetadata($document, ['started_at' => now()->toISOString()]),
                ])->save();

                $storage = $this->fileService->documentStorage($document);

                return ['storage' => $storage, 'sha256' => $document->sha256];
            });

            if ($snapshot === null) {
                return ['status' => 'SKIPPED', 'chunks' => 0, 'error_code' => null, 'error_id' => null];
            }

            Log::info('document_processing_started', ['document_id' => $documentId]);
            if ($snapshot['storage'] === null) {
                throw new RuntimeException('DOCUMENT_FILE_MISSING');
            }

            $this->assertStoredObjectIsProcessable($snapshot['storage']['disk'], $snapshot['storage']['key']);
            $extraction = $this->fileService->withLocalCopy(
                $snapshot['storage']['disk'],
                $snapshot['storage']['key'],
                fn (string $path): DocumentExtractionResult => $this->extractor->extract($path),
            );
            $this->assertOcrFallbackDidNotLeaveUnusableText($extraction);
            $extracted = $extraction->document;
            $this->assertExtractionLimits($extracted);
            $normalized = $this->normalizer->normalize($extracted);
            $this->assertTextIsUsable($normalized->characterCount());
            $sections = $this->sectionDetector->detect($normalized);
            $chunks = $this->chunker->chunk($sections);
            if ($chunks === []) {
                throw new RuntimeException('TEXT_EXTRACTION_FAILED');
            }

            Log::info('text_extracted', [
                'document_id' => $documentId,
                'pages' => count($normalized->pages),
                'characters' => $normalized->characterCount(),
                'extraction_method' => $extraction->method,
                'quality_score' => $extraction->quality->score,
                'quality_score_after_ocr' => $extraction->ocrQuality?->score,
                'fallback_reason' => $extraction->fallbackReason,
                'ocr_error_code' => $extraction->ocrErrorCode,
            ]);

            $contentHash = hash('sha256', implode("\n\f\n", array_map(fn ($page): string => $page->text, $normalized->pages)));
            DB::transaction(function () use ($documentId, $snapshot, $normalized, $sections, $chunks, $contentHash, $startedAt, $extraction): void {
                $document = KnowledgeDocument::query()->whereKey($documentId)->lockForUpdate()->firstOrFail();
                if ($this->fileService->documentStorage($document) !== $snapshot['storage'] || $document->sha256 !== $snapshot['sha256']) {
                    throw new RuntimeException('DOCUMENT_CHANGED_DURING_PROCESSING');
                }

                $document->chunks()->delete();
                $document->chunks()->createMany(array_map(fn (KnowledgeChunkDraft $chunk): array => [
                    'chunk_index' => $chunk->index,
                    'section' => $chunk->section,
                    'page_start' => $chunk->pageStart,
                    'page_end' => $chunk->pageEnd,
                    'content' => $chunk->content,
                    'token_count' => null,
                    'metadata' => [...$chunk->metadata, 'extraction_method' => $extraction->method],
                ], $chunks));

                $document->forceFill([
                    'content_status' => KnowledgeDocument::STATUS_READY,
                    'content_hash' => $contentHash,
                    'embedding_status' => KnowledgeDocument::STATUS_PENDING,
                    'processed_at' => now(),
                    'metadata' => $this->processingMetadata($document, [
                        'completed_at' => now()->toISOString(),
                        'pages' => count($normalized->pages),
                        'characters' => $normalized->characterCount(),
                        'sections' => count($sections),
                        'chunks' => count($chunks),
                        'duration_ms' => (int) ((hrtime(true) - $startedAt) / 1_000_000),
                        'extraction' => $extraction->metadata(),
                    ]),
                ])->save();
            });

            Log::info('chunks_created', ['document_id' => $documentId, 'chunks' => count($chunks)]);
            Log::info('document_processing_completed', [
                'document_id' => $documentId,
                'chunks' => count($chunks),
                'duration_ms' => (int) ((hrtime(true) - $startedAt) / 1_000_000),
            ]);

            return ['status' => 'READY', 'chunks' => count($chunks), 'error_code' => null, 'error_id' => null];
        } catch (Throwable $exception) {
            $errorId = Str::uuid()->toString();
            $errorCode = $this->controlledErrorCode($exception);
            $this->markFailed($documentId, $errorCode, $errorId);

            Log::error('document_processing_failed', [
                'document_id' => $documentId,
                'error_id' => $errorId,
                'error_code' => $errorCode,
                'duration_ms' => (int) ((hrtime(true) - $startedAt) / 1_000_000),
                'exception' => $exception,
            ]);

            return ['status' => 'FAILED', 'chunks' => 0, 'error_code' => $errorCode, 'error_id' => $errorId];
        }
    }

    private function assertStoredObjectIsProcessable(string $diskName, string $key): void
    {
        if (! $this->fileService->objectExists($diskName, $key)) {
            throw new RuntimeException('DOCUMENT_FILE_MISSING');
        }

        if ($this->fileService->objectSize($diskName, $key) > (int) config('knowledge.processing.max_pdf_bytes')) {
            throw new RuntimeException('PDF_SIZE_LIMIT_EXCEEDED');
        }
    }

    private function assertOcrFallbackDidNotLeaveUnusableText(DocumentExtractionResult $extraction): void
    {
        if ($extraction->ocrFailed()
            && ! in_array($extraction->ocrErrorCode, [DocumentOcrException::DISABLED, DocumentOcrException::NOT_CONFIGURED], true)
            && $extraction->document->characterCount() < (int) config('knowledge.processing.min_text_characters')) {
            throw new RuntimeException('OCR_UNAVAILABLE');
        }
    }

    private function assertExtractionLimits(ExtractedPdf $document): void
    {
        if (count($document->pages) === 0 || count($document->pages) > (int) config('knowledge.processing.max_pages')) {
            throw new RuntimeException('PDF_PAGE_LIMIT_EXCEEDED');
        }

        if ($document->characterCount() > (int) config('knowledge.processing.max_text_characters')) {
            throw new RuntimeException('TEXT_SIZE_LIMIT_EXCEEDED');
        }
    }

    private function assertTextIsUsable(int $characters): void
    {
        if ($characters < (int) config('knowledge.processing.min_text_characters')) {
            throw new RuntimeException('TEXT_EXTRACTION_FAILED');
        }
    }

    /** @param array<string, mixed> $processing */
    private function processingMetadata(KnowledgeDocument $document, array $processing): array
    {
        $metadata = $document->metadata ?? [];
        $metadata['processing'] = $processing;

        return $metadata;
    }

    private function markFailed(int $documentId, string $errorCode, string $errorId): void
    {
        DB::transaction(function () use ($documentId, $errorCode, $errorId): void {
            $document = KnowledgeDocument::query()->whereKey($documentId)->lockForUpdate()->first();
            if ($document === null || $document->content_status !== KnowledgeDocument::STATUS_PROCESSING) {
                return;
            }

            $document->forceFill([
                'content_status' => KnowledgeDocument::STATUS_FAILED,
                'processed_at' => now(),
                'metadata' => $this->processingMetadata($document, [
                    'failed_at' => now()->toISOString(),
                    'error_code' => $errorCode,
                    'error_id' => $errorId,
                ]),
            ])->save();
        });
    }

    private function controlledErrorCode(Throwable $exception): string
    {
        return in_array($exception->getMessage(), [
            'DOCUMENT_FILE_MISSING',
            'PDF_SIZE_LIMIT_EXCEEDED',
            'PDF_PAGE_LIMIT_EXCEEDED',
            'TEXT_SIZE_LIMIT_EXCEEDED',
            'TEXT_EXTRACTION_FAILED',
            'OCR_UNAVAILABLE',
            'DOCUMENT_CHANGED_DURING_PROCESSING',
        ], true) ? $exception->getMessage() : 'PROCESSING_ERROR';
    }
}
