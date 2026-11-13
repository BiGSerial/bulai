<?php

namespace Tests\Feature\Consulta;

use App\Models\Knowledge\ActiveIngredient;
use App\Models\Knowledge\AnvisaMedicine;
use App\Services\Interactions\FuzzyMedicineMatcher;
use App\Services\Interactions\MedicationSearchService;
use App\Services\Patient\MedicationMentionDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Tolerância a erros de digitação (pg_trgm) na busca do catálogo e na detecção de remédios citados em perguntas. */
final class BuscaToleranteAErrosTest extends TestCase
{
    use RefreshDatabase;

    private function medicine(string $name, string $ingredient, ?ActiveIngredient $shared = null): AnvisaMedicine
    {
        $medicine = AnvisaMedicine::factory()->create(['product_name' => $name, 'normalized_product_name' => strtolower($name), 'active_ingredient' => $ingredient, 'normalized_active_ingredient' => strtolower($ingredient), 'registration_status' => 'Ativo']);
        $ingredientModel = $shared ?? ActiveIngredient::factory()->create(['canonical_name' => $ingredient, 'normalized_name' => strtolower($ingredient)]);
        $medicine->ingredients()->attach($ingredientModel->id, ['component_text' => $ingredient, 'method' => 'alias', 'confidence' => 1]);

        return $medicine;
    }

    public function test_the_catalog_search_falls_back_to_similar_names_when_the_prefix_search_finds_nothing(): void
    {
        $this->medicine('LOSARTANA POTASSICA', 'LOSARTANA POTASSICA');
        $this->medicine('OMEPRAZOL', 'OMEPRAZOL');
        $search = app(MedicationSearchService::class);

        $prefix = $search->search('losar');
        $this->assertSame(['LOSARTANA POTASSICA', false, null], [$prefix[0]['name'], $prefix[0]['fuzzy'], $prefix[0]['matched']]);

        $typo = $search->search('losartam');
        $this->assertSame(['LOSARTANA POTASSICA', true, 'losartana'], [$typo[0]['name'], $typo[0]['fuzzy'], $typo[0]['matched']]);
        $this->assertSame(['OMEPRAZOL'], array_column($search->search('omeprasol'), 'name'));
        $this->assertSame([], $search->search('xyzqw'), 'sem nada parecido não inventa resultado');
        $this->assertSame([], $search->search('lo'), 'termo curto demais continua sem busca');
    }

    public function test_the_mention_detector_accepts_misspelled_medicine_names_but_not_ambiguous_or_common_words(): void
    {
        $this->medicine('LOSARTANA POTASSICA', 'LOSARTANA POTASSICA');
        $omeprazol = ActiveIngredient::factory()->create(['canonical_name' => 'OMEPRAZOL', 'normalized_name' => 'omeprazol']);
        // Como no catálogo real: genérico identificado, registro sem princípio ativo ligado e uma combinação com outro ingrediente.
        AnvisaMedicine::factory()->create(['product_name' => 'OMEPRAZOL', 'normalized_product_name' => 'omeprazol', 'active_ingredient' => 'OMEPRAZOL', 'normalized_active_ingredient' => 'omeprazol', 'registration_status' => 'Ativo']);
        $this->medicine('OMEPRAZOL', 'OMEPRAZOL', $omeprazol);
        $this->medicine('ESTOMADEFENS', 'OMEPRAZOL + BICARBONATO DE SODIO', $omeprazol)->ingredients()->attach(ActiveIngredient::factory()->create(['canonical_name' => 'BICARBONATO DE SODIO', 'normalized_name' => 'bicarbonato de sodio'])->id, ['component_text' => 'BICARBONATO', 'method' => 'alias', 'confidence' => 1]);
        $dipirona = ActiveIngredient::factory()->create(['canonical_name' => 'DIPIRONA', 'normalized_name' => 'dipirona']);
        $this->medicine('DIPIRONA SODICA', 'DIPIRONA SODICA', $dipirona);
        $this->medicine('DIPIRONA MONOIDRATADA', 'DIPIRONA MONOIDRATADA', $dipirona);
        foreach (['ACIDO FOLICO', 'ACIDO VALPROICO', 'ACIDO ASCORBICO', 'ACIDO TRANEXAMICO'] as $acid) {
            $this->medicine($acid, $acid);
        }
        $detector = app(MedicationMentionDetector::class);
        $summary = fn (string $text): array => array_map(fn (array $m): string => $m['name'].'='.$m['matched_text'].($m['fuzzy'] ? '~'.$m['corrected'] : ''), $detector->detect($text));

        $mentions = $detector->detect('posso tomar lozartana com omeprasol?');
        $this->assertSame(['LOSARTANA POTASSICA=lozartana~losartana', 'OMEPRAZOL=omeprasol~omeprazol'], $summary('posso tomar lozartana com omeprasol?'));
        $this->assertTrue($mentions[1]['identified'], 'entre registros de mesmo nome, prefere o que tem princípio ativo identificado');
        $this->assertSame(['LOSARTANA POTASSICA=losartana'], $summary('losartana dá tontura?'), 'nome certo continua pelo caminho exato, sem marca de correção');
        $this->assertSame(['DIPIRONA SODICA=dipironna~dipirona'], $summary('dipironna serve para febre?'), 'grupos que compartilham o princípio ativo não são ambíguos');
        $this->assertSame([], $summary('dipirnoa serve para febre?'), 'letras trocadas de lugar ficam abaixo da semelhança mínima (limitação conhecida)');
        $this->assertSame([], $summary('acidu faz mal para o estômago?'), 'palavra que se parece com vários remédios diferentes é ambígua');
        $this->assertSame([], $summary('posso beber bastante água durante o tratamento?'), 'palavras comuns não viram remédio');

        $best = app(FuzzyMedicineMatcher::class)->best('omeprazol');
        $this->assertSame(['OMEPRAZOL', 1.0], [$best['medicine']->product_name, $best['similarity']]);
        $this->assertNull(app(FuzzyMedicineMatcher::class)->best('ome'), 'termo curto demais');
    }

