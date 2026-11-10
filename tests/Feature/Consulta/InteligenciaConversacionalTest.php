<?php

namespace Tests\Feature\Consulta;

use App\Data\Ai\TextGenerationRequest;
use App\Data\Ai\TextGenerationResponse;
use App\Data\Knowledge\ExtractedPage;
use App\Data\Knowledge\ExtractedPdf;
use App\Data\Knowledge\ExtractionQuality;
use App\Models\Consultation\MedicationQuestion;
use App\Models\Knowledge\ActiveIngredient;
use App\Models\Knowledge\AnvisaMedicine;
use App\Models\Knowledge\DrugInteraction;
use App\Models\Knowledge\KnowledgeDocument;
use App\Models\Knowledge\KnowledgeLeafletFact;
use App\Models\Knowledge\KnowledgeSource;
use App\Models\PatientMedication;
use App\Models\PatientProfile;
use App\Models\User;
use App\Services\Ai\AiProviderException;
use App\Services\Ai\AiProviderManager;
use App\Services\Ai\TextGenerationProvider;
use App\Services\Interactions\ExplanationValidator;
use App\Services\Knowledge\DocumentExtractionQualityEvaluator;
use App\Services\Knowledge\DocumentOcrClient;
use App\Services\Knowledge\LeafletEvidenceService;
use App\Services\Knowledge\PdfTextExtractor;
use App\Services\Patient\MedicationQuestionService;
use App\Services\Patient\QuestionIntentDetector;
use App\Services\Patient\UrgencyDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Correção P0 do Perguntar (docs/ai/perguntar-inteligencia-conversacional.md): a intenção da pergunta decide o que se busca
 * e como se responde ("serve para dor de cabeça?" é indicação, não efeito adverso), a resposta fala como a bula humanizada,
 * o perfil só aparece quando há achado, e o PDF original é relido quando o texto indexado não representa a bula inteira.
 * O provedor de texto é roteirizado: aqui se verifica o que o sistema busca, envia, valida, grava e mostra.
 */
