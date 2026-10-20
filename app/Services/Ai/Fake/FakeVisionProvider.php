<?php

namespace App\Services\Ai\Fake;

use App\Data\Ai\TokenUsage;
use App\Data\Ai\VisionRequest;
use App\Data\Ai\VisionResponse;
use App\Services\Ai\VisionProvider;

final class FakeVisionProvider implements VisionProvider
{
    public function __construct(public string $reply = 'fake-vision-response') {}

    public function analyze(VisionRequest $request): VisionResponse
    {
        return new VisionResponse($this->reply, $request->model ?? 'fake-vision', new TokenUsage(0, 0), ['provider' => 'fake']);
    }
}
