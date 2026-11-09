<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\Analytics\AiUsage\Index;
use App\Models\AiUsageLog;
use App\Models\User;
use App\Services\Analytics\AiConsumptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class AiUsageAdminTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: User} */
    private function seedUsage(): array
    {
        $ana = User::factory()->create(['name' => 'Ana Consumo']);
        $bruno = User::factory()->create(['name' => 'Bruno Consumo']);
        AiUsageLog::factory()->create(['user_id' => $ana->id, 'origin' => 'livewire:consulta.perguntar', 'input_tokens' => 6200, 'cache_read_tokens' => 5000, 'cache_write_tokens' => 1000, 'output_tokens' => 300, 'estimated_cost_usd' => 0.0069, 'provider_usage' => ['input_tokens' => 200, 'cache_read_input_tokens' => 5000, 'service_tier' => 'standard']]);
        AiUsageLog::factory()->create(['user_id' => $ana->id, 'input_tokens' => 1000, 'output_tokens' => 100, 'estimated_cost_usd' => 0.0031]);
        AiUsageLog::factory()->create(['user_id' => $bruno->id, 'provider' => 'openai', 'model' => 'gpt-x', 'input_tokens' => 500, 'output_tokens' => 100, 'reasoning_tokens' => 40, 'estimated_cost_usd' => null]);
        AiUsageLog::factory()->failed()->create(['user_id' => $bruno->id, 'provider' => 'openai', 'model' => 'gpt-x']);
        AiUsageLog::factory()->create(['user_id' => null, 'origin' => 'artisan:evaluation:run', 'input_tokens' => 9000, 'output_tokens' => 900, 'estimated_cost_usd' => 0.5]);

        return [$ana, $bruno];
    }

    public function test_consumption_pages_are_admin_only(): void
    {
        $patient = User::factory()->create();

        $this->get('/admin/analytics/consumo-ia')->assertRedirect('/login');
        $this->actingAs($patient)->get('/admin/analytics/consumo-ia')->assertForbidden();
        $this->actingAs($patient)->get("/admin/analytics/consumo-ia/usuarios/{$patient->id}")->assertForbidden();
    }

    public function test_service_separates_providers_and_averages_only_attributed_usage(): void
    {
        [$ana, $bruno] = $this->seedUsage();
        $service = app(AiConsumptionService::class);
        [$from, $to] = AiConsumptionService::period('30d');

        $providers = collect($service->byProvider($from, $to))->keyBy('provider');
        $this->assertSame(['anthropic', 'openai'], $providers->keys()->sort()->values()->all());
        $this->assertSame([3, 1, 2], [$providers['anthropic']['totals']['calls'], $providers['anthropic']['users'], $providers['openai']['totals']['calls']]);
        $this->assertSame([40, 1, 1], [$providers['openai']['totals']['reasoning_tokens'], $providers['openai']['totals']['unpriced'], $providers['openai']['totals']['failed']]);

        $averages = $service->averages($from, $to);
        $this->assertSame(2, $averages['users']);
        $this->assertEqualsWithDelta(0.005, $averages['cost_avg'], 0.000001);
        $this->assertEqualsWithDelta(0.005, $averages['cost_median'], 0.000001);
        $this->assertEqualsWithDelta(0.01, $averages['cost_max'], 0.000001);
        $this->assertSame(['calls' => 1, 'cost' => 0.5], ['calls' => $averages['unattributed']['calls'], 'cost' => $averages['unattributed']['cost']]);

        $users = $service->byUser($from, $to);
        $this->assertSame([$ana->id, $bruno->id], $users->getCollection()->pluck('user_id')->all());
        $this->assertSame([2, 7200, 5000, 7200 + 400], [$users[0]->calls, $users[0]->input_tokens, $users[0]->cache_read_tokens, $users[0]->total_tokens]);

        $this->assertSame(1, $service->byUser($from, $to, 'openai')->total());
    }

    public function test_admin_sees_usage_per_user_and_each_call_with_the_raw_api_usage(): void
    {
        [$ana] = $this->seedUsage();
        $admin = User::factory()->admin()->create();

        Livewire::actingAs($admin)->test(Index::class)
            ->assertSee(['Anthropic (Claude)', 'OpenAI', 'Ana Consumo', 'Bruno Consumo'])
            ->set('provider', 'openai')
            ->assertSee('Bruno Consumo')
            ->assertDontSee('Ana Consumo');

        $this->actingAs($admin)->get("/admin/analytics/consumo-ia/usuarios/{$ana->id}")
            ->assertOk()
            ->assertSee(['Consumo de IA · Ana Consumo', 'livewire:consulta.perguntar', '&quot;service_tier&quot;: &quot;standard&quot;'], false);
    }
}
