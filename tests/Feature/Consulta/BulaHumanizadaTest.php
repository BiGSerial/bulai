<?php

namespace Tests\Feature\Consulta;

use App\Data\Ai\TextGenerationRequest;
use App\Data\Ai\TextGenerationResponse;
use App\Data\Ai\TokenUsage;
use App\Livewire\Consulta\Bula;
use App\Livewire\Consulta\Bulas;
use App\Livewire\Consulta\Pesquisa;
use App\Models\Consultation\MedicineLeafletSummary;
use App\Models\Knowledge\ActiveIngredient;
use App\Models\Knowledge\AnvisaMedicine;
use App\Models\Knowledge\DrugInteraction;
use App\Models\Knowledge\KnowledgeDocument;
use App\Models\Knowledge\KnowledgeSource;
use App\Models\PatientAllergy;
use App\Models\PatientMedication;
use App\Models\PatientProfile;
use App\Models\User;
use App\Services\Ai\AiProviderException;
use App\Services\Ai\AiProviderManager;
use App\Services\Ai\TextGenerationProvider;
use App\Services\Leaflet\LeafletSummaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

final class BulaHumanizadaTest extends TestCase
{
    use RefreshDatabase;

    public ?string $scripted = null;

    public bool $providerDown = false;

    public int $calls = 0;

    public ?string $lastPrompt = null;

    public ?string $lastSystem = null;

    protected function setUp(): void
    {
        parent::setUp();
        config(['bulai.generation.enabled' => true, 'ai.text.default' => 'scripted', 'bulai.retrieval.mode' => 'lexical']);
        $test = $this;
        $manager = new AiProviderManager;
        $manager->extend(AiProviderManager::TEXT, 'scripted', fn () => new class($test) implements TextGenerationProvider
        {
            public function __construct(private readonly BulaHumanizadaTest $test) {}

            public function generate(TextGenerationRequest $request): TextGenerationResponse
            {
                $this->test->calls++;
                $this->test->lastPrompt = $request->prompt;
                $this->test->lastSystem = $request->system;
                if ($this->test->providerDown) {
                    throw new AiProviderException(AiProviderException::UNAVAILABLE);
                }

                return new TextGenerationResponse((string) $this->test->scripted, 'scripted-model', new TokenUsage(300, 120));
            }
        });
        $this->app->instance(AiProviderManager::class, $manager);
    }

    private function medicine(string $name, string $ingredient, bool $identified = true): AnvisaMedicine
    {
        $medicine = AnvisaMedicine::factory()->create(['product_name' => $name, 'normalized_product_name' => strtolower($name), 'active_ingredient' => $ingredient, 'normalized_active_ingredient' => strtolower($ingredient)]);
        if ($identified) {
            $medicine->ingredients()->attach(ActiveIngredient::factory()->create(['canonical_name' => $ingredient, 'normalized_name' => strtolower($ingredient)])->id, ['component_text' => $ingredient, 'method' => 'alias', 'confidence' => 1]);
        }

        return $medicine;
    }

    /** @param array<string, string> $sections seção da bula => texto */
    private function leaflet(AnvisaMedicine $medicine, array $sections, string $type = 'bula_paciente', string $hash = 'hash-v1'): KnowledgeDocument
    {
        $source = KnowledgeSource::query()->firstOrCreate(['name' => 'ANVISA', 'organization' => null], ['type' => 'document', 'active' => true]);
        $document = KnowledgeDocument::query()->create(['source_id' => $source->id, 'external_key' => uniqid('d_'), 'title' => $medicine->product_name.' — Bula', 'document_type' => $type, 'language' => 'pt-BR', 'metadata' => [], 'content_status' => 'READY', 'file_status' => 'READY', 'embedding_status' => 'PENDING', 'content_hash' => $hash]);
        $index = 0;
        foreach ($sections as $section => $content) {
            $document->chunks()->create(['chunk_index' => $index, 'section' => $section, 'page_start' => $index + 1, 'page_end' => $index + 1, 'content' => $content, 'metadata' => []]);
            $index++;
        }
        DB::table('knowledge_document_ingredients')->insert(['document_id' => $document->id, 'ingredient_id' => $medicine->ingredients()->value('active_ingredients.id'), 'method' => 'registration', 'confidence' => 1, 'created_at' => now(), 'updated_at' => now()]);

        return $document;
    }

