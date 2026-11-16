<?php

namespace Tests\Feature\Api;

use App\Models\Consultation\MedicineLeafletSummary;
use App\Models\Knowledge\ActiveIngredient;
use App\Models\Knowledge\AnvisaMedicine;
use App\Models\Knowledge\DrugInteraction;
use App\Models\Knowledge\IngestionToken;
use App\Models\Knowledge\KnowledgeDocument;
use App\Models\Knowledge\KnowledgeSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Consultas de leitura para analisar o sistema pela API, sem tinker na VPS (auditoria de 16/11/2026): catálogo, princípio
 * ativo, par no DDInter, bulas humanizadas, trechos de um documento e registro completo de um evaluation:run.
 */
final class SystemInspectionApiTest extends TestCase
{
    use RefreshDatabase;

    private function token(): string
    {
        return IngestionToken::issue('agente', IngestionToken::SCOPE_API_READ, now()->addDay())[1];
    }

    private function ingredient(string $name, ?string $alias = null): ActiveIngredient
    {
        $ingredient = ActiveIngredient::factory()->create(['canonical_name' => $name, 'normalized_name' => mb_strtolower($name)]);
        if ($alias !== null) {
            $ingredient->aliases()->create(['alias' => $alias, 'normalized_alias' => $alias, 'language' => 'pt', 'origin' => 'document', 'review_status' => 'pending_review']);
        }

        return $ingredient;
    }

    public function test_the_new_queries_need_a_valid_api_token(): void
    {
        foreach (['catalog?q=anlodipino', 'ingredients?name=anlodipino', 'interaction?a=1&b=2', 'leaflet-summaries', 'documents/1/chunks', 'evaluations/0123456789abcdef'] as $path) {
            $this->getJson("/api/v1/audit/{$path}")->assertUnauthorized();
        }
    }

    public function test_catalog_ingredient_and_pair_queries_show_links_aliases_and_whether_the_base_covers_each_drug(): void
    {
        $amlodipine = $this->ingredient('Amlodipine', 'besilato de anlodipino');
        $carbamazepine = $this->ingredient('Carbamazepine');
        $simvastatin = $this->ingredient('Simvastatin');
        $dipirona = $this->ingredient('Dipirona');
        DrugInteraction::factory()->create(['drug_a_ingredient_id' => $amlodipine->id, 'drug_b_ingredient_id' => $carbamazepine->id, 'severity' => 'major']);
        DrugInteraction::factory()->create(['drug_a_ingredient_id' => $simvastatin->id, 'drug_b_ingredient_id' => $carbamazepine->id, 'severity' => 'moderate']);
        $medicine = AnvisaMedicine::factory()->create(['product_name' => 'BESILATO DE ANLODIPINO', 'normalized_product_name' => 'besilato de anlodipino', 'active_ingredient' => 'BESILATO DE ANLODIPINO', 'normalized_active_ingredient' => 'besilato de anlodipino']);
        $medicine->ingredients()->attach($amlodipine->id, ['component_text' => 'BESILATO DE ANLODIPINO', 'method' => 'alias', 'confidence' => 1]);
        AnvisaMedicine::factory()->create(['product_name' => 'ANLODIPINO BESILATO', 'normalized_product_name' => 'anlodipino besilato', 'active_ingredient' => null, 'normalized_active_ingredient' => null]);
        $token = $this->token();

        $catalog = $this->withToken($token)->getJson('/api/v1/audit/catalog?q=anlodipino')->assertOk()->json();
        $this->assertSame(2, $catalog['total']);
        $this->assertSame([['id' => $amlodipine->id, 'name' => 'Amlodipine']], collect($catalog['medicines'])->firstWhere('product_name', 'BESILATO DE ANLODIPINO')['ingredients']);
        $this->assertSame([], collect($catalog['medicines'])->firstWhere('product_name', 'ANLODIPINO BESILATO')['ingredients'], 'registro sem vínculo aparece como tal');

        $found = $this->withToken($token)->getJson('/api/v1/audit/ingredients?name=anlodipino')->assertOk()->json('ingredients.0');
        $this->assertSame('Amlodipine', $found['canonical_name']);
        $this->assertSame('besilato de anlodipino', $found['aliases'][0]['alias']);
        $this->assertSame(['in_source' => true, 'interactions_by_severity' => ['major' => 1]], $found['ddinter']);
        $this->assertSame(1, $found['catalog_medicines']);

        $registered = $this->withToken($token)->getJson("/api/v1/audit/interaction?a={$amlodipine->id}&b={$carbamazepine->id}")->assertOk()->json();
        $this->assertSame(['registered', 'major'], [$registered['verdict'], $registered['interaction']['severity']]);
        $this->assertSame('not_registered', $this->withToken($token)->getJson("/api/v1/audit/interaction?a={$amlodipine->id}&b={$simvastatin->id}")->json('verdict'));
        $absent = $this->withToken($token)->getJson("/api/v1/audit/interaction?a={$dipirona->id}&b={$carbamazepine->id}")->assertOk()->json();
        $this->assertSame(['not_verifiable_absent_from_source', false], [$absent['verdict'], $absent['a']['in_source']]);
        $this->withToken($token)->getJson("/api/v1/audit/interaction?a={$dipirona->id}&b={$dipirona->id}")->assertUnprocessable();
    }

