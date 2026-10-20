<?php

namespace App\Services\Ai;

use App\Data\Ai\TextGenerationRequest;
use App\Data\Ai\TextGenerationResponse;

interface TextGenerationProvider
{
    /** @throws AiProviderException */
    public function generate(TextGenerationRequest $request): TextGenerationResponse;
}