    private function omeprazol(): AnvisaMedicine
    {
        $medicine = $this->medicine('OMEPRAZOL', 'OMEPRAZOLSUB');
        $this->leaflet($medicine, [
            'INDICAÇÕES' => 'Este medicamento é indicado para o tratamento de úlcera gástrica e refluxo gastroesofágico.',
            'COMO DEVO USAR' => 'Tome um comprimido pela manhã em jejum com um copo de água.',
            'CONTRAINDICAÇÕES' => 'Não use se tiver alergia ao omeprazol ou a qualquer componente da fórmula.',
            'REAÇÕES ADVERSAS' => 'Reações comuns: dor de cabeça, diarreia e náusea.',
        ]);

        return $medicine;
    }

    /** @param array<string, list<array{text: string, evidence_ids: list<string>}>> $sections */
    private function script(array $sections): void
    {
        $this->scripted = json_encode(['sections' => $sections]);
    }

    public function test_the_summary_speaks_as_the_leaflet_and_refuses_claims_that_narrate_the_source(): void
    {
        $medicine = $this->omeprazol();
        $this->script([
            'para_que_serve' => [
                ['text' => 'Segundo a bula, é indicado para o tratamento de úlcera gástrica.', 'evidence_ids' => ['E1']],
                ['text' => 'A bula também indica o uso no refluxo gastroesofágico.', 'evidence_ids' => ['E1']],
            ],
            'nao_use_se' => [['text' => 'A bula contraindica o uso em quem tem alergia ao omeprazol.', 'evidence_ids' => ['E4']]],
        ]);

        $summary = app(LeafletSummaryService::class)->forMedicine($medicine);

        $this->assertSame(['É indicado para o tratamento de úlcera gástrica.'], array_column($summary->sections['para_que_serve']['claims'], 'text'));
        $this->assertSame([], $summary->sections['nao_use_se']['claims']);
        $this->assertSame(['narrates_source' => 2], $summary->generation['rejection_reasons']);
    }

    public function test_the_bula_simples_page_lists_the_ready_leaflets_and_marks_search_results_that_have_one(): void
    {
        $medicine = $this->omeprazol();
        $brand = AnvisaMedicine::factory()->create(['product_name' => 'OMEPRAZOL MARCA', 'normalized_product_name' => 'omeprazol marca', 'active_ingredient' => 'OMEPRAZOLSUB', 'normalized_active_ingredient' => 'omeprazolsub']);
        $brand->ingredients()->attach($medicine->ingredients()->value('active_ingredients.id'), ['component_text' => 'OMEPRAZOLSUB', 'method' => 'alias', 'confidence' => 1]);
        $without = $this->medicine('OMEGAVIT', 'OMEGASUB');
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('consulta.inicio'))->assertOk()->assertSee(route('consulta.bulas'))->assertSee('Bula simples');
        $this->actingAs($user)->get(route('consulta.bulas'))->assertOk()->assertSee('Bulas simples prontas')
            ->assertSee(route('consulta.bula', $medicine->id))->assertDontSee(route('consulta.bula', $brand->id))->assertDontSee('OMEGAVIT');

