<?php

namespace Tests\Feature\Evaluation;

use App\Models\Knowledge\AnvisaMedicine;
use App\Models\Knowledge\DrugInteraction;
use App\Services\Evaluation\DatasetBuilder;
use App\Services\Evaluation\EvaluationRunRecorder;
use App\Services\Evaluation\IdentificationEvaluator;
use App\Services\Evaluation\InteractionEvaluator;
use App\Services\Evaluation\RetrievalEvaluator;
use App\Services\Knowledge\ActiveIngredientCatalogBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
    }

    public function test_retrieval_evaluator_reports_unavailable_embedding_modes_instead_of_inventing_results(): void
    {
        File::put($this->directory.'/judgments.json', json_encode(['items' => [['id' => 1, 'owner' => 'warfarin', 'other' => 'fluconazole', 'relevant' => ['k#1']]]]));

        $report = app(RetrievalEvaluator::class)->run($this->directory.'/judgments.json', ['lexical', 'vector', 'hybrid']);

        $this->assertSame('executed', $report['modes']['lexical']['status']);
        $this->assertSame('not_executed', $report['modes']['vector']['status']);
        $this->assertStringContainsString('AI_NOT_CONFIGURED', $report['modes']['hybrid']['reason']);
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
