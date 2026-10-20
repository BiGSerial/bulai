<?php

namespace Tests\Unit\Services\Ai;

use App\Data\Ai\DocumentClassificationRequest;
use App\Services\Ai\AiProviderException;
use App\Services\Ai\AiProviderManager;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class LlamafileClassificationProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('ai.classifier.default', 'llamafile');
        config()->set('ai.classifier.drivers.llamafile', ['base_url' => 'http://local-ai:8080/v1', 'api_key' => null, 'model' => 'qwen', 'timeout' => 5, 'retries' => 0, 'structured_output' => 'grammar']);
    }

    public function test_valid_structured_answer_is_returned_as_dto_and_request_carries_only_evidence(): void
    {
        $this->fakeHttp(Http::response(['model' => 'qwen', 'choices' => [['message' => ['content' => json_encode([
            'product_name' => 'Novalgina', 'active_ingredient' => null, 'document_type' => 'bula_paciente', 'confidence' => 0.9, 'evidence' => ['cabeçalho'],
        ])]]]]));

        $response = app(AiProviderManager::class)->classifier()->classify($this->request());

        $this->assertSame('Novalgina', $response->productName);
        $this->assertSame('llamafile', $response->metadata['provider']);
        $this->assertSame('grammar', $response->metadata['prompt']['structured_output']);
        $this->assertStringContainsString('product_name', $response->metadata['prompt']['system']);
        $this->assertStringContainsString('Novalgina', $response->metadata['raw_response']);
        Http::assertSent(function (Request $request): bool {
            $sent = json_decode($request->body(), true);
            $user = json_decode($sent['messages'][1]['content'], true);

            return $request->url() === 'http://local-ai:8080/v1/chat/completions'
                && $sent['temperature'] === 0
                && $sent['response_format']['type'] === 'json_object' && isset($sent['response_format']['schema']['properties'])
                && $user['heuristic_score'] === 67 && $user['ambiguous_fields'] === ['product_name']
                && array_keys($user) === ['heuristic_score', 'candidates', 'registration', 'audience_candidates', 'evidence', 'ambiguous_fields'];
        });
    }

    public function test_invalid_json_and_empty_choices_are_rejected(): void
    {
        foreach (['not json at all', '{"product_name":"X"}'] as $content) {
            $this->fakeHttp(Http::response(['choices' => [['message' => ['content' => $content]]]]));
            $this->assertClassifyError('AI_INVALID_RESPONSE');
        }
        $this->fakeHttp(Http::response(['choices' => []]));
        $this->assertClassifyError('AI_INVALID_RESPONSE');
    }

    public function test_timeout_unavailable_and_loading_service_are_controlled(): void
    {
        $this->fakeHttp(fn () => throw new ConnectionException('cURL error 28: timed out'));
        $this->assertClassifyError('AI_TIMEOUT');
        $this->fakeHttp(Http::response(['error' => 'MODEL_NOT_READY', 'status' => 'MODEL_LOADING'], 503));
        $this->assertClassifyError('AI_UNAVAILABLE');
    }

    public function test_unconfigured_driver_raises_controlled_error(): void
    {
        config()->set('ai.classifier.default', null);

        $this->assertFalse(app(AiProviderManager::class)->isConfigured('classifier'));
        $this->expectExceptionObject(new AiProviderException('AI_NOT_CONFIGURED'));
        app(AiProviderManager::class)->classifier();
    }

    private function request(): DocumentClassificationRequest
    {
        return new DocumentClassificationRequest(67, ['product_name' => ['NOVALGINA', 'dipirona']], '178170001', ['patient'],
            [['page' => 1, 'type' => 'page_header', 'text' => 'NOVALGINA']], ['product_name']);
    }

    private function assertClassifyError(string $code): void
    {
        try {
            app(AiProviderManager::class)->classifier()->classify($this->request());
            $this->fail('Expected exception.');
        } catch (AiProviderException $exception) {
            $this->assertSame($code, $exception->errorCode);
        }
    }

    private function fakeHttp(mixed $stub): void
    {
        Http::swap(new Factory);
        Http::fake(['*' => $stub]);
    }
}
