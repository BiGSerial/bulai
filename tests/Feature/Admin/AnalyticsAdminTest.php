<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\Analytics\Dashboard;
use App\Livewire\Admin\Analytics\Questions\Show;
use App\Models\AiUsageLog;
use App\Models\Consultation\InteractionConsultation;
use App\Models\Consultation\MedicationQuestion;
use App\Models\Consultation\MedicineLeafletSummary;
use App\Models\User;
use App\Services\Analytics\AnalyticsService;
use Database\Factories\Consultation\InteractionConsultationFactory;
use Database\Factories\Consultation\InteractionPairResultFactory;
use Database\Factories\Consultation\MedicationQuestionFactory;
use Database\Factories\Consultation\MedicineLeafletSummaryFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\TestCase;

final class AnalyticsAdminTest extends TestCase
{
    use RefreshDatabase;

    private function seedActivity(): User
    {
        $patient = User::factory()->create(['email' => 'paciente-secreto@example.com', 'name' => 'Paciente Secreto']);
        $consultation = InteractionConsultationFactory::new()->create(['user_id' => $patient->id, 'duration_ms' => 4000]);
        InteractionPairResultFactory::new()->create(['consultation_id' => $consultation->id, 'position' => 0]);
        InteractionPairResultFactory::new()->notFound()->create(['consultation_id' => $consultation->id, 'position' => 1]);
        InteractionConsultationFactory::new()->failed()->create(['user_id' => $patient->id, 'duration_ms' => 1000]);
        MedicationQuestionFactory::new()->create(['user_id' => $patient->id, 'duration_ms' => 800]);
        MedicationQuestionFactory::new()->create(['user_id' => $patient->id, 'status' => 'no_evidence', 'answer' => null, 'evidences' => [], 'generation' => [], 'treatment_context' => ['consented' => false], 'duration_ms' => 200]);
        MedicineLeafletSummaryFactory::new()->create(['views' => 5]);
        AiUsageLog::factory()->create(['input_tokens' => 1000, 'output_tokens' => 100, 'estimated_cost_usd' => 0.0030, 'latency_ms' => 1500]);
        AiUsageLog::factory()->failed('AI_RATE_LIMITED')->create(['latency_ms' => 50]);

        return $patient;
    }

    public function test_analytics_is_admin_only(): void
    {
        $this->get('/admin/analytics')->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get('/admin/analytics')->assertForbidden();
        $this->actingAs(User::factory()->create())->get('/admin/analytics/consultas')->assertForbidden();
        $this->actingAs(User::factory()->admin()->create())->get('/admin/analytics')->assertOk()->assertSee('Analytics · BULAI', false);
    }

    public function test_service_aggregates_grounding_rate_verdicts_durations_and_ai_usage(): void
    {
        $this->seedActivity();
        $overview = app(AnalyticsService::class)->overview();

        $this->assertSame(2, $overview['consultations']['total']);
        $this->assertSame(['COMPLETED' => 1, 'FAILED' => 1], $overview['consultations']['by_status']);
        $this->assertSame(2, $overview['consultations']['pairs']);
        $this->assertSame(1, $overview['consultations']['distinct_users']);
        $this->assertSame(['avg' => 2500.0, 'p50' => 2500.0, 'p95' => 3850.0], $overview['consultations']['duration']);
        $this->assertSame(['lexical' => 2], $overview['consultations']['by_retrieval_mode']);

        $this->assertSame(['INTERACTION_FOUND' => 1, 'NOT_FOUND_IN_SOURCES' => 1], $overview['pairs']['by_verdict']);
        $this->assertSame(['major' => 1], $overview['pairs']['by_severity']);
        $this->assertSame(['generated' => 1], $overview['pairs']['by_generation_status']);
        $this->assertSame(['not_grounded_in_cited_evidence' => 1], $overview['pairs']['rejection_reasons']);
        $this->assertSame(['accepted' => 2, 'rejected' => 1, 'rate' => 0.6667], $overview['pairs']['grounding']);
        $this->assertSame(['lookup_ms' => 4.0, 'retrieval_ms' => 10.0, 'generation_ms' => 2200.0], $overview['pairs']['timings']);
        $this->assertSame(1, $overview['pairs']['with_explanation']);
        $this->assertSame(['lexical' => 1], $overview['pairs']['evidence_methods']);

        $this->assertSame(2, $overview['questions']['total']);
        $this->assertSame(['answered' => 1, 'no_evidence' => 1], $overview['questions']['by_status']);
        $this->assertSame(1, $overview['questions']['with_treatment_context']);
        $this->assertSame(1, $overview['questions']['with_treatment_interaction']);
        $this->assertSame(1.0, $overview['questions']['grounding']['rate']);

        $this->assertSame(1, $overview['leaflets']['total']);
        $this->assertSame(5, $overview['leaflets']['views']);
        $this->assertSame(4, $overview['leaflets']['cache_hits']);

        $this->assertSame(2, $overview['ai']['calls']);
        $this->assertSame(1, $overview['ai']['failed']);
        $this->assertSame(1000, $overview['ai']['input_tokens']);
        $this->assertEqualsWithDelta(0.003, $overview['ai']['estimated_cost_usd'], 0.000001);
        $this->assertSame(['AI_RATE_LIMITED' => 1], $overview['ai']['failures_by_error']);
        $this->assertSame(2, $overview['ai']['daily'][0]['calls']);
    }

