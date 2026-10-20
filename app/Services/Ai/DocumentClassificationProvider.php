<?php

namespace App\Services\Ai;

use App\Data\Ai\DocumentClassificationRequest;
use App\Data\Ai\DocumentClassificationResponse;

interface DocumentClassificationProvider
{
    /** @throws AiProviderException */
    public function classify(DocumentClassificationRequest $request): DocumentClassificationResponse;
}
