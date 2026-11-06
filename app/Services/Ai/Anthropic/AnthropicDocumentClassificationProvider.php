<?php

namespace App\Services\Ai\Anthropic;

use App\Data\Ai\DocumentClassificationRequest;
use App\Data\Ai\DocumentClassificationResponse;
use App\Data\Ai\TokenUsage;
use App\Services\Ai\AiProviderException;
use App\Services\Ai\DocumentClassificationProvider;
use App\Services\Ai\OpenAiCompatible\OpenAiCompatibleDocumentClassificationProvider;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Classificador da ingestão sobre a Anthropic Messages API: retaguarda da IA local, com o mesmo prompt e o mesmo
 * payload (só candidatos e trechos curtos, nunca o PDF). A saída é forçada ao schema por `output_config.format`
 * (saída estruturada) e ainda assim validada de forma estrita por DocumentClassificationResponse::fromModelOutput.
 * Uso e custo são registrados com capability `classify`, sob os mesmos orçamentos do driver de texto.
 */
final class AnthropicDocumentClassificationProvider implements DocumentClassificationProvider
{
    public const CAPABILITY = 'classify';

    private const MAX_TOKENS = 1200;

    public function __construct(
        private readonly AnthropicClient $client,
        private readonly AnthropicUsageTracker $tracker,
    ) {}

    public function classify(DocumentClassificationRequest $request): DocumentClassificationResponse
    {
        $startedAt = hrtime(true);
        $correlationId = Str::uuid()->toString();
        $model = $this->client->model();
        if ($model === null || ! $this->client->isConfigured()) {
            throw new AiProviderException(AiProviderException::NOT_CONFIGURED, $correlationId);
        }

        $user = json_encode($request->toPayload(), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        $system = OpenAiCompatibleDocumentClassificationProvider::SYSTEM_PROMPT;
        $payload = [
            'model' => $model,
            'max_tokens' => self::MAX_TOKENS,
            'system' => $system,
            'messages' => [['role' => 'user', 'content' => $user]],
            'output_config' => ['format' => ['type' => 'json_schema', 'schema' => self::schema()]],
        ];

        try {
            if (mb_strlen($user) > (int) config('ai.max_input_characters')) {
                throw new AiProviderException(AiProviderException::INVALID_REQUEST, $correlationId);
            }
            $this->tracker->guard(mb_strlen($user.$system), $correlationId);
            $response = $this->client->send($payload, $correlationId);
        } catch (AiProviderException $exception) {
            $this->tracker->recordFailure($model, null, $exception, $startedAt, self::CAPABILITY);

            throw $exception;
        }

        $requestId = $response->header('request-id') ?: null;
        $body = $response->json();
        $content = is_array($body) ? $this->text($body) : null;
        $responseModel = is_array($body) && is_string($body['model'] ?? null) ? mb_substr($body['model'], 0, 100) : $model;
        $usage = is_array($body)
            ? new TokenUsage(is_int($body['usage']['input_tokens'] ?? null) ? $body['usage']['input_tokens'] : null, is_int($body['usage']['output_tokens'] ?? null) ? $body['usage']['output_tokens'] : null)
            : null;

        try {
            if ($content === null || in_array($body['stop_reason'] ?? null, ['max_tokens', 'refusal'], true)) {
                throw new AiProviderException(AiProviderException::INVALID_RESPONSE, $correlationId);
            }
            $result = DocumentClassificationResponse::fromModelOutput($content, $responseModel, [
                'provider' => AnthropicUsageTracker::PROVIDER,
                'prompt' => ['system' => $system, 'max_tokens' => self::MAX_TOKENS, 'structured_output' => 'json_schema'],
                'raw_response' => mb_substr($content, 0, 4000),
            ]);
        } catch (AiProviderException) {
            $exception = $this->client->fail(AiProviderException::INVALID_RESPONSE, $correlationId, $payload, $response->status(), $requestId);
            $this->tracker->recordFailure($model, null, $exception, $startedAt, self::CAPABILITY);

            throw $exception;
        }

        $cost = $this->tracker->recordSuccess($model, null, $usage, $startedAt, $requestId, $response->status(), self::CAPABILITY);
        Log::info('ai_request_completed', [
            'provider' => AnthropicUsageTracker::PROVIDER,
            'capability' => self::CAPABILITY,
            'model' => $result->model,
            'latency_ms' => (int) ((hrtime(true) - $startedAt) / 1_000_000),
            'input_tokens' => $usage?->inputTokens,
            'output_tokens' => $usage?->outputTokens,
            'estimated_cost_usd' => $cost,
            'provider_request_id' => $requestId,
            'correlation_id' => $correlationId,
            'success' => true,
        ]);

        return $result;
    }

    /** @param array<string, mixed> $body */
    private function text(array $body): ?string
    {
        foreach ((array) ($body['content'] ?? []) as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
                return $block['text'];
            }
        }

        return null;
    }

    /**
     * Schema do contrato sem as restrições que a saída estruturada não aceita (maxLength, maxItems, minimum, maximum):
     * elas continuam sendo verificadas localmente em DocumentClassificationResponse::fromModelOutput.
     *
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        $strip = function (array $node) use (&$strip): array {
            unset($node['maxLength'], $node['maxItems'], $node['minimum'], $node['maximum']);
            foreach ($node as $key => $value) {
                if (is_array($value)) {
                    $node[$key] = $strip($value);
                }
            }

            return $node;
        };

        return $strip(DocumentClassificationResponse::jsonSchema());
    }
}
