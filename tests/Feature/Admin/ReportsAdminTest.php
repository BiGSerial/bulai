<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\Reports\Index;
use App\Models\AdminReport;
use App\Models\AiUsageLog;
use App\Models\User;
use App\Models\UserAuditEvent;
use App\Services\Reports\ReportGenerator;
use Database\Factories\Consultation\InteractionConsultationFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

final class ReportsAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(ReportGenerator::DISK);
        config(['bulai.consultation.execution' => 'sync']);
    }

    public function test_reports_are_admin_only(): void
    {
        $this->get('/admin/relatorios')->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get('/admin/relatorios')->assertForbidden();
        $this->actingAs(User::factory()->admin()->create())->get('/admin/relatorios')->assertOk()->assertSee('Relatórios · BULAI', false)->assertSee('Analytics do período');
    }

    public function test_each_report_type_is_generated_stored_and_downloadable_only_by_admins(): void
    {
        $admin = User::factory()->admin()->create();
        InteractionConsultationFactory::new()->create();
        AiUsageLog::factory()->create(['provider' => 'anthropic', 'model' => 'claude-sonnet-5-5', 'capability' => 'text', 'input_tokens' => 100, 'output_tokens' => 20, 'estimated_cost_usd' => 0.001]);
        UserAuditEvent::query()->create(['user_id' => $admin->id, 'actor_id' => $admin->id, 'event' => 'created', 'details' => ['password' => 'segredo', 'role' => 'ADMIN'], 'ip' => '127.0.0.1']);

        $generator = app(ReportGenerator::class);
        $expected = ['analytics' => 'json', 'knowledge_inventory' => 'csv', 'ai_usage' => 'csv', 'user_audit' => 'csv', 'evaluation' => 'json'];
        foreach ($expected as $type => $format) {
            $report = $generator->start($type, $format, ['from' => now()->subDay()->toDateString(), 'to' => now()->toDateString()], $admin)->refresh();

            $this->assertSame(AdminReport::STATUS_COMPLETED, $report->status, $type.' '.$report->error_code);
            $this->assertTrue(Storage::disk(ReportGenerator::DISK)->exists((string) $report->path), $type);
            $this->assertGreaterThan(0, $report->size);
        }

        $audit = AdminReport::query()->where('type', 'user_audit')->firstOrFail();
        $content = Storage::disk(ReportGenerator::DISK)->get((string) $audit->path);
        $this->assertStringContainsString($admin->email, $content);
        $this->assertStringNotContainsString('segredo', $content);

        $this->actingAs(User::factory()->create())->get(route('admin.reports.download', $audit))->assertForbidden();
        $this->actingAs($admin)->get(route('admin.reports.download', $audit))->assertOk()->assertDownload($audit->fileName());

        $generator->delete($audit);
        $this->assertFalse(Storage::disk(ReportGenerator::DISK)->exists((string) $audit->path));
        $this->assertDatabaseMissing('admin_reports', ['id' => $audit->id]);
    }

    public function test_page_generates_lists_and_deletes_reports_and_rejects_an_unsupported_format(): void
    {
        $admin = User::factory()->admin()->create();

        $component = Livewire::actingAs($admin)->test(Index::class)
            ->set('tipo', 'knowledge_inventory')->assertSet('formato', 'csv')
            ->call('gerar')->assertHasNoErrors()->assertSee('Inventário da base de conhecimento');
        $this->assertSame(1, AdminReport::query()->where('status', AdminReport::STATUS_COMPLETED)->count());

        $component->set('tipo', 'analytics')->set('formato', 'csv')->set('de', '2026-11-05')->set('ate', '2026-11-01')->call('gerar')->assertHasErrors(['ate']);

        $uuid = AdminReport::query()->value('uuid');
        $component->call('apagar', $uuid)->assertSee('Relatório apagado');
        $this->assertSame(0, AdminReport::query()->count());

        $this->expectExceptionMessage(ReportGenerator::INVALID_FORMAT);
        app(ReportGenerator::class)->start('knowledge_inventory', 'json');
    }

    public function test_command_generates_a_report_and_rejects_unknown_types(): void
    {
        $this->artisan('reports:generate', ['type' => 'ai_usage'])->assertSuccessful()->expectsOutputToContain('Gerado');
        $this->assertSame(1, AdminReport::query()->where('type', 'ai_usage')->where('status', AdminReport::STATUS_COMPLETED)->count());

        $this->artisan('reports:generate', ['type' => 'inexistente'])->assertFailed();
    }
}
