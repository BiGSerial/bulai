<?php

namespace App\Services\Ai\Anthropic;

use App\Data\Ai\TextGenerationRequest;
use App\Data\Ai\TextGenerationResponse;
use App\Data\Ai\TokenUsage;
use App\Services\Ai\AiProviderException;
use App\Services\Ai\StreamingTextGenerationProvider;
use App\Services\Ai\TextGenerationProvider;
use Generator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Geração de texto via Anthropic Messages API.
 *
 * TextGenerationRequest::$temperature é IGNORADO: a versão de API 2026-10-21 removeu temperature/top_p/top_k e
 * enviá-los retorna 400. O system vai no campo top-level `system`; não há prefill nem `thinking` por padrão.
 */
final class AnthropicTextGenerationProvider implements StreamingTextGenerationProvider, TextGenerationProvider
{
    public function __construct(
        private readonly AnthropicClient $client,
        private readonly AnthropicUsageTracker $tracker,
        private readonly AnthropicResponseMapper $mapper = new AnthropicResponseMapper,
        private readonly AnthropicSseParser $parser = new AnthropicSseParser,
    ) {}

    public function generate(TextGenerationRequest $request): TextGenerationResponse
    {
        $startedAt = hrtime(true);
        $correlationId = Str::uuid()->toString();
        [$model, $payload, $runId] = $this->prepare($request, $correlationId, $startedAt, false);

        try {
            $response = $this->client->send($payload, $correlationId);
            $requestId = $response->header('request-id') ?: null;
            $body = $response->json();
            if (! is_array($body)) {
                throw $this->client->fail(AiProviderException::INVALID_RESPONSE, $correlationId, $payload, $response->status(), $requestId);
            }

            $result = $this->mapper->map($body, $model, $correlationId, $requestId);
        } catch (AiProviderException $exception) {
            $this->tracker->recordFailure($model, $runId, $exception, $startedAt);

            throw $exception;
        }

        $this->finish($result, $model, $runId, $startedAt, $requestId, $correlationId);

        return $result;
    }

    /**
     * Streaming SSE. O frontend nunca fala com a Anthropic: a aplicação repassa os fragmentos.
     *
     * @return Generator<int, string, mixed, TextGenerationResponse>
     */
    public function stream(TextGenerationRequest $request): Generator
    {
        $startedAt = hrtime(true);
        $correlationId = Str::uuid()->toString();
        [$model, $payload, $runId] = $this->prepare($request, $correlationId, $startedAt, true);
        $requestId = null;

        try {
            $response = $this->client->send($payload, $correlationId, true);
            $requestId = $response->header('request-id') ?: null;

            $text = '';
            $inputTokens = null;
            $outputTokens = null;
            $stopReason = null;
            $finished = false;
            $responseModel = $model;

            foreach ($this->parser->parse(AnthropicSseParser::chunks($response->toPsrResponse()->getBody())) as $event) {
                $data = $event['data'];
                switch ($event['event']) {
                    case 'message_start':
                        $inputTokens = is_int($data['message']['usage']['input_tokens'] ?? null) ? $data['message']['usage']['input_tokens'] : $inputTokens;
                        $responseModel = is_string($data['message']['model'] ?? null) ? $data['message']['model'] : $responseModel;
                        break;
                    case 'content_block_delta':
                        if (($data['delta']['type'] ?? null) === 'text_delta' && is_string($data['delta']['text'] ?? null) && $data['delta']['text'] !== '') {
                            $text .= $data['delta']['text'];

                            yield $data['delta']['text'];
                        }
                        break;
                    case 'message_delta':
                        $stopReason = is_string($data['delta']['stop_reason'] ?? null) ? $data['delta']['stop_reason'] : $stopReason;
                        $outputTokens = is_int($data['usage']['output_tokens'] ?? null) ? $data['usage']['output_tokens'] : $outputTokens;
                        break;
                    case 'message_stop':
                        $finished = true;
                        break;
                    case 'error':
                        throw $this->client->fail($this->streamErrorCode($data['error']['type'] ?? null), $correlationId, $payload, 200, $requestId, is_string($data['error']['type'] ?? null) ? $data['error']['type'] : null);
                    default:
                        break;
                }
                if ($finished) {
                    break;
                }
            }

            if (! $finished) {
                throw $this->client->fail(AiProviderException::INVALID_RESPONSE, $correlationId, $payload, 200, $requestId);
            }

            $result = $this->mapper->map([
                'content' => [['type' => 'text', 'text' => $text]],
                'stop_reason' => $stopReason,
                'model' => $responseModel,
                'usage' => ['input_tokens' => $inputTokens, 'output_tokens' => $outputTokens],
            ], $model, $correlationId, $requestId);
        } catch (Throwable $throwable) {
            $exception = $throwable instanceof AiProviderException
                ? $throwable
                : $this->client->fail(AiProviderException::TIMEOUT, $correlationId, $payload, null, $requestId);
            $this->tracker->recordFailure($model, $runId, $exception, $startedAt);

            throw $exception;
        }

        $this->finish($result, $model, $runId, $startedAt, $requestId, $correlationId);

        return $result;
    }