    public function test_dashboard_shows_kpis_and_filters_by_period(): void
    {
        $this->seedActivity();
        InteractionConsultationFactory::new()->create(['created_at' => now()->subDays(60), 'updated_at' => now()->subDays(60)]);

        Livewire::actingAs(User::factory()->admin()->create())->test(Dashboard::class)
            ->assertSee('Consultas de interação')->assertSee('66,7%')->assertSee('Interação documentada')->assertSee('AI_RATE_LIMITED')->assertSee('claude-sonnet-5-5')
            ->set('period', 0)->assertSet('period', 0)
            ->set('period', 999)->assertSet('period', 30);

        $this->assertSame(3, app(AnalyticsService::class)->overview()['consultations']['total']);
        $this->assertSame(2, app(AnalyticsService::class)->overview(now()->subDays(30))['consultations']['total']);
    }

    public function test_timeline_fills_days_without_activity_with_zero(): void
    {
        InteractionConsultationFactory::new()->create(['created_at' => now()->subDays(2)]);
        InteractionConsultationFactory::new()->create(['created_at' => now()->subDays(2)]);
        MedicationQuestionFactory::new()->create(['created_at' => now()]);

        $timeline = app(AnalyticsService::class)->timeline(now()->subDays(3));

        $this->assertCount(4, $timeline['days']);
        $this->assertSame([0, 2, 0, 0], $timeline['consultations']);
        $this->assertSame([0, 0, 0, 1], $timeline['questions']);
        $this->assertSame([0, 0, 0, 0], $timeline['leaflets']);
        $this->assertCount(90, app(AnalyticsService::class)->timeline()['days']);
    }

    public function test_dashboard_renders_charts_with_accessible_data_and_aligned_table_columns(): void
    {
        $this->seedActivity();

        Livewire::actingAs(User::factory()->admin()->create())->test(Dashboard::class)
            ->assertSeeHtml('x-data="bulaiChart(')
            ->assertSeeHtml('aria-label="Atividade diária"')
            ->assertSeeHtml('aria-label="Vereditos"')
            ->assertSeeHtml('[&amp;_td:nth-child(2)]:text-right')
            ->assertDontSeeHtml('[&_td:last-child]:text-right');
    }

