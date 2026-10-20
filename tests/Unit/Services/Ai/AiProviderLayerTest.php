<?php

namespace Tests\Unit\Services\Ai;

use App\Data\Ai\EmbeddingBatchRequest;
use App\Data\Ai\EmbeddingMetadata;
use App\Data\Ai\EmbeddingRequest;
use App\Data\Ai\TextGenerationRequest;
use App\Data\Ai\TextGenerationResponse;
use App\Data\Ai\TokenUsage;
use App\Data\Ai\VisionImage;
use App\Data\Ai\VisionRequest;
use App\Services\Ai\AiProviderException;
use App\Services\Ai\AiProviderManager;
use App\Services\Ai\EmbeddingProvider;
use App\Services\Ai\Fake\FakeTextGenerationProvider;
use App\Services\Ai\OpenAiCompatible\OpenAiCompatibleTextGenerationProvider;
use App\Services\Ai\TextGenerationProvider;
use App\Services\Ai\VisionProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class AiProviderLayerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['text', 'embedding', 'vision'] as $capability) {
            config()->set("ai.{$capability}.drivers.openai_compatible", [
                'base_url' => 'http://llm.internal/v1', 'api_key' => 'sk-very-secret', 'model' => "{$capability}-model", 'timeout' => 5, 'retries' => 0,
            ]);
        }
    }

    public function test_provider_is_resolved_by_configured_driver_per_capability(): void
    {
        config()->set('ai.text.default', 'openai_compatible');
        config()->set('ai.embedding.default', 'fake');
        config()->set('ai.vision.default', null);

        $manager = app(AiProviderManager::class);

        $this->assertInstanceOf(OpenAiCompatibleTextGenerationProvider::class, $manager->text());
        $this->assertInstanceOf(EmbeddingProvider::class, app(EmbeddingProvider::class));
        $this->assertNotInstanceOf(OpenAiCompatibleTextGenerationProvider::class, $manager->embedding());
        $this->expectExceptionObject(new AiProviderException(AiProviderException::NOT_CONFIGURED));
        $manager->vision();
    }

    public function test_unknown_driver_is_not_configured_and_custom_drivers_can_be_registered(): void
    {
        config()->set('ai.text.default', 'inexistente');
        $manager = app(AiProviderManager::class);
        try {
            $manager->text();
            $this->fail('Expected exception.');
        } catch (AiProviderException $exception) {
            $this->assertSame('AI_NOT_CONFIGURED', $exception->errorCode);
        }

        $manager->extend('text', 'custom_http', fn (): TextGenerationProvider => new FakeTextGenerationProvider('custom'));
        config()->set('ai.text.default', 'custom_http');
        $this->assertSame('custom', $manager->text()->generate(new TextGenerationRequest('oi'))->content);
    }

    public function test_domain_consumer_depends_only_on_the_contract_and_provider_swap_needs_no_change(): void
    {
        $makeConsumer = fn (): object => new class(app(TextGenerationProvider::class))
        {
            public function __construct(private readonly TextGenerationProvider $provider) {}

            public function summarize(string $text): string
            {
                return $this->provider->generate(new TextGenerationRequest($text))->content;
            }
        };
        config()->set('ai.text.default', 'fake');

        $this->assertSame('fake-response', $makeConsumer()->summarize('x'));

        $this->app->instance(TextGenerationProvider::class, new class implements TextGenerationProvider
        {
            public function generate(TextGenerationRequest $request): TextGenerationResponse
            {
                return new TextGenerationResponse('outro provider', 'm', new TokenUsage(1, 1));
            }
        });
        $this->assertSame('outro provider', $makeConsumer()->summarize('x'));
    }

    public function test_openai_compatible_text_generation_is_normalized_into_dtos(): void
    {
        $this->fakeHttp(['*' => Http::response([
            'model' => 'gpt-x', 'choices' => [['message' => ['content' => 'Olá'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 11, 'completion_tokens' => 3, 'total_tokens' => 14],
        ])]);
        config()->set('ai.text.default', 'openai_compatible');

        $response = app(AiProviderManager::class)->text()->generate(new TextGenerationRequest('Pergunta', system: 'Sistema', temperature: 0.2));

        $this->assertSame('Olá', $response->content);
        $this->assertSame('gpt-x', $response->model);
        $this->assertSame(11, $response->usage->inputTokens);
        $this->assertSame(3, $response->usage->outputTokens);
        $this->assertSame('openai_compatible', $response->metadata['provider']);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://llm.internal/v1/chat/completions'
            && $request->hasHeader('Authorization', 'Bearer sk-very-secret')
            && $request['model'] === 'text-model'
            && $request['messages'][0]['role'] === 'system');
    }

    public function test_openai_compatible_embeddings_keep_input_order_and_vectors_are_floats(): void
    {
        $this->fakeHttp(['*' => Http::response([
            'model' => 'emb-1',
            'data' => [['index' => 1, 'embedding' => [0, 1]], ['index' => 0, 'embedding' => [0.5, 0.25]]],
            'usage' => ['prompt_tokens' => 7],
        ])]);
        config()->set('ai.embedding.default', 'openai_compatible');
        $provider = app(EmbeddingProvider::class);

        $batch = $provider->embedMany(new EmbeddingBatchRequest(['a', 'b']));

        $this->assertSame([0.5, 0.25], $batch->embeddings[0]->vector);
        $this->assertSame([0.0, 1.0], $batch->embeddings[1]->vector);
        $this->assertSame(7, $batch->inputTokens);
        $this->assertSame(2, $batch->embeddings[0]->dimensions());
        $this->assertSame('emb-1', $batch->model);
    }

    public function test_single_embed_and_vision_use_their_own_endpoints(): void
    {
        $this->fakeHttp(['*/embeddings' => Http::response(['data' => [['index' => 0, 'embedding' => [1.0]]]]),
            '*/chat/completions' => Http::response(['choices' => [['message' => ['content' => 'imagem de bula']]]])]);
        config()->set('ai.embedding.default', 'openai_compatible');
        config()->set('ai.vision.default', 'openai_compatible');

        $this->assertSame([1.0], app(EmbeddingProvider::class)->embed(new EmbeddingRequest('texto'))->vector);
        $vision = app(VisionProvider::class)->analyze(new VisionRequest('descreva', [new VisionImage('image/png', 'png-bytes')]));

        $this->assertSame('imagem de bula', $vision->content);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/chat/completions')
            && str_starts_with($request['messages'][0]['content'][1]['image_url']['url'], 'data:image/png;base64,'));
    }

    public function test_invalid_requests_are_rejected_before_any_http_call(): void
    {
        Http::fake();
        config()->set('ai.vision.default', 'openai_compatible');
        config()->set('ai.max_input_characters', 10);
        config()->set('ai.text.default', 'openai_compatible');

        foreach ([
            fn () => app(VisionProvider::class)->analyze(new VisionRequest('x', [new VisionImage('application/pdf', 'x')])),
            fn () => app(TextGenerationProvider::class)->generate(new TextGenerationRequest(str_repeat('a', 11))),
        ] as $call) {
            try {
                $call();
                $this->fail('Expected exception.');
            } catch (AiProviderException $exception) {
                $this->assertSame('AI_INVALID_REQUEST', $exception->errorCode);
            }
        }
        Http::assertNothingSent();
    }

    public function test_http_failures_are_controlled_and_never_expose_secrets_or_urls(): void
    {
        config()->set('ai.text.default', 'openai_compatible');
        $cases = [
            ['stub' => Http::response(['error' => 'sk-very-secret'], 429), 'code' => 'AI_RATE_LIMITED'],
            ['stub' => Http::response('boom llm.internal', 500), 'code' => 'AI_UNAVAILABLE'],
            ['stub' => Http::response('', 401), 'code' => 'AI_AUTH_FAILED'],
            ['stub' => Http::response('', 400), 'code' => 'AI_REJECTED'],
            ['stub' => Http::response(['choices' => []]), 'code' => 'AI_INVALID_RESPONSE'],
            ['stub' => fn () => throw new ConnectionException('cURL error 28: timed out http://llm.internal sk-very-secret'), 'code' => 'AI_TIMEOUT'],
            ['stub' => fn () => throw new ConnectionException('cURL error 7: refused sk-very-secret'), 'code' => 'AI_UNAVAILABLE'],
        ];

        foreach ($cases as $case) {
            $this->fakeHttp(['*' => $case['stub']]);
            try {
                app(AiProviderManager::class)->text()->generate(new TextGenerationRequest('Pergunta'));
                $this->fail('Expected exception.');
            } catch (AiProviderException $exception) {
                $this->assertSame($case['code'], $exception->errorCode);
                $this->assertSame($case['code'], $exception->getMessage());
                $this->assertStringNotContainsString('sk-very-secret', (string) $exception);
                $this->assertNotNull($exception->correlationId);
            }
        }
    }

    public function test_embedding_metadata_detects_obsolete_content_or_model(): void
    {
        $response = app(AiProviderManager::class)->embedding('fake')->embed(new EmbeddingRequest('texto original', 'model-a'));
        $metadata = EmbeddingMetadata::forContent('texto original', 'fake', $response);

        $this->assertSame(8, $metadata->dimensions);
        $this->assertFalse($metadata->isObsoleteFor('texto original', 'fake', 'model-a'));
        $this->assertTrue($metadata->isObsoleteFor('texto alterado', 'fake', 'model-a'));
        $this->assertTrue($metadata->isObsoleteFor('texto original', 'fake', 'model-b'));
        $this->assertTrue($metadata->isObsoleteFor('texto original', 'openai_compatible', 'model-a'));
        $this->assertEquals($metadata, EmbeddingMetadata::fromArray($metadata->toArray()));
        $this->assertNull(EmbeddingMetadata::fromArray(['provider' => 'x']));
    }

    private function fakeHttp(mixed $stub): void
    {
        Http::swap(new Factory);
        Http::fake($stub);
    }
}
