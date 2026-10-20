<?php

namespace App\Services\Ai;

use App\Data\Ai\EmbeddingBatchRequest;
use App\Data\Ai\EmbeddingBatchResponse;
use App\Data\Ai\EmbeddingRequest;
use App\Data\Ai\EmbeddingResponse;

interface EmbeddingProvider
{
    /** @throws AiProviderException */
    public function embed(EmbeddingRequest $request): EmbeddingResponse;

    /** @throws AiProviderException */
    public function embedMany(EmbeddingBatchRequest $request): EmbeddingBatchResponse;
}
