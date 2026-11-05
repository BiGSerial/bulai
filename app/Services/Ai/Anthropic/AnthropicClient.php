<?php

namespace App\Services\Ai\Anthropic;

use App\Services\Ai\AiProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

/**
 * Transporte HTTP da Anthropic Messages API (POST /v1/messages).
 *
 * Não herda semântica OpenAI: autentica com x-api-key e anthropic-version, nunca envia temperature.
 * Retry com backoff exponencial + jitter para 5xx/529 e falhas de conexão; 429 só é reintentado quando há
 * retry-after (429 sem o cabeçalho costuma indicar teto de gasto e não deve ser insistido).
 * Logs nunca contêm prompt, resposta nem chave.
 */
final class AnthropicClient
{
    /** @param array<string, mixed> $config base_url, api_key, api_version, model, max_tokens, timeout, retries, retry_base_ms, retry_after_max_seconds */
    public function __construct(private readonly array $config) {}

    public function model(): ?string
    {
        return filled($this->config['model'] ?? null) ? (string) $this->config['model'] : null;
    }

    public function defaultMaxTokens(): int
    {
        return max(1, (int) ($this->config['max_tokens'] ?? 2048));
    }

    public function isConfigured(): bool
    {
        return filled($this->config['api_key'] ?? null) && filled($this->config['base_url'] ?? null);
    }

    /**
     * Envia a requisição e devolve a resposta HTTP de sucesso; qualquer falha vira AiProviderException.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws AiProviderException
     */
    public function send(array $payload, string $correlationId, bool $stream = false): Response
    {
        if (! $this->isConfigured()) {
            throw new AiProviderException(AiProviderException::NOT_CONFIGURED, $correlationId);
        }

        $maxAttempts = max(0, (int) ($this->config['retries'] ?? 0)) + 1;
        $url = rtrim((string) $this->config['base_url'], '/').'/v1/messages';

        for ($attempt = 1; ; $attempt++) {
            try {
                $response = $this->pending($correlationId, $stream)->post($url, $payload);
            } catch (ConnectionException $exception) {
                $timedOut = str_contains($exception->getMessage(), 'cURL error 28') || str_contains($exception->getMessage(), 'timed out');
                $code = $timedOut ? AiProviderException::TIMEOUT : AiProviderException::UNAVAILABLE;
                if ($timedOut || $attempt >= $maxAttempts) {
                    throw $this->fail($code, $correlationId, $payload, null, null, null, $attempt);
                }
                $this->backoff($attempt);

                continue;
            }

            if ($response->successful()) {
                return $response;
            }

            $status = $response->status();
            $requestId = $response->header('request-id') ?: null;
            $errorType = $response->json('error.type');
            $retryAfter = $this->retryAfterSeconds($response);
            $retryable = $status === 429 ? $retryAfter !== null : $status >= 500;

            if ($retryable && $attempt < $maxAttempts) {
                $status === 429 ? Sleep::for($retryAfter)->seconds() : $this->backoff($attempt);

                continue;
            }

            throw $this->fail($this->errorCodeFor($status), $correlationId, $payload, $status, $requestId, is_string($errorType) ? $errorType : null, $attempt);
        }
    }

    private function pending(string $correlationId, bool $stream): PendingRequest
    {
        $request = Http::acceptJson()
            ->asJson()
            ->timeout((int) ($this->config['timeout'] ?? 90))
            ->connectTimeout(5)
            ->withHeaders([
                'x-api-key' => (string) $this->config['api_key'],
                'anthropic-version' => (string) ($this->config['api_version'] ?? '2026-10-21'),
                'X-Correlation-Id' => $correlationId,
            ]);

        return $stream ? $request->withOptions(['stream' => true]) : $request;
    }

    private function errorCodeFor(int $status): string
    {
        return match (true) {
            $status === 400 => AiProviderException::INVALID_REQUEST,
            in_array($status, [401, 403], true) => AiProviderException::AUTH_FAILED,
            $status === 429 => AiProviderException::RATE_LIMITED,
            $status === 504 => AiProviderException::TIMEOUT,
            $status >= 500 => AiProviderException::UNAVAILABLE,
            default => AiProviderException::REJECTED,
        };
    }

    /** Segundos do retry-after (limitados pelo máximo configurado), ou null quando ausente/inválido/longo demais. */
    private function retryAfterSeconds(Response $response): ?int
    {
        $header = $response->header('retry-after');
        if (! is_string($header) || ! ctype_digit($header)) {
            return null;
        }

        $seconds = (int) $header;

        return $seconds <= (int) ($this->config['retry_after_max_seconds'] ?? 30) ? $seconds : null;
    }

    private function backoff(int $attempt): void
    {
        $base = max(0, (int) ($this->config['retry_base_ms'] ?? 500));
        $delay = $base * (2 ** ($attempt - 1));
        Sleep::for($delay + ($base > 0 ? random_int(0, $base) : 0))->milliseconds();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function fail(string $code, string $correlationId, array $payload, ?int $status = null, ?string $requestId = null, ?string $errorType = null, ?int $attempt = null): AiProviderException
    {
        Log::warning('ai_request_failed', [
            'provider' => 'anthropic',
            'capability' => 'text',
            'model' => is_string($payload['model'] ?? null) ? $payload['model'] : null,
            'http_status' => $status,
            'error_code' => $code,
            'provider_error_type' => $errorType,
            'provider_request_id' => $requestId,
            'correlation_id' => $correlationId,
            'attempts' => $attempt,
            'success' => false,
        ]);

        return new AiProviderException($code, $correlationId, $status, $requestId);
    }
}
