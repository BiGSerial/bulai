<?php

namespace App\Services\Ai\OpenAiCompatible;

use App\Data\Ai\EmbeddingBatchRequest;
use App\Data\Ai\EmbeddingBatchResponse;
use App\Data\Ai\EmbeddingRequest;
use App\Data\Ai\EmbeddingResponse;
use App\Data\Ai\TokenUsage;
use App\Services\Ai\AiProviderException;
use App\Services\Ai\EmbeddingProvider;

final class OpenAiCompatibleEmbeddingProvider implements EmbeddingProvider
{
    public function __construct(private readonly OpenAiCompatibleClient $client) {}

    public function embed(EmbeddingRequest $request): EmbeddingResponse
    {
        $batch = $this->embedMany(new EmbeddingBatchRequest([$request->input], $request->model));

        return $batch->embeddings[0];
    }

    public function embedMany(EmbeddingBatchRequest $request): EmbeddingBatchResponse
    {
        $startedAt = hrtime(true);
        $model = $request->model ?? $this->client->defaultModel();
        if ($model === null) {
            throw new AiProviderException(AiProviderException::NOT_CONFIGURED);
        }

        $totalCharacters = array_sum(array_map('mb_strlen', $request->inputs));
        if ($request->inputs === [] || in_array('', $request->inputs, true) || $totalCharacters > (int) config('ai.max_input_characters')) {
            throw new AiProviderException(AiProviderException::INVALID_REQUEST);
        }

        $body = $this->client->post('embeddings', ['model' => $model, 'input' => array_values($request->inputs)], 'embed', $model);
        $data = $body['data'] ?? null;
        if (! is_array($data) || count($data) !== count($request->inputs)) {
            throw $this->client->invalidResponse('embed', $model, $startedAt);
        }

        usort($data, fn (mixed $a, mixed $b): int => ($a['index'] ?? 0) <=> ($b['index'] ?? 0));
        $responseModel = is_string($body['model'] ?? null) ? $body['model'] : $model;
        $inputTokens = is_int($body['usage']['prompt_tokens'] ?? null) ? $body['usage']['prompt_tokens'] : null;
        $metadata = ['provider' => $this->client->provider];

        $embeddings = [];
        foreach ($data as $item) {
            $vector = $item['embedding'] ?? null;
            if (! is_array($vector) || $vector === [] || array_filter($vector, fn (mixed $v): bool => ! is_int($v) && ! is_float($v)) !== []) {
                throw $this->client->invalidResponse('embed', $model, $startedAt);
            }

            $embeddings[] = new EmbeddingResponse(array_map('floatval', array_values($vector)), $responseModel, null, $metadata);
        }

        $this->client->logSuccess('embed', $responseModel, new TokenUsage($inputTokens, null), $startedAt);

        return new EmbeddingBatchResponse($embeddings, $responseModel, $inputTokens, $metadata);
    }
}