    /**
     * @return array{0: string, 1: array<string, mixed>, 2: ?string}
     *
     * @throws AiProviderException
     */
    private function prepare(TextGenerationRequest $request, string $correlationId, int $startedAt, bool $stream): array
    {
        $model = $request->model ?? $this->client->model();
        if ($model === null || ! $this->client->isConfigured()) {
            throw new AiProviderException(AiProviderException::NOT_CONFIGURED, $correlationId);
        }

        $runId = is_scalar($request->metadata['run_id'] ?? null) ? (string) $request->metadata['run_id'] : null;
        $characters = mb_strlen($request->prompt.($request->system ?? ''));
        $payload = ['model' => $model, 'max_tokens' => $request->maxOutputTokens ?? $this->client->defaultMaxTokens()];

        try {
            if ($request->prompt === '' || $characters > (int) config('ai.max_input_characters')) {
                throw new AiProviderException(AiProviderException::INVALID_REQUEST, $correlationId);
            }
            $this->tracker->guard($characters, $correlationId);
        } catch (AiProviderException $exception) {
            $this->tracker->recordFailure($model, $runId, $exception, $startedAt);

            throw $exception;
        }

        if ($request->system !== null && $request->system !== '') {
            // System longo e estável vira prefixo em cache (o que varia fica na mensagem do usuário); curto demais não seria cacheado.
            $payload['system'] = mb_strlen($request->system) >= (int) config('ai.text.drivers.anthropic.cache_system_min_characters')
                ? [['type' => 'text', 'text' => $request->system, 'cache_control' => ['type' => 'ephemeral']]]
                : $request->system;
        }
        if ($request->effort !== null) {
            $payload['output_config'] = ['effort' => $request->effort];
        }
        $payload['messages'] = [['role' => 'user', 'content' => $request->prompt]];
        if ($stream) {
            $payload['stream'] = true;
        }

        return [$model, $payload, $runId];
    }

    private function finish(TextGenerationResponse $result, string $model, ?string $runId, int $startedAt, ?string $requestId, string $correlationId): void
    {
        $usage = $result->usage ?? new TokenUsage(null, null);
        $cost = $this->tracker->recordSuccess($model, $runId, $usage, $startedAt, $requestId);

        Log::info('ai_request_completed', [
            'provider' => 'anthropic',
            'capability' => 'text',
            'model' => $result->model,
            'latency_ms' => (int) ((hrtime(true) - $startedAt) / 1_000_000),
            'input_tokens' => $usage->inputTokens,
            'output_tokens' => $usage->outputTokens,
            'estimated_cost_usd' => $cost,
            'stop_reason' => $result->metadata['stop_reason'] ?? null,
            'provider_request_id' => $requestId,
            'correlation_id' => $correlationId,
            'success' => true,
        ]);
    }

    private function streamErrorCode(mixed $type): string
    {
        return match ($type) {
            'rate_limit_error' => AiProviderException::RATE_LIMITED,
            'authentication_error', 'permission_error' => AiProviderException::AUTH_FAILED,
            'invalid_request_error' => AiProviderException::INVALID_REQUEST,
            'timeout_error' => AiProviderException::TIMEOUT,
            'billing_error', 'not_found_error', 'request_too_large' => AiProviderException::REJECTED,
            default => AiProviderException::UNAVAILABLE,
        };
    }
}
