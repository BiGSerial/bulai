<?php

namespace Tests\Unit\Services\Ai;

use App\Data\Ai\TextGenerationRequest;
use App\Services\Ai\Anthropic\AnthropicClient;
use App\Services\Ai\Anthropic\AnthropicTextGenerationProvider;
use App\Services\Ai\Anthropic\AnthropicUsageTracker;
use App\Services\Ai\Fake\InMemoryAiUsageRecorder;
use Tests\TestCase;

/** Conectividade real: só roda com ANTHROPIC_LIVE_TEST=1, ANTHROPIC_API_KEY e ANTHROPIC_MODEL. Consome tokens reais. */
final class AnthropicLiveConnectivityTest extends TestCase
{
    public function test_live_call_returns_text(): void
    {
        $key = getenv('ANTHROPIC_API_KEY') ?: '';
        $model = getenv('ANTHROPIC_MODEL') ?: '';
        if (getenv('ANTHROPIC_LIVE_TEST') !== '1' || $key === '' || $model === '') {
            $this->markTestSkipped('Defina ANTHROPIC_LIVE_TEST=1, ANTHROPIC_API_KEY e ANTHROPIC_MODEL para o teste real.');
        }

        $config = [...config('ai.text.drivers.anthropic'), 'api_key' => $key, 'model' => $model, 'max_tokens' => 32];
        $provider = new AnthropicTextGenerationProvider(new AnthropicClient($config), new AnthropicUsageTracker(new InMemoryAiUsageRecorder, $config));

        $response = $provider->generate(new TextGenerationRequest('Responda apenas: ok'));

        $this->assertNotSame('', $response->content);
        $this->assertGreaterThan(0, $response->usage?->outputTokens);
    }
}
