<?php

namespace Tests\Feature\Knowledge;

use App\Models\Knowledge\ActiveIngredient;
use App\Models\Knowledge\AnvisaMedicine;
use App\Models\Knowledge\DrugInteraction;
use App\Models\Knowledge\KnowledgeDocument;
use App\Models\Knowledge\KnowledgeSource;
use App\Services\Knowledge\ActiveIngredientCatalogBuilder;
use App\Services\Knowledge\ActiveIngredientResolver;
use App\Services\Knowledge\IngredientNameNormalizer;
use App\Services\Knowledge\KnowledgeDocumentIngredientLinker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ActiveIngredientCatalogTest extends TestCase
{
    use RefreshDatabase;

    private function csv(string $body): string
    {
        $path = sys_get_temp_dir().'/'.uniqid('alias_').'.csv';
        file_put_contents($path, "pt_name,en_name\n".$body);

        return $path;
    }

    private function interaction(string $a, string $b, string $severity = 'major'): DrugInteraction
    {
        static $n = 1;
        $n++;

        return DrugInteraction::factory()->create([
            'pair_key' => "t:$n", 'drug_a_name' => $a, 'normalized_drug_a_name' => strtolower($a),
            'drug_b_name' => $b, 'normalized_drug_b_name' => strtolower($b), 'severity' => $severity,
        ]);
    }

    public function test_normalizer_strips_salts_hydrates_and_route_qualifiers(): void
    {
        $normalizer = app(IngredientNameNormalizer::class);

        $this->assertSame('metformina', $normalizer->base('CLORIDRATO DE METFORMINA'));
        $this->assertSame('varfarina', $normalizer->base('VARFARINA SÓDICA'));
        $this->assertSame('pantoprazol', $normalizer->base('pantoprazol sódico sesqui-hidratado'));
        $this->assertSame('phenylephrine', $normalizer->withoutQualifiers('Phenylephrine (nasal)'));
        $this->assertSame(['paracetamol', 'cafeina anidra'], array_map(fn ($c) => $normalizer->normalize($c), $normalizer->components('Paracetamol + Cafeína anidra')));
    }

    public function test_builder_links_medicines_and_interactions_through_curated_and_rule_aliases(): void
    {
        $this->interaction('Warfarin', 'Fluconazole');
        $this->interaction('Warfarin (oral)', 'Simvastatin', 'moderate');
        $varfarina = AnvisaMedicine::factory()->create(['product_name' => 'MAREVAN', 'active_ingredient' => 'VARFARINA SÓDICA']);
        $rule = AnvisaMedicine::factory()->create(['product_name' => 'ZOCOR', 'active_ingredient' => 'SINVASTATINA']);
        $unknown = AnvisaMedicine::factory()->create(['product_name' => 'XPTO', 'active_ingredient' => 'SUBSTANCIA INEXISTENTE']);

        $stats = app(ActiveIngredientCatalogBuilder::class)->build($this->csv("varfarina,warfarin\nfoo,inexistente\n"));

        $this->assertSame(3, $stats['ingredients']);
        $this->assertSame(['foo → inexistente'], $stats['curated_skipped']);
        $this->assertSame(2, $stats['medicines_linked']);
        $this->assertSame('warfarin', $varfarina->fresh()->load('ingredients')->ingredients->first()->normalized_name);
        $link = $rule->fresh()->ingredients->first();
        $this->assertSame('simvastatin', $link->normalized_name);
        $this->assertSame('cognate_rule', $link->pivot->method);
        $this->assertCount(0, $unknown->fresh()->ingredients);
        $this->assertSame(2, $stats['interactions_linked']);
        $this->assertSame(
            ActiveIngredient::where('normalized_name', 'warfarin')->value('id'),
            DrugInteraction::where('severity', 'moderate')->value('drug_a_ingredient_id'),
        );
    }

    public function test_ambiguous_names_are_never_linked(): void
    {
        $this->interaction('Calcium carbonate', 'Warfarin');
        $this->interaction('Calcium acetate', 'Warfarin');
        $medicine = AnvisaMedicine::factory()->create(['product_name' => 'CALCIO', 'active_ingredient' => 'CALCIO']);

        $stats = app(ActiveIngredientCatalogBuilder::class)->build($this->csv("calcio,calcium carbonate\ncalcio,calcium acetate\n"));

        $this->assertSame(1, $stats['components_ambiguous']);
        $this->assertSame(0, $stats['medicines_linked']);
        $this->assertCount(0, $medicine->fresh()->ingredients);
        $this->assertSame('ambiguous', app(ActiveIngredientResolver::class)->resolve('calcio')->status);
    }

    public function test_builder_is_idempotent(): void
    {
        $this->interaction('Warfarin', 'Fluconazole');
        AnvisaMedicine::factory()->create(['active_ingredient' => 'VARFARINA']);
        $builder = app(ActiveIngredientCatalogBuilder::class);

        $builder->build($this->csv("varfarina,warfarin\n"));
        $builder->build($this->csv("varfarina,warfarin\n"));

        $this->assertSame(2, ActiveIngredient::count());
        $this->assertSame(1, DB::table('anvisa_medicine_ingredients')->count());
    }

    public function test_a_leaflet_for_an_ingredient_outside_ddinter_creates_it_and_links_document_and_medicines(): void
    {
        $medicine = AnvisaMedicine::factory()->create(['product_name' => 'NIMESULIDA', 'active_ingredient' => 'NIMESULIDA']);
        $source = KnowledgeSource::query()->create(['name' => 'ANVISA', 'type' => 'document', 'active' => true]);
        $document = KnowledgeDocument::query()->create(['source_id' => $source->id, 'external_key' => 'doc-nimesulida', 'title' => 'NIMESULIDA — Bula do profissional de saúde', 'document_type' => 'bula_profissional', 'language' => 'pt-BR', 'metadata' => ['active_ingredient' => 'nimesulida'], 'content_status' => 'READY', 'file_status' => 'READY', 'embedding_status' => 'PENDING']);

        $linker = app(KnowledgeDocumentIngredientLinker::class);
        $this->assertSame(1, $linker->link($document));
        $this->assertSame(1, $linker->link($document));

        $ingredient = ActiveIngredient::query()->where('normalized_name', 'nimesulida')->sole();
        $this->assertSame([$ingredient->id], DB::table('knowledge_document_ingredients')->where('document_id', $document->id)->pluck('ingredient_id')->all());
        $this->assertSame([$ingredient->id], $medicine->fresh()->ingredients->pluck('id')->all());
    }

    public function test_a_leaflet_linked_before_the_catalog_knows_the_portuguese_name_uses_the_ddinter_ingredient(): void
    {
        $this->interaction('Ibuprofen', 'Warfarin');
        app(ActiveIngredientCatalogBuilder::class)->build($this->csv(''));
        $document = $this->leaflet('IBUPROFENO');

        app(KnowledgeDocumentIngredientLinker::class)->link($document);

        $this->assertSame(['ibuprofen'], ActiveIngredient::query()->whereIn('id', DB::table('knowledge_document_ingredients')->where('document_id', $document->id)->pluck('ingredient_id'))->pluck('normalized_name')->all());
        $this->assertSame(0, ActiveIngredient::query()->where('normalized_name', 'ibuprofeno')->count());
        $this->assertSame('ibuprofen', app(KnowledgeDocumentIngredientLinker::class)->createIngredient('Ibuprofeno')?->normalized_name);
    }

    public function test_a_portuguese_duplicate_created_earlier_is_merged_and_the_medicine_gets_its_interactions_back(): void
    {
        $this->interaction('Ibuprofen', 'Warfarin');
        $medicine = AnvisaMedicine::factory()->create(['product_name' => 'IBUPROFENO', 'active_ingredient' => 'IBUPROFENO']);
        $document = $this->leaflet('IBUPROFENO');
        $duplicate = ActiveIngredient::query()->create(['normalized_name' => 'ibuprofeno', 'canonical_name' => 'Ibuprofeno']);
        $duplicate->aliases()->create(['alias' => 'ibuprofeno', 'normalized_alias' => 'ibuprofeno', 'language' => 'pt', 'origin' => 'document', 'review_status' => 'pending_review']);
        $medicine->ingredients()->attach($duplicate->id, ['component_text' => 'ibuprofeno', 'method' => 'document', 'confidence' => 0.9]);
        DB::table('knowledge_document_ingredients')->insert(['document_id' => $document->id, 'ingredient_id' => $duplicate->id, 'method' => 'metadata', 'confidence' => 0.9, 'created_at' => now(), 'updated_at' => now()]);

        $stats = app(ActiveIngredientCatalogBuilder::class)->build($this->csv(''));

        $this->assertSame([['from' => 'Ibuprofeno', 'into' => 'Ibuprofen']], $stats['merged']);
        $ibuprofen = ActiveIngredient::query()->where('normalized_name', 'ibuprofen')->sole();
        $this->assertSame([$ibuprofen->id], $medicine->fresh()->ingredients->pluck('id')->all());
        $this->assertSame([$ibuprofen->id], DB::table('knowledge_document_ingredients')->where('document_id', $document->id)->pluck('ingredient_id')->all());
        $this->assertSame(0, ActiveIngredient::query()->where('normalized_name', 'ibuprofeno')->count());
        $this->assertSame($ibuprofen->id, DrugInteraction::query()->value('drug_a_ingredient_id'));
    }

    private function leaflet(string $activeIngredient): KnowledgeDocument
    {
        $source = KnowledgeSource::query()->firstOrCreate(['name' => 'ANVISA'], ['type' => 'document', 'active' => true]);

        return KnowledgeDocument::query()->create(['source_id' => $source->id, 'external_key' => uniqid('doc-'), 'title' => "{$activeIngredient} — Bula do paciente", 'document_type' => 'bula_paciente', 'language' => 'pt-BR', 'metadata' => ['active_ingredient' => $activeIngredient], 'content_status' => 'READY', 'file_status' => 'READY', 'embedding_status' => 'PENDING']);
    }
}
