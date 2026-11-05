<?php

namespace Tests\Unit\Services\Ai;

use App\Data\Ai\VisionImage;
use App\Data\Ai\VisionRequest;
use App\Services\Ai\AiProviderException;
use App\Services\Ai\AiProviderManager;
use App\Services\Ai\AiUsageRecorder;
use App\Services\Ai\Anthropic\AnthropicClient;
use App\Services\Ai\Anthropic\AnthropicUsageTracker;
use App\Services\Ai\Anthropic\AnthropicVisionProvider;
use App\Services\Ai\Fake\InMemoryAiUsageRecorder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/** Sem banco de dados: Http::fake + recorder em memória. */
final class AnthropicVisionProviderTest extends TestCase
{
    private InMemoryAiUsageRecorder $recorder;

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
        $this->recorder = new InMemoryAiUsageRecorder;
        $this->app->instance(AiUsageRecorder::class, $this->recorder);
    }

    private function provider(): AnthropicVisionProvider
    {
        $config = [
            'base_url' => 'https://api.anthropic.com', 'api_key' => 'sk-ant-test', 'api_version' => '2026-10-21',
            'model' => 'claude-sonnet-5-5', 'max_tokens' => 1500, 'timeout' => 5, 'retries' => 0, 'retry_base_ms' => 10, 'retry_after_max_seconds' => 30,
            'limits' => ['max_input_tokens' => null, 'chars_per_token' => 3, 'daily_tokens' => null, 'monthly_tokens' => null, 'daily_usd' => null, 'monthly_usd' => null],
            'pricing' => ['reference' => '2026-11-03', 'models' => ['claude-sonnet-5-5' => ['input' => 2.0, 'output' => 10.0]]],
        ];

        return new AnthropicVisionProvider(new AnthropicClient($config), new AnthropicUsageTracker($this->recorder, $config));
    }

    private function request(string $mime = 'image/jpeg', string $bytes = 'binary-jpeg'): VisionRequest
    {
        return new VisionRequest('Transcreva a receita.', [new VisionImage($mime, $bytes)], null, 900, ['run_id' => 'rx-1', 'system' => 'Responda em JSON.']);
    }

    public function test_sends_image_block_then_text_and_records_usage_as_vision(): void
    {
        Http::fake(['https://api.anthropic.com/v1/messages' => Http::response([
            'id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-5-5',
            'content' => [['type' => 'text', 'text' => '{"items": []}']], 'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 1600, 'output_tokens' => 120],
        ], 200, ['request-id' => 'req_9'])]);

        $response = $this->provider()->analyze($this->request());

        $this->assertSame('{"items": []}', $response->content);
        $this->assertSame([1600, 120], [$response->usage?->inputTokens, $response->usage?->outputTokens]);
        Http::assertSent(function (Request $request): bool {
            $body = $request->data();
            $blocks = $body['messages'][0]['content'];

            return $request->hasHeader('x-api-key', 'sk-ant-test')
                && $body['system'] === 'Responda em JSON.'
                && $body['max_tokens'] === 900
                && $blocks[0]['type'] === 'image' && $blocks[0]['source']['media_type'] === 'image/jpeg' && $blocks[0]['source']['data'] === base64_encode('binary-jpeg')
                && $blocks[1] === ['type' => 'text', 'text' => 'Transcreva a receita.'];
        });
        $record = $this->recorder->records[0];
        $this->assertSame(['anthropic', 'vision', 'success', 'rx-1', 'req_9'], [$record->provider, $record->capability, $record->status, $record->runId, $record->providerRequestId]);
        $this->assertEqualsWithDelta(0.0044, (float) $record->estimatedCostUsd, 0.00001);
    }

    public function test_rejects_unsupported_mime_or_empty_image_before_calling_the_api(): void
    {
        Http::fake();
        foreach ([$this->request('image/gif'), $this->request('image/png', '')] as $request) {
            try {
                $this->provider()->analyze($request);
                $this->fail('Esperava INVALID_REQUEST.');
            } catch (AiProviderException $exception) {
                $this->assertSame(AiProviderException::INVALID_REQUEST, $exception->errorCode);
            }
        }
        Http::assertNothingSent();
        $this->assertSame(['vision', 'failed'], [$this->recorder->records[0]->capability, $this->recorder->records[0]->status]);
    }

    public function test_refusal_and_api_errors_become_provider_exceptions(): void
    {
        Http::fake(['https://api.anthropic.com/v1/messages' => Http::sequence()
            ->push(['content' => [], 'stop_reason' => 'refusal', 'model' => 'claude-sonnet-5-5'], 200)
            ->push(['error' => ['type' => 'invalid_request_error']], 400)]);

        foreach ([AiProviderException::REJECTED, AiProviderException::INVALID_REQUEST] as $expected) {
            try {
                $this->provider()->analyze($this->request());
                $this->fail('Esperava '.$expected);
            } catch (AiProviderException $exception) {
                $this->assertSame($expected, $exception->errorCode);
            }
        }
    }

    public function test_manager_resolves_the_anthropic_vision_driver(): void
    {
        config()->set('ai.vision.default', 'anthropic');
        config()->set('ai.vision.drivers.anthropic.api_key', 'sk-ant-test');
        config()->set('ai.vision.drivers.anthropic.model', 'claude-sonnet-5-5');

        $this->assertInstanceOf(AnthropicVisionProvider::class, (new AiProviderManager)->vision());
    }
}