    public function test_leaflet_summaries_come_with_their_sources_and_readability(): void
    {
        MedicineLeafletSummary::query()->create([
            'uuid' => 'f0d3c0de-0000-4000-8000-000000000001', 'ingredient_key' => 'i:1', 'prompt_version' => 'humanized-leaflet-v2', 'documents_fingerprint' => 'x', 'status' => MedicineLeafletSummary::STATUS_GENERATED,
            'sections' => ['indicacao' => ['label' => 'Para que serve', 'claims' => ['Serve para tratar a pressão alta.', 'Ajuda o coração a trabalhar menos.']]],
            'evidences' => [['document_title' => 'Anlodipino — Bula', 'section' => 'INDICAÇÕES', 'page_start' => 1, 'excerpt' => 'Este medicamento é indicado para o tratamento da hipertensão arterial sistêmica e da angina pectoris estável crônica.']],
            'generation' => ['claims_accepted' => 2], 'retrieval' => [], 'pipeline' => [], 'duration_ms' => 10, 'views' => 3,
        ]);

        $summary = $this->withToken($this->token())->getJson('/api/v1/audit/leaflet-summaries')->assertOk()->json('summaries.0');

        $this->assertSame(['Serve para tratar a pressão alta.', 'Ajuda o coração a trabalhar menos.'], $summary['sections'][0]['claims']);
        $this->assertSame('INDICAÇÕES', $summary['sources'][0]['section']);
        $this->assertGreaterThan($summary['readability']['sources']['index'], $summary['readability']['summary']['index'], 'a frase simples lê mais fácil que o trecho técnico');
    }

    public function test_document_chunks_and_the_complete_evaluation_record_are_available(): void
    {
        $source = KnowledgeSource::query()->create(['name' => 'ANVISA', 'type' => 'document', 'active' => true]);
        $document = KnowledgeDocument::query()->create(['source_id' => $source->id, 'external_key' => 'doc', 'title' => 'Doc — Bula', 'document_type' => 'bula_paciente', 'language' => 'pt-BR', 'metadata' => [], 'content_status' => 'READY', 'file_status' => 'READY', 'embedding_status' => 'PENDING']);
        DB::table('knowledge_chunks')->insert(['document_id' => $document->id, 'chunk_index' => 0, 'section' => '7. CUIDADOS DE ARMAZENAMENTO', 'page_start' => 3, 'page_end' => 3, 'content' => str_repeat('Guardar em temperatura ambiente. ', 20), 'created_at' => now(), 'updated_at' => now()]);
        $token = $this->token();

        $chunks = $this->withToken($token)->getJson("/api/v1/audit/documents/{$document->id}/chunks")->assertOk()->json('chunks');
        $this->assertSame(['7. CUIDADOS DE ARMAZENAMENTO', false, 300], [$chunks[0]['section'], $chunks[0]['embedded'], mb_strlen($chunks[0]['content'])]);
        $this->assertGreaterThan(300, mb_strlen($this->withToken($token)->getJson("/api/v1/audit/documents/{$document->id}/chunks?full=1")->json('chunks.0.content')));

        $directory = storage_path('app/evaluation/runs');
        File::ensureDirectoryExists($directory);
        $path = $directory.'/test-system-inspection-0f0f0f0f.json';
        File::put($path, json_encode(['run_id' => '0f0f0f0f-aaaa-4bbb-8ccc-000000000000', 'task' => 'retrieval', 'results' => ['details' => ['hybrid' => array_fill(0, 20, ['query' => 'q', 'top' => []])]]]));
        try {
            $run = $this->withToken($token)->getJson('/api/v1/audit/evaluations/0f0f0f0f')->assertOk()->json();
            $this->assertCount(20, $run['results']['details']['hybrid'], 'o registro completo não resume as listas');
            $this->withToken($token)->getJson('/api/v1/audit/evaluations/deadbeef')->assertNotFound();
        } finally {
            File::delete($path);
        }
    }
}
