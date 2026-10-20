<?php

namespace Tests\Unit\Services\Knowledge;

use App\Services\Knowledge\DocumentOcrException;
use App\Services\Knowledge\HttpDocumentOcrClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class HttpDocumentOcrClientTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('document_ocr.enabled', true);
        config()->set('document_ocr.drivers.http.url', 'http://ocr.internal:8090');
        config()->set('document_ocr.drivers.http.token', 'super-secret-token');
        config()->set('document_ocr.drivers.http.retry_sleep_ms', 0);
        $this->file = tempnam(sys_get_temp_dir(), 'ocr').'.pdf';
        file_put_contents($this->file, "%PDF-1.4\nfake");
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        parent::tearDown();
    }

    public function test_ocr_response_becomes_ordered_extracted_pages_with_engine_metadata(): void
    {
        Http::fake(['*' => Http::response([
            'engine' => 'paddleocr',
            'engine_version' => '3.0.0',
            'pages' => [
                ['page' => 2, 'text' => 'Segunda', 'confidence' => 0.9, 'blocks' => []],
                ['page' => 1, 'text' => 'Primeira', 'confidence' => null],
            ],
            'processing' => ['duration_ms' => 1234],
        ])]);

        $document = (new HttpDocumentOcrClient)->parse($this->file);

        $this->assertSame([1, 2], array_map(fn ($page) => $page->number, $document->pages));
        $this->assertSame('Primeira', $document->pages[0]->text);
        $this->assertNull($document->pages[0]->confidence);
        $this->assertSame(0.9, $document->pages[1]->confidence);
        $this->assertSame('paddleocr', $document->metadata['engine']);
        $this->assertSame(1234, $document->metadata['duration_ms']);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://ocr.internal:8090/v1/documents/parse'
            && $request->hasHeader('Authorization', 'Bearer super-secret-token')
            && $request->hasHeader('X-Correlation-Id'));
    }

    public function test_disabled_or_unconfigured_ocr_does_not_call_the_service(): void
    {
        Http::fake();
        config()->set('document_ocr.enabled', false);
        $this->assertOcrError(DocumentOcrException::DISABLED);

        config()->set('document_ocr.enabled', true);
        config()->set('document_ocr.drivers.http.url', null);
        $this->assertOcrError(DocumentOcrException::NOT_CONFIGURED);
        Http::assertNothingSent();
    }

    public function test_non_pdf_and_oversized_files_are_rejected_before_sending(): void
    {
        Http::fake();
        file_put_contents($this->file, 'not a pdf');
        $this->assertOcrError(DocumentOcrException::INVALID_FILE);

        file_put_contents($this->file, "%PDF-1.4\nfake");
        config()->set('document_ocr.drivers.http.max_pdf_bytes', 4);
        $this->assertOcrError(DocumentOcrException::PAYLOAD_TOO_LARGE);
        Http::assertNothingSent();
    }

    public function test_http_errors_map_to_controlled_codes_without_leaking_secrets(): void
    {
        config()->set('document_ocr.drivers.http.retries', 0);

        foreach ([429 => DocumentOcrException::RATE_LIMITED, 503 => DocumentOcrException::UNAVAILABLE, 422 => DocumentOcrException::REJECTED] as $status => $code) {
            $this->fakeHttp(['*' => Http::response(['detail' => 'super-secret-token http://ocr.internal'], $status)]);
            $exception = $this->assertOcrError($code);
            $this->assertStringNotContainsString('super-secret-token', $exception->getMessage());
            $this->assertStringNotContainsString('ocr.internal', $exception->getMessage());
        }
    }

    public function test_connection_failure_and_timeout_are_controlled_and_transient_failures_are_retried(): void
    {
        config()->set('document_ocr.drivers.http.retries', 1);
        $this->fakeHttp(['*' => Http::sequence()->push('', 503)->push(['pages' => [['page' => 1, 'text' => 'ok']]])]);
        $this->assertSame('ok', (new HttpDocumentOcrClient)->parse($this->file)->pages[0]->text);

        $this->fakeHttp(fn () => throw new ConnectionException('cURL error 28: Operation timed out for http://ocr.internal'));
        $exception = $this->assertOcrError(DocumentOcrException::TIMEOUT);
        $this->assertStringNotContainsString('ocr.internal', $exception->getMessage());

        $this->fakeHttp(fn () => throw new ConnectionException('cURL error 7: Failed to connect'));
        $this->assertOcrError(DocumentOcrException::UNAVAILABLE);
    }

    public function test_malformed_responses_are_rejected(): void
    {
        foreach ([['pages' => []], ['pages' => [['page' => 1]]], ['pages' => [['page' => 1, 'text' => 'a'], ['page' => 1, 'text' => 'b']]], ['x' => 1]] as $body) {
            $this->fakeHttp(['*' => Http::response($body)]);
            $this->assertOcrError(DocumentOcrException::INVALID_RESPONSE);
        }
    }

    private function fakeHttp(mixed $stub): void
    {
        Http::swap(new Factory);
        Http::fake($stub);
    }

    private function assertOcrError(string $code): DocumentOcrException
    {
        try {
            (new HttpDocumentOcrClient)->parse($this->file);
        } catch (DocumentOcrException $exception) {
            $this->assertSame($code, $exception->errorCode);

            return $exception;
        }

        $this->fail("Expected {$code}.");
    }
}
