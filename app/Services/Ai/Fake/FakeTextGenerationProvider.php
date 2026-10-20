<?php

namespace App\Services\Ai\Fake;

use App\Data\Ai\TextGenerationRequest;
use App\Data\Ai\TextGenerationResponse;
use App\Data\Ai\TokenUsage;
use App\Services\Ai\TextGenerationProvider;

/** Provider determinístico para testes e desenvolvimento offline. */
final class FakeTextGenerationProvider implements TextGenerationProvider
{
    /** @var list<TextGenerationRequest> */
    public array $requests = [];

    public function __construct(public string $reply = 'fake-response') {}

    public function generate(TextGenerationRequest $request): TextGenerationResponse
    {
        $this->requests[] = $request;

        return new TextGenerationResponse($this->reply, $request->model ?? 'fake-text', new TokenUsage(0, 0), ['provider' => 'fake']);
    }
}
