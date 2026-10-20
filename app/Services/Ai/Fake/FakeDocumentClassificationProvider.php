<?php

namespace App\Services\Ai\Fake;

use App\Data\Ai\DocumentClassificationRequest;
use App\Data\Ai\DocumentClassificationResponse;
use App\Services\Ai\AiProviderException;
use App\Services\Ai\DocumentClassificationProvider;

final class FakeDocumentClassificationProvider implements DocumentClassificationProvider
{
    public int $calls = 0;

    public function __construct(private readonly ?DocumentClassificationResponse $response = null, private readonly ?string $failWith = null) {}

    public function classify(DocumentClassificationRequest $request): DocumentClassificationResponse
    {
        $this->calls++;
        if ($this->failWith !== null) {
            throw new AiProviderException($this->failWith);
        }

        return $this->response ?? new DocumentClassificationResponse(null, null, null, 0.0, [], 'fake-classifier', ['provider' => 'fake']);
    }
}
