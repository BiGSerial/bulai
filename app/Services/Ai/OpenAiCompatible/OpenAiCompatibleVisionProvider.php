<?php

namespace App\Services\Ai\OpenAiCompatible;

use App\Data\Ai\TokenUsage;
use App\Data\Ai\VisionImage;
use App\Data\Ai\VisionRequest;
use App\Data\Ai\VisionResponse;
use App\Services\Ai\AiProviderException;
use App\Services\Ai\VisionProvider;

final class OpenAiCompatibleVisionProvider implements VisionProvider
{
    public function __construct(private readonly OpenAiCompatibleClient $client) {}

    public function analyze(VisionRequest $request): VisionResponse
    {
        $startedAt = hrtime(true);
        $model = $request->model ?? $this->client->defaultModel();
        if ($model === null) {
            throw new AiProviderException(AiProviderException::NOT_CONFIGURED);
        }

        if ($request->prompt === '' || $request->images === [] || mb_strlen($request->prompt) > (int) config('ai.max_input_characters')) {
            throw new AiProviderException(AiProviderException::INVALID_REQUEST);
        }

        $parts = [['type' => 'text', 'text' => $request->prompt]];
        foreach ($request->images as $image) {
            if (! in_array($image->mimeType, VisionImage::ALLOWED_MIME_TYPES, true) || strlen($image->contents) > (int) config('ai.max_image_bytes')) {
                throw new AiProviderException(AiProviderException::INVALID_REQUEST);
            }

            $parts[] = ['type' => 'image_url', 'image_url' => ['url' => "data:{$image->mimeType};base64,".base64_encode($image->contents)]];
        }

        $body = $this->client->post('chat/completions', array_filter([
            'model' => $model,
            'messages' => [['role' => 'user', 'content' => $parts]],
            'max_tokens' => $request->maxOutputTokens,
        ], fn (mixed $value): bool => $value !== null), 'analyze', $model);

        $content = $body['choices'][0]['message']['content'] ?? null;
        if (! is_string($content)) {
            throw $this->client->invalidResponse('analyze', $model, $startedAt);
        }

        $usage = new TokenUsage(
            is_int($body['usage']['prompt_tokens'] ?? null) ? $body['usage']['prompt_tokens'] : null,
            is_int($body['usage']['completion_tokens'] ?? null) ? $body['usage']['completion_tokens'] : null,
        );
        $this->client->logSuccess('analyze', $model, $usage, $startedAt);

        return new VisionResponse($content, is_string($body['model'] ?? null) ? $body['model'] : $model, $usage, ['provider' => $this->client->provider]);
    }
}