        Livewire::actingAs($user)->test(Bulas::class)->set('busca', 'ome')
            // A lista de prontas continua na tela (o filtro dela roda no aparelho) e o catálogo aparece embaixo.
            ->assertSee('Bulas simples prontas')->assertSee('No catálogo de remédios')
            ->assertSee(route('consulta.bula', $without->id))->assertSee('Ainda sem bula')
            ->assertSee(route('consulta.bula', $brand->id))->assertSee('Bula pronta');
    }

    public function test_the_admin_opens_a_generated_summary_named_by_its_ingredients(): void
    {
        $medicine = $this->omeprazol();
        $this->script(['para_que_serve' => [['text' => 'É indicado para o tratamento de úlcera gástrica.', 'evidence_ids' => ['E1']]]]);
        $summary = app(LeafletSummaryService::class)->forMedicine($medicine);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('admin.analytics.leaflets.index'))->assertOk()->assertSee('OMEPRAZOLSUB')->assertDontSee('Princípios ativos '.$summary->ingredient_key);
        $this->actingAs($admin)->get(route('admin.analytics.leaflets.show', $summary))->assertOk()->assertSee('OMEPRAZOLSUB')->assertSee('É indicado para o tratamento de úlcera gástrica.');
        $this->actingAs($admin)->get(route('admin.analytics.leaflets.show', $summary).'?aba=evidencias')->assertOk()->assertSee('Sustenta a(s) afirmação(ões) Para que serve 1.');
    }

    public function test_returns_null_without_identified_ingredient_or_without_a_registered_leaflet(): void
    {
        $service = app(LeafletSummaryService::class);
        $this->assertNull($service->forMedicine($this->medicine('XPTO', 'XPTOSUB', false)));
        $this->assertNull($service->forMedicine($this->medicine('SEMBULA', 'SEMBULASUB')));
        $this->assertSame(0, $this->calls);
        $this->assertSame(0, MedicineLeafletSummary::count());
    }

    public function test_generates_a_validated_summary_from_leaflet_excerpts_persists_it_and_reuses_the_cache(): void
    {
        $medicine = $this->omeprazol();
        $this->script([
            'para_que_serve' => [['text' => 'A bula diz que é indicado para o tratamento de úlcera gástrica e refluxo gastroesofágico.', 'evidence_ids' => ['E1']]],
            'como_usar' => [['text' => 'Tome um comprimido pela manhã em jejum com um copo de água.', 'evidence_ids' => ['E2']]],
            'beneficios' => [['text' => 'Ajuda no tratamento de úlcera gástrica e refluxo gastroesofágico.', 'evidence_ids' => ['E1']]],
            'riscos' => [['text' => 'Informação não encontrada na bula.', 'evidence_ids' => []]],
            'efeitos_colaterais' => [['text' => 'Reações comuns: dor de cabeça, diarreia e náusea.', 'evidence_ids' => ['E3']]],
            'nao_use_se' => [['text' => 'Não é indicado para quem tem alergia ao omeprazol ou a qualquer componente da fórmula.', 'evidence_ids' => ['E4']]],
            'interacoes' => [],
            'procure_ajuda' => [],
        ]);

        $summary = app(LeafletSummaryService::class)->forMedicine($medicine);

        $this->assertNotNull($summary);
        $this->assertSame(MedicineLeafletSummary::STATUS_PARTIAL, $summary->status);
        $this->assertSame(1, $this->calls);
        $this->assertStringContainsString('<evidencia id="E1"', $this->lastPrompt);
        $this->assertStringContainsString('nunca substitui médico ou farmacêutico', $this->lastSystem);
        $this->assertSame('Para que serve', $summary->sections['para_que_serve']['label']);
        $this->assertSame('É indicado para o tratamento de úlcera gástrica e refluxo gastroesofágico.', $summary->sections['para_que_serve']['claims'][0]['text'], 'a bula humanizada fala do remédio, sem "A bula diz que"');
        $this->assertStringContainsString('Nunca narre a fonte', $this->lastSystem);
        $this->assertSame([], $summary->sections['riscos']['claims'], 'Afirmação sem evidência é rejeitada');
        $this->assertSame(5, $summary->generation['claims_accepted']);
        $this->assertSame(['no_evidence_reference' => 1], $summary->generation['rejection_reasons']);
        $this->assertSame('scripted-model', $summary->generation['model']);
        $this->assertSame('humanized-leaflet-v2', $summary->prompt_version);
        $this->assertSame('bula_paciente', $summary->pipeline['document_type']);
        $this->assertContains('para_que_serve#0', collect($summary->evidences)->firstWhere('section', 'INDICAÇÕES')['supports_claims']);
        $this->assertSame('lexical', $summary->retrieval['mode']);
        $this->assertArrayHasKey('como_usar', $summary->retrieval['sections']);

        $again = app(LeafletSummaryService::class)->forMedicine($medicine);
        $this->assertSame($summary->id, $again->id);
        $this->assertSame(1, $this->calls, 'Cache: o provedor não é chamado de novo');
        $this->assertSame(1, $again->fresh()->views);

        $equivalent = $this->medicine('OMEPRAZOL GENÉRICO', 'OMEPRAZOLSUB', false);
        $equivalent->ingredients()->attach($medicine->ingredients()->value('active_ingredients.id'), ['component_text' => 'OMEPRAZOLSUB', 'method' => 'alias', 'confidence' => 1]);
        $this->assertSame($summary->id, app(LeafletSummaryService::class)->forMedicine($equivalent)->id, 'Produtos equivalentes compartilham o resumo');

        KnowledgeDocument::query()->update(['content_hash' => 'hash-v2']);
        app(LeafletSummaryService::class)->forMedicine($medicine);
        $this->assertSame(2, $this->calls, 'Bula reprocessada invalida o cache');
        $this->assertSame(2, MedicineLeafletSummary::count());
    }

    public function test_invalid_outputs_are_discarded_and_provider_failures_are_recorded_as_unavailable(): void
    {
        $medicine = $this->omeprazol();
        $service = app(LeafletSummaryService::class);

        $this->scripted = 'isto não é json';
        $summary = $service->forMedicine($medicine);
        $this->assertSame(MedicineLeafletSummary::STATUS_INVALID, $summary->status);
        $this->assertTrue($summary->generation['malformed']);
        $summary->delete();

        $this->script(['para_que_serve' => [['text' => 'Suspenda o uso e troque por outro remédio para úlcera gástrica.', 'evidence_ids' => ['E1']]]]);
        $summary = $service->forMedicine($medicine);
        $this->assertSame(MedicineLeafletSummary::STATUS_INVALID, $summary->status);
        $this->assertSame(['forbidden_recommendation' => 1], $summary->generation['rejection_reasons']);
        $this->assertSame([], collect($summary->sections)->pluck('claims')->flatten(1)->all(), 'Nenhuma afirmação é exibida');
        $this->assertSame([], collect($summary->evidences)->pluck('supports_claims')->flatten()->all(), 'Evidências ficam só para auditoria');
        $summary->delete();

        $this->providerDown = true;
        $summary = $service->forMedicine($medicine);
        $this->assertSame(MedicineLeafletSummary::STATUS_UNAVAILABLE, $summary->status);
        $this->assertSame(AiProviderException::UNAVAILABLE, $summary->generation['error_code']);

        $this->providerDown = false;
        config(['bulai.generation.enabled' => false]);
        $summary->delete();
        $this->assertSame('disabled', $service->forMedicine($medicine)->generation['reason']);
    }

    public function test_page_shows_states_sections_sources_and_personal_context(): void
    {
        $user = User::factory()->create();
        PatientProfile::query()->create(['user_id' => $user->id, 'health_consent_at' => now()]);
        PatientAllergy::query()->create(['user_id' => $user->id, 'substance' => 'Omeprazolsub', 'normalized_substance' => 'omeprazolsub']);
        $medicine = $this->omeprazol();
        $other = $this->medicine('MAREVAN', 'WARFARINSUB');
        PatientMedication::query()->create(['user_id' => $user->id, 'medicine_id' => $other->id, 'dosage' => '5 mg']);
        DrugInteraction::factory()->create(['drug_a_ingredient_id' => $medicine->ingredients()->value('active_ingredients.id'), 'drug_b_ingredient_id' => $other->ingredients()->value('active_ingredients.id'), 'severity' => 'major']);
        $this->script([
            'para_que_serve' => [['text' => 'Indicado para o tratamento de úlcera gástrica e refluxo gastroesofágico.', 'evidence_ids' => ['E1']]],
            'nao_use_se' => [['text' => 'A bula contraindica o uso em quem tem alergia ao omeprazol ou a qualquer componente da fórmula.', 'evidence_ids' => ['E4']]],
        ]);

        $this->actingAs($user)->get(route('consulta.bula', $medicine))->assertOk()->assertSee('Preparando a bula humanizada')->assertSee('wire:init="gerar"', false);

        Livewire::actingAs($user)->test(Bula::class, ['medicine' => $medicine])
            ->assertSet('estado', Bula::LOADING)
            ->call('gerar')
            ->assertSet('estado', Bula::READY)
            ->assertSee('Para que serve')->assertSee('úlcera gástrica')->assertSee('Não use se')
            ->assertSee('OMEPRAZOL — Bula · INDICAÇÕES · p. 1')
            ->assertDontSee('Este medicamento é indicado para o tratamento', false)
            ->assertSee('não trouxe trechos suficientes para: Como usar')
            ->assertSee('Você registrou alergia a Omeprazolsub')
            ->assertSee('Com MAREVAN')->assertSee('Gravidade: Maior')
            ->assertSee(route('consulta.perguntar', ['medicamento' => $medicine->id]));
        $this->assertSame(1, $this->calls);

        $this->actingAs($user)->get(route('consulta.bula', $medicine))->assertOk()->assertSee('Para que serve')->assertDontSee('Preparando a bula humanizada');
        $this->assertSame(1, $this->calls, 'Cache hit renderiza sem gerar de novo');
        $this->assertSame(1, MedicineLeafletSummary::first()->views, 'Só o acesso com cache conta como visualização');

        Livewire::actingAs($user)->test(Bula::class, ['medicine' => $this->medicine('XPTO', 'XPTOSUB', false)])->assertSet('estado', Bula::NOT_IDENTIFIED)->assertSee('Não identificamos o princípio ativo');
        Livewire::actingAs($user)->test(Bula::class, ['medicine' => $this->medicine('SEMBULA', 'SEMBULASUB')])->assertSet('estado', Bula::NO_LEAFLET)->assertSee('Ainda não há bula');

        $this->providerDown = true;
        $falho = $this->medicine('FALHO', 'FALHOSUB');
        $this->leaflet($falho, ['INDICAÇÕES' => 'Indicado para dor.']);
        Livewire::actingAs($user)->test(Bula::class, ['medicine' => $falho])->call('gerar')->assertSet('estado', Bula::READY)->assertSee('Não foi possível montar a bula humanizada');

        $this->actingAs($user)->get('/bula/999999')->assertNotFound();
        auth()->logout();
        $this->get(route('consulta.bula', $medicine))->assertRedirectToRoute('login');
    }

    public function test_without_health_consent_the_personal_block_invites_to_the_profile_and_generation_is_rate_limited(): void
    {
        $user = User::factory()->create();
        $medicine = $this->omeprazol();
        $this->script(['para_que_serve' => [['text' => 'Indicado para o tratamento de úlcera gástrica.', 'evidence_ids' => ['E1']]]]);

        Livewire::actingAs($user)->test(Bula::class, ['medicine' => $medicine])->call('gerar')->assertSee('registre seu perfil de saúde')->assertDontSee('Você registrou alergia');

        config(['bulai.consultation.rate_limit_per_hour' => 1]);
        $outro = $this->medicine('OUTRO', 'OUTROSUB');
        $this->leaflet($outro, ['INDICAÇÕES' => 'Indicado para febre.']);
        Livewire::actingAs($user)->test(Bula::class, ['medicine' => $outro])->call('gerar')->assertSet('estado', Bula::RATE_LIMITED)->assertSee('limite de bulas');
        $this->assertSame(1, $this->calls);
    }

    public function test_search_results_and_selected_items_link_to_the_humanized_leaflet(): void
    {
        $medicine = $this->medicine('OMEPRAZOL', 'OMEPRAZOLSUB');

        Livewire::actingAs(User::factory()->create())->test(Pesquisa::class)
            ->set('busca', 'omepra')->assertSee(route('consulta.bula', $medicine->id))->assertSee('Ver bula humanizada de OMEPRAZOL')
            ->call('adicionar', $medicine->id)->assertSee(route('consulta.bula', $medicine->id));
    }
}
