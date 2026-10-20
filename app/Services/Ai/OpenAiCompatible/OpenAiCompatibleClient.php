<?php

namespace App\Services\Ai\OpenAiCompatible;

use App\Data\Ai\TokenUsage;
use App\Services\Ai\AiProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Transporte HTTP para APIs no padrão OpenAI (OpenAI, gateways, Ollama, vLLM, llama.cpp...).
 * Nada do JSON do fornecedor sai desta camada: os providers o convertem em DTOs.
 */
final class OpenAiCompatibleClient
{
    /** @param array<string, mixed> $config base_url, api_key, model, timeout, retries */
    public function __construct(
        public readonly string $provider,
        public readonly string $capability,
        private readonly array $config,
    ) {}

    public function defaultModel(): ?string
    {
        return filled($this->config['model'] ?? null) ? (string) $this->config['model'] : null;
    }

    public function option(string $key): mixed
    {
        return $this->config[$key] ?? null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function post(string $path, array $payload, string $operation, string $model): array
    {
        $correlationId = Str::uuid()->toString();
        $startedAt = hrtime(true);

        if (blank($this->config['base_url'] ?? null)) {
            throw new AiProviderException(AiProviderException::NOT_CONFIGURED, $correlationId);
        }

        try {
            $response = $this->request($correlationId)->post(rtrim((string) $this->config['base_url'], '/').'/'.ltrim($path, '/'), $payload);
        } catch (ConnectionException $exception) {
            $code = str_contains($exception->getMessage(), 'cURL error 28') || str_contains($exception->getMessage(), 'timed out')
                ? AiProviderException::TIMEOUT
                : AiProviderException::UNAVAILABLE;

            throw $this->fail($code, $correlationId, $operation, $model, $startedAt);
        }

        if ($response->failed()) {
            $code = match (true) {
                $response->status() === 429 => AiProviderException::RATE_LIMITED,
                in_array($response->status(), [401, 403], true) => AiProviderException::AUTH_FAILED,
                $response->serverError() => AiProviderException::UNAVAILABLE,
                default => AiProviderException::REJECTED,
            };

            throw $this->fail($code, $correlationId, $operation, $model, $startedAt, $response->status());
        }

        $body = $response->json();
        if (! is_array($body)) {
            throw $this->fail(AiProviderException::INVALID_RESPONSE, $correlationId, $operation, $model, $startedAt, $response->status());
        }

        return $body;
    }

    /** Registra métricas sem conteúdo de prompts/respostas nem credenciais. */
    public function logSuccess(string $operation, string $model, ?TokenUsage $usage, int $startedAt): void
    {
        Log::info('ai_request_completed', [
            'provider' => $this->provider,
            'capability' => $this->capability,
            'operation' => $operation,
            'model' => $model,
            'latency_ms' => (int) ((hrtime(true) - $startedAt) / 1_000_000),
            'input_tokens' => $usage?->inputTokens,
            'output_tokens' => $usage?->outputTokens,
            'success' => true,
        ]);
    }

    public function invalidResponse(string $operation, string $model, int $startedAt): AiProviderException
    {
        return $this->fail(AiProviderException::INVALID_RESPONSE, Str::uuid()->toString(), $operation, $model, $startedAt);
    }

    private function request(string $correlationId): PendingRequest
    {
        $request = Http::acceptJson()
            ->asJson()
            ->timeout((int) ($this->config['timeout'] ?? 60))
            ->connectTimeout(5)
            ->withHeaders(['X-Correlation-Id' => $correlationId]);

        if (filled($this->config['api_key'] ?? null)) {
            $request = $request->withToken((string) $this->config['api_key']);
        }

        $retries = max(0, (int) ($this->config['retries'] ?? 0));
        if ($retries === 0) {
            return $request;
        }

        return $request->retry(
            $retries + 1,
            (int) config('ai.retry_sleep_ms'),
            fn (\Throwable $exception): bool => $exception instanceof ConnectionException
                ? ! str_contains($exception->getMessage(), 'cURL error 28')
                : ($exception->response ?? null) === null || $exception->response->status() === 429 || $exception->response->serverError(),
            throw: false,
        );
    }

    private function fail(string $code, string $correlationId, string $operation, string $model, int $startedAt, ?int $status = null): AiProviderException
    {
        Log::warning('ai_request_failed', [
            'provider' => $this->provider,
            'capability' => $this->capability,
            'operation' => $operation,
            'model' => $model,
            'latency_ms' => (int) ((hrtime(true) - $startedAt) / 1_000_000),
            'http_status' => $status,
            'error_code' => $code,
            'correlation_id' => $correlationId,
            'success' => false,
        ]);

        return new AiProviderException($code, $correlationId);
    }
}