    /** Produção, 11/11/2026: "Para que serve o dabigatrana?" não achava o remédio, porque o catálogo escreve "etexilato de dabigatrana". */
    public function test_a_base_name_finds_the_medicine_the_catalog_names_after_its_salt(): void
    {
        $this->medicine('PRADAXA', 'ETEXILATO DE DABIGATRANA');
        $anlodipino = ActiveIngredient::factory()->create(['canonical_name' => 'BESILATO DE ANLODIPINO', 'normalized_name' => 'besilato de anlodipino']);
        $this->medicine('NORVASC', 'BESILATO DE ANLODIPINO', $anlodipino);
        $this->medicine('NAPRIX A', 'RAMIPRIL + BESILATO DE ANLODIPINO', $anlodipino)->ingredients()->attach(ActiveIngredient::factory()->create(['canonical_name' => 'RAMIPRIL', 'normalized_name' => 'ramipril'])->id, ['component_text' => 'RAMIPRIL', 'method' => 'alias', 'confidence' => 1]);
        $this->medicine('CLORETO DE SODIO', 'CLORETO DE SODIO');
        $detector = app(MedicationMentionDetector::class);
        $names = fn (string $text): array => array_column($detector->detect($text), 'name');

        $this->assertSame(['PRADAXA'], $names('Para que serve o dabigatrana?'));
        $this->assertSame(['NORVASC'], $names('anlodipino dá inchaço?'), 'entre o remédio só com o ativo e a combinação, fica o remédio só com ele');
        $this->assertSame([], $names('posso comer sodio?'), 'nome-base curto não vira sal do catálogo');
    }

    /**
     * Produção, 12/11/2026 (Issue #68): "anlodipino" sumia da pergunta. O catálogo grafa o mesmo fármaco de formas
     * incoerentes; pelo texto, o grupo ligado ao Amlodipine parecia associação. Registros na forma real do catálogo.
     */
    public function test_a_base_name_follows_the_ingredient_links_when_the_catalog_text_spells_the_same_drug_inconsistently(): void
    {
        $amlodipine = ActiveIngredient::factory()->create(['canonical_name' => 'Amlodipine', 'normalized_name' => 'amlodipine']);
        $catalog = fn (string $name, ?string $active, string $status, array $ingredients): AnvisaMedicine => tap(
            AnvisaMedicine::factory()->create(['product_name' => $name, 'normalized_product_name' => mb_strtolower($name), 'active_ingredient' => $active, 'normalized_active_ingredient' => $active === null ? null : mb_strtolower($active), 'registration_status' => $status]),
            fn (AnvisaMedicine $medicine) => collect($ingredients)->each(fn (ActiveIngredient $ingredient) => $medicine->ingredients()->attach($ingredient->id, ['component_text' => (string) $active, 'method' => 'alias', 'confidence' => 1])),
        );
        $other = fn (string $name): ActiveIngredient => ActiveIngredient::factory()->create(['canonical_name' => $name, 'normalized_name' => mb_strtolower($name)]);
        $catalog('BESILATO DE ANLODIPINO', 'BESILATO DE ANLODIPINO', 'Ativo', [$amlodipine]);
        $catalog('NORVASC', 'BESILATO DE ANLODIPINO', 'Inativo', [$amlodipine]);
        $catalog('AMLOCOR', 'BESILATO DE AMLODIPINA, BESILATO DE ANLODIPINO', 'Inativo', [$amlodipine]);
        $catalog('ANLODIPINO BESILATO', null, 'Inativo', []);
        $catalog('BENICARANLO', 'OLMESARTANA MEDOXOMILA, BESILATO DE ANLODIPINO', 'Ativo', [$amlodipine, $other('Olmesartan')]);
        $catalog('BRASART BCC', 'VALSARTANA, BESILATO DE ANLODIPINO', 'Ativo', [$amlodipine, $valsartan = $other('Valsartan')]);
        $catalog('EXFORGE HCT', 'VALSARTANA, HIDROCLOROTIAZIDA, BESILATO DE ANLODIPINO', 'Ativo', [$amlodipine, $valsartan, $other('Hydrochlorothiazide')]);
        $catalog('LOTAR', 'BESILATO DE ANLODIPINO, losartana potássica', 'Ativo', [$amlodipine, $other('Losartan')]);
        $detector = app(MedicationMentionDetector::class);
        $found = fn (string $text): array => array_map(fn (array $m): string => $m['name'].($m['identified'] ? '' : ' (sem vínculo)'), $detector->detect($text));

        $this->assertSame(['BESILATO DE ANLODIPINO'], $found('anlodipino dá inchaço?'), 'não cai no registro inativo e sem vínculo');

        $catalog('ANLODIPINO 5 MG', 'BESILATO DE AMLODIPINA', 'Inativo', []);
        $this->assertSame(['BESILATO DE ANLODIPINO'], $found('anlodipino dá inchaço?'), 'um registro incoerente não torna o termo ambíguo');
        $this->assertSame(['NORVASC'], $found('Norvasc dá inchaço?'), 'a marca continua pelo nome exato');
    }
}