final class InteligenciaConversacionalTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> respostas consumidas em ordem; esgotada a fila vale `$scripted` */
    public array $queue = [];

    public string $scripted = '{"coverage":"none","segments":[]}';

    public bool $failing = false;

    /** @var list<array{prompt: string, system: string|null, purpose: string|null}> */
    public array $requests = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['bulai.generation.enabled' => true, 'ai.text.default' => 'scripted', 'bulai.retrieval.mode' => 'lexical']);
        $test = $this;
        $manager = new AiProviderManager;
        $manager->extend(AiProviderManager::TEXT, 'scripted', fn () => new class($test) implements TextGenerationProvider
        {
            public function __construct(private readonly InteligenciaConversacionalTest $test) {}

            public function generate(TextGenerationRequest $request): TextGenerationResponse
            {
                $this->test->requests[] = ['prompt' => $request->prompt, 'system' => $request->system, 'purpose' => $request->metadata['purpose'] ?? null];
                if ($this->test->failing) {
                    throw new AiProviderException('provider down', 'PROVIDER_UNAVAILABLE');
                }
                $content = $this->test->queue !== [] ? array_shift($this->test->queue) : $this->test->scripted;
                preg_match_all('/<evidencia id="([^"]+)"/', $request->prompt, $ids);
                $content = str_replace('["ALL"]', (string) json_encode(array_values(array_diff($ids[1], ['T1']))), $content);

                return new TextGenerationResponse($content, 'scripted', null);
            }
        });
        $this->app->instance(AiProviderManager::class, $manager);
    }

    private function medicine(string $name, string $ingredient): AnvisaMedicine
    {
        $medicine = AnvisaMedicine::factory()->create(['product_name' => $name, 'normalized_product_name' => mb_strtolower($name), 'active_ingredient' => $ingredient, 'normalized_active_ingredient' => mb_strtolower($ingredient)]);
        $existing = ActiveIngredient::query()->where('normalized_name', mb_strtolower($ingredient))->first();
        $medicine->ingredients()->attach(($existing ?? ActiveIngredient::factory()->create(['canonical_name' => $ingredient, 'normalized_name' => mb_strtolower($ingredient)]))->id, ['component_text' => $ingredient, 'method' => 'alias', 'confidence' => 1]);

        return $medicine;
    }

    /**
     * @param  array<string, string>  $sections  seção => texto (página = posição + 2)
     * @param  array<string, mixed>  $attributes
     */
    private function leaflet(AnvisaMedicine $medicine, array $sections, array $attributes = []): KnowledgeDocument
    {
        $source = KnowledgeSource::query()->firstOrCreate(['name' => 'ANVISA', 'organization' => null], ['type' => 'document', 'active' => true]);
        $document = KnowledgeDocument::query()->create($attributes + ['source_id' => $source->id, 'external_key' => uniqid('d_'), 'title' => $medicine->product_name.' — Bula', 'document_type' => 'bula_paciente', 'language' => 'pt-BR', 'metadata' => [], 'content_status' => 'READY', 'file_status' => 'READY', 'embedding_status' => 'PENDING', 'content_hash' => 'h-'.uniqid(), 'sha256' => hash('sha256', uniqid('pdf', true))]);
        $index = 0;
        foreach ($sections as $section => $content) {
            $document->chunks()->create(['chunk_index' => $index, 'section' => $section, 'page_start' => $index + 2, 'page_end' => $index + 2, 'content' => $content, 'metadata' => []]);
            $index++;
        }
        DB::table('knowledge_document_ingredients')->insert(['document_id' => $document->id, 'ingredient_id' => $medicine->ingredients()->value('active_ingredients.id'), 'method' => 'registration', 'confidence' => 1, 'created_at' => now(), 'updated_at' => now()]);

        return $document;
    }

    private function dipirona(): AnvisaMedicine
    {
        $medicine = $this->medicine('DIPIRONA', 'DIPIRONA');
        $this->leaflet($medicine, [
            'PARA QUE ESTE MEDICAMENTO É INDICADO?' => 'A dipirona é indicada como analgésico, para o alívio da dor, inclusive dor de cabeça, e como antitérmico, para reduzir a febre.',
            'QUANDO NÃO DEVO USAR ESTE MEDICAMENTO?' => 'A dipirona não deve ser usada por pessoas com alergia à dipirona ou a outros analgésicos, nem por quem tem problemas na produção das células do sangue.',
            'O QUE DEVO SABER ANTES DE USAR ESTE MEDICAMENTO?' => 'Antes de usar a dipirona informe ao médico se você tem pressão baixa, doença nos rins ou no fígado.',
            'COMO DEVO USAR ESTE MEDICAMENTO?' => 'Adultos e adolescentes acima de 15 anos: 20 a 40 gotas da solução oral de dipirona, até 4 vezes ao dia.',
            'QUAIS OS MALES QUE ESTE MEDICAMENTO PODE ME CAUSAR?' => 'A dipirona pode causar reações alérgicas na pele e queda da pressão arterial; reações graves no sangue são raras.',
            'O QUE FAZER SE ALGUÉM USAR UMA QUANTIDADE MAIOR DO QUE A INDICADA DESTE MEDICAMENTO?' => 'Em caso de superdose da dipirona podem ocorrer náuseas, vômitos e sonolência; procure socorro médico imediatamente.',
        ]);

        return $medicine;
    }

    /** @param list<array{kind: string, text: string, evidence_ids?: list<string>}> $segments */
    private function reply(string $coverage, array $segments): string
    {
        return (string) json_encode(['coverage' => $coverage, 'segments' => $segments]);
    }

    /** @param list<array<string, mixed>> $conversation */
    private function ask(User $user, string $question, array $conversation = []): array
    {
        return app(MedicationQuestionService::class)->askFreeform($user, $question, [], $conversation);
    }

    /** @return list<string> */
    private function sections(array $result): array
    {
        return collect($result['evidences'])->reject(fn (array $e): bool => in_array($e['document_type'] ?? null, ['treatment_context', 'conversation_context'], true))->pluck('section')->filter()->unique()->values()->all();
    }

    private function generationPrompt(): string
    {
        return (string) collect($this->requests)->firstWhere('purpose', 'medication_question')['prompt'];
    }

    /** @return array<string, array{0: string, 1: string, 2: list<string>, 3: list<string>}> */
    public static function questions(): array
    {
        return [
            'indicação' => ['Dipirona serve para dor de cabeça?', 'INDICATION', ['INDICATION'], ['para_que_serve']],
            'grafia errada' => ['Diporona serve para dor de cabeça?', 'INDICATION', ['INDICATION'], ['para_que_serve']],
            'uso pessoal com finalidade' => ['Posso tomar dipirona para dor de cabeça?', 'PERSONAL_USE', ['PERSONAL_USE', 'INDICATION'], ['para_que_serve', 'nao_use_se']],
            'febre' => ['Dipirona baixa febre?', 'INDICATION', ['INDICATION'], ['para_que_serve']],
            'efeitos colaterais' => ['Quais os efeitos colaterais da dipirona?', 'ADVERSE_EFFECT', ['ADVERSE_EFFECT'], ['efeitos_colaterais']],
            'interação' => ['Dipirona interage com acetilcisteína?', 'DRUG_INTERACTION', ['DRUG_INTERACTION'], ['interacoes']],
            'dose' => ['Quantas gotas posso tomar?', 'DOSAGE', ['DOSAGE'], ['como_usar']],
            'alergia' => ['Tenho alergia à dipirona, posso tomar?', 'CONTRAINDICATION', ['CONTRAINDICATION', 'PERSONAL_USE'], ['nao_use_se']],
            'superdose' => ['Tomei uma quantidade excessiva de dipirona', 'EMERGENCY', ['EMERGENCY'], ['procure_ajuda']],
            'outro remédio' => ['E se eu tomar junto com outro remédio?', 'DRUG_INTERACTION', ['DRUG_INTERACTION'], ['interacoes']],
            'sintoma causado' => ['Dipirona dá sono?', 'ADVERSE_EFFECT', ['ADVERSE_EFFECT'], ['efeitos_colaterais']],
        ];
    }

    /** @param list<string> $intents @param list<string> $sections */
    #[DataProvider('questions')]
    public function test_each_question_gets_its_own_intent_and_only_the_matching_leaflet_sections(string $question, string $primary, array $intents, array $sections): void
    {
        $detector = app(QuestionIntentDetector::class);
        $plan = $detector->plan($question);
        $canonical = $detector->canonical($plan['topics'], false, app(UrgencyDetector::class)->detect($question) !== null);

        $this->assertSame($primary, $canonical['primary']);
        $this->assertSame($intents, $canonical['intents']);
        $this->assertSame($sections, $plan['sections']);
    }

    public function test_a_symptom_to_treat_is_never_read_as_an_adverse_effect(): void
    {
        $detector = app(QuestionIntentDetector::class);

        $this->assertSame(['symptom' => 'dor de cabeca', 'role' => 'target'], $detector->symptom('Posso tomar dipirona para dor de cabeça?'));
        $this->assertSame('target', $detector->symptom('Estou com febre, posso tomar dipirona?')['role']);
        $this->assertSame('adverse', $detector->symptom('A dipirona me deu dor de cabeça')['role']);
        $this->assertSame('adverse', $detector->symptom('Fiquei com tontura depois de tomar dipirona')['role']);
        $this->assertSame('gotas', $detector->presentation('Quantas gotas posso tomar?'));
        $this->assertTrue($detector->mentionsUnnamedMedicine('E se eu tomar junto com outro remédio?'));
    }

    public function test_an_indication_question_is_answered_directly_without_risks_limits_or_questions(): void
    {
        $this->dipirona();
        $this->scripted = $this->reply('full', [
            ['kind' => 'fact', 'text' => 'Segundo a bula, a dipirona é um analgésico que alivia a dor, inclusive a dor de cabeça.', 'evidence_ids' => ['ALL']],
            ['kind' => 'limit', 'text' => 'Para saber se você pode tomar, fale com um farmacêutico.'],
            ['kind' => 'ask', 'text' => 'Você já teve alergia à dipirona?'],
        ]);

        $result = $this->ask(User::factory()->create(), 'Dipirona serve para dor de cabeça?');

        $this->assertSame(MedicationQuestionService::ANSWERED, $result['status']);
        $this->assertSame('A dipirona é um analgésico que alivia a dor, inclusive a dor de cabeça.', $result['answer'], 'resposta direta, na voz da bula humanizada, sem ressalva nem pergunta');
        $this->assertSame(['PARA QUE ESTE MEDICAMENTO É INDICADO?'], $this->sections($result));
        $prompt = $this->generationPrompt();
        $this->assertStringContainsString('Intenção principal: INDICATION', $prompt);
        $this->assertStringContainsString('Não fale de contraindicações, efeitos colaterais, superdose', $prompt);
        $this->assertStringNotContainsString('QUAIS OS MALES', $prompt, 'efeitos adversos não são buscados para uma pergunta de indicação');
        $this->assertStringNotContainsString('QUANTIDADE MAIOR', $prompt, 'o título da superdose ("…a indicada…") não passa por seção de indicação');
        $this->assertEquals(['limit_not_needed' => 1, 'asks_disabled' => 1], $result['generation']['rejection_reasons']);
        $this->assertSame(['INDICATION'], $result['retrieval']['classification']['intents']);
        $this->assertSame('sufficient', $result['retrieval']['sufficiency']['verdict']);
    }

    public function test_a_misspelled_name_is_corrected_and_answered_as_an_indication(): void
    {
        $this->dipirona();
        $this->scripted = $this->reply('full', [['kind' => 'fact', 'text' => 'A dipirona alivia a dor, inclusive a dor de cabeça, e reduz a febre.', 'evidence_ids' => ['ALL']]]);

        $result = $this->ask(User::factory()->create(), 'Diporona serve para dor de cabeça?');

        $this->assertSame(['DIPIRONA', true], [$result['medicines'][0]['name'], $result['medicines'][0]['fuzzy']]);
        $this->assertSame([MedicationQuestionService::ANSWERED, ['para_que_serve']], [$result['status'], $result['intent']]);
        $this->assertSame(['PARA QUE ESTE MEDICAMENTO É INDICADO?'], $this->sections($result));
    }

    public function test_personal_use_with_a_purpose_answers_the_purpose_first_with_one_proportional_caution(): void
    {
        $this->dipirona();
        $this->scripted = $this->reply('full', [
            ['kind' => 'fact', 'text' => 'A dipirona é usada para aliviar dores, inclusive a dor de cabeça.', 'evidence_ids' => ['ALL']],
            ['kind' => 'limit', 'text' => 'Se ela é adequada para você depende de alergias, de outros remédios e da sua saúde.'],
            ['kind' => 'ask', 'text' => 'Você já teve alguma reação à dipirona?'],
        ]);

        $result = $this->ask(User::factory()->create(), 'Posso tomar dipirona para dor de cabeça?');

        $this->assertSame(['PARA QUE ESTE MEDICAMENTO É INDICADO?', 'QUANDO NÃO DEVO USAR ESTE MEDICAMENTO?'], $this->sections($result), 'indicação primeiro e só a seção de quando não usar; nada de riscos nem efeitos');
        $paragraphs = explode("\n\n", (string) $result['answer']);
        $this->assertStringStartsWith('A dipirona é usada para aliviar dores', $paragraphs[0]);
        $this->assertSame(['Você já teve alguma reação à dipirona?'], $result['follow_up']);
        $this->assertStringContainsString('Intenção principal: PERSONAL_USE', $this->generationPrompt());
        $this->assertStringContainsString('uma única frase de cautela proporcional', $this->generationPrompt());
        $this->assertStringContainsString('NUNCA conclui que é seguro', (string) $this->requests[0]['system'], 'a segurança individual continua no prompt');
    }

    public function test_adverse_effects_dosage_contraindication_and_emergency_retrieve_their_own_sections(): void
    {
        $this->dipirona();
        $user = User::factory()->create();
        $this->scripted = $this->reply('full', [['kind' => 'fact', 'text' => 'A dipirona pode causar reações alérgicas na pele e queda da pressão arterial.', 'evidence_ids' => ['ALL']]]);

        $this->assertSame(['QUAIS OS MALES QUE ESTE MEDICAMENTO PODE ME CAUSAR?'], $this->sections($this->ask($user, 'Quais os efeitos colaterais da dipirona?')));
        $this->scripted = $this->reply('full', [['kind' => 'fact', 'text' => 'A dipirona é um antitérmico: ela ajuda a reduzir a febre.', 'evidence_ids' => ['ALL']]]);
        $this->assertSame(['PARA QUE ESTE MEDICAMENTO É INDICADO?'], $this->sections($this->ask($user, 'Dipirona baixa febre?')));
        $this->scripted = $this->reply('full', [['kind' => 'fact', 'text' => 'A dipirona não deve ser usada por pessoas com alergia à dipirona ou a outros analgésicos.', 'evidence_ids' => ['ALL']]]);
        $this->assertSame(['QUANDO NÃO DEVO USAR ESTE MEDICAMENTO?'], $this->sections($this->ask($user, 'Tenho alergia à dipirona, posso tomar?')));

        $this->requests = [];
        $urgent = $this->ask($user, 'Tomei uma quantidade excessiva de dipirona');
        $this->assertSame(['overdose'], $urgent['urgency']['kinds']);
        $this->assertStringContainsString('0800 722 6001', $urgent['urgency']['message']);
        $this->assertSame('EMERGENCY', $urgent['retrieval']['classification']['primary']);
        $this->assertStringContainsString('possível urgência', $this->generationPrompt());
        $this->assertSame([], $urgent['follow_up']);
    }

    public function test_a_dosage_follow_up_keeps_the_medicine_uses_the_presentation_and_never_calculates_a_personal_dose(): void
    {
        $this->dipirona();
        $user = User::factory()->create();
        $first = $this->ask($user, 'Dipirona serve para dor?');
        $this->requests = [];
        $this->scripted = $this->reply('full', [
            ['kind' => 'fact', 'text' => 'Para adultos e adolescentes acima de 15 anos, a dose é de 20 a 40 gotas, até 4 vezes ao dia.', 'evidence_ids' => ['ALL']],
            ['kind' => 'ask', 'text' => 'A dose é para um adulto ou para uma criança?'],
        ]);

        $result = $this->ask($user, 'Quantas gotas posso tomar?', [['question' => 'Dipirona serve para dor?', 'answer' => $first['answer'], 'medicine_ids' => array_column($first['medicines'], 'id'), 'topics' => $first['intent']]]);

        $this->assertSame('conversa', $result['medicines'][0]['matched_text'], 'o remédio vem da conversa');
        $this->assertSame(['DOSAGE', 'FOLLOW_UP'], $result['retrieval']['classification']['intents']);
        $this->assertSame(['COMO DEVO USAR ESTE MEDICAMENTO?'], $this->sections($result));
        $prompt = $this->generationPrompt();
        $this->assertStringContainsString('apresentação "gotas"', $prompt);
        $this->assertStringContainsString('Não calcule dose individual', $prompt);
        $this->assertSame(['A dose é para um adulto ou para uma criança?'], $result['follow_up']);
    }

    public function test_an_unnamed_other_medicine_is_asked_for_without_reading_the_whole_leaflet_first(): void
    {
        $dipirona = $this->dipirona();
        $this->scripted = $this->reply('none', [['kind' => 'ask', 'text' => 'Qual é o outro remédio que você quer tomar junto com a dipirona?']]);

        $result = $this->ask(User::factory()->create(), 'E se eu tomar junto com outro remédio?', [['question' => 'Dipirona serve para dor?', 'answer' => 'A dipirona alivia a dor.', 'medicine_ids' => [$dipirona->id], 'topics' => ['para_que_serve']]]);

        $this->assertSame([MedicationQuestionService::NEEDS_INFO, ['Qual é o outro remédio que você quer tomar junto com a dipirona?']], [$result['status'], $result['follow_up']]);
        $this->assertTrue($result['retrieval']['classification']['unnamed_other_medicine']);
        $this->assertSame('medication_question', $this->requests[0]['purpose'], 'a pergunta vem antes de qualquer leitura da bula inteira');
        $this->assertStringContainsString('pergunte qual é', $this->requests[0]['prompt']);
    }

    public function test_an_interaction_question_reports_the_structured_result_without_treating_absence_as_safety(): void
    {
        $this->dipirona();
        $acetil = $this->medicine('ACETILCISTEINA', 'ACETILCISTEINA');
        $this->leaflet($acetil, ['INTERAÇÕES MEDICAMENTOSAS' => 'A acetilcisteína não deve ser misturada a outros medicamentos na mesma solução.']);
        $this->scripted = $this->reply('partial', [['kind' => 'fact', 'text' => 'A acetilcisteína não deve ser misturada a outros medicamentos na mesma solução.', 'evidence_ids' => ['ALL']]]);

        $result = $this->ask(User::factory()->create(), 'Dipirona interage com acetilcisteína?');

        $this->assertSame('DRUG_INTERACTION', $result['retrieval']['classification']['primary']);
        $this->assertStringContainsString('não registra interação entre DIPIRONA e ACETILCISTEINA; isso não comprova que a combinação seja segura', $this->generationPrompt());
        $this->assertStringContainsString('não prova que a combinação é segura', $this->generationPrompt());
        $this->assertStringContainsString('não liste interações com outros remédios que ela não citou', $this->generationPrompt(), 'a primeira frase trata do par perguntado (avaliação de 09/11, D2)');
    }

    /**
     * Avaliação em produção de 09/11/2026 (docs/qa/avaliacao-api-2026-11-09.md): "Tomei 10 comprimidos…" saiu sem a
     * orientação de socorro, porque o detector só conhecia quantidades por extenso logo após o verbo.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function overdoses(): array
    {
        return [
            'algarismo e de uma vez' => ['Tomei 10 comprimidos de dipirona de uma vez, o que eu faço?', 'overdose_quantidade'],
            'algarismo' => ['Engoli 3 comprimidos de dipirona', 'overdose_quantidade'],
            'caixa inteira' => ['Minha mãe tomou a caixa toda de dipirona', 'overdose_quantidade'],
            'criança' => ['Meu filho engoliu um comprimido de dipirona', 'ingestao_acidental'],
        ];
    }

    #[DataProvider('overdoses')]
    public function test_an_overdose_with_a_number_is_an_emergency_answered_with_the_poison_center_first(string $question, string $kind): void
    {
        $this->dipirona();
        $this->scripted = $this->reply('partial', [['kind' => 'fact', 'text' => 'Em caso de superdose da dipirona podem ocorrer náuseas, vômitos e sonolência.', 'evidence_ids' => ['ALL']]]);

        $result = $this->ask(User::factory()->create(), $question);

        $this->assertContains($kind, $result['urgency']['kinds']);
        $this->assertStringContainsString('0800 722 6001', $result['urgency']['message']);
        $this->assertSame('EMERGENCY', $result['retrieval']['classification']['primary']);
    }

    public function test_an_answer_built_on_the_overdose_section_always_carries_the_poison_center_even_when_the_question_was_not_flagged(): void
    {
        $this->dipirona();
        $this->scripted = $this->reply('full', [['kind' => 'fact', 'text' => 'Em caso de superdose da dipirona podem ocorrer náuseas, vômitos e sonolência.', 'evidence_ids' => ['ALL']]]);

        $result = $this->ask(User::factory()->create(), 'E se alguém usar dipirona em excesso?');

        $this->assertNull(app(UrgencyDetector::class)->detect('E se alguém usar dipirona em excesso?'), 'a pergunta em si não é reconhecida como urgência');
        $this->assertContains('O QUE FAZER SE ALGUÉM USAR UMA QUANTIDADE MAIOR DO QUE A INDICADA DESTE MEDICAMENTO?', $this->sections($result));
        $this->assertSame(['secao_superdose'], $result['urgency']['kinds']);
        $this->assertStringContainsString('0800 722 6001', $result['urgency']['message']);
    }

    public function test_when_the_first_fact_is_rejected_the_next_one_never_opens_with_a_loose_pronoun(): void
    {
        $this->dipirona();
        $this->scripted = $this->reply('full', [
            ['kind' => 'fact', 'text' => 'A dipirona cura infecções bacterianas resistentes em poucas horas.', 'evidence_ids' => ['ALL']],
            ['kind' => 'fact', 'text' => 'Ela é indicada como analgésico, para o alívio da dor, e como antitérmico, para reduzir a febre.', 'evidence_ids' => ['ALL']],
        ]);

        $result = $this->ask(User::factory()->create(), 'Dipirona é pra quê?');

        $this->assertStringStartsWith('A dipirona é indicada como analgésico', (string) $result['answer']);
        $this->assertSame(1, $result['generation']['rejection_reasons']['not_grounded_in_cited_evidence'] ?? null);
    }

    public function test_how_it_is_used_is_a_dosage_question(): void
    {
        $this->assertSame(['como_usar'], app(QuestionIntentDetector::class)->topics('Como se usa a dipirona?'));
    }

    public function test_a_combination_found_for_a_single_named_ingredient_is_announced_in_the_answer(): void
    {
        $combination = AnvisaMedicine::factory()->create(['product_name' => 'LOSARTANA POTÁSSICA + HIDROCLOROTIAZIDA', 'normalized_product_name' => 'losartana potassica + hidroclorotiazida', 'active_ingredient' => 'LOSARTANA POTÁSSICA + HIDROCLOROTIAZIDA', 'normalized_active_ingredient' => 'losartana potassica + hidroclorotiazida']);
        foreach (['LOSARTANA POTÁSSICA', 'HIDROCLOROTIAZIDA'] as $ingredient) {
            $combination->ingredients()->attach(ActiveIngredient::factory()->create(['canonical_name' => $ingredient, 'normalized_name' => mb_strtolower($ingredient)])->id, ['component_text' => $ingredient, 'method' => 'alias', 'confidence' => 1]);
        }
        $this->leaflet($combination, ['INDICAÇÕES' => 'A losartana potássica associada à hidroclorotiazida é indicada para o tratamento da hipertensão.']);
        $this->scripted = $this->reply('full', [['kind' => 'fact', 'text' => 'A losartana com hidroclorotiazida é indicada para o tratamento da hipertensão.', 'evidence_ids' => ['ALL']]]);

        $result = $this->ask(User::factory()->create(), 'Para que serve a losartana potássica e hidroclorotiazida?');
        $this->assertNull($result['retrieval']['classification']['combination'], 'citou os dois: não há aviso');

        $this->requests = [];
        $single = $this->ask(User::factory()->create(), 'Para que serve a losartana?');
        $this->assertSame(['LOSARTANA POTÁSSICA', 'HIDROCLOROTIAZIDA'], $single['retrieval']['classification']['combination']);
        $this->assertStringContainsString('O remédio encontrado é a combinação losartana potássica + hidroclorotiazida, e a pessoa citou só parte dela', $this->generationPrompt());
    }

    public function test_the_profile_is_always_checked_but_only_reaches_the_answer_when_there_is_a_finding(): void
    {
        $dipirona = $this->dipirona();
        $losartana = $this->medicine('LOSARTANA', 'LOSARTANA');
        $user = User::factory()->create();
        PatientProfile::query()->create(['user_id' => $user->id, 'health_consent_at' => now()]);
        PatientMedication::query()->create(['user_id' => $user->id, 'medicine_id' => $losartana->id]);
        $this->scripted = $this->reply('full', [['kind' => 'fact', 'text' => 'A dipirona alivia a dor, inclusive a dor de cabeça.', 'evidence_ids' => ['ALL']]]);

        $quiet = $this->ask($user, 'Dipirona serve para dor de cabeça?');
        $this->assertStringNotContainsString('id="T1"', $this->generationPrompt(), 'sem interação nem alergia, nada do perfil vai ao modelo');
        $this->assertSame(['consented' => true, 'medications' => 1, 'interactions' => 0, 'allergy_alerts' => 0, 'alerted' => false], $quiet['retrieval']['treatment_check']);

        DrugInteraction::factory()->create(['drug_a_ingredient_id' => $dipirona->ingredients()->value('active_ingredients.id'), 'drug_b_ingredient_id' => $losartana->ingredients()->value('active_ingredients.id'), 'severity' => 'moderate']);
        $this->requests = [];
        $alerted = $this->ask($user, 'Dipirona serve para dor de cabeça?');
        $this->assertStringContainsString('registra interação de gravidade moderada entre DIPIRONA e LOSARTANA, que o paciente usa', $this->generationPrompt());
        $this->assertSame([1, true], [$alerted['retrieval']['treatment_check']['interactions'], $alerted['retrieval']['treatment_check']['alerted']]);
    }

    public function test_the_answer_speaks_as_the_humanized_leaflet_and_never_narrates_its_own_search(): void
    {
        $validator = app(ExplanationValidator::class);
        $evidence = ['E1' => 'A dipirona é indicada como analgésico, para o alívio da dor, e como antitérmico, para reduzir a febre.'];

        $result = $validator->validateSegments($this->reply('full', [
            ['kind' => 'fact', 'text' => 'A bula diz que a dipirona é indicada para o alívio da dor e para reduzir a febre.', 'evidence_ids' => ['E1']],
            ['kind' => 'fact', 'text' => 'Li na bula que a dipirona serve para aliviar a dor e reduzir a febre.', 'evidence_ids' => ['E1']],
            ['kind' => 'limit', 'text' => 'Os trechos que consultei não trazem informação sobre crianças.'],
        ]), $evidence);

        $this->assertSame(['A dipirona é indicada para o alívio da dor e para reduzir a febre.', 'A dipirona serve para aliviar a dor e reduzir a febre.'], array_column($result['facts'], 'text'));
        $this->assertSame([], $result['limits']);
        $this->assertSame(['narrates_source'], array_column($result['rejected'], 'reason'));
    }

    public function test_changing_medicine_drops_the_previous_conversation_from_the_context(): void
    {
        $dipirona = $this->dipirona();
        $ibuprofeno = $this->medicine('IBUPROFENO', 'IBUPROFENO');
        $this->leaflet($ibuprofeno, ['PARA QUE ESTE MEDICAMENTO É INDICADO?' => 'O ibuprofeno é indicado para o alívio da dor e da febre e para inflamações.']);
        $this->scripted = $this->reply('full', [['kind' => 'fact', 'text' => 'O ibuprofeno alivia a dor e a febre e trata inflamações.', 'evidence_ids' => ['ALL']]]);

        $result = $this->ask(User::factory()->create(), 'E o ibuprofeno serve para dor?', [['question' => 'Posso tomar dipirona?', 'answer' => 'A dipirona alivia a dor.', 'medicine_ids' => [$dipirona->id], 'topics' => ['seguranca_de_uso']]]);

        $this->assertSame(['IBUPROFENO'], array_column($result['medicines'], 'name'));
        $this->assertTrue($result['retrieval']['classification']['medicine_switched']);
        $this->assertStringNotContainsString('<conversa>', $this->generationPrompt());
        $this->assertSame(['INDICATION'], $result['retrieval']['classification']['intents'], 'o assunto anterior (segurança de uso) não é herdado');
    }

    public function test_the_document_of_the_asked_presentation_comes_first(): void
    {
        $dipirona = $this->medicine('DIPIRONA', 'DIPIRONA');
        $this->leaflet($dipirona, ['COMO DEVO USAR ESTE MEDICAMENTO?' => 'Comprimidos de dipirona: adultos, 1 a 2 comprimidos até 4 vezes ao dia.'], ['title' => 'DIPIRONA COMPRIMIDOS — Bula']);
        $this->leaflet($dipirona, ['COMO DEVO USAR ESTE MEDICAMENTO?' => 'Gotas de dipirona: adultos, 20 a 40 gotas até 4 vezes ao dia.'], ['title' => 'DIPIRONA GOTAS — Bula']);
        $this->scripted = $this->reply('full', [['kind' => 'fact', 'text' => 'Para adultos, a dose é de 20 a 40 gotas até 4 vezes ao dia.', 'evidence_ids' => ['ALL']]]);

        $this->ask(User::factory()->create(), 'Quantas gotas de dipirona posso tomar?');

        $this->assertMatchesRegularExpression('/<evidencia id="E1" documento="DIPIRONA GOTAS/', $this->generationPrompt());
        $this->assertStringContainsString('Use só números dessa apresentação', $this->generationPrompt());
    }

    public function test_conflicting_evidence_reaches_the_model_with_the_rule_to_report_the_conflict(): void
    {
        $dipirona = $this->medicine('DIPIRONA', 'DIPIRONA');
        $this->leaflet($dipirona, ['COMO DEVO USAR ESTE MEDICAMENTO?' => 'Dipirona solução oral: adultos, 20 a 40 gotas até 4 vezes ao dia.']);
        $this->leaflet($dipirona, ['COMO DEVO USAR ESTE MEDICAMENTO?' => 'Dipirona solução oral: adultos, 10 a 20 gotas até 3 vezes ao dia.']);

        $this->ask(User::factory()->create(), 'Como tomar dipirona?');

        $this->assertStringContainsString('20 a 40 gotas', $this->generationPrompt());
        $this->assertStringContainsString('10 a 20 gotas', $this->generationPrompt());
        $this->assertStringContainsString('Se duas evidências se contradizem, diga isso em um limit', (string) $this->requests[0]['system']);
    }

    public function test_a_provider_failure_is_said_plainly_without_raw_leaflet_text(): void
    {
        $this->dipirona();
        $this->failing = true;

        $result = $this->ask(User::factory()->create(), 'Dipirona serve para dor de cabeça?');

        $this->assertSame([MedicationQuestionService::UNAVAILABLE, null, []], [$result['status'], $result['answer'], $result['evidences']]);
    }

    /** Bula indexada só com a página 2, mas o PDF tem 3 páginas: a dose está na página que ficou fora do índice. */
    private function leafletWithMissingPage(AnvisaMedicine $medicine, ?string $storageKey = 'present'): KnowledgeDocument
    {
        $document = $this->leaflet($medicine, ['PARA QUE ESTE MEDICAMENTO É INDICADO?' => 'A dipirona é indicada para o alívio da dor e para reduzir a febre.'], ['metadata' => ['processing' => ['pages' => 3]]]);
        if ($storageKey !== null) {
            $key = "knowledge/originals/{$document->id}/bula.pdf";
            Storage::disk(config('knowledge.filesystem_disk', 'knowledge'))->put($key, '%PDF-1.4 fake');
            $document->forceFill(['storage_key' => $key, 'storage_disk' => config('knowledge.filesystem_disk', 'knowledge')])->save();
        }

        return $document;
    }

    private function fakeOriginal(bool $reliable): void
    {
        $pages = new ExtractedPdf([
            new ExtractedPage(2, 'A dipirona é indicada para o alívio da dor e para reduzir a febre.'),
            new ExtractedPage(3, 'Como devo usar: adultos e adolescentes acima de 15 anos tomam de 20 a 40 gotas da solução oral, até 4 vezes ao dia.'),
        ], ['engine' => 'fake']);
        $this->app->instance(PdfTextExtractor::class, new class($pages) implements PdfTextExtractor
        {
            public function __construct(private readonly ExtractedPdf $pages) {}

            public function extract(string $absolutePath): ExtractedPdf
            {
                return $this->pages;
            }
        });
        $this->app->instance(DocumentExtractionQualityEvaluator::class, new class($reliable) implements DocumentExtractionQualityEvaluator
        {
            public function __construct(private readonly bool $reliable) {}

            public function evaluate(ExtractedPdf $document): ExtractionQuality
            {
                return new ExtractionQuality($this->reliable ? 0.9 : 0.2, $this->reliable, $this->reliable ? [] : ['LOW_TEXT_DENSITY'], []);
            }
        });
        $this->app->instance(DocumentOcrClient::class, new class($pages) implements DocumentOcrClient
        {
            public function __construct(private readonly ExtractedPdf $pages) {}

            public function parse(string $localFile): ExtractedPdf
            {
                return $this->pages;
            }
        });
    }

    public function test_when_the_index_misses_pages_the_original_pdf_is_read_verified_and_sent_to_curation(): void
    {
        Storage::fake(config('knowledge.filesystem_disk', 'knowledge'));
        $this->fakeOriginal(reliable: true);
        $document = $this->leafletWithMissingPage($this->medicine('DIPIRONA', 'DIPIRONA'));
        $excerpt = 'adultos e adolescentes acima de 15 anos tomam de 20 a 40 gotas da solução oral, até 4 vezes ao dia.';
        $this->queue = [
            json_encode(['found' => false, 'items' => []]),
            json_encode(['found' => true, 'items' => [['chunk' => 3, 'excerpt' => $excerpt, 'summary' => 'Dose para adultos.', 'confidence' => 0.9]]]),
            $this->reply('full', [['kind' => 'fact', 'text' => 'Para adultos e adolescentes acima de 15 anos, a dose é de 20 a 40 gotas da solução oral, até 4 vezes ao dia.', 'evidence_ids' => ['ALL']]]),
        ];

        $result = $this->ask(User::factory()->create(), 'Como tomar dipirona?');

        $this->assertSame(MedicationQuestionService::ANSWERED, $result['status']);
        $this->assertStringContainsString('20 a 40 gotas', (string) $result['answer']);
        $fact = KnowledgeLeafletFact::query()->sole();
        $this->assertSame([null, 3, KnowledgeLeafletFact::METHOD_ORIGINAL_PDF_AI, KnowledgeLeafletFact::REVIEW_PENDING, $document->sha256], [$fact->chunk_id, $fact->page_start, $fact->extraction_method, $fact->review_status, $fact->document_sha256]);
        $this->assertSame('original_pdf', $result['retrieval']['sufficiency']['fallback']);
        $original = collect($result['retrieval']['leaflet_fallback']['per_medicine'][0]['documents'])->firstWhere('source', 'original_pdf');
        $this->assertSame(['pages_missing_from_index', 'ok', true], [$original['reason'], $original['status'], $original['reliable']]);
        $this->assertSame(-(LeafletEvidenceService::ORIGINAL_ID_OFFSET + $fact->id), collect($result['evidences'])->firstWhere('page_start', 3)['chunk_id'], 'evidência citável e auditável, fora da faixa dos chunks');

        // Mesma pergunta de novo: o texto do PDF vem do cache e o trecho não é gravado duas vezes.
        $this->queue = [
            json_encode(['found' => false, 'items' => []]),
            json_encode(['found' => true, 'items' => [['chunk' => 3, 'excerpt' => $excerpt, 'summary' => 'Dose para adultos.', 'confidence' => 0.9]]]),
            $this->reply('full', [['kind' => 'fact', 'text' => 'Para adultos, a dose é de 20 a 40 gotas da solução oral, até 4 vezes ao dia.', 'evidence_ids' => ['ALL']]]),
        ];
        $again = $this->ask(User::factory()->create(), 'Como tomar dipirona?');
        $this->assertSame(1, KnowledgeLeafletFact::query()->count(), 'evidência duplicada não é gravada de novo');
        $this->assertTrue(collect($again['retrieval']['leaflet_fallback']['per_medicine'][0]['documents'])->firstWhere('source', 'original_pdf')['cached']);
    }

    public function test_without_the_original_pdf_the_limit_is_stated_and_the_same_miss_is_not_searched_again(): void
    {
        $this->leafletWithMissingPage($this->medicine('DIPIRONA', 'DIPIRONA'), storageKey: null);
        $this->scripted = json_encode(['found' => false, 'items' => []]);

        $result = $this->ask(User::factory()->create(), 'Como tomar dipirona?');

        $this->assertSame(MedicationQuestionService::NO_EVIDENCE, $result['status']);
        $original = collect($result['retrieval']['leaflet_fallback']['per_medicine'][0]['documents'])->firstWhere('source', 'original_pdf');
        $this->assertSame(['unavailable', 'NO_ORIGINAL_FILE'], [$original['status'], $original['unavailable']]);

        $calls = count($this->requests);
        $again = $this->ask(User::factory()->create(), 'Como tomar dipirona?');
        $this->assertSame('recent_miss', $again['retrieval']['leaflet_fallback']['per_medicine'][0]['documents'][0]['skipped']);
        $this->assertSame(0, collect(array_slice($this->requests, $calls))->where('purpose', 'leaflet_evidence')->count(), 'a bula não é relida para a mesma pergunta sem resposta');
    }

    public function test_low_confidence_ocr_goes_to_curation_but_never_supports_the_answer(): void
    {
        Storage::fake(config('knowledge.filesystem_disk', 'knowledge'));
        $this->fakeOriginal(reliable: false);
        $this->leafletWithMissingPage($this->medicine('DIPIRONA', 'DIPIRONA'));
        $this->queue = [
            json_encode(['found' => false, 'items' => []]),
            json_encode(['found' => true, 'items' => [['chunk' => 3, 'excerpt' => 'adultos e adolescentes acima de 15 anos tomam de 20 a 40 gotas da solução oral, até 4 vezes ao dia.', 'summary' => 'Dose.', 'confidence' => 0.9]]]),
        ];

        $result = $this->ask(User::factory()->create(), 'Como tomar dipirona?');

        $this->assertNotSame(MedicationQuestionService::ANSWERED, $result['status']);
        $this->assertStringNotContainsString('20 a 40 gotas', (string) $result['answer']);
        $fact = KnowledgeLeafletFact::query()->sole();
        $this->assertSame([null, KnowledgeLeafletFact::METHOD_ORIGINAL_OCR_AI, KnowledgeLeafletFact::REVIEW_PENDING], [$fact->chunk_id, $fact->extraction_method, $fact->review_status]);
        $this->assertLessThanOrEqual(0.3, $fact->confidence);
        $original = collect($result['retrieval']['leaflet_fallback']['per_medicine'][0]['documents'])->firstWhere('source', 'original_pdf');
        $this->assertSame([false, 'low_ocr_quality'], [$original['reliable'], $original['not_used']]);
    }

    public function test_without_any_leaflet_information_the_answer_is_honest_and_queued_for_review(): void
    {
        $this->leaflet($this->medicine('DIPIRONA', 'DIPIRONA'), ['CONSERVAÇÃO' => 'Conservar em temperatura ambiente, protegido da luz.']);
        $this->scripted = json_encode(['found' => false, 'items' => []]);
        Cache::flush();

        $result = $this->ask(User::factory()->create(), 'Dipirona serve para dor de cabeça?');

        $this->assertSame([MedicationQuestionService::NO_EVIDENCE, [], 'insufficient'], [$result['status'], $result['evidences'], $result['retrieval']['sufficiency']['verdict']]);
        $this->assertSame('pending', MedicationQuestion::query()->sole()->review_status);
    }
}
