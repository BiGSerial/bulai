<?php

namespace Tests\Feature\Consulta;

use App\Data\Ai\TextGenerationRequest;
use App\Data\Ai\TextGenerationResponse;
use App\Data\Ai\TokenUsage;
use App\Jobs\RegenerateLeafletSummary;
use App\Livewire\Admin\Analytics\Leaflets\Show;
use App\Models\Consultation\MedicineLeafletSummary;
use App\Models\Knowledge\ActiveIngredient;
use App\Models\Knowledge\AnvisaMedicine;
use App\Models\Knowledge\KnowledgeDocument;
use App\Models\Knowledge\KnowledgeSource;
use App\Models\User;
use App\Services\Ai\AiProviderManager;
use App\Services\Ai\TextGenerationProvider;
use App\Services\Leaflet\LeafletSummaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Linguagem acessível é o propósito da bula humanizada (auditoria de 16/11/2026: 9 de 10 bulas "difíceis" pelo índice de
 * Flesch). Abaixo do alvo, o modelo reescreve com orientação de simplificação; fica a melhor versão; o Admin mostra o índice
 * e o texto; o comando refaz as bulas de versão anterior do prompt.
 */
final class LegibilidadeBulaHumanizadaTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> respostas do modelo, na ordem das chamadas (a última se repete) */
    public array $responses = [];

    /** @var list<string> */
    public array $prompts = [];

    private const HARD = ['sections' => [
        'para_que_serve' => [['text' => 'Indicado para o tratamento de úlcera gástrica e refluxo gastroesofágico.', 'evidence_ids' => ['E1']]],
        'nao_use_se' => [['text' => 'Contraindicado em pacientes com hipersensibilidade conhecida aos componentes da formulação.', 'evidence_ids' => ['E2']]],
    ]];

    private const EASY = ['sections' => [
        'para_que_serve' => [['text' => 'É indicado para ferida no estômago e azia.', 'evidence_ids' => ['E1']]],
        'nao_use_se' => [['text' => 'Não é para quem tem alergia ao remédio.', 'evidence_ids' => ['E2']]],
    ]];

    protected function setUp(): void
    {
        parent::setUp();
        config(['bulai.generation.enabled' => true, 'ai.text.default' => 'scripted', 'bulai.retrieval.mode' => 'lexical', 'bulai.leaflet.readability.min_flesch' => 50, 'bulai.leaflet.readability.max_rewrites' => 2]);
        $test = $this;
        $manager = new AiProviderManager;
        $manager->extend(AiProviderManager::TEXT, 'scripted', fn () => new class($test) implements TextGenerationProvider
        {
            public function __construct(private readonly LegibilidadeBulaHumanizadaTest $test) {}

            public function generate(TextGenerationRequest $request): TextGenerationResponse
            {
                $this->test->prompts[] = $request->prompt;
                $response = count($this->test->responses) > 1 ? array_shift($this->test->responses) : $this->test->responses[0];

                return new TextGenerationResponse($response, 'scripted-model', new TokenUsage(300, 120));
            }
        });
        $this->app->instance(AiProviderManager::class, $manager);
    }

    private function omeprazol(): AnvisaMedicine
    {
        $medicine = AnvisaMedicine::factory()->create(['product_name' => 'OMEPRAZOL', 'normalized_product_name' => 'omeprazol', 'active_ingredient' => 'OMEPRAZOL', 'normalized_active_ingredient' => 'omeprazol']);
        $ingredient = ActiveIngredient::factory()->create(['canonical_name' => 'Omeprazole', 'normalized_name' => 'omeprazole']);
        $medicine->ingredients()->attach($ingredient->id, ['component_text' => 'OMEPRAZOL', 'method' => 'alias', 'confidence' => 1]);
        $source = KnowledgeSource::query()->create(['name' => 'ANVISA', 'type' => 'document', 'active' => true]);
        $document = KnowledgeDocument::query()->create(['source_id' => $source->id, 'external_key' => 'omeprazol', 'title' => 'Omeprazol — Bula', 'document_type' => 'bula_paciente', 'language' => 'pt-BR', 'metadata' => [], 'content_status' => 'READY', 'file_status' => 'READY', 'embedding_status' => 'PENDING', 'content_hash' => 'h1']);
        foreach ([
            'PARA QUE ESTE MEDICAMENTO É INDICADO?' => 'Este medicamento é indicado para o tratamento de úlcera gástrica (ferida no estômago) e refluxo gastroesofágico (azia).',
            'QUANDO NÃO DEVO USAR ESTE MEDICAMENTO?' => 'Contraindicado em pacientes com hipersensibilidade conhecida aos componentes da formulação. Não é para quem tem alergia ao remédio.',
        ] as $index => $content) {
            $document->chunks()->create(['chunk_index' => array_search($index, ['PARA QUE ESTE MEDICAMENTO É INDICADO?', 'QUANDO NÃO DEVO USAR ESTE MEDICAMENTO?'], true), 'section' => $index, 'page_start' => 1, 'page_end' => 1, 'content' => $content, 'metadata' => []]);
        }
        DB::table('knowledge_document_ingredients')->insert(['document_id' => $document->id, 'ingredient_id' => $ingredient->id, 'method' => 'registration', 'confidence' => 1, 'created_at' => now(), 'updated_at' => now()]);

        return $medicine;
    }

    public function test_a_summary_below_the_readability_target_is_rewritten_and_the_simpler_version_is_kept(): void
    {
        $this->responses = [(string) json_encode(self::HARD), (string) json_encode(self::EASY)];

        $summary = app(LeafletSummaryService::class)->forMedicine($this->omeprazol());

        $readability = $summary->generation['readability'];
        $this->assertCount(2, $this->prompts, 'uma reescrita');
        $this->assertStringContainsString('REESCREVA EM LINGUAGEM MAIS SIMPLES', $this->prompts[1]);
        $this->assertStringContainsString('Seções mais difíceis:', $this->prompts[1]);
        $this->assertLessThan(50, $readability['attempts'][0]['flesch']);
        $this->assertSame([2, true], [$readability['chosen_attempt'], $readability['meets_target']]);
        $this->assertSame(['É indicado para ferida no estômago e azia.'], $summary->claimTexts($summary->sections['para_que_serve']));
        $this->assertSame($readability['index'], $summary->readability()['summary']['index'], 'o índice gravado é o do texto gravado');
    }

    public function test_rewrites_stop_at_the_limit_and_a_summary_still_below_the_target_is_flagged_not_hidden(): void
    {
        $this->responses = [(string) json_encode(self::HARD)];

        $summary = app(LeafletSummaryService::class)->forMedicine($this->omeprazol());

        $this->assertCount(3, $this->prompts, '1 geração + 2 reescritas, sem laço infinito');
        $this->assertFalse($summary->generation['readability']['meets_target']);
        $this->assertCount(3, $summary->generation['readability']['attempts']);
        $this->assertSame(MedicineLeafletSummary::STATUS_PARTIAL, $summary->status, 'o conteúdo validado continua disponível');
        $this->assertFalse($summary->readability()['meets_target']);
    }

    public function test_the_admin_shows_the_index_the_text_and_the_rewrites(): void
    {
        $this->responses = [(string) json_encode(self::HARD), (string) json_encode(self::EASY)];
        $summary = app(LeafletSummaryService::class)->forMedicine($this->omeprazol());
        $admin = User::factory()->admin()->create();
        $index = number_format($summary->readability()['summary']['index'], 1, ',', '');

        $this->actingAs($admin)->get(route('admin.analytics.leaflets.index'))->assertOk()
            ->assertSee('Legibilidade (Flesch)')->assertSee($index)->assertSee('no alvo');
        $this->actingAs($admin)->get(route('admin.analytics.leaflets.show', $summary))->assertOk()
            ->assertSee('Texto e legibilidade')->assertSee('Legibilidade da bula humanizada')->assertSee($index)
            ->assertSee('É indicado para ferida no estômago e azia.')->assertSee('Reescritas para linguagem mais simples')->assertSee('(versão usada)', false);
    }

    public function test_the_command_redoes_summaries_from_an_older_prompt_and_only_lists_current_ones_below_target(): void
    {
        $medicine = $this->omeprazol();
        $key = MedicineLeafletSummary::keyFor($medicine->ingredients()->pluck('active_ingredients.id')->all());
        MedicineLeafletSummary::query()->create(['uuid' => 'aaaaaaaa-0000-4000-8000-000000000001', 'ingredient_key' => $key, 'prompt_version' => 'humanized-leaflet-v2', 'documents_fingerprint' => 'old', 'status' => 'generated',
            'sections' => ['nao_use_se' => ['label' => 'Não use se', 'claims' => [['text' => 'Contraindicado em pacientes com hipersensibilidade conhecida aos componentes da formulação.', 'evidence_chunk_ids' => []]]]],
            'evidences' => [], 'generation' => [], 'retrieval' => [], 'pipeline' => [], 'duration_ms' => 1, 'views' => 0]);
        $this->responses = [(string) json_encode(self::EASY)];

        $this->artisan('leaflets:refresh-readability', ['--dry-run' => true])->expectsOutputToContain('seria refeita (OMEPRAZOL)')->assertSuccessful();
        $this->assertSame([], $this->prompts, 'simulação não chama o modelo');

        $this->artisan('leaflets:refresh-readability')->expectsOutputToContain('refeita, no alvo')->assertSuccessful();
        $this->assertTrue(MedicineLeafletSummary::query()->where('ingredient_key', $key)->where('prompt_version', config('bulai.leaflet.prompt_version'))->exists());
        $this->assertSame(2, MedicineLeafletSummary::query()->where('ingredient_key', $key)->count(), 'a versão antiga fica no histórico');
    }

    /** O Admin refaz qualquer bula (inclusive da versão atual) em segundo plano e guarda o índice anterior. */
    public function test_the_admin_redoes_a_summary_in_the_background_and_keeps_the_previous_index(): void
    {
        $this->responses = [(string) json_encode(self::HARD)];
        config(['bulai.leaflet.readability.max_rewrites' => 0]);
        $summary = app(LeafletSummaryService::class)->forMedicine($this->omeprazol());
        $before = $summary->readability()['summary']['index'];
        $admin = User::factory()->admin()->create();

        Queue::fake();
        Livewire::actingAs($admin)->test(Show::class, ['summary' => $summary])->call('refazer')->assertSee('segundo plano');
        Queue::assertPushed(RegenerateLeafletSummary::class, fn (RegenerateLeafletSummary $job): bool => $job->summaryId === $summary->id);

        $this->responses = [(string) json_encode(self::EASY)];
        (new RegenerateLeafletSummary($summary->id))->handle(app(LeafletSummaryService::class));

        $redone = $summary->fresh();
        $this->assertSame(1, MedicineLeafletSummary::query()->count(), 'mesma identidade de cache: atualiza o registro');
        $this->assertTrue($redone->readability()['meets_target']);
        $this->assertSame($before, $redone->generation['readability']['previous'][0]['index']);
    }

    /** A lista mostra só a versão atual de cada remédio; o histórico aparece com o filtro e marcado como substituído. */
    public function test_the_list_shows_the_current_version_and_the_history_on_request(): void
    {
        $medicine = $this->omeprazol();
        $key = MedicineLeafletSummary::keyFor($medicine->ingredients()->pluck('active_ingredients.id')->all());
        $old = MedicineLeafletSummary::query()->create(['uuid' => 'bbbbbbbb-0000-4000-8000-000000000001', 'ingredient_key' => $key, 'prompt_version' => 'humanized-leaflet-v2', 'documents_fingerprint' => 'old', 'status' => 'generated',
            'sections' => ['nao_use_se' => ['label' => 'Não use se', 'claims' => [['text' => 'Contraindicado em pacientes com hipersensibilidade conhecida aos componentes da formulação.', 'evidence_chunk_ids' => []]]]],
            'evidences' => [], 'generation' => [], 'retrieval' => [], 'pipeline' => [], 'duration_ms' => 1, 'views' => 0]);
        $this->responses = [(string) json_encode(self::EASY)];
        $current = app(LeafletSummaryService::class)->forMedicine($medicine);
        $admin = User::factory()->admin()->create();
        $oldIndex = number_format($old->readability()['summary']['index'], 1, ',', '');

        $this->actingAs($admin)->get(route('admin.analytics.leaflets.index'))->assertOk()->assertDontSee($oldIndex)->assertDontSee('Substituída');
        $this->actingAs($admin)->get(route('admin.analytics.leaflets.index', ['versoes' => 'todas']))->assertOk()->assertSee($oldIndex)->assertSee('Substituída');
        $this->actingAs($admin)->get(route('admin.analytics.leaflets.show', $old))->assertOk()->assertSee('já substituída')->assertSee(route('admin.analytics.leaflets.show', $current), false);
    }
}
