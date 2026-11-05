<?php

namespace Tests\Unit\Services\Ai;

use App\Data\Ai\TextGenerationRequest;
use App\Data\Ai\TokenUsage;
use App\Services\Ai\AiProviderException;
use App\Services\Ai\AiProviderManager;
use App\Services\Ai\AiUsageRecorder;
use App\Services\Ai\Anthropic\AnthropicClient;
use App\Services\Ai\Anthropic\AnthropicTextGenerationProvider;
use App\Services\Ai\Anthropic\AnthropicUsageTracker;
use App\Services\Ai\Fake\FakeTextGenerationProvider;
use App\Services\Ai\Fake\InMemoryAiUsageRecorder;
use App\Services\Ai\OpenAiCompatible\OpenAiCompatibleTextGenerationProvider;
use App\Services\Ai\StreamingTextGenerationProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/** Sem banco de dados: Http::fake + recorder em memória. */
final class AnthropicTextGenerationProviderTest extends TestCase
{
    private const KEY = 'sk-ant-super-secret-key';

    private InMemoryAiUsageRecorder $recorder;

    /** @var list<string> */
    private array $logs = [];

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
        $this->recorder = new InMemoryAiUsageRecorder;
        $this->app->instance(AiUsageRecorder::class, $this->recorder);
        Log::listen(function (MessageLogged $event): void {
            $this->logs[] = $event->message.json_encode($event->context);
        });
    }

    /** @param array<string, mixed> $overrides */
    private function provider(array $overrides = []): AnthropicTextGenerationProvider
    {
        $config = array_replace_recursive([
            'base_url' => 'https://api.anthropic.com', 'api_key' => self::KEY, 'api_version' => '2026-10-21',
            'model' => 'claude-sonnet-5-5', 'max_tokens' => 2048, 'timeout' => 5, 'retries' => 2,
            'retry_base_ms' => 10, 'retry_after_max_seconds' => 30,
            'limits' => ['max_input_tokens' => null, 'chars_per_token' => 3, 'daily_tokens' => null, 'monthly_tokens' => null, 'daily_usd' => null, 'monthly_usd' => null],
            'pricing' => ['reference' => '2026-11-03', 'models' => [
                'claude-sonnet-5-5' => ['input' => 2.0, 'output' => 10.0],
                'claude-haiku-5-5' => ['input' => null, 'output' => null],
            ]],
        ], $overrides);

        return new AnthropicTextGenerationProvider(new AnthropicClient($config), new AnthropicUsageTracker($this->recorder, $config));
    }

    /** @return array<string, mixed> */
    private function message(string $text = 'Resposta', string $stopReason = 'end_turn'): array
    {
        return [
            'id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-5-5',
            'content' => [['type' => 'text', 'text' => $text]], 'stop_reason' => $stopReason,
            'usage' => ['input_tokens' => 1000, 'output_tokens' => 500],
        ];
    }

    private function request(): TextGenerationRequest
    {
        return new TextGenerationRequest('Pergunta?', 'Seja breve.', 0.7, null, null, ['run_id' => 'run-123']);
    }

    private function assertFailsWith(string $code, callable $callback): AiProviderException
    {
        try {
            $callback();
        } catch (AiProviderException $exception) {
            $this->assertSame($code, $exception->errorCode);
            $this->assertStringNotContainsString(self::KEY, $exception->getMessage());

            return $exception;
        }

        $this->fail("Expected {$code}.");
    }

    public function test_valid_response_maps_text_usage_cost_and_metadata(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response($this->message('Olá'), 200, ['request-id' => 'req_abc'])]);

        $response = $this->provider()->generate($this->request());

        $this->assertSame('Olá', $response->content);
        $this->assertSame(1000, $response->usage->inputTokens);
        $this->assertSame(500, $response->usage->outputTokens);
        $this->assertFalse($response->metadata['truncated']);
        $this->assertSame('req_abc', $response->metadata['provider_request_id']);

        $record = $this->recorder->records[0];
        $this->assertSame('success', $record->status);
        $this->assertSame('run-123', $record->runId);
        $this->assertSame('req_abc', $record->providerRequestId);
        $this->assertSame(0.007, $record->estimatedCostUsd);
        $this->assertSame('2026-11-03', $record->pricingReference);
    }

    public function test_payload_has_no_temperature_and_correct_shape_and_headers(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response($this->message())]);

        $this->provider()->generate($this->request());

        Http::assertSent(function (Request $request): bool {
            $body = $request->data();

            return $request->url() === 'https://api.anthropic.com/v1/messages'
                && $request->hasHeader('x-api-key', self::KEY)
                && $request->hasHeader('anthropic-version', '2026-10-21')
                && ! array_key_exists('temperature', $body) && ! array_key_exists('top_p', $body) && ! array_key_exists('top_k', $body)
                && ! array_key_exists('thinking', $body)
                && $body['model'] === 'claude-sonnet-5-5'
                && $body['max_tokens'] === 2048
                && $body['system'] === 'Seja breve.'
                && $body['messages'] === [['role' => 'user', 'content' => 'Pergunta?']];
        });
    }

    public function test_missing_api_key_or_model_is_not_configured_and_nothing_is_sent(): void
    {
        Http::fake();

        $this->assertFailsWith(AiProviderException::NOT_CONFIGURED, fn () => $this->provider(['api_key' => null])->generate($this->request()));
        $this->assertFailsWith(AiProviderException::NOT_CONFIGURED, fn () => $this->provider(['model' => null])->generate($this->request()));
        Http::assertNothingSent();
    }

    public function test_unauthorized_maps_to_auth_failed_without_retry_and_never_leaks_key(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response(['type' => 'error', 'error' => ['type' => 'authentication_error', 'message' => 'invalid x-api-key '.self::KEY], 'request_id' => 'req_x'], 401, ['request-id' => 'req_x'])]);

        $exception = $this->assertFailsWith(AiProviderException::AUTH_FAILED, fn () => $this->provider()->generate($this->request()));

        $this->assertSame(401, $exception->httpStatus);
        $this->assertSame('req_x', $exception->providerRequestId);
        Http::assertSentCount(1);
        $this->assertSame('failed', $this->recorder->records[0]->status);
        $this->assertSame(AiProviderException::AUTH_FAILED, $this->recorder->records[0]->errorCode);
        $this->assertStringNotContainsString(self::KEY, implode("\n", $this->logs));
        $this->assertStringNotContainsString('Pergunta?', implode("\n", $this->logs));
    }

    public function test_status_mapping_for_non_retryable_errors(): void
    {
        $status = 0;
        $calls = 0;
        Http::fake(['api.anthropic.com/*' => function () use (&$status, &$calls) {
            $calls++;

            return Http::response(['type' => 'error', 'error' => ['type' => 'x', 'message' => 'm']], $status);
        }]);
        foreach ([[400, AiProviderException::INVALID_REQUEST], [402, AiProviderException::REJECTED], [403, AiProviderException::AUTH_FAILED], [404, AiProviderException::REJECTED], [413, AiProviderException::REJECTED]] as [$status, $code]) {
            $this->assertFailsWith($code, fn () => $this->provider()->generate($this->request()));
        }
        $this->assertSame(5, $calls);
    }

    public function test_rate_limit_with_retry_after_waits_and_retries(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push(['type' => 'error', 'error' => ['type' => 'rate_limit_error', 'message' => 'slow']], 429, ['retry-after' => '2'])
            ->push($this->message('ok'))]);

        $this->assertSame('ok', $this->provider()->generate($this->request())->content);

        Http::assertSentCount(2);
        Sleep::assertSleptTimes(1);
        Sleep::assertSequence([Sleep::for(2)->seconds()]);
    }

    public function test_rate_limit_without_retry_after_is_not_retried(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response(['type' => 'error', 'error' => ['type' => 'rate_limit_error', 'message' => 'spend limit']], 429)]);

        $this->assertFailsWith(AiProviderException::RATE_LIMITED, fn () => $this->provider()->generate($this->request()));

        Http::assertSentCount(1);
        Sleep::assertNeverSlept();
    }

    public function test_server_errors_are_retried_with_backoff_then_succeed(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push(['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'o']], 529)
            ->push(['type' => 'error', 'error' => ['type' => 'api_error', 'message' => 'e']], 500)
            ->push($this->message('depois'))]);

        $this->assertSame('depois', $this->provider()->generate($this->request())->content);

        Http::assertSentCount(3);
        Sleep::assertSleptTimes(2);
    }

    public function test_persistent_server_errors_exhaust_retries(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response(['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'o']], 529)]);
        $this->assertFailsWith(AiProviderException::UNAVAILABLE, fn () => $this->provider()->generate($this->request()));
        Http::assertSentCount(3);
    }

    public function test_gateway_timeout_maps_to_timeout(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response('gateway timeout', 504)]);
        $this->assertFailsWith(AiProviderException::TIMEOUT, fn () => $this->provider(['retries' => 0])->generate($this->request()));
    }

    public function test_read_timeout_maps_to_timeout_without_retry(): void
    {
        $calls = 0;
        Http::fake(['api.anthropic.com/*' => function () use (&$calls): never {
            $calls++;

            throw new ConnectionException('cURL error 28: Operation timed out');
        }]);

        $this->assertFailsWith(AiProviderException::TIMEOUT, fn () => $this->provider()->generate($this->request()));
        $this->assertSame(1, $calls);
    }

    public function test_connection_failure_is_retried_then_unavailable(): void
    {
        $calls = 0;
        Http::fake(['api.anthropic.com/*' => function () use (&$calls): never {
            $calls++;

            throw new ConnectionException('cURL error 7: refused');
        }]);

        $this->assertFailsWith(AiProviderException::UNAVAILABLE, fn () => $this->provider()->generate($this->request()));
        $this->assertSame(3, $calls);
    }

    public function test_malformed_responses_are_invalid_response(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push(['foo' => 'bar'])
            ->push('<html>nope</html>')
            ->push(['content' => [['type' => 'tool_use', 'id' => 't']], 'stop_reason' => 'tool_use'])]);
        for ($i = 0; $i < 3; $i++) {
            $this->assertFailsWith(AiProviderException::INVALID_RESPONSE, fn () => $this->provider()->generate($this->request()));
        }
    }

    public function test_max_tokens_returns_partial_text_flagged_as_truncated(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response($this->message('parcial', 'max_tokens'))]);

        $response = $this->provider()->generate($this->request());

        $this->assertSame('parcial', $response->content);
        $this->assertTrue($response->metadata['truncated']);
        $this->assertSame('max_tokens', $response->metadata['stop_reason']);
    }

    public function test_refusal_is_rejected(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response($this->message('não posso', 'refusal'))]);

        $this->assertFailsWith(AiProviderException::REJECTED, fn () => $this->provider()->generate($this->request()));
    }

    public function test_only_text_blocks_are_concatenated(): void
    {
        $body = $this->message();
        $body['content'] = [['type' => 'thinking', 'thinking' => 'x'], ['type' => 'text', 'text' => 'A'], ['type' => 'text', 'text' => 'B']];
        Http::fake(['api.anthropic.com/*' => Http::response($body)]);

        $this->assertSame('AB', $this->provider()->generate($this->request())->content);
    }

    public function test_cost_is_null_when_pricing_is_unknown(): void
    {
        $tracker = new AnthropicUsageTracker($this->recorder, ['pricing' => ['reference' => 'x', 'models' => ['claude-haiku-5-5' => ['input' => null, 'output' => null]]]]);

        $this->assertNull($tracker->estimateCost('claude-haiku-5-5', new TokenUsage(10, 10)));
        $this->assertNull($tracker->estimateCost('modelo-desconhecido', new TokenUsage(10, 10)));
        $this->assertNull($tracker->estimateCost('claude-haiku-5-5', null));
    }

    public function test_input_limit_blocks_before_calling_the_api(): void
    {
        Http::fake();

        $this->assertFailsWith(AiProviderException::INPUT_LIMIT_EXCEEDED, fn () => $this->provider(['limits' => ['max_input_tokens' => 5]])->generate(new TextGenerationRequest(str_repeat('a', 100))));

        Http::assertNothingSent();
        $this->assertSame(AiProviderException::INPUT_LIMIT_EXCEEDED, $this->recorder->records[0]->errorCode);
    }

    public function test_exhausted_token_or_usd_budget_blocks_before_calling_the_api(): void
    {
        Http::fake();

        $this->recorder->tokensConsumed = 1000;
        $this->assertFailsWith(AiProviderException::BUDGET_EXCEEDED, fn () => $this->provider(['limits' => ['daily_tokens' => 500]])->generate($this->request()));

        $this->recorder->tokensConsumed = 0;
        $this->recorder->costConsumed = 12.5;
        $this->assertFailsWith(AiProviderException::BUDGET_EXCEEDED, fn () => $this->provider(['limits' => ['monthly_usd' => 10]])->generate($this->request()));

        Http::assertNothingSent();
    }

    public function test_budget_within_limit_allows_the_call(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response($this->message())]);
        $this->recorder->tokensConsumed = 100;

        $this->assertSame('Resposta', $this->provider(['limits' => ['daily_tokens' => 500]])->generate($this->request())->content);
    }

    /** @param list<array{0: string, 1: array<string, mixed>}> $events */
    private function sse(array $events): string
    {
        return implode('', array_map(fn (array $event): string => "event: {$event[0]}\ndata: ".json_encode($event[1])."\n\n", $events));
    }

    /** @return list<array{0: string, 1: array<string, mixed>}> */
    private function streamEvents(): array
    {
        return [
            ['message_start', ['type' => 'message_start', 'message' => ['model' => 'claude-sonnet-5-5', 'usage' => ['input_tokens' => 1000, 'output_tokens' => 1]]]],
            ['content_block_start', ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']]],
            ['ping', ['type' => 'ping']],
            ['content_block_delta', ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Olá, ']]],
            ['content_block_delta', ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'mundo']]],
            ['content_block_stop', ['type' => 'content_block_stop', 'index' => 0]],
            ['message_delta', ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 500]]],
            ['message_stop', ['type' => 'message_stop']],
        ];
    }

    public function test_streaming_yields_fragments_and_returns_full_response(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response($this->sse($this->streamEvents()), 200, ['content-type' => 'text/event-stream', 'request-id' => 'req_s'])]);

        $generator = $this->provider()->stream($this->request());
        $fragments = iterator_to_array($generator, false);
        $response = $generator->getReturn();

        $this->assertSame(['Olá, ', 'mundo'], $fragments);
        $this->assertSame('Olá, mundo', $response->content);
        $this->assertSame(1000, $response->usage->inputTokens);
        $this->assertSame(500, $response->usage->outputTokens);
        $this->assertSame('req_s', $response->metadata['provider_request_id']);
        $this->assertSame(0.007, $this->recorder->records[0]->estimatedCostUsd);
        Http::assertSent(fn (Request $request): bool => $request['stream'] === true && ! isset($request['temperature']));
    }

    public function test_streaming_error_event_mid_stream_raises_controlled_error(): void
    {
        $events = array_slice($this->streamEvents(), 0, 4);
        $events[] = ['error', ['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']]];
        Http::fake(['api.anthropic.com/*' => Http::response($this->sse($events), 200, ['content-type' => 'text/event-stream'])]);

        $generator = $this->provider()->stream($this->request());
        $received = [];
        try {
            foreach ($generator as $fragment) {
                $received[] = $fragment;
            }
            $this->fail('Expected error.');
        } catch (AiProviderException $exception) {
            $this->assertSame(AiProviderException::UNAVAILABLE, $exception->errorCode);
        }

        $this->assertSame(['Olá, '], $received);
        $this->assertSame('failed', $this->recorder->records[0]->status);
    }

    public function test_truncated_stream_without_message_stop_is_invalid_response(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response($this->sse(array_slice($this->streamEvents(), 0, 4)), 200)]);

        $this->assertFailsWith(AiProviderException::INVALID_RESPONSE, fn () => iterator_to_array($this->provider()->stream($this->request())));
    }

    public function test_manager_switches_between_anthropic_fake_and_openai_compatible(): void
    {
        config()->set('ai.text.drivers.anthropic', ['base_url' => 'https://api.anthropic.com', 'api_key' => self::KEY, 'model' => 'claude-sonnet-5-5']);
        config()->set('ai.text.drivers.openai_compatible', ['base_url' => 'http://llm.internal/v1', 'api_key' => 'k', 'model' => 'm']);
        $manager = new AiProviderManager;

        config()->set('ai.text.default', 'anthropic');
        $this->assertInstanceOf(AnthropicTextGenerationProvider::class, $manager->text());
        $this->assertInstanceOf(StreamingTextGenerationProvider::class, $manager->text());
        config()->set('ai.text.default', 'fake');
        $this->assertInstanceOf(FakeTextGenerationProvider::class, $manager->text());
        config()->set('ai.text.default', 'openai_compatible');
        $this->assertInstanceOf(OpenAiCompatibleTextGenerationProvider::class, $manager->text());

        Http::fake(['api.anthropic.com/*' => Http::response($this->message('via manager'))]);
        $this->assertSame('via manager', $manager->text('anthropic')->generate($this->request())->content);
    }

    public function test_anthropic_driver_without_key_does_not_break_other_providers(): void
    {
        config()->set('ai.text.drivers.anthropic', ['api_key' => null, 'model' => null]);
        $manager = new AiProviderManager;

        $this->assertFailsWith(AiProviderException::NOT_CONFIGURED, fn () => $manager->text('anthropic')->generate($this->request()));
        $this->assertSame('fake-response', $manager->text('fake')->generate($this->request())->content);
    }
}
