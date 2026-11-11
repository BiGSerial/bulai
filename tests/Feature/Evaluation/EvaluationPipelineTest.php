<?php

namespace Tests\Feature\Evaluation;

use App\Models\Knowledge\ActiveIngredient;
use App\Models\Knowledge\AnvisaMedicine;
use App\Models\Knowledge\DrugInteraction;
use App\Models\Knowledge\KnowledgeDocument;
use App\Models\Knowledge\KnowledgeSource;
use App\Services\Ai\AiProviderManager;
use App\Services\Ai\Fake\FakeTextGenerationProvider;
use App\Services\Evaluation\BaselineEvaluator;
use App\Services\Evaluation\DatasetBuilder;
use App\Services\Evaluation\EvaluationRunRecorder;
use App\Services\Evaluation\GenerationEvaluator;
use App\Services\Evaluation\IdentificationEvaluator;
use App\Services\Evaluation\InteractionEvaluator;
use App\Services\Evaluation\RetrievalEvaluator;
use App\Services\Interactions\PairAnalyzer;
use App\Services\Interactions\PairVerdict;
use App\Services\Knowledge\ActiveIngredientCatalogBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class EvaluationPipelineTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/'.uniqid('eval_');
        File::ensureDirectoryExists($this->directory);
        config(['bulai.generation.enabled' => false, 'ai.embedding.default' => null]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    private function seedWorld(): string
    {
        foreach ([['Warfarin', 'Fluconazole', 'major'], ['Warfarin', 'Simvastatin', 'minor']] as $n => [$a, $b, $severity]) {
            DrugInteraction::factory()->create(['pair_key' => "e:$n", 'drug_a_name' => $a, 'normalized_drug_a_name' => strtolower($a), 'drug_b_name' => $b, 'normalized_drug_b_name' => strtolower($b), 'severity' => $severity]);
        }
        DrugInteraction::factory()->create(['pair_key' => 'e:9', 'drug_a_name' => 'Digoxin', 'normalized_drug_a_name' => 'digoxin', 'drug_b_name' => 'Aspirin', 'normalized_drug_b_name' => 'aspirin', 'severity' => 'moderate']);
        foreach ([['MAREVAN', 'VARFARINA SÓDICA', '100000001'], ['FLUCONAZOL', 'FLUCONAZOL', '100000002'], ['SINVASTATINA', 'SINVASTATINA', '100000003'], ['LANOXIN', '', '100000004']] as [$name, $ingredient, $registration]) {
            AnvisaMedicine::factory()->create(['product_name' => $name, 'normalized_product_name' => strtolower($name), 'active_ingredient' => $ingredient, 'registration_number' => $registration]);
        }
        File::put($this->directory.'/aliases.csv', "pt_name,en_name\nvarfarina,warfarin\n");
        app(ActiveIngredientCatalogBuilder::class)->build($this->directory.'/aliases.csv');

        $seed = $this->directory.'/seed.json';
        File::put($seed, json_encode(['description' => 'teste', 'items' => [
            ['query' => 'Marevan', 'expected' => ['warfarin']], ['query' => 'Fluconazol', 'expected' => ['fluconazole']],
            ['query' => 'Sinvastatina', 'expected' => ['simvastatin']], ['query' => 'Lanoxin', 'expected' => ['digoxin']],
            ['query' => 'Inexistente', 'expected' => ['x']],
        ]]));

        return $seed;
    }

    public function test_builder_labels_pairs_from_expected_names_and_reports_what_it_dropped(): void
    {
        $summary = app(DatasetBuilder::class)->build($this->seedWorld(), $this->directory);

        $this->assertSame(4, $summary['identification']);
        $this->assertSame(['Inexistente'], $summary['dropped']);
        $this->assertSame(6, $summary['interactions']);
        $this->assertSame(2, $summary['positives']);
        $dataset = json_decode(File::get($this->directory.'/interactions.v1.json'), true);
        $byNames = collect($dataset['items'])->mapWithKeys(fn ($i) => [$i['a_name'].'|'.$i['b_name'] => $i['gold_severity']]);
        $this->assertSame('major', $byNames['MAREVAN|FLUCONAZOL']);
        $this->assertSame('minor', $byNames['MAREVAN|SINVASTATINA']);
        $this->assertNotEmpty($dataset['seed_sha256']);
    }

    public function test_evaluators_report_confusion_matrix_abstentions_and_missed_major_interactions(): void
    {
        app(DatasetBuilder::class)->build($this->seedWorld(), $this->directory);

        $identification = app(IdentificationEvaluator::class)->run($this->directory.'/identification.v1.json');
        $this->assertSame(3, $identification['outcomes']['exact']);
        $this->assertSame(1, $identification['outcomes']['unresolved']);
        $this->assertSame('LANOXIN', $identification['errors'][0]['product']);

        $interactions = app(InteractionEvaluator::class)->run($this->directory.'/interactions.v1.json', 'lexical');
        $this->assertSame(['tp' => 2, 'fp' => 0, 'tn' => 1, 'fn' => 0], array_intersect_key($interactions['binary'], array_flip(['tp', 'fp', 'tn', 'fn'])));
        $this->assertSame(3, $interactions['abstention']['by_verdict']['MEDICATION_NOT_IDENTIFIED']);
        $this->assertSame(['major' => 1, 'moderate' => 0, 'minor' => 0, 'unknown' => 0], $interactions['severity_confusion_matrix']['major']);
        $this->assertSame(1.0, $interactions['severity_agreement']);
        $this->assertSame(0.5, $interactions['abstention']['rate']);
        $this->assertSame(1.0, $interactions['conservative_recall']);
        $this->assertSame([], $interactions['major_interactions_missed']);
        $this->assertSame(['products' => 4, 'products_with_leaflet' => [], 'pairs_with_a_leaflet_of_one' => 0, 'pairs_with_a_leaflet_of_both' => 0], $interactions['leaflet_coverage']);
    }

    public function test_interaction_run_counts_the_pairs_that_have_a_leaflet_in_the_database_being_measured(): void
    {
        app(DatasetBuilder::class)->build($this->seedWorld(), $this->directory);
        $source = KnowledgeSource::query()->create(['name' => 'ANVISA', 'type' => 'document', 'active' => true]);
        $document = KnowledgeDocument::query()->create(['source_id' => $source->id, 'external_key' => 'marevan', 'title' => 'Marevan — Bula', 'document_type' => 'bula_paciente', 'language' => 'pt-BR', 'metadata' => [], 'content_status' => 'READY', 'file_status' => 'READY', 'embedding_status' => 'READY', 'content_hash' => 'h']);
        DB::table('knowledge_document_ingredients')->insert(['document_id' => $document->id, 'ingredient_id' => ActiveIngredient::query()->where('normalized_name', 'warfarin')->value('id'), 'method' => 'manual', 'confidence' => 1, 'created_at' => now(), 'updated_at' => now()]);

        $coverage = app(InteractionEvaluator::class)->run($this->directory.'/interactions.v1.json', 'lexical')['leaflet_coverage'];

        $this->assertSame(['MAREVAN'], $coverage['products_with_leaflet']);
        $this->assertSame(3, $coverage['pairs_with_a_leaflet_of_one']);
        $this->assertSame(0, $coverage['pairs_with_a_leaflet_of_both']);
    }

    public function test_baseline_leaves_room_for_reasoning_and_records_each_pair(): void
    {
        app(DatasetBuilder::class)->build($this->seedWorld(), $this->directory);
        $provider = new FakeTextGenerationProvider('{"interaction": "yes", "severity": "major"}');
        app(AiProviderManager::class)->extend('text', 'scripted', fn (): FakeTextGenerationProvider => $provider);
        config(['ai.text.default' => 'scripted']);

        $report = app(BaselineEvaluator::class)->run($this->directory.'/interactions.v1.json', $this->directory.'/identification.v1.json', null);

        $this->assertGreaterThanOrEqual(1024, $provider->requests[0]->maxOutputTokens);
        $this->assertCount($report['items'], $report['pairs']);
        $this->assertSame(['tp' => 2, 'fp' => 4], array_count_values(array_column($report['pairs'], 'outcome')));
        $marevanFluconazol = collect($report['pairs'])->firstWhere(fn (array $pair): bool => $pair['a_name'].'|'.$pair['b_name'] === 'MAREVAN|FLUCONAZOL');
        $this->assertSame(['tp', 'major', 'major'], [$marevanFluconazol['outcome'], $marevanFluconazol['gold_severity'], $marevanFluconazol['predicted_severity']]);
    }

    public function test_retrieval_evaluator_reports_unavailable_embedding_modes_instead_of_inventing_results(): void
    {
        File::put($this->directory.'/judgments.json', json_encode(['items' => [['id' => 1, 'owner' => 'warfarin', 'other' => 'fluconazole', 'relevant' => ['k#1']]]]));

        $report = app(RetrievalEvaluator::class)->run($this->directory.'/judgments.json', ['lexical', 'vector', 'hybrid']);

        $this->assertSame('executed', $report['modes']['lexical']['status']);
        $this->assertSame('not_executed', $report['modes']['vector']['status']);
        $this->assertStringContainsString('AI_NOT_CONFIGURED', $report['modes']['hybrid']['reason']);
    }

    public function test_subject_judgments_are_built_in_the_database_being_measured_and_skip_what_has_no_leaflet(): void
    {
        $source = KnowledgeSource::query()->create(['name' => 'ANVISA', 'type' => 'document', 'active' => true]);
        $document = KnowledgeDocument::query()->create(['source_id' => $source->id, 'external_key' => 'omeprazol', 'title' => 'Omeprazol — Bula', 'document_type' => 'bula_paciente', 'language' => 'pt-BR', 'metadata' => [], 'content_status' => 'READY', 'file_status' => 'READY', 'embedding_status' => 'READY', 'content_hash' => 'h']);
        $ingredient = ActiveIngredient::query()->create(['canonical_name' => 'Omeprazole', 'normalized_name' => 'omeprazole']);
        DB::table('knowledge_document_ingredients')->insert(['document_id' => $document->id, 'ingredient_id' => $ingredient->id, 'method' => 'manual', 'confidence' => 1, 'created_at' => now(), 'updated_at' => now()]);
        foreach ([['INDICAÇÕES', 'O omeprazol é indicado para tratar úlcera do estômago e azia.'], ['5. ONDE, COMO E POR QUANTO TEMPO POSSO GUARDAR ESTE MEDICAMENTO?', 'Guardar o omeprazol em temperatura ambiente, protegido da luz e da umidade.'], ['REAÇÕES ADVERSAS', 'Pode causar dor de cabeça, diarreia e náusea.']] as $index => [$section, $content]) {
            DB::table('knowledge_chunks')->insert(['document_id' => $document->id, 'chunk_index' => $index, 'section' => $section, 'page_start' => 1, 'page_end' => 1, 'content' => $content, 'created_at' => now(), 'updated_at' => now()]);
        }
        File::put($this->directory.'/subjects.json', json_encode([
            'subjects' => [
                'armazenamento' => ['question' => 'Como devo guardar o {name}?', 'sections' => 'ARMAZENAMENTO|ONDE, COMO E POR QUANTO TEMPO'],
                'superdose' => ['question' => 'Tomei {name} a mais, o que acontece?', 'sections' => 'SUPERDOSE'],
            ],
            'items' => [['ingredient' => 'Omeprazole', 'name' => 'omeprazol'], ['ingredient' => 'Riluzole', 'name' => 'riluzol']],
        ]));

        $report = app(RetrievalEvaluator::class)->run($this->directory.'/subjects.json', ['lexical']);

        $this->assertSame(1, $report['items']);
        $this->assertSame(['listed' => 2, 'with_leaflet' => 1], $report['ingredients']);
        $this->assertSame(['armazenamento' => 1], $report['by_subject']);
        $this->assertSame(1.0, $report['modes']['lexical']['hit@1']['rate']);
        $this->assertSame(1.0, $report['modes']['lexical']['mrr']);
    }

    /**
     * Comparação controlada entre modelos (análise crítica do TCC): a segunda execução repete exatamente os pares da primeira,
     * na mesma ordem, e informa se os trechos recuperados foram os mesmos, em vez de sortear pares de novo.
     */
    public function test_generation_can_repeat_the_exact_pairs_of_a_previous_run_and_checks_the_evidence_is_the_same(): void
    {
        foreach (['100000001', '100000002', '100000003'] as $registration) {
            AnvisaMedicine::factory()->create(['registration_number' => $registration]);
        }
        $this->mock(PairAnalyzer::class, function ($mock): void {
            $mock->shouldReceive('analyze')->andReturnUsing(fn (array $a, array $b): array => [
                'verdict' => PairVerdict::InteractionFound,
                'evidences' => [['chunk_id' => $b['id'] === AnvisaMedicine::query()->where('registration_number', '100000003')->value('id') ? 99 : 7]],
                'generation' => ['status' => 'generated', 'claims_accepted' => 1, 'claims_rejected' => 0],
                'timings' => ['generation_ms' => 10],
            ]);
        });
        $reference = [['a' => '100000001', 'b' => '100000002', 'evidence_chunk_ids' => [7]], ['a' => '100000001', 'b' => '100000003', 'evidence_chunk_ids' => [8]]];

        $results = app(GenerationEvaluator::class)->run($this->directory.'/unused.json', 'hybrid', null, 42, $reference);

        $this->assertSame([['a' => '100000001', 'b' => '100000002', 'evidence_chunk_ids' => [7], 'status' => 'generated'], ['a' => '100000001', 'b' => '100000003', 'evidence_chunk_ids' => [99], 'status' => 'generated']], $results['pairs']);
        $this->assertSame(['reference_pairs' => 2, 'same_evidence' => 1], $results['controlled'], 'o segundo par recuperou outro trecho: a comparação avisa');
    }

    public function test_run_record_captures_everything_needed_to_reproduce_the_run(): void
    {
        config(['bulai.code_version' => 'abc123', 'ai.text.default' => 'fake']);
        $dataset = $this->directory.'/ds.json';
        File::put($dataset, '{"items":[]}');
        $recorder = app(EvaluationRunRecorder::class);

        $run = $recorder->begin('interactions', $dataset, ['mode' => 'lexical', 'seed' => 42]);
        $path = $recorder->finish($run, ['ok' => true], 'Teste de Registro');
        $saved = json_decode(File::get($path), true);

        $this->assertSame('abc123', $saved['code']['version']);
        $this->assertSame(hash_file('sha256', $dataset), $saved['dataset']['sha256']);
        $this->assertSame(['mode' => 'lexical', 'seed' => 42], $saved['parameters']);
        $this->assertSame('fake', $saved['models']['generation']['driver']);
        $this->assertArrayHasKey('documents', $saved);
        $this->assertArrayHasKey('interaction_sources', $saved);
        $this->assertArrayHasKey('configuration', $saved);
        $this->assertSame(['ok' => true], $saved['results']);
        $this->assertStringContainsString('teste-de-registro', $path);
        File::delete($path);
    }
}
