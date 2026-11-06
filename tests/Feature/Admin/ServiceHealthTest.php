<?php

namespace Tests\Feature\Admin;

use App\Data\Health\ServiceHealth;
use App\Livewire\Admin\Knowledge\ServiceHealth as ServiceHealthComponent;
use App\Models\User;
use App\Services\Health\ServiceHealthChecker;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

final class ServiceHealthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('ai.classifier.default', 'llamafile');
        config()->set('ai.local_ai.url', 'http://local-ai:8080');
        config()->set('document_ocr.enabled', true);
        config()->set('document_ocr.drivers.http.url', 'http://document-ocr:8000');
    }

    public function test_local_ai_reports_each_real_state(): void
    {
        foreach ([ServiceHealth::READY, ServiceHealth::MODEL_LOADING, ServiceHealth::MODEL_NOT_INSTALLED, ServiceHealth::ERROR, ServiceHealth::SERVICE_AVAILABLE] as $state) {
            $this->fakeHttp(['*' => Http::response(['status' => $state, 'model' => 'qwen'])]);

            $health = app(ServiceHealthChecker::class)->localAi();

            $this->assertSame($state, $health->state);
            $this->assertSame('qwen', $health->model);
        }
    }

    public function test_a_service_that_stops_answering_is_busy_first_and_unreachable_only_after_three_failures(): void
    {
        // Última leitura boa: ocupado. A primeira sondagem sem resposta não derruba o indicador.
        $this->fakeHttp(['*' => Http::response(['status' => 'READY', 'model' => 'qwen', 'busy' => true, 'active_requests' => 1, 'current_seconds' => 40])]);
        $this->assertTrue(app(ServiceHealthChecker::class)->refresh()['local_ai']->isWorking());

        Http::swap(new Factory);
        Http::fake(['*' => fn () => throw new ConnectionException('timed out')]);
        $first = app(ServiceHealthChecker::class)->refresh()['local_ai'];
        $this->assertSame([ServiceHealth::UNRESPONSIVE, 1, true, 'qwen', 'warning'], [$first->state, $first->failures, $first->busy, $first->model, $first->tone()]);
        $this->assertStringStartsWith('Ocupado, sem resposta há ', $first->label());
        $this->assertStringStartsWith('Última resposta há ', $first->hint());

        $second = app(ServiceHealthChecker::class)->refresh()['local_ai'];
        $this->assertSame([ServiceHealth::UNRESPONSIVE, 2], [$second->state, $second->failures]);

        $third = app(ServiceHealthChecker::class)->refresh()['local_ai'];
        $this->assertSame([ServiceHealth::UNREACHABLE, 3, 'Inacessível', 'danger'], [$third->state, $third->failures, $third->label(), $third->tone()]);

        // Sem leitura boa anterior o rótulo é só "Sem resposta"; voltar a responder zera as falhas.
        $this->fakeHttp(['*' => fn () => throw new ConnectionException('refused')]);
        $this->assertSame('Sem resposta', app(ServiceHealthChecker::class)->ocr()->label());
        Http::swap(new Factory);
        Http::fake(['*' => Http::response(['status' => 'ok', 'busy' => false])]);
        $this->assertSame([ServiceHealth::READY, 0], [app(ServiceHealthChecker::class)->refresh()['ocr']->state, app(ServiceHealthChecker::class)->ocr()->failures]);
    }

    public function test_unknown_and_disabled_services_never_throw(): void
    {
        $this->fakeHttp(['*' => Http::response(['status' => 'WHATEVER'])]);
        $this->assertSame('ERROR', app(ServiceHealthChecker::class)->localAi()->state);

        config()->set('ai.classifier.default', null);
        config()->set('document_ocr.enabled', false);
        $this->assertSame('DISABLED', app(ServiceHealthChecker::class)->localAi()->state);
        $this->assertSame('DISABLED', app(ServiceHealthChecker::class)->ocr()->state);
    }

    public function test_ocr_health_uses_the_health_endpoint(): void
    {
        $this->fakeHttp(['*' => Http::response(['status' => 'ok'])]);
        $this->assertTrue(app(ServiceHealthChecker::class)->ocr()->isReady());

        $this->fakeHttp(['*' => Http::response('', 500)]);
        $this->assertSame('ERROR', app(ServiceHealthChecker::class)->ocr()->state);
    }

    public function test_pages_read_the_cached_probe_and_only_probe_inline_when_the_reading_is_stale(): void
    {
        $this->fakeHttp(['*' => Http::response(['status' => 'ok', 'busy' => false])]);
        app(ServiceHealthChecker::class)->refresh();
        Http::swap(new Factory);
        Http::fake(['*' => Http::response('', 500)]);

        $health = app(ServiceHealthChecker::class)->ocr();
        $this->assertSame([ServiceHealth::READY, 0], [$health->state, $health->failures]);
        $this->assertStringStartsWith('Lido há ', $health->hint());
        Http::assertNothingSent();

        // Leitura mais velha que o limite (scheduler parado): sonda na hora.
        Cache::put('service-health:ocr', [...$health->toArray(), 'observed_at' => time() - ServiceHealthChecker::FRESH_SECONDS - 1]);
        $this->assertSame(ServiceHealth::ERROR, app(ServiceHealthChecker::class)->ocr()->state);
        Http::assertSentCount(1);
    }

    public function test_ocr_progress_is_read_from_the_status_endpoint_and_exposed_to_the_panel_and_the_json(): void
    {
        $this->fakeHttp([
            '*8000/status' => Http::response(['status' => 'ok', 'busy' => true, 'active' => 1, 'queued' => 0, 'current_seconds' => 65.2,
                'current' => ['correlation_id' => 'abc-123', 'page' => 7, 'total_pages' => 12, 'started_at' => '2026-11-06T10:00:00Z', 'elapsed_seconds' => 65.2]]),
            '*' => Http::response(['status' => 'READY', 'model' => 'qwen', 'busy' => true, 'current_seconds' => 12]),
        ]);
        $ocr = app(ServiceHealthChecker::class)->ocr();
        $this->assertSame([['page' => 7, 'total' => 12], 'abc-123', 65, 'Página 7 de 12 · 65 s'], [$ocr->progress, $ocr->correlationId, $ocr->currentSeconds, $ocr->hint()]);
        $this->assertSame('Processando há 12 s', app(ServiceHealthChecker::class)->localAi()->hint());

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get('/admin/knowledge/queue')->assertOk()->assertSee('Página 7 de 12 · 65 s')->assertSee('Processando há 12 s');
        $this->actingAs($admin)->get('/admin/knowledge')->assertOk()->assertSee('Página 7 de 12 · 65 s');
        $this->actingAs($admin)->getJson('/admin/knowledge/services/health')->assertOk()
            ->assertJsonPath('data.ocr.progress.page', 7)->assertJsonPath('data.ocr.hint', 'Página 7 de 12 · 65 s')->assertJsonPath('data.ocr.correlation_id', 'abc-123');
    }

    public function test_the_probe_command_is_scheduled_every_five_seconds_and_writes_the_cache(): void
    {
        $events = collect(app(Schedule::class)->events())->filter(fn ($event): bool => str_contains($event->command ?? '', 'services:probe-health'));
        $this->assertCount(1, $events);
        $this->assertSame('* * * * *', $events->first()->expression);
        $this->assertSame(5, $events->first()->repeatSeconds);

        $this->fakeHttp(['*' => Http::response(['status' => 'READY', 'model' => 'qwen'])]);
        $this->artisan('services:probe-health')->assertSuccessful()->expectsOutputToContain('local_ai: Pronta');
        $this->assertSame('READY', Cache::get('service-health:local_ai')['state']);
    }

    public function test_admin_pages_show_the_visible_health_indicator_and_json_endpoint_requires_admin(): void
    {
        $this->fakeHttp(['*/status' => Http::response(['status' => 'READY', 'model' => 'qwen']), '*/health' => Http::response(['status' => 'ok'])]);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/admin/knowledge')->assertOk()->assertSee('data-testid="service-health"', false)
            ->assertSee('IA local')->assertSee('Pronta')->assertSee('OCR');
        $this->actingAs($admin)->getJson('/admin/knowledge/services/health')->assertOk()
            ->assertJsonPath('data.local_ai.state', 'READY')->assertJsonPath('data.ocr.state', 'READY');

        auth()->logout();
        $this->actingAs(User::factory()->create())->getJson('/admin/knowledge/services/health')->assertForbidden();
    }

    public function test_component_shows_loading_and_missing_model_states(): void
    {
        $this->fakeHttp(['*/status' => Http::response(['status' => 'MODEL_NOT_INSTALLED']), '*/health' => Http::response([])]);

        Livewire::test(ServiceHealthComponent::class)->assertSee('Modelo não instalado')->assertSeeHtml('data-state="MODEL_NOT_INSTALLED"');
    }

    private function fakeHttp(array $stubs): void
    {
        Cache::flush();
        Http::swap(new Factory);
        Http::fake($stubs);
    }
}
