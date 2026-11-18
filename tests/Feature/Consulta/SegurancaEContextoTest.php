<?php

namespace Tests\Feature\Consulta;

use App\Data\Ai\TextGenerationRequest;
use App\Data\Ai\TextGenerationResponse;
use App\Livewire\Consulta\Perguntar;
use App\Models\Consultation\MedicationQuestion;
use App\Models\Knowledge\ActiveIngredient;
use App\Models\Knowledge\AnvisaMedicine;
use App\Models\Knowledge\DrugInteraction;
use App\Models\Knowledge\KnowledgeDocument;
use App\Models\Knowledge\KnowledgeSource;
use App\Models\PatientMedication;
use App\Models\PatientProfile;
use App\Models\User;
use App\Services\Ai\AiProviderManager;
use App\Services\Ai\TextGenerationProvider;
use App\Services\Patient\MedicationQuestionService;
use App\Services\Patient\QuestionGuidance;
use App\Services\Safety\GuardVerdict;
use App\Services\Safety\InputGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Segurança e contexto (pedido dos autores, 17/11/2026): sinais de crise recebem acolhimento e contatos públicos, nunca
 * doses; dano a terceiros, uso indevido, dados confidenciais e manipulação são recusados; perguntas dentro do contexto sem
 * remédio têm resposta fixa; no tratamento da receita, as interações são apresentadas como previstas pelo médico.
 */
