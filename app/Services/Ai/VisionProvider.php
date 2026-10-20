<?php

namespace App\Services\Ai;

use App\Data\Ai\VisionRequest;
use App\Data\Ai\VisionResponse;

interface VisionProvider
{
    /** @throws AiProviderException */
    public function analyze(VisionRequest $request): VisionResponse;
}
