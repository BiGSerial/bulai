<?php

namespace Tests\Feature\Admin;

use App\Data\Health\ServiceHealth;
use App\Livewire\Admin\Knowledge\ServiceHealth as ServiceHealthComponent;
use App\Models\User;
use App\Services\Health\ServiceHealthChecker;
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

    public function test_unreachable_unknown_and_disabled_services_never_throw(): void
    {
        $this->fakeHttp(['*' => fn () => throw new ConnectionException('refused')]);
        $this->assertSame('UNREACHABLE', app(ServiceHealthChecker::class)->localAi()->state);

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