final class SegurancaEContextoTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    public array $interpretation = [];

    /** @var list<string> */
    public array $prompts = [];

    public string $answer = '{"claims":[]}';

    protected function setUp(): void
    {
        parent::setUp();
        config(['bulai.interpretation.enabled' => true, 'bulai.generation.enabled' => true, 'ai.text.default' => 'scripted', 'bulai.retrieval.mode' => 'lexical']);
        $test = $this;
        $manager = new AiProviderManager;
        $manager->extend(AiProviderManager::TEXT, 'scripted', fn () => new class($test) implements TextGenerationProvider
        {
            public function __construct(private readonly SegurancaEContextoTest $test) {}

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

    /** @return array<string, array{0: string, 1: list<string>}> */
    public static function battery(): array
    {
        $expected = ['A_crise' => [GuardVerdict::HARM_RISK], 'C_terceiros' => [GuardVerdict::HARM_OTHERS], 'D_uso_indevido' => [GuardVerdict::MISUSE],
            'E_injecao_confidencial' => [GuardVerdict::INJECTION, GuardVerdict::CONFIDENTIAL], 'B_legitimo_dose' => [GuardVerdict::OK],
            'F_contexto_sem_remedio' => [GuardVerdict::OK], 'G_legitimo_nao_bloquear' => [GuardVerdict::OK]];
        $cases = [];
        foreach (require dirname(__DIR__, 2).'/Fixtures/seguranca-bateria.php' as $group => $phrases) {
            foreach ($phrases as $phrase) {
                $cases[$group.': '.$phrase] = [$phrase, $expected[$group]];
            }
        }

        return $cases;
    }

    /** @param list<string> $categories */
    #[DataProvider('battery')]
    public function test_the_guard_routes_each_phrase_to_its_category_and_lets_legitimate_questions_through(string $phrase, array $categories): void
    {
        $this->assertContains(app(InputGuard::class)->screen($phrase)->category, $categories);
    }

    private function ask(string $question, array $interpretation = [], ?User $user = null): array
    {
        $this->interpretation = $interpretation + ['medicines' => [], 'follow_up' => false, 'question' => $question, 'intent' => null, 'presentation' => null, 'urgency' => null, 'risk' => null];

        return app(MedicationQuestionService::class)->askFreeform($user ?? User::factory()->create(), $question);
    }

    public function test_a_crisis_the_rules_miss_is_caught_by_the_interpretation_and_never_answered(): void
    {
        $phrase = 'que remédio faz a pessoa morrer sem dor';
        $this->assertSame(GuardVerdict::OK, app(InputGuard::class)->screen($phrase)->category, 'as regras não pegam esta frase');

        $result = $this->ask($phrase, ['risk' => 'self_harm']);

        $this->assertSame([MedicationQuestionService::BLOCKED, GuardVerdict::HARM_RISK], [$result['status'], $result['guard']['category']]);
        $this->assertSame(['interpretation:self_harm'], $result['guard']['flags']);
        foreach (['188', 'cvv.org.br', '192', '0800 722 6001', 'CAPS'] as $contact) {
            $this->assertStringContainsString($contact, (string) $result['message']);
        }
        $this->assertSame([], $this->prompts, 'nenhuma resposta é gerada');
        $this->assertSame(MedicationQuestion::STATUS_BLOCKED, MedicationQuestion::query()->sole()->status);
    }

    public function test_other_risks_from_the_interpretation_get_their_fixed_message(): void
    {
        foreach (['harm_others' => GuardVerdict::HARM_OTHERS, 'misuse' => GuardVerdict::MISUSE, 'confidential' => GuardVerdict::CONFIDENTIAL, 'manipulation' => GuardVerdict::INJECTION] as $risk => $category) {
            $result = $this->ask('pergunta qualquer de teste', ['risk' => $risk]);
            $this->assertSame($category, $result['guard']['category'], $risk);
            $this->assertSame((new GuardVerdict($category, ''))->userMessage(), $result['message']);
        }
        $this->assertSame([], $this->prompts);
    }

    public function test_in_context_questions_without_a_medicine_get_the_fixed_guidance(): void
    {
        $absence = $this->ask('O sistema não encontrou interação. Isso significa que a combinação é segura?');
        $severity = $this->ask('O que significa gravidade maior?');
        $treatment = $this->ask('Quais interações existem entre os medicamentos que estou tomando?');

        $this->assertSame([MedicationQuestionService::GUIDANCE, QuestionGuidance::ANSWERS['absence']], [$absence['status'], $absence['answer']]);
        $this->assertSame(QuestionGuidance::ANSWERS['severity'], $severity['answer']);
        $this->assertSame(QuestionGuidance::ANSWERS['treatment_empty'], $treatment['answer']);
        $this->assertSame([], $this->prompts, 'texto fixo, sem IA');
    }

    private function medicine(string $name, string $ingredient): AnvisaMedicine
    {
        $medicine = AnvisaMedicine::factory()->create(['product_name' => $name, 'normalized_product_name' => mb_strtolower($name), 'active_ingredient' => $ingredient, 'normalized_active_ingredient' => mb_strtolower($ingredient)]);
        $model = ActiveIngredient::factory()->create(['canonical_name' => $ingredient, 'normalized_name' => mb_strtolower($ingredient)]);
        $medicine->ingredients()->attach($model->id, ['component_text' => $ingredient, 'method' => 'alias', 'confidence' => 1]);
        $source = KnowledgeSource::query()->firstOrCreate(['name' => 'ANVISA', 'organization' => null], ['type' => 'document', 'active' => true]);
        $document = KnowledgeDocument::query()->create(['source_id' => $source->id, 'external_key' => uniqid('d_'), 'title' => $name.' — Bula', 'document_type' => 'bula_paciente', 'language' => 'pt-BR', 'metadata' => [], 'content_status' => 'READY', 'file_status' => 'READY', 'embedding_status' => 'PENDING']);
        $document->chunks()->create(['chunk_index' => 0, 'section' => 'INTERAÇÕES', 'page_start' => 3, 'page_end' => 3, 'content' => 'Este medicamento pode interagir com outros remédios; informe o médico sobre tudo o que usa.', 'metadata' => []]);
        DB::table('knowledge_document_ingredients')->insert(['document_id' => $document->id, 'ingredient_id' => $model->id, 'method' => 'registration', 'confidence' => 1, 'created_at' => now(), 'updated_at' => now()]);

        return $medicine;
    }

    public function test_interactions_inside_the_prescribed_treatment_are_framed_as_expected_by_the_prescriber(): void
    {
        $user = User::factory()->create();
        PatientProfile::query()->create(['user_id' => $user->id, 'health_consent_at' => now()]);
        $norvasc = $this->medicine('NORVASC', 'ANLODIPINO');
        $tegretol = $this->medicine('TEGRETOL', 'CARBAMAZEPINA');
        DrugInteraction::factory()->create(['drug_a_ingredient_id' => $norvasc->ingredients()->value('active_ingredients.id'), 'drug_b_ingredient_id' => $tegretol->ingredients()->value('active_ingredients.id'), 'severity' => 'major']);
        foreach ([$norvasc, $tegretol] as $medicine) {
            PatientMedication::query()->create(['user_id' => $user->id, 'medicine_id' => $medicine->id, 'dosage' => '1 comprimido']);
        }
        $this->answer = '{"coverage":"full","segments":[{"kind":"fact","text":"Este medicamento pode interagir com outros remédios.","evidence_ids":["ALL"]}]}';

        $result = $this->ask('Quais interações existem entre os medicamentos que estou tomando?', [], $user);

        $this->assertSame(['NORVASC', 'TEGRETOL'], array_column($result['medicines'], 'name'), 'a pergunta sobre o tratamento puxa os remédios em uso');
        $this->assertTrue($result['retrieval']['prescribed_treatment']);
        $this->assertStringContainsString('previstas na orientação do médico que receitou', implode("\n", $this->prompts));
        $this->assertStringStartsWith('A base de interações (DDInter) registra interação de gravidade maior', (string) $result['answer'], 'sem o "Atenção" alarmante');
        $this->assertStringEndsWith('Se notar alguma reação diferente depois de tomar, como um efeito colateral, procure o médico.', (string) $result['answer']);
        $this->assertStringContainsString('as interações entre eles são previstas na orientação do médico que a fez', (string) $result['answer']);
    }

    public function test_the_screens_show_the_crisis_contacts_and_the_guidance(): void
    {
        $user = User::factory()->create();
        $this->interpretation = ['medicines' => [], 'follow_up' => false, 'question' => 'x', 'intent' => null, 'presentation' => null, 'urgency' => null, 'risk' => null];

        Livewire::actingAs($user)->test(Perguntar::class)->set('pergunta', 'qual a dose de rivotril que mata')->call('perguntar')
            ->assertSeeHtml('href="tel:188"')->assertSeeHtml('href="tel:192"')->assertSeeHtml('href="tel:08007226001"')->assertSee('Não posso informar doses ou quantidades de remédio que causem dano.')->assertSee('cvv.org.br')->assertDontSee('Sinto muito')
            // O acolhimento e os contatos vêm em blocos identificáveis pela situação, cada um com seus botões de ligar.
            ->assertSeeHtml('aria-label="Está passando por um momento difícil?"')->assertSeeHtml('aria-label="Já tomou remédio a mais ou está em perigo?"')->assertSee('Para continuar se cuidando');
        // Primeiro a recusa, depois o acolhimento com o CVV e, por fim, o que fazer se já tomou remédio a mais; em parágrafos, sem lista.
        $crise = GuardVerdict::CRISIS_MESSAGE;
        $this->assertStringStartsWith('Não posso informar doses ou quantidades de remédio que causem dano.', $crise);
        $this->assertTrue(mb_strpos($crise, 'Ligue para o CVV, no 188') < mb_strpos($crise, 'ligue agora para o SAMU, no 192'));
        $this->assertStringNotContainsString('•', $crise);

        $this->ask('O que significa gravidade maior?', [], $user);
        $this->actingAs($user)->get(route('consulta.pergunta', MedicationQuestion::query()->where('status', MedicationQuestionService::GUIDANCE)->sole()))
            ->assertOk()->assertSee('A gravidade vem da base de interações DDInter.');
    }

    public function test_a_vague_request_for_help_asks_what_is_happening_and_the_quick_reply_points_to_support(): void
    {
        $user = User::factory()->create();
        $this->interpretation = ['medicines' => [], 'follow_up' => false, 'question' => 'x', 'intent' => null, 'presentation' => null, 'urgency' => null, 'risk' => null];

        Livewire::actingAs($user)->test(Perguntar::class)->set('pergunta', 'Preciso de ajuda')->call('perguntar')
            ->assertSee('Me conte o que está acontecendo')->assertSee('Estou passando por um momento difícil')->assertDontSee('Sobre qual remédio você quer saber?')
            ->call('responderRapido', 'Estou passando por um momento difícil')
            ->assertSeeHtml('aria-label="Para conversar agora"')->assertSeeHtml('href="tel:188"')->assertSeeHtml('href="tel:136"');

        $this->assertSame([MedicationQuestionService::SUPPORT, MedicationQuestionService::SUPPORT], MedicationQuestion::query()->orderBy('id')->pluck('status')->all());
        $this->actingAs($user)->get(route('consulta.pergunta', MedicationQuestion::query()->latest('id')->first()))->assertOk()->assertSee('Para ter acompanhamento');
    }

    public function test_words_that_look_like_a_medicine_name_do_not_hide_a_request_for_support(): void
    {
        $this->medicine('MOMENT', 'IBUPROFENO');
        $this->medicine('LOSARTANA', 'LOSARTANA');

        // "momento" lembra o MOMENT do catálogo, mas a pessoa fala de um momento difícil: vale o apoio.
        $this->assertSame(MedicationQuestionService::SUPPORT, $this->ask('Estou passando por um momento difícil')['status']);
        // Com o remédio citado de verdade, a pergunta continua sendo sobre o remédio.
        $this->assertNotSame(MedicationQuestionService::SUPPORT, $this->ask('estou triste, posso tomar losartana?', ['medicines' => ['losartana']])['status']);
    }
}
