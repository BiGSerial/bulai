<?php

namespace App\Services\Ai\OpenAiCompatible;

use App\Data\Ai\TextGenerationRequest;
use App\Data\Ai\TextGenerationResponse;
use App\Data\Ai\TokenUsage;
use App\Services\Ai\AiProviderException;
use App\Services\Ai\TextGenerationProvider;

final class OpenAiCompatibleTextGenerationProvider implements TextGenerationProvider
{
    public function __construct(private readonly OpenAiCompatibleClient $client) {}

    public function generate(TextGenerationRequest $request): TextGenerationResponse
    {
        $startedAt = hrtime(true);
        $model = $request->model ?? $this->client->defaultModel();
        if ($model === null || $request->prompt === '' || mb_strlen($request->prompt.($request->system ?? '')) > (int) config('ai.max_input_characters')) {
            throw new AiProviderException($model === null ? AiProviderException::NOT_CONFIGURED : AiProviderException::INVALID_REQUEST);
        }

        $messages = array_values(array_filter([
            $request->system !== null ? ['role' => 'system', 'content' => $request->system] : null,
            ['role' => 'user', 'content' => $request->prompt],
        ]));
        $body = $this->client->post('chat/completions', array_filter([
            'model' => $model,
            'messages' => $messages,
            'temperature' => $request->temperature,
            'max_tokens' => $request->maxOutputTokens,
        ], fn (mixed $value): bool => $value !== null), 'generate', $model);

        $content = $body['choices'][0]['message']['content'] ?? null;
        if (! is_string($content)) {
            throw $this->client->invalidResponse('generate', $model, $startedAt);
        }

        $usage = new TokenUsage(
            is_int($body['usage']['prompt_tokens'] ?? null) ? $body['usage']['prompt_tokens'] : null,
            is_int($body['usage']['completion_tokens'] ?? null) ? $body['usage']['completion_tokens'] : null,
        );
        $this->client->logSuccess('generate', $model, $usage, $startedAt);

        return new TextGenerationResponse($content, is_string($body['model'] ?? null) ? $body['model'] : $model, $usage, [
            'provider' => $this->client->provider,
            'finish_reason' => is_string($body['choices'][0]['finish_reason'] ?? null) ? $body['choices'][0]['finish_reason'] : null,
        ]);
    }
}
