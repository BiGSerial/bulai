<?php

namespace Tests\Feature\Ai;

use App\Data\Ai\DocumentClassificationRequest;
use App\Data\Ai\DocumentClassificationResponse;
use App\Data\Knowledge\DocumentIdentification;
use App\Jobs\ClassifyPendingDocument;
use App\Models\AiUsageLog;
use App\Models\Knowledge\IngestionBatchItem;
use App\Services\Ai\AiProviderException;
use App\Services\Ai\AiProviderManager;
use App\Services\Ai\Anthropic\AnthropicDocumentClassificationProvider;
use App\Services\Ai\Fake\FakeDocumentClassificationProvider;
use App\Services\Ai\FallbackDocumentClassificationProvider;
use App\Services\Knowledge\AutomaticAnalysisReport;
use App\Services\Knowledge\KnowledgeDocumentClassificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Claude como retaguarda do classificador local: provider Anthropic (API simulada) e encadeamento por gatilhos. */
final class AnthropicClassificationFallbackTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('ai.classifier.drivers.anthropic', [...config('ai.classifier.drivers.anthropic'), 'api_key' => 'sk-test', 'base_url' => 'https://api.anthropic.com', 'model' => 'claude-haiku-5-5', 'retries' => 0]);
        config()->set('document_classification.ai_min_confidence', 0.85);
    }

    public function test_the_anthropic_provider_sends_the_same_prompt_with_structured_output_and_records_usage(): void
    {
        Http::fake([self::ENDPOINT => Http::response($this->apiBody('{"product_name":"Novalgina","active_ingredient":"dipirona monoidratada","document_type":"bula_paciente","confidence":0.93,"evidence":["NOVALGINA comprimidos"]}'), 200, ['request-id' => 'req_1'])]);

        $response = app(AiProviderManager::class)->classifier('anthropic')->classify($this->request());

        $this->assertInstanceOf(AnthropicDocumentClassificationProvider::class, app(AiProviderManager::class)->classifier('anthropic'));
        $this->assertSame(['Novalgina', 'dipirona monoidratada', 'bula_paciente', 0.93, 'claude-haiku-5-5', 'anthropic'], [$response->productName, $response->activeIngredient, $response->documentType, $response->confidence, $response->model, $response->metadata['provider']]);
        $this->assertSame('json_schema', $response->metadata['prompt']['structured_output']);
        $this->assertStringContainsString('product_name', $response->metadata['raw_response']);

        Http::assertSent(function (Request $request): bool {
            $body = $request->data();
            $schema = $body['output_config']['format']['schema'];

            return $request->hasHeader('x-api-key', 'sk-test') && $request->hasHeader('anthropic-version')
                && $body['model'] === 'claude-haiku-5-5' && ! array_key_exists('temperature', $body) && ! array_key_exists('tools', $body)
                && str_starts_with($body['system'], 'Você identifica bulas') && str_contains($body['messages'][0]['content'], '"heuristic_score":60')
                && $body['output_config']['format']['type'] === 'json_schema' && $schema['additionalProperties'] === false
                && ! isset($schema['properties']['product_name']['maxLength']) && ! isset($schema['properties']['confidence']['minimum']);
        });
        $log = AiUsageLog::query()->sole();
        $this->assertSame(['anthropic', 'claude-haiku-5-5', 'classify', AiUsageLog::STATUS_SUCCESS, 500, 40], [$log->provider, $log->model, $log->capability, $log->status, $log->input_tokens, $log->output_tokens]);
    }

    public function test_an_answer_outside_the_contract_is_an_invalid_response_and_the_failure_is_recorded(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['id' => 'msg_2', 'model' => 'claude-haiku-5-5', 'content' => [['type' => 'tool_use', 'id' => 't1', 'name' => 'x', 'input' => []]], 'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 10, 'output_tokens' => 1]])]);

        try {
            app(AiProviderManager::class)->classifier('anthropic')->classify($this->request());
            $this->fail('Resposta sem bloco de texto deveria ser rejeitada.');
        } catch (AiProviderException $exception) {
            $this->assertSame(AiProviderException::INVALID_RESPONSE, $exception->errorCode);
        }

        $log = AiUsageLog::query()->sole();
        $this->assertSame(['classify', AiUsageLog::STATUS_FAILED, AiProviderException::INVALID_RESPONSE], [$log->capability, $log->status, $log->error_code]);
    }

    public function test_the_secondary_is_called_only_on_the_three_triggers(): void
    {
        $secondaryAnswer = new DocumentClassificationResponse('Novalgina', 'dipirona', 'bula_paciente', 0.95, [], 'claude-haiku-5-5', ['provider' => 'anthropic']);

        // Primário lança exceção.
        [$primary, $secondary] = [new FakeDocumentClassificationProvider(null, AiProviderException::TIMEOUT), new FakeDocumentClassificationProvider($secondaryAnswer)];
        $response = $this->chain($primary, $secondary)->classify($this->request());
        $this->assertSame([1, 'Novalgina', 'anthropic'], [$secondary->calls, $response->productName, $response->metadata['provider']]);
        $this->assertSame(['used' => true, 'primary' => 'llamafile', 'secondary' => 'anthropic', 'reason' => 'exception:AI_TIMEOUT'], $response->metadata['fallback']);

        // Confiança abaixo do mínimo.
        [$primary, $secondary] = [new FakeDocumentClassificationProvider(new DocumentClassificationResponse('Novalgina', 'dipirona', null, 0.5, [], 'qwen')), new FakeDocumentClassificationProvider($secondaryAnswer)];
        $response = $this->chain($primary, $secondary)->classify($this->request());
        $this->assertSame([1, 'low_confidence', 0.5], [$secondary->calls, $response->metadata['fallback']['reason'], $response->metadata['fallback']['primary_confidence']]);

        // Princípio ativo ambíguo que a IA local não resolveu.
        [$primary, $secondary] = [new FakeDocumentClassificationProvider(new DocumentClassificationResponse('Novalgina', null, 'bula_paciente', 0.9, [], 'qwen')), new FakeDocumentClassificationProvider($secondaryAnswer)];
        $response = $this->chain($primary, $secondary)->classify($this->request(ambiguous: ['active_ingredient']));
        $this->assertSame([1, 'missing_active_ingredient', 'dipirona'], [$secondary->calls, $response->metadata['fallback']['reason'], $response->activeIngredient]);

        // Sem gatilho: a IA local respondeu bem e o Claude não é chamado.
        [$primary, $secondary] = [new FakeDocumentClassificationProvider(new DocumentClassificationResponse('Novalgina', 'dipirona', 'bula_paciente', 0.9, [], 'qwen', ['provider' => 'llamafile'])), new FakeDocumentClassificationProvider($secondaryAnswer)];
        $response = $this->chain($primary, $secondary)->classify($this->request(ambiguous: ['active_ingredient']));
        $this->assertSame([0, 'qwen', ['used' => false, 'primary' => 'llamafile']], [$secondary->calls, $response->model, $response->metadata['fallback']]);

        // Os dois falham: a exceção do secundário sobe (o serviço a transforma em AI_UNAVAILABLE).
        [$primary, $secondary] = [new FakeDocumentClassificationProvider(null, AiProviderException::UNAVAILABLE), new FakeDocumentClassificationProvider(null, AiProviderException::BUDGET_EXCEEDED)];
        try {
            $this->chain($primary, $secondary)->classify($this->request());
            $this->fail('Falha dupla deveria lançar.');
        } catch (AiProviderException $exception) {
            $this->assertSame(AiProviderException::BUDGET_EXCEEDED, $exception->errorCode);
        }
    }

    public function test_the_manager_chains_the_default_driver_with_the_configured_fallback(): void
    {
        $manager = app(AiProviderManager::class);
        $manager->extend('classifier', 'local', fn () => new FakeDocumentClassificationProvider(null, AiProviderException::UNAVAILABLE));
        $manager->extend('classifier', 'backup', fn () => new FakeDocumentClassificationProvider(new DocumentClassificationResponse('Novalgina', null, 'bula_paciente', 0.9, ['NOVALGINA comprimidos'], 'claude-haiku-5-5', ['provider' => 'anthropic'])));
        config()->set('ai.classifier.default', 'local');

        config()->set('ai.classifier.fallback', null);
        $this->assertInstanceOf(FakeDocumentClassificationProvider::class, $manager->classifier());
        config()->set('ai.classifier.fallback', 'local');
        $this->assertInstanceOf(FakeDocumentClassificationProvider::class, $manager->classifier());
        $this->assertNull($manager->classifierFallbackDriver());
        config()->set('ai.classifier.fallback', 'backup');
        $this->assertInstanceOf(FallbackDocumentClassificationProvider::class, $manager->classifier());
        $this->assertInstanceOf(FakeDocumentClassificationProvider::class, $manager->classifier('local'), 'driver explícito nunca é encadeado');
        $this->assertSame('backup', $manager->classifierFallbackDriver());

        // O serviço de classificação grava quem respondeu, e a revisão explica em linguagem simples.
        $result = app(KnowledgeDocumentClassificationService::class)->refine($this->identification());
        $this->assertSame(['anthropic', true, 'exception:AI_UNAVAILABLE', 'local'], [$result->classification['provider'], $result->classification['fallback']['used'], $result->classification['fallback']['reason'], $result->classification['fallback']['primary']]);
        // Sem confirmação do catálogo ANVISA a resposta do Claude fica só como sugestão: a regra de autonomia não muda.
        $this->assertSame([KnowledgeDocumentClassificationService::AI_SUGGESTED, 'Novalgina', null], [$result->classification['decision'], $result->classification['result']['product_name'], $result->fields['product_name']]);

        $item = new IngestionBatchItem(['metadata' => ['preliminary_identification' => ['score' => 60, 'fields' => $result->fields, 'classification' => $result->classification]]]);
        $report = app(AutomaticAnalysisReport::class)->forItem($item);
        $this->assertSame(['anthropic', 'A IA local não conseguiu conectar; a resposta veio do Claude (claude-haiku-5-5).'], [$report['classification']['provider'], $report['classification']['fallback']['text']]);
    }

    public function test_the_job_timeout_includes_the_fallback_driver(): void
    {
        config()->set(['knowledge.processing.job_timeout_seconds' => 10, 'document_ocr.drivers.http.timeout' => 100, 'ai.classifier.drivers.llamafile.timeout' => 50, 'ai.classifier.default' => 'llamafile', 'ai.classifier.drivers.anthropic.timeout' => 60]);
        config()->set('ai.classifier.fallback', null);
        $this->assertSame(180, (new ClassifyPendingDocument(1, 1))->timeout);
        config()->set('ai.classifier.fallback', 'anthropic');
        $this->assertSame(240, (new ClassifyPendingDocument(1, 1))->timeout);
    }

    private function chain(FakeDocumentClassificationProvider $primary, FakeDocumentClassificationProvider $secondary): FallbackDocumentClassificationProvider
    {
        return new FallbackDocumentClassificationProvider($primary, $secondary, 'llamafile', 'anthropic', 0.85);
    }

    /** @param list<string> $ambiguous */
    private function request(array $ambiguous = ['product_name']): DocumentClassificationRequest
    {
        return new DocumentClassificationRequest(60, ['product_name' => ['NOVALGINA']], '178170001', ['patient'], [['page' => 1, 'type' => 'page_header', 'text' => 'NOVALGINA comprimidos']], $ambiguous);
    }

    private function identification(): DocumentIdentification
    {
        $fields = ['product_name' => null, 'active_ingredient' => 'dipirona', 'registration_number' => '178170001', 'registration_number_normalized' => '178170001', 'holder' => null, 'audience' => 'patient', 'document_type' => 'bula_paciente', 'title' => null];

        return new DocumentIdentification('anvisa', $fields, [], ['product_name'], 60, false, 2,
            candidates: ['product_name' => ['NOVALGINA'], 'active_ingredient' => [], 'registration_number' => ['178170001'], 'audience' => ['patient']],
            excerpts: [['page' => 1, 'type' => 'page_header', 'text' => 'NOVALGINA comprimidos']]);
    }

    /** @return array<string, mixed> */
    private function apiBody(string $text): array
    {
        return ['id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-haiku-5-5', 'content' => [['type' => 'text', 'text' => $text]], 'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 500, 'output_tokens' => 40]];
    }
}
