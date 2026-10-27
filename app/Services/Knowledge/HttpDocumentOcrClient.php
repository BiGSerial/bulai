<?php

namespace App\Services\Knowledge;

use App\Data\Knowledge\ExtractedPage;
use App\Data\Knowledge\ExtractedPdf;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class HttpDocumentOcrClient implements PageLimitedDocumentOcrClient
{
    public function parse(string $localFile): ExtractedPdf
    {
        return $this->send($localFile, null);
    }

    public function parseFirstPages(string $localFile, int $pages): ExtractedPdf
    {
        return $this->send($localFile, max(1, $pages));
    }

    private function send(string $localFile, ?int $pageLimit): ExtractedPdf
    {
        $correlationId = Str::uuid()->toString();
        $config = config('document_ocr.drivers.http');
        $startedAt = hrtime(true);

        if (! config('document_ocr.enabled')) {
            throw new DocumentOcrException(DocumentOcrException::DISABLED, $correlationId);
        }

        if (blank($config['url'])) {
            throw new DocumentOcrException(DocumentOcrException::NOT_CONFIGURED, $correlationId);
        }

        $contents = $this->readPdf($localFile, (int) $config['max_pdf_bytes'], $correlationId);

        try {
            $response = $this->request($config, $correlationId)
                ->attach('file', $contents, 'document.pdf', ['Content-Type' => 'application/pdf'])
                ->post(rtrim((string) $config['url'], '/').'/v1/documents/parse', $pageLimit === null ? [] : ['page_limit' => $pageLimit]);
        } catch (ConnectionException $exception) {
            $code = str_contains($exception->getMessage(), 'timed out') || str_contains($exception->getMessage(), 'cURL error 28')
                ? DocumentOcrException::TIMEOUT
                : DocumentOcrException::UNAVAILABLE;
            $this->logFailure($code, $correlationId, $startedAt, null);

            throw new DocumentOcrException($code, $correlationId);
        }

        if ($response->failed()) {
            $code = match (true) {
                $response->status() === 429 => DocumentOcrException::RATE_LIMITED,
                $response->serverError() => DocumentOcrException::UNAVAILABLE,
                default => DocumentOcrException::REJECTED,
            };
            $this->logFailure($code, $correlationId, $startedAt, $response->status());

            throw new DocumentOcrException($code, $correlationId);
        }

        try {
            $result = $this->toExtractedPdf($response, $correlationId);
        } catch (DocumentOcrException $exception) {
            $this->logFailure($exception->errorCode, $correlationId, $startedAt, $response->status());

            throw $exception;
        }

        Log::info('document_ocr_completed', [
            'correlation_id' => $correlationId,
            'engine' => $result->metadata['engine'] ?? null,
            'pages' => count($result->pages),
            'duration_ms' => (int) ((hrtime(true) - $startedAt) / 1_000_000),
        ]);

        return $result;
    }

    private function readPdf(string $localFile, int $maxBytes, string $correlationId): string
    {
        if (! is_file($localFile) || ! is_readable($localFile)) {
            throw new DocumentOcrException(DocumentOcrException::INVALID_FILE, $correlationId);
        }

        if (filesize($localFile) > $maxBytes) {
            throw new DocumentOcrException(DocumentOcrException::PAYLOAD_TOO_LARGE, $correlationId);
        }

        $contents = (string) file_get_contents($localFile);
        if (! str_starts_with(ltrim(substr($contents, 0, 1024)), '%PDF-')) {
            throw new DocumentOcrException(DocumentOcrException::INVALID_FILE, $correlationId);
        }

        return $contents;
    }

    /** @param array<string, mixed> $config */
    private function request(array $config, string $correlationId): PendingRequest
    {
        $retries = max(0, (int) $config['retries']);
        $request = Http::acceptJson()
            ->timeout((int) $config['timeout'])
            ->connectTimeout((int) $config['connect_timeout'])
            ->withHeaders(['X-Correlation-Id' => $correlationId]);

        if (filled($config['token'])) {
            $request = $request->withToken((string) $config['token']);
        }

        if ($retries === 0) {
            return $request;
        }

        // Somente falhas transitórias (conexão, 429, 5xx) são repetidas; o parse é idempotente.
        return $request->retry(
            $retries + 1,
            (int) $config['retry_sleep_ms'],
            fn (\Throwable $exception): bool => $exception instanceof ConnectionException
                ? ! str_contains($exception->getMessage(), 'cURL error 28')
                : ($exception->response ?? null) === null || $exception->response->status() === 429 || $exception->response->serverError(),
            throw: false,
        );
    }

    private function toExtractedPdf(Response $response, string $correlationId): ExtractedPdf
    {
        if (strlen($response->body()) > 32 * 1024 * 1024) {
            throw new DocumentOcrException(DocumentOcrException::INVALID_RESPONSE, $correlationId);
        }

        $payload = $response->json();
        $rawPages = is_array($payload) ? ($payload['pages'] ?? null) : null;
        if (! is_array($rawPages) || $rawPages === [] || count($rawPages) > (int) config('knowledge.processing.max_pages')) {
            throw new DocumentOcrException(DocumentOcrException::INVALID_RESPONSE, $correlationId);
        }

        $pages = [];
        foreach ($rawPages as $rawPage) {
            if (! is_array($rawPage) || ! is_int($rawPage['page'] ?? null) || $rawPage['page'] < 1
                || ! is_string($rawPage['text'] ?? null)
                || (isset($rawPage['confidence']) && ! is_numeric($rawPage['confidence']))
                || isset($pages[$rawPage['page']])) {
                throw new DocumentOcrException(DocumentOcrException::INVALID_RESPONSE, $correlationId);
            }

            $pages[$rawPage['page']] = new ExtractedPage(
                $rawPage['page'],
                $rawPage['text'],
                isset($rawPage['confidence']) ? (float) $rawPage['confidence'] : null,
            );
        }
        ksort($pages);

        return new ExtractedPdf(array_values($pages), array_filter([
            'engine' => is_string($payload['engine'] ?? null) ? $payload['engine'] : null,
            'engine_version' => is_string($payload['engine_version'] ?? null) ? $payload['engine_version'] : null,
            'duration_ms' => is_numeric($payload['processing']['duration_ms'] ?? null) ? (int) $payload['processing']['duration_ms'] : null,
            'correlation_id' => $correlationId,
        ], fn (mixed $value): bool => $value !== null));
    }

    private function logFailure(string $code, string $correlationId, int $startedAt, ?int $status): void
    {
        Log::warning('document_ocr_failed', [
            'correlation_id' => $correlationId,
            'error_code' => $code,
            'http_status' => $status,
            'duration_ms' => (int) ((hrtime(true) - $startedAt) / 1_000_000),
        ]);
    }
}
