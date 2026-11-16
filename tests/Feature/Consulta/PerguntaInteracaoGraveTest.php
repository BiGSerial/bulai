<?php

namespace Tests\Feature\Consulta;

use App\Data\Ai\TextGenerationRequest;
use App\Data\Ai\TextGenerationResponse;
use App\Models\Knowledge\ActiveIngredient;
use App\Models\Knowledge\AnvisaMedicine;
use App\Models\Knowledge\DrugInteraction;
use App\Models\Knowledge\KnowledgeDocument;
use App\Models\Knowledge\KnowledgeSource;
use App\Models\User;
use App\Services\Ai\AiProviderManager;
use App\Services\Ai\TextGenerationProvider;
use App\Services\Patient\MedicationQuestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Issue #68 (P0): pergunta livre sobre um par com interação grave no DDInter. Nenhum caminho (nome comercial, ordem invertida,
 * remédio sem vínculo, nome não reconhecido) pode virar "sem interação registrada": ou a gravidade chega à pessoa, ou o texto
 * diz que o par não foi verificado.
 */
final class PerguntaInteracaoGraveTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    public array $interpretation = [];

    /** @var list<string> */
    public array $prompts = [];

    /** Resposta roteirizada do modelo; ["ALL"] vira a lista das evidências do prompt (menos o contexto T1). */
    public string $answer = '{"claims":[]}';

    protected function setUp(): void
    {
        parent::setUp();
        config(['bulai.interpretation.enabled' => true, 'bulai.generation.enabled' => true, 'ai.text.default' => 'scripted', 'bulai.retrieval.mode' => 'lexical']);
        $test = $this;
        $manager = new AiProviderManager;
        $manager->extend(AiProviderManager::TEXT, 'scripted', fn () => new class($test) implements TextGenerationProvider
        {
            public function __construct(private readonly PerguntaInteracaoGraveTest $test) {}

            public function generate(TextGenerationRequest $request): TextGenerationResponse
            {
                if (($request->metadata['purpose'] ?? null) === 'question_interpretation') {
                    return new TextGenerationResponse((string) json_encode($this->test->interpretation), 'scripted', null);
                }
                $this->test->prompts[] = $request->prompt;
                preg_match_all('/<evidencia id="([^"]+)"/', $request->prompt, $ids);

                return new TextGenerationResponse(str_replace('["ALL"]', (string) json_encode(array_values(array_diff($ids[1], ['T1']))), $this->test->answer), 'scripted', null);
            }
        });
        $this->app->instance(AiProviderManager::class, $manager);
    }

    private function medicine(string $name, string $ingredient, bool $linked = true): AnvisaMedicine
    {
        $medicine = AnvisaMedicine::factory()->create(['product_name' => $name, 'normalized_product_name' => mb_strtolower($name), 'active_ingredient' => $ingredient, 'normalized_active_ingredient' => mb_strtolower($ingredient)]);
        if ($linked) {
            $ingredientModel = ActiveIngredient::query()->firstOrCreate(['normalized_name' => mb_strtolower($ingredient)], ['canonical_name' => $ingredient]);
            $medicine->ingredients()->attach($ingredientModel->id, ['component_text' => $ingredient, 'method' => 'alias', 'confidence' => 1]);
        }

        return $medicine;
    }

    private function leaflet(AnvisaMedicine $medicine): void
    {
        $source = KnowledgeSource::query()->firstOrCreate(['name' => 'ANVISA', 'organization' => null], ['type' => 'document', 'active' => true]);
        $document = KnowledgeDocument::query()->create(['source_id' => $source->id, 'external_key' => uniqid('d_'), 'title' => $medicine->product_name.' — Bula', 'document_type' => 'bula_paciente', 'language' => 'pt-BR', 'metadata' => [], 'content_status' => 'READY', 'file_status' => 'READY', 'embedding_status' => 'PENDING']);
        $document->chunks()->create(['chunk_index' => 0, 'section' => 'INTERAÇÕES', 'page_start' => 3, 'page_end' => 3, 'content' => 'Este medicamento pode interagir com outros remédios; informe o médico sobre tudo o que usa.', 'metadata' => []]);
        DB::table('knowledge_document_ingredients')->insert(['document_id' => $document->id, 'ingredient_id' => $medicine->ingredients()->value('active_ingredients.id'), 'method' => 'registration', 'confidence' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function majorBetween(AnvisaMedicine $a, AnvisaMedicine $b): void
    {
        DrugInteraction::factory()->create([
            'drug_a_ingredient_id' => $a->ingredients()->value('active_ingredients.id'),
            'drug_b_ingredient_id' => $b->ingredients()->value('active_ingredients.id'),
            'severity' => 'major',
        ]);
    }

    private function prompts(): string
    {
        return implode("\n", $this->prompts);
    }

    /** @param list<string> $named */
    private function ask(string $question, array $named): array
    {
        $this->interpretation = ['medicines' => $named, 'follow_up' => false, 'question' => $question, 'intent' => 'DRUG_INTERACTION', 'presentation' => null, 'urgency' => null];

        return app(MedicationQuestionService::class)->askFreeform(User::factory()->create(), $question);
    }

    public function test_a_major_pair_asked_by_brand_names_in_either_order_reaches_the_prompt_and_the_limitations(): void
    {
        $norvasc = $this->medicine('NORVASC', 'ANLODIPINO');
        $tegretol = $this->medicine('TEGRETOL', 'CARBAMAZEPINA');
        $this->majorBetween($norvasc, $tegretol);
        $this->leaflet($norvasc);
        $this->leaflet($tegretol);

        foreach ([['Posso tomar Norvasc junto com Tegretol?', ['Norvasc', 'Tegretol']], ['Posso tomar Tegretol junto com Norvasc?', ['Tegretol', 'Norvasc']]] as [$question, $named]) {
            $this->prompts = [];
            $result = $this->ask($question, $named);

            $this->assertSame('major', $result['pair_interactions'][0]['severity']);
            $this->assertStringContainsString('registra interação de gravidade maior', $this->prompts());
            $this->assertStringNotContainsString('não registra interação', $this->prompts());
            $this->assertContains('A base de interações (DDInter) registra interação de gravidade maior entre '.$result['pair_interactions'][0]['a'].' e '.$result['pair_interactions'][0]['b'].'.', $result['limitations']);
        }
    }

    public function test_a_medicine_without_an_ingredient_link_makes_the_pair_unverified_and_never_no_interaction(): void
    {
        $this->medicine('ANLODIPINO BESILATO', 'BESILATO DE ANLODIPINO', linked: false);
        $this->leaflet($this->medicine('TEGRETOL', 'CARBAMAZEPINA'));

        $result = $this->ask('Posso tomar anlodipino junto com Tegretol?', ['anlodipino', 'Tegretol']);

        $this->assertSame('unverified', $result['pair_interactions'][0]['severity']);
        $this->assertStringContainsString('não foi verificada', $this->prompts());
        $this->assertStringNotContainsString('não registra interação', $this->prompts());
        $this->assertTrue(collect($result['limitations'])->contains(fn (string $line): bool => str_contains($line, 'Não foi possível verificar a interação')));
    }

    public function test_a_named_medicine_the_detection_did_not_resolve_is_declared_and_the_pair_is_not_reported_as_checked(): void
    {
        $this->leaflet($this->medicine('ITRACONAZOL', 'ITRACONAZOL'));

        $result = $this->ask('Posso tomar anlodipino junto com itraconazol?', ['anlodipino', 'itraconazol']);

        $this->assertSame(['anlodipino'], $result['retrieval']['unresolved_names']);
        $this->assertSame([], $result['pair_interactions']);
        $this->assertStringContainsString('Não foi possível identificar no catálogo: anlodipino', $this->prompts());
        $this->assertStringNotContainsString('não registra interação', $this->prompts());
        $this->assertTrue(collect($result['limitations'])->contains(fn (string $line): bool => str_contains($line, 'Não consegui identificar no catálogo: anlodipino')));
    }

    /** Produção, 12/11/2026: "anlodipino" sumia porque o catálogo grafa o mesmo fármaco de formas incoerentes (forma real dos registros). */
    public function test_anlodipino_with_carbamazepine_reaches_the_major_interaction_despite_inconsistent_catalog_spellings(): void
    {
        $amlodipine = ActiveIngredient::factory()->create(['canonical_name' => 'Amlodipine', 'normalized_name' => 'amlodipine']);
        $catalog = function (string $name, ?string $active, string $status, bool $linked) use ($amlodipine): AnvisaMedicine {
            $medicine = AnvisaMedicine::factory()->create(['product_name' => $name, 'normalized_product_name' => mb_strtolower($name), 'active_ingredient' => $active, 'normalized_active_ingredient' => $active === null ? null : mb_strtolower($active), 'registration_status' => $status]);
            if ($linked) {
                $medicine->ingredients()->attach($amlodipine->id, ['component_text' => (string) $active, 'method' => 'alias', 'confidence' => 1]);
            }

            return $medicine;
        };
        $generic = $catalog('BESILATO DE ANLODIPINO', 'BESILATO DE ANLODIPINO', 'Ativo', true);
        $catalog('AMLOCOR', 'BESILATO DE AMLODIPINA, BESILATO DE ANLODIPINO', 'Inativo', true);
        $catalog('ANLODIPINO BESILATO', null, 'Inativo', false);
        $catalog('ANLODIPINO 5 MG', 'BESILATO DE AMLODIPINA', 'Inativo', false);
        $catalog('LOTAR', 'BESILATO DE ANLODIPINO, losartana potássica', 'Ativo', true)->ingredients()->attach(ActiveIngredient::factory()->create(['canonical_name' => 'Losartan', 'normalized_name' => 'losartan'])->id, ['component_text' => 'losartana', 'method' => 'alias', 'confidence' => 1]);
        $carbamazepina = $this->medicine('CARBAMAZEPINA', 'CARBAMAZEPINA');
        $this->majorBetween($generic, $carbamazepina);
        $this->leaflet($generic);
        $this->leaflet($carbamazepina);

        $result = $this->ask('Posso tomar anlodipino junto com carbamazepina?', ['anlodipino', 'carbamazepina']);

        $this->assertSame([], $result['retrieval']['unresolved_names']);
        $this->assertSame(['BESILATO DE ANLODIPINO', 'CARBAMAZEPINA'], array_column($result['medicines'], 'name'));
        $this->assertSame('major', $result['pair_interactions'][0]['severity']);
        $this->assertStringContainsString('registra interação de gravidade maior', $this->prompts());
        $this->assertContains('A base de interações (DDInter) registra interação de gravidade maior entre BESILATO DE ANLODIPINO e CARBAMAZEPINA.', $result['limitations']);
    }

    /** Auditoria de 16/11/2026, D1: dipirona não consta do DDInter; o par não pode sair como "a base não registra interação". */
    public function test_a_drug_the_interaction_base_does_not_cover_makes_the_pair_unverified_instead_of_no_interaction(): void
    {
        $dipirona = $this->medicine('DIPIRONA', 'DIPIRONA');
        $varfarina = $this->medicine('MAREVAN', 'VARFARINA');
        $this->leaflet($dipirona);
        $this->leaflet($varfarina);
        $outro = $this->medicine('ASPIRINA', 'ACIDO ACETILSALICILICO');
        $this->majorBetween($varfarina, $outro);

        $result = $this->ask('Dipirona com varfarina tem interação?', ['dipirona', 'varfarina']);

        $this->assertSame(['unverified', 'not_in_source', ['DIPIRONA']], [$result['pair_interactions'][0]['severity'], $result['pair_interactions'][0]['reason'], $result['pair_interactions'][0]['absent']]);
        $this->assertStringContainsString('DIPIRONA não consta da base de interações', $this->prompts());
        $this->assertStringNotContainsString('não registra interação', $this->prompts());
        $this->assertTrue(collect($result['limitations'])->contains(fn (string $line): bool => str_contains($line, 'DIPIRONA não consta da base de interações (DDInter)')));
    }

    /** Auditoria de 16/11/2026, D2/D3 e D7: a gravidade abre a resposta; a afirmação recusada fica gravada com o texto. */
    public function test_the_registered_severity_opens_the_answer_and_rejected_claims_keep_their_text(): void
    {
        $norvasc = $this->medicine('NORVASC', 'ANLODIPINO');
        $tegretol = $this->medicine('TEGRETOL', 'CARBAMAZEPINA');
        $this->majorBetween($norvasc, $tegretol);
        $this->leaflet($norvasc);
        $this->leaflet($tegretol);
        $this->answer = '{"coverage":"full","segments":[{"kind":"fact","text":"Este medicamento pode interagir com outros remédios.","evidence_ids":["ALL"]},{"kind":"fact","text":"O remédio cura a gripe em dois dias.","evidence_ids":["ALL"]}]}';

        $result = $this->ask('Posso tomar Norvasc junto com Tegretol?', ['Norvasc', 'Tegretol']);

        $this->assertStringStartsWith('Atenção: a base de interações (DDInter) registra interação de gravidade maior entre NORVASC e TEGRETOL.', (string) $result['answer']);
        $this->assertStringContainsString('Este medicamento pode interagir com outros remédios.', (string) $result['answer']);
        $rejected = collect($result['generation']['rejected'] ?? [])->firstWhere('text', 'O remédio cura a gripe em dois dias.');
        $this->assertNotNull($rejected, 'a afirmação recusada fica gravada com o texto');
        $this->assertSame('not_grounded_in_cited_evidence', $rejected['reason']);

        $this->answer = '{"coverage":"full","segments":[{"kind":"fact","text":"Existe registro de interação de gravidade maior entre os dois.","evidence_ids":["T1"]},{"kind":"fact","text":"Este medicamento pode interagir com outros remédios.","evidence_ids":["ALL"]}]}';
        $again = $this->ask('Posso tomar Norvasc junto com Tegretol?', ['Norvasc', 'Tegretol']);
        $this->assertStringNotContainsString('Atenção: a base de interações', (string) $again['answer'], 'o texto do modelo já informa a gravidade: não repete');
    }

    public function test_a_pair_with_no_registered_interaction_keeps_the_honest_absence_wording_and_no_extra_limitation(): void
    {
        $dorflex = $this->medicine('DORFLEX', 'ORFENADRINA');
        $losartec = $this->medicine('LOSARTEC', 'LOSARTANA');
        $this->leaflet($dorflex);
        $this->leaflet($losartec);
        // Os dois fármacos constam do DDInter (com outros remédios); só não há registro entre eles.
        $outro = $this->medicine('ASPIRINA', 'ACIDO ACETILSALICILICO');
        $this->majorBetween($dorflex, $outro);
        $this->majorBetween($losartec, $outro);

        $result = $this->ask('Posso tomar Dorflex junto com Losartec?', ['Dorflex', 'Losartec']);

        $this->assertNull($result['pair_interactions'][0]['severity']);
        $this->assertStringContainsString('não registra interação', $this->prompts());
        $this->assertStringContainsString('não comprova que a combinação seja segura', $this->prompts());
        $this->assertSame([], $result['retrieval']['unresolved_names']);
        $this->assertFalse(collect($result['limitations'])->contains(fn (string $line): bool => str_contains($line, 'DDInter')));
    }
}
