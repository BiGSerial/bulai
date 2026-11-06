<?php

namespace Tests\Feature\Consulta;

use App\Livewire\Consulta\Historico;
use App\Livewire\Consulta\Inicio;
use App\Livewire\Consulta\Pesquisa;
use App\Livewire\Consulta\Resultado;
use App\Models\Consultation\InteractionConsultation;
use App\Models\Knowledge\ActiveIngredient;
use App\Models\Knowledge\AnvisaMedicine;
use App\Models\PatientAllergy;
use App\Models\PatientMedication;
use App\Models\PatientProfile;
use App\Models\User;
use App\Models\UserAuditEvent;
use App\Services\Interactions\InteractionConsultationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\TestCase;

final class ConsultaPwaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['bulai.consultation.execution' => 'sync', 'bulai.generation.enabled' => false]);
    }

    private function medicine(string $name, string $ingredient = 'SUBSTANCIA X', bool $identified = true): AnvisaMedicine
    {
        $medicine = AnvisaMedicine::factory()->create([
            'product_name' => $name, 'normalized_product_name' => strtolower($name),
            'active_ingredient' => $ingredient, 'normalized_active_ingredient' => strtolower($ingredient),
        ]);
        if ($identified) {
            $medicine->ingredients()->attach(ActiveIngredient::factory()->create()->id, ['component_text' => $ingredient, 'method' => 'alias', 'confidence' => 1]);
        }

        return $medicine;
    }

    public function test_guests_see_the_landing_and_protected_pages_require_login(): void
    {
        $this->get('/')->assertOk()->assertSee('Limites desta ferramenta')->assertSee('não</strong> significa', false);
        foreach (['/inicio', '/consulta', '/historico'] as $url) {
            $this->get($url)->assertRedirectToRoute('login');
        }
    }

    public function test_home_is_a_senior_first_start_screen_with_three_destinations_and_four_big_actions(): void
    {
        $user = User::factory()->create(['name' => 'Maria Souza']);
        $this->actingAs($user)->get('/')->assertRedirectToRoute('consulta.inicio');

        $page = $this->actingAs($user)->get('/inicio')->assertOk()
            ->assertSee('Olá, Maria')->assertSee('Perguntar sobre um remédio')->assertSee('Fotografar minha receita')->assertSee('Meus remédios')->assertSee('Ver o que já perguntei')
            ->assertSee('O BULA-IA não substitui seu médico ou farmacêutico.')->assertSee(route('consulta.sobre'))
            ->assertDontSee('Recentes')->assertDontSee('remédios em uso');
        $barra = explode('aria-label="Principal (celular)"', $page->getContent())[1];
        $this->assertSame(3, preg_match_all('/<li[^>]*><a href="[^"]+" wire:navigate/', $barra));
        $this->assertStringNotContainsString('>Conta<', $barra);
        $this->assertStringNotContainsString('>Histórico<', $barra);
        $this->assertStringContainsString('text-lg leading-relaxed', $page->getContent());

        $this->actingAs($user)->get('/sobre')->assertOk()->assertSee('Como o aplicativo funciona')->assertSee(config('bulai.guidelines.disclaimer'));

        PatientProfile::query()->create(['user_id' => $user->id, 'health_consent_at' => now()]);
        PatientAllergy::query()->create(['user_id' => $user->id, 'substance' => 'Dipirona', 'normalized_substance' => 'dipirona']);
        foreach ([$this->medicine('ALFA'), $this->medicine('BETA')] as $medicine) {
            PatientMedication::query()->create(['user_id' => $user->id, 'medicine_id' => $medicine->id]);
        }
        app(InteractionConsultationService::class)->create($user, PatientMedication::query()->limit(2)->pluck('medicine_id')->all());

        Livewire::actingAs($user->fresh())->test(Inicio::class)->assertSee('Perguntar sobre um remédio')->assertDontSee('ALFA + BETA')->assertDontSee('Montar meu perfil');
        Livewire::actingAs($user->fresh())->test(Historico::class)->call('mostrar', 'consultas')->assertSee('ALFA + BETA');
    }

    public function test_common_user_searches_by_brand_and_ingredient_and_gets_feedback_for_unknown_medicines(): void
    {
        $this->medicine('MAREVAN', 'VARFARINA SÓDICA');
        $this->medicine('PROFEN', 'IBUPROFENO');

        Livewire::actingAs(User::factory()->create())->test(Pesquisa::class)
            ->set('busca', 'marev')->assertSee('MAREVAN')->assertDontSee('PROFEN')
            ->set('busca', 'ibupro')->assertSee('PROFEN')
            ->set('busca', 'xyzxyz')->assertSee('Nenhum medicamento encontrado')->assertDontSee('Você quis dizer')
            ->set('busca', 'ma')->assertDontSee('Nenhum medicamento encontrado')
            // Erro de digitação: a busca tolerante sugere o parecido e a tela pergunta "Você quis dizer…?".
            ->set('busca', 'marevam')->assertSee('Você quis dizer')->assertSee('MAREVAN')->assertDontSee('Nenhum medicamento encontrado')
            ->set('busca', 'marev')->assertDontSee('Você quis dizer');
    }

    public function test_selection_prevents_duplicates_enforces_limits_and_flags_unidentified_ingredients(): void
    {
        $a = $this->medicine('ALFA');
        $b = $this->medicine('BETA', identified: false);
        config(['bulai.consultation.max_medicines' => 2]);
        $c = $this->medicine('GAMA');

        Livewire::actingAs(User::factory()->create())->test(Pesquisa::class)
            ->call('adicionar', $a->id)->call('adicionar', $a->id)->assertSee('já está na lista')
            ->call('adicionar', $b->id)->call('adicionar', $c->id)->assertSee('Limite de 2')
            ->assertCount('selecionados', 2)
            ->call('remover', $a->id)->assertCount('selecionados', 1)
            ->call('verificar')->assertSee('pelo menos dois')
            ->call('adicionar', 999999)->assertSee('não encontrado');
    }

    public function test_verifying_creates_a_consultation_and_the_result_page_shows_inconclusive_states_without_calling_them_safe(): void
    {
        $user = User::factory()->create();
        $a = $this->medicine('ALFA');
        $b = $this->medicine('BETA');

        $component = Livewire::actingAs($user)->test(Pesquisa::class)->call('adicionar', $a->id)->call('adicionar', $b->id)->call('verificar');
        $consultation = InteractionConsultation::query()->sole();
        $component->assertRedirectToRoute('consulta.resultado', $consultation);

        $this->actingAs($user)->get(route('consulta.resultado', $consultation))->assertOk()
            ->assertSee('ALFA')->assertSee('BETA')->assertSee('Interação não identificada nas fontes consultadas')
            ->assertSee('Isto não significa que a combinação seja segura', false);
    }

    public function test_results_are_private_to_their_owner(): void
    {
        $consultation = app(InteractionConsultationService::class)->create(User::factory()->create(), [$this->medicine('ZZPRIVADO1')->id, $this->medicine('ZZPRIVADO2')->id]);

        $this->actingAs(User::factory()->create())->get(route('consulta.resultado', $consultation))->assertNotFound();
        Livewire::actingAs(User::factory()->create())->test(Historico::class)->call('mostrar', 'consultas')->assertDontSee('ZZPRIVADO1')->assertSee('ainda não fez nenhuma consulta');
    }

    public function test_pending_consultation_polls_and_a_stalled_one_is_failed_with_a_retry_option(): void
    {
        $user = User::factory()->create();
        $consultation = app(InteractionConsultationService::class)->create($user, [$this->medicine('A')->id, $this->medicine('B')->id]);

        $pending = Livewire::actingAs($user)->test(Resultado::class, ['consultation' => $consultation]);
        $pending->assertSee('Aguardando o início da análise')->assertSeeHtml('wire:poll.2s');

        InteractionConsultation::query()->whereKey($consultation->id)->update(['updated_at' => now()->subHour()]);
        Livewire::actingAs($user)->test(Resultado::class, ['consultation' => $consultation->fresh()])
            ->assertSee('demorou mais que o esperado')->assertSee('Tentar novamente')->assertDontSeeHtml('wire:poll.2s');
        $this->assertSame('FAILED', $consultation->fresh()->status);
    }

    public function test_user_can_delete_one_or_all_consultations_and_deletions_are_audited(): void
    {
        $user = User::factory()->create();
        $service = app(InteractionConsultationService::class);
        $ids = [$this->medicine('A')->id, $this->medicine('B')->id];
        $first = $service->create($user, $ids);
        $service->create($user, $ids);
        $other = $service->create(User::factory()->create(), $ids);

        $component = Livewire::actingAs($user)->test(Historico::class)->call('apagar', $first->uuid);
        $this->assertSame(1, InteractionConsultation::where('user_id', $user->id)->count());
        $component->call('apagar', $other->uuid);
        $this->assertTrue(InteractionConsultation::where('uuid', $other->uuid)->exists());

        $component->call('apagarTudo');
        $this->assertSame(0, InteractionConsultation::where('user_id', $user->id)->count());
        $this->assertSame(2, UserAuditEvent::where('event', 'consultations_deleted')->count());
        $this->assertTrue(InteractionConsultation::where('uuid', $other->uuid)->exists());
    }

    public function test_starting_consultations_is_rate_limited_per_user(): void
    {
        config(['bulai.consultation.rate_limit_per_hour' => 1]);
        $user = User::factory()->create();
        $a = $this->medicine('A');
        $b = $this->medicine('B');
        RateLimiter::clear('consulta:'.$user->id);

        $component = Livewire::actingAs($user)->test(Pesquisa::class)->call('adicionar', $a->id)->call('adicionar', $b->id)->call('verificar');
        $component->assertRedirect();
        Livewire::actingAs($user)->test(Pesquisa::class)->call('adicionar', $a->id)->call('adicionar', $b->id)->call('verificar')->assertSee('limite de consultas por hora');
        $this->assertSame(1, InteractionConsultation::count());
    }

    public function test_search_is_throttled_per_user_without_breaking_the_page(): void
    {
        config(['bulai.consultation.search_limit_per_minute' => 2]);
        $this->medicine('MAREVAN', 'VARFARINA');
        $user = User::factory()->create();
        RateLimiter::clear('busca:'.$user->id);

        Livewire::actingAs($user)->test(Pesquisa::class)
            ->set('busca', 'marev')->assertSee('MAREVAN')
            ->set('busca', 'maref')->set('busca', 'marev')
            ->assertSee('Muitas buscas em pouco tempo')->assertDontSee('MAREVAN');
    }

    public function test_pages_never_expose_provider_credentials_or_keys(): void
    {
        config(['ai.text.drivers.anthropic.api_key' => 'sk-ant-segredo-de-teste']);
        $user = User::factory()->create();
        $consultation = app(InteractionConsultationService::class)->create($user, [$this->medicine('A')->id, $this->medicine('B')->id]);

        foreach (['/', '/consulta', '/historico', route('consulta.resultado', $consultation, false)] as $url) {
            $this->actingAs($user)->get($url)->assertDontSee('sk-ant-segredo-de-teste')->assertDontSee('api.anthropic.com');
        }
    }
}
