<?php

namespace App\Services\Ai\Fake;

use App\Data\Ai\EmbeddingBatchRequest;
use App\Data\Ai\EmbeddingBatchResponse;
use App\Data\Ai\EmbeddingRequest;
use App\Data\Ai\EmbeddingResponse;
use App\Services\Ai\EmbeddingProvider;

/** Gera vetores determinísticos a partir do hash do texto. */
final class FakeEmbeddingProvider implements EmbeddingProvider
{
    public function __construct(private readonly int $dimensions = 8) {}

    public function embed(EmbeddingRequest $request): EmbeddingResponse
    {
        return new EmbeddingResponse($this->vector($request->input), $request->model ?? 'fake-embedding', 0, ['provider' => 'fake']);
    }

    public function embedMany(EmbeddingBatchRequest $request): EmbeddingBatchResponse
    {
        $model = $request->model ?? 'fake-embedding';

        return new EmbeddingBatchResponse(
            array_map(fn (string $input): EmbeddingResponse => new EmbeddingResponse($this->vector($input), $model, 0, ['provider' => 'fake']), $request->inputs),
            $model,
            0,
            ['provider' => 'fake'],
        );
    }

    /** @return list<float> */
    private function vector(string $input): array
    {
        $bytes = unpack('C*', substr(hash('sha256', $input, true), 0, $this->dimensions));

        return array_map(fn (int $byte): float => round($byte / 255, 6), array_values($bytes));
    }
}