    public function test_consultation_detail_shows_full_evidence_but_never_the_users_identity(): void
    {
        $patient = $this->seedActivity();
        $admin = User::factory()->admin()->create();
        $consultation = $patient->fresh()->load('patientProfile') !== null ? InteractionConsultation::query()->where('status', 'COMPLETED')->firstOrFail() : null;

        $this->actingAs($admin)->get('/admin/analytics/consultas')->assertOk()->assertSee('MEDICAMENTO A')->assertSee('#'.$patient->id)
            ->assertDontSee('paciente-secreto@example.com')->assertDontSee('Paciente Secreto');

        $this->actingAs($admin)->get(route('admin.analytics.consultations.show', $consultation))->assertOk()
            ->assertSee('O uso concomitante com beta aumenta o risco de sangramento.')->assertSee('INTERAÇÕES MEDICAMENTOSAS')->assertSee('Sustenta a(s) afirmação(ões) 1')
            ->assertSee('not_grounded_in_cited_evidence: 1')->assertSee('Gravidade: Maior')->assertSee('NOT_FOUND_IN_SOURCES')
            ->assertDontSee('paciente-secreto@example.com')->assertDontSee('Paciente Secreto');
    }

    public function test_question_and_leaflet_details_show_context_evidence_and_metrics(): void
    {
        $this->seedActivity();
        $admin = User::factory()->admin()->create();
        $question = MedicationQuestion::query()->where('status', 'answered')->firstOrFail();
        $summary = MedicineLeafletSummary::query()->firstOrFail();

        $this->actingAs($admin)->get('/admin/analytics/perguntas')->assertOk()->assertSee('OMEPRAZOL')->assertSee('em uso');
        $this->actingAs($admin)->get(route('admin.analytics.questions.show', $question))->assertOk()
            ->assertSee('Posso tomar em jejum?')->assertSee('CLOPIDOGREL: Maior')->assertSee('O comprimido deve ser tomado pela manhã em jejum.')->assertSee('claude-sonnet-5-5');

        $this->actingAs($admin)->get('/admin/analytics/bulas')->assertOk()->assertSee('Gerada');
        $this->actingAs($admin)->get(route('admin.analytics.leaflets.show', $summary))->assertOk()
            ->assertSee('Para que serve')->assertSee('É indicado para azia e úlcera.')->assertSee('Indicado para azia e úlcera.');
    }

    public function test_export_command_writes_json_and_csv_with_anonymised_rows(): void
    {
        $this->seedActivity();
        $directory = storage_path('framework/testing/analytics-'.uniqid());
        File::ensureDirectoryExists($directory);

        try {
            $this->artisan('analytics:export', ['--out' => "{$directory}/export.json"])->assertSuccessful();
            $json = json_decode(File::get("{$directory}/export.json"), true);
            $this->assertSame(2, $json['overview']['consultations']['total']);
            $this->assertCount(2, $json['pairs']);
            $this->assertCount(2, $json['questions']);
            $this->assertSame('INTERACTION_FOUND', $json['pairs'][0]['verdict']);
            $this->assertStringNotContainsString('paciente-secreto', File::get("{$directory}/export.json"));

            $this->artisan('analytics:export', ['--format' => 'csv', '--out' => "{$directory}/export.csv"])->assertSuccessful();
            $lines = array_values(array_filter(explode("\n", File::get("{$directory}/export.csv"))));
            $this->assertCount(5, $lines);
            $this->assertStringStartsWith('kind,uuid,created_at,status', $lines[0]);
            $this->assertStringStartsWith('pair,', $lines[1]);
            $this->assertStringStartsWith('question,', $lines[3]);

            $this->artisan('analytics:export', ['--format' => 'xml'])->assertFailed();
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_analytics_screens_share_the_tab_navigation_and_detail_tabs_fall_back_safely(): void
    {
        $this->seedActivity();
        $admin = User::factory()->admin()->create();
        $question = MedicationQuestion::query()->where('status', 'answered')->firstOrFail();

        $this->actingAs($admin)->get('/admin/analytics')->assertOk()->assertSee('Qualidade das respostas')->assertSeeInOrder(['Visão geral', 'Consultas', 'Perguntas', 'Bulas humanizadas']);
        Livewire::actingAs($admin)->test(Show::class, ['question' => $question])
            ->assertSet('tab', 'contexto')->set('tab', 'evidencias')->assertSet('tab', 'evidencias')->set('tab', 'x')->assertSet('tab', 'contexto');
    }
}
