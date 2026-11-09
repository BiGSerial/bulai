<?php

namespace App\Services\Ai\Anthropic;

use App\Data\Ai\TokenUsage;
use App\Data\Ai\VisionImage;
use App\Data\Ai\VisionRequest;
use App\Data\Ai\VisionResponse;
use App\Services\Ai\AiProviderException;
use App\Services\Ai\VisionProvider;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Análise de imagens via Anthropic Messages API: cada imagem vai como bloco `image` (base64) seguido do bloco de texto.
 * A instrução de sistema pode vir em `metadata['system']`. Uso e custo são registrados com capability `vision`.
 */
final class AnthropicVisionProvider implements VisionProvider
{
    public function __construct(
        private readonly AnthropicClient $client,
        private readonly AnthropicUsageTracker $tracker,
        private readonly AnthropicResponseMapper $mapper = new AnthropicResponseMapper,
    ) {}

    public function analyze(VisionRequest $request): VisionResponse
    {
        $startedAt = hrtime(true);
        $correlationId = Str::uuid()->toString();
        $model = $request->model ?? $this->client->model();
        if ($model === null || ! $this->client->isConfigured()) {
            throw new AiProviderException(AiProviderException::NOT_CONFIGURED, $correlationId);
        }
        $runId = is_scalar($request->metadata['run_id'] ?? null) ? (string) $request->metadata['run_id'] : null;
        $system = is_string($request->metadata['system'] ?? null) ? $request->metadata['system'] : null;

        try {
            $characters = mb_strlen($request->prompt.($system ?? ''));
            if ($request->prompt === '' || $request->images === [] || $characters > (int) config('ai.max_input_characters')) {
                throw new AiProviderException(AiProviderException::INVALID_REQUEST, $correlationId);
            }
            $content = [];
            foreach ($request->images as $image) {
                if (! in_array($image->mimeType, VisionImage::ALLOWED_MIME_TYPES, true) || $image->contents === '' || strlen($image->contents) > (int) config('ai.max_image_bytes')) {
                    throw new AiProviderException(AiProviderException::INVALID_REQUEST, $correlationId);
                }
                $content[] = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $image->mimeType, 'data' => base64_encode($image->contents)]];
            }
            $content[] = ['type' => 'text', 'text' => $request->prompt];
            $this->tracker->guard($characters, $correlationId);
        } catch (AiProviderException $exception) {
            $this->tracker->recordFailure($model, $runId, $exception, $startedAt, 'vision');

            throw $exception;
        }

        $payload = ['model' => $model, 'max_tokens' => $request->maxOutputTokens ?? $this->client->defaultMaxTokens(), 'messages' => [['role' => 'user', 'content' => $content]]];
        if ($system !== null && $system !== '') {
            $payload['system'] = $system;
        }

        try {
            $response = $this->client->send($payload, $correlationId);
            $requestId = $response->header('request-id') ?: null;
            $body = $response->json();
            if (! is_array($body)) {
                throw $this->client->fail(AiProviderException::INVALID_RESPONSE, $correlationId, $payload, $response->status(), $requestId);
            }
            $mapped = $this->mapper->map($body, $model, $correlationId, $requestId);
        } catch (AiProviderException $exception) {
            $this->tracker->recordFailure($model, $runId, $exception, $startedAt, 'vision');

            throw $exception;
        }

        $usage = $mapped->usage ?? new TokenUsage(null, null);
        $cost = $this->tracker->recordSuccess($model, $runId, $usage, $startedAt, $requestId, 200, 'vision', $mapped->model);
        Log::info('ai_request_completed', [
            'provider' => 'anthropic', 'capability' => 'vision', 'model' => $mapped->model, 'images' => count($request->images),
            'latency_ms' => (int) ((hrtime(true) - $startedAt) / 1_000_000), 'input_tokens' => $usage->inputTokens, 'output_tokens' => $usage->outputTokens,
            'estimated_cost_usd' => $cost, 'stop_reason' => $mapped->metadata['stop_reason'] ?? null, 'provider_request_id' => $requestId, 'correlation_id' => $correlationId, 'success' => true,
        ]);

        return new VisionResponse($mapped->content, $mapped->model, $usage, $mapped->metadata);
    }
}
