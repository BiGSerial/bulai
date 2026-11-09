<?php

namespace Tests\Feature\Ai;

use App\Data\Ai\AiUsageRecord;
use App\Data\Ai\TextGenerationRequest;
use App\Http\Middleware\AttributeAiUsageToUser;
use App\Models\AiUsageLog;
use App\Models\User;
use App\Services\Ai\AiProviderException;
use App\Services\Ai\AiProviderManager;
use App\Services\Ai\AiUsageRecorder;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\TestCase;

final class AiUsageByUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_anthropic_call_is_attributed_to_the_signed_in_user_with_the_usage_returned_by_the_api(): void
    {
        $user = User::factory()->create();
        config()->set('ai.text.default', 'anthropic');
        config()->set('ai.text.drivers.anthropic.api_key', 'sk-ant-test');
        config()->set('ai.text.drivers.anthropic.model', 'claude-sonnet-5-5');
        config()->set('ai.text.drivers.anthropic.retries', 0);
        $usage = ['input_tokens' => 200, 'cache_creation_input_tokens' => 1000, 'cache_read_input_tokens' => 5000, 'output_tokens' => 300, 'service_tier' => 'standard'];
        Http::fake(['api.anthropic.com/*' => Http::response([
            'id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-5-5',
            'content' => [['type' => 'text', 'text' => 'Resposta']], 'stop_reason' => 'end_turn', 'usage' => $usage,
        ], 200, ['request-id' => 'req_abc'])]);

        $this->actingAs($user);
        app(AiProviderManager::class)->text()->generate(new TextGenerationRequest(prompt: 'Posso tomar com café?'));

        $log = AiUsageLog::query()->sole();
        $this->assertSame($user->id, $log->user_id);
        $this->assertSame(['anthropic', 'claude-sonnet-5-5', 'req_abc'], [$log->provider, $log->response_model, $log->provider_request_id]);
        $this->assertSame([6200, 5000, 1000, 300, null], [$log->input_tokens, $log->cache_read_tokens, $log->cache_write_tokens, $log->output_tokens, $log->reasoning_tokens]);
        $this->assertEquals($usage, $log->provider_usage);
        // Tabela de config/ai.php: 200 × 2 + 5.000 × 0,2 + 1.000 × 2,5 + 300 × 10.
        $this->assertSame('0.006900', $log->estimated_cost_usd);
    }

    public function test_openai_calls_are_recorded_under_openai_with_their_own_usage_and_failures(): void
    {
        $user = User::factory()->create();
        config()->set('ai.text.default', 'openai_compatible');
        config()->set('ai.text.drivers.openai_compatible', [
            'base_url' => 'https://api.openai.com/v1', 'api_key' => 'sk-test', 'model' => 'gpt-x', 'timeout' => 5, 'retries' => 0,
            'provider' => null, 'pricing' => ['reference' => '2026-11-08', 'models' => ['gpt-x' => ['input' => 1.0, 'cache_read' => 0.1, 'output' => 4.0]]],
        ]);
        $usage = ['prompt_tokens' => 1200, 'completion_tokens' => 400, 'total_tokens' => 1600, 'prompt_tokens_details' => ['cached_tokens' => 1000], 'completion_tokens_details' => ['reasoning_tokens' => 150]];
        Http::fake(['api.openai.com/*' => Http::sequence()
            ->push(['model' => 'gpt-x-2026', 'choices' => [['message' => ['content' => 'Oi'], 'finish_reason' => 'stop']], 'usage' => $usage])
            ->push(['error' => ['message' => 'indisponível']], 500)]);

        $this->actingAs($user);
        app(AiProviderManager::class)->text()->generate(new TextGenerationRequest(prompt: 'Olá'));
        try {
            app(AiProviderManager::class)->text()->generate(new TextGenerationRequest(prompt: 'Olá de novo'));
            $this->fail('A segunda chamada deveria falhar.');
        } catch (AiProviderException) {
        }

        $success = AiUsageLog::query()->where('status', AiUsageLog::STATUS_SUCCESS)->sole();
        $this->assertSame(['openai', 'gpt-x', 'gpt-x-2026', $user->id], [$success->provider, $success->model, $success->response_model, $success->user_id]);
        $this->assertSame([1200, 1000, 400, 150], [$success->input_tokens, $success->cache_read_tokens, $success->output_tokens, $success->reasoning_tokens]);
        $this->assertEquals($usage, $success->provider_usage);
        $this->assertSame(['0.001900', '2026-11-08'], [$success->estimated_cost_usd, $success->pricing_reference]);

        $failure = AiUsageLog::query()->where('status', AiUsageLog::STATUS_FAILED)->sole();
        $this->assertSame(['openai', AiProviderException::UNAVAILABLE, 500, $user->id], [$failure->provider, $failure->error_code, $failure->http_status, $failure->user_id]);
    }

    public function test_work_outside_the_request_keeps_the_user_and_screen_that_started_it(): void
    {
        $user = User::factory()->create();
        $request = Request::create('/perguntar');
        $request->setUserResolver(fn (): User => $user);
        $request->setRouteResolver(fn (): Route => (new Route('GET', '/perguntar', []))->name('consulta.perguntar'));

        (new AttributeAiUsageToUser)->handle($request, fn (): Response => new Response);
        $this->assertGuest();
        app(AiUsageRecorder::class)->record(new AiUsageRecord('anthropic', 'claude-sonnet-5-5', null, 'text', 10, 5, 100, AiUsageLog::STATUS_SUCCESS));

        $log = AiUsageLog::query()->sole();
        $this->assertSame([$user->id, 'route:consulta.perguntar'], [$log->user_id, $log->origin]);
    }

    public function test_commands_are_recorded_without_user_and_with_their_name_but_the_worker_is_not(): void
    {
        event(new CommandStarting('queue:work', new ArrayInput([]), new NullOutput));
        app(AiUsageRecorder::class)->record(new AiUsageRecord('anthropic', 'claude-sonnet-5-5', null, 'text', 10, 5, 100, AiUsageLog::STATUS_SUCCESS));
        event(new CommandStarting('evaluation:run', new ArrayInput([]), new NullOutput));
        app(AiUsageRecorder::class)->record(new AiUsageRecord('anthropic', 'claude-sonnet-5-5', null, 'text', 10, 5, 100, AiUsageLog::STATUS_SUCCESS));

        $this->assertSame([null, 'artisan:evaluation:run'], AiUsageLog::query()->orderBy('id')->pluck('origin')->all());
        $this->assertSame(0, AiUsageLog::query()->whereNotNull('user_id')->count());
    }
}
