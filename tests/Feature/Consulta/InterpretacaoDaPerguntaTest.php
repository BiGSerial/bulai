<?php

namespace Tests\Feature\Consulta;

use App\Data\Ai\TextGenerationRequest;
use App\Data\Ai\TextGenerationResponse;
use App\Models\Knowledge\ActiveIngredient;
use App\Models\Knowledge\AnvisaMedicine;
use App\Models\Knowledge\KnowledgeDocument;
use App\Models\Knowledge\KnowledgeSource;
use App\Models\User;
use App\Services\Ai\AiProviderException;
use App\Services\Ai\AiProviderManager;
use App\Services\Ai\TextGenerationProvider;
use App\Services\Interactions\ExplanationValidator;
use App\Services\Patient\MedicationQuestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A IA interpreta a pergunta antes da busca (QuestionInterpreter): resolve "ela", "e na versão gotas?", "tá, mas serve…?"
 * com a conversa, e só depois o sistema busca nas bases e humaniza. Casos reais de 10/11/2026 (capturas do Perguntar).
 * O provedor é roteirizado por finalidade: a interpretação e a resposta têm roteiros separados.
 */
final class InterpretacaoDaPerguntaTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed>|null resposta da interpretação; null = provedor falha nessa etapa */
    public ?array $interpretation = [];

    public string $answer = '{"coverage":"none","segments":[]}';

    /** @var list<array{purpose: string|null, prompt: string}> */
    public array $requests = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['bulai.interpretation.enabled' => true, 'bulai.generation.enabled' => true, 'ai.text.default' => 'scripted', 'bulai.retrieval.mode' => 'lexical']);
        $test = $this;
        $manager = new AiProviderManager;
        $manager->extend(AiProviderManager::TEXT, 'scripted', fn () => new class($test) implements TextGenerationProvider
        {
            public function __construct(private readonly InterpretacaoDaPerguntaTest $test) {}

            public function generate(TextGenerationRequest $request): TextGenerationResponse
            {
                $purpose = $request->metadata['purpose'] ?? null;
                $this->test->requests[] = ['purpose' => $purpose, 'prompt' => $request->prompt];
                if ($purpose === 'question_interpretation') {
                    if ($this->test->interpretation === null) {
                        throw new AiProviderException('down', 'PROVIDER_UNAVAILABLE');
                    }

                    return new TextGenerationResponse((string) json_encode($this->test->interpretation), 'scripted', null);
                }
                preg_match_all('/<evidencia id="([^"]+)"/', $request->prompt, $ids);

                return new TextGenerationResponse(str_replace('["ALL"]', (string) json_encode(array_values(array_diff($ids[1], ['T1']))), $this->test->answer), 'scripted', null);
            }
        });
        $this->app->instance(AiProviderManager::class, $manager);
    }

    private function medicine(string $name, string $ingredient): AnvisaMedicine
    {
        $medicine = AnvisaMedicine::factory()->create(['product_name' => $name, 'normalized_product_name' => mb_strtolower($name), 'active_ingredient' => $ingredient, 'normalized_active_ingredient' => mb_strtolower($ingredient)]);
        $medicine->ingredients()->attach(ActiveIngredient::factory()->create(['canonical_name' => $ingredient, 'normalized_name' => mb_strtolower($ingredient)])->id, ['component_text' => $ingredient, 'method' => 'alias', 'confidence' => 1]);

        return $medicine;
    }

    /** @param array<string, string> $sections */
    private function leaflet(AnvisaMedicine $medicine, array $sections): void
    {
        $source = KnowledgeSource::query()->firstOrCreate(['name' => 'ANVISA', 'organization' => null], ['type' => 'document', 'active' => true]);
        $document = KnowledgeDocument::query()->create(['source_id' => $source->id, 'external_key' => uniqid('d_'), 'title' => $medicine->product_name.' — Bula', 'document_type' => 'bula_paciente', 'language' => 'pt-BR', 'metadata' => [], 'content_status' => 'READY', 'file_status' => 'READY', 'embedding_status' => 'PENDING', 'content_hash' => uniqid('h_'), 'sha256' => hash('sha256', uniqid('pdf', true))]);
        $index = 0;
        foreach ($sections as $section => $content) {
            $document->chunks()->create(['chunk_index' => $index, 'section' => $section, 'page_start' => $index + 2, 'page_end' => $index + 2, 'content' => $content, 'metadata' => []]);
            $index++;
        }
        DB::table('knowledge_document_ingredients')->insert(['document_id' => $document->id, 'ingredient_id' => $medicine->ingredients()->value('active_ingredients.id'), 'method' => 'registration', 'confidence' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function berotec(): AnvisaMedicine
    {
        $berotec = $this->medicine('BEROTEC', 'BROMIDRATO DE FENOTEROL');
        $this->leaflet($berotec, [
            'COMO DEVO USAR ESTE MEDICAMENTO?' => 'Berotec gotas: a solução para nebulização deve ser diluída em soro fisiológico; adultos usam de 10 a 20 gotas por nebulização.',
            'PARA QUE ESTE MEDICAMENTO É INDICADO?' => 'Berotec é indicado para o tratamento da crise de asma e da bronquite.',
        ]);

        return $berotec;
    }

    private function dipirona(): AnvisaMedicine
    {
        $dipirona = $this->medicine('DIPIRONA', 'DIPIRONA');
        $this->leaflet($dipirona, [
            'PARA QUE ESTE MEDICAMENTO É INDICADO?' => 'Este medicamento é indicado como analgésico (para dor) e antitérmico (para febre).',
            'QUAIS OS MALES QUE ESTE MEDICAMENTO PODE ME CAUSAR?' => 'A dipirona pode causar queda da pressão arterial e reações alérgicas na pele.',
        ]);

        return $dipirona;
    }

    private function generationPrompt(): string
    {
        return (string) collect($this->requests)->firstWhere('purpose', 'medication_question')['prompt'];
    }

    /** @return array<string, mixed> */
    private function ask(string $question, array $conversation = []): array
    {
        return app(MedicationQuestionService::class)->askFreeform(User::factory()->create(), $question, [], $conversation);
    }

    public function test_a_presentation_follow_up_keeps_the_medicine_of_the_conversation_instead_of_matching_a_common_word(): void
    {
        $berotec = $this->berotec();
        $this->medicine('VERSA', 'VERSA');
        $this->interpretation = ['medicines' => ['Berotec'], 'follow_up' => true, 'question' => 'Como usar o Berotec em gotas para nebulização?', 'intent' => 'DOSAGE', 'presentation' => 'gotas', 'urgency' => null];
        $this->answer = '{"coverage":"full","segments":[{"kind":"fact","text":"Berotec gotas é diluído em soro fisiológico para a nebulização; adultos usam de 10 a 20 gotas por nebulização.","evidence_ids":["ALL"]}]}';

        $result = $this->ask('E na versao gotas para nebulizaçao?', [['question' => 'Qual a forma de uso para o berotec?', 'answer' => 'O Berotec é um aerossol…', 'medicine_ids' => [$berotec->id]]]);

        $this->assertSame(['BEROTEC'], array_column($result['medicines'], 'name'), '"versao" não vira o remédio VERSA');
        $this->assertSame('question_interpretation', $this->requests[0]['purpose'], 'a IA interpreta antes de qualquer busca');
        $this->assertStringContainsString('Qual a forma de uso para o berotec?', $this->requests[0]['prompt'], 'a conversa vai para a interpretação');
        $this->assertSame('DOSAGE', $result['retrieval']['classification']['primary']);
        $this->assertSame('gotas', $result['retrieval']['classification']['presentation']);
        $this->assertSame('Como usar o Berotec em gotas para nebulização?', $result['retrieval']['interpretation']['question']);
        $this->assertStringContainsString('<pergunta>Como usar o Berotec em gotas para nebulização?</pergunta>', $this->generationPrompt());
        $this->assertStringContainsString('A pessoa escreveu "E na versao gotas para nebulizaçao?"', $this->generationPrompt());
        $this->assertSame(MedicationQuestionService::ANSWERED, $result['status']);
    }

    public function test_a_pronoun_indication_after_an_adverse_effect_turn_is_an_indication_not_an_inherited_adverse_effect(): void
    {
        $dipirona = $this->dipirona();
        $this->interpretation = ['medicines' => ['dipirona'], 'follow_up' => true, 'question' => 'A dipirona serve para dor de cabeça?', 'intent' => 'INDICATION', 'presentation' => null, 'urgency' => null];
        $this->answer = '{"coverage":"full","segments":[{"kind":"fact","text":"Sim, a dipirona é indicada como analgésico, para dor em geral, o que inclui a dor de cabeça.","evidence_ids":["ALL"]}]}';

        $result = $this->ask('Ela serve para dores de cabeça?', [['question' => 'qual efeito adverso da dipirona?', 'answer' => 'Em casos raros, a queda de pressão…', 'medicine_ids' => [$dipirona->id], 'topics' => ['efeitos_colaterais']]]);

        $this->assertSame('INDICATION', $result['retrieval']['classification']['primary']);
        $this->assertFalse($result['retrieval']['inherited_intent'], 'o assunto da troca anterior (efeitos) não é herdado');
        $this->assertContains('PARA QUE ESTE MEDICAMENTO É INDICADO?', array_column($result['evidences'], 'section'));
        $this->assertStringContainsString('uma dor comum (de cabeça, de dente, muscular, de garganta) ou a febre estão dentro dessa indicação', $this->generationPrompt());
        $this->assertStringStartsWith('Sim, a dipirona é indicada como analgésico', (string) $result['answer']);
    }

    public function test_a_medicine_the_ai_invented_is_dropped_and_the_question_still_finds_the_one_asked(): void
    {
        $this->dipirona();
        $this->medicine('IBUPROFENO', 'IBUPROFENO');
        $this->interpretation = ['medicines' => ['ibuprofeno'], 'follow_up' => false, 'question' => 'Para que serve o ibuprofeno?', 'intent' => 'INDICATION', 'presentation' => null, 'urgency' => null];

        $result = $this->ask('Para que serve a dipirona?');

        $this->assertSame(['ibuprofeno'], $result['retrieval']['interpretation']['dropped'], 'nome que não está na pergunta nem na conversa é descartado');
        $this->assertSame(['DIPIRONA'], array_column($result['medicines'], 'name'));
    }

    /** Produção, 11/11/2026: "Para que serve o AAS?" saía como "não encontrei o remédio", porque a sigla de 3 letras era descartada. */
    public function test_a_short_brand_like_aas_is_kept_when_written_exactly_and_dropped_when_only_similar(): void
    {
        $this->medicine('AAS', 'ÁCIDO ACETILSALICÍLICO');
        $this->interpretation = ['medicines' => ['AAS'], 'follow_up' => false, 'question' => 'Para que serve o AAS?', 'intent' => 'INDICATION', 'presentation' => null, 'urgency' => null];

        $result = $this->ask('Para que serve o AAS?');

        $this->assertSame([], $result['retrieval']['interpretation']['dropped']);
        $this->assertSame(['AAS'], array_column($result['medicines'], 'name'));

        $this->interpretation = ['medicines' => ['AAS'], 'follow_up' => false, 'question' => 'Para que serve o ABS?', 'intent' => 'INDICATION', 'presentation' => null, 'urgency' => null];
        $this->assertSame(['AAS'], $this->ask('Para que serve o ABS?')['retrieval']['interpretation']['dropped'], 'sigla parecida não é a mesma sigla');
    }

    public function test_a_new_medicine_outside_the_catalog_is_not_answered_with_the_previous_one(): void
    {
        $dipirona = $this->dipirona();
        $this->interpretation = ['medicines' => ['Zentrovax'], 'follow_up' => false, 'question' => 'Para que serve o Zentrovax?', 'intent' => 'INDICATION', 'presentation' => null, 'urgency' => null];

        $result = $this->ask('E o Zentrovax, serve pra quê?', [['question' => 'Para que serve a dipirona?', 'answer' => 'Para dor e febre.', 'medicine_ids' => [$dipirona->id]]]);

        $this->assertSame(MedicationQuestionService::NO_MEDICINE, $result['status'], 'não responde sobre a dipirona no lugar do remédio perguntado');
    }

    public function test_without_the_interpretation_the_previous_rules_still_answer(): void
    {
        $this->dipirona();
        $this->interpretation = null;
        $this->answer = '{"coverage":"full","segments":[{"kind":"fact","text":"A dipirona é indicada como analgésico, para dor, e antitérmico, para febre.","evidence_ids":["ALL"]}]}';

        $result = $this->ask('Para que serve a dipirona?');

        $this->assertNull($result['retrieval']['interpretation']);
        $this->assertSame(MedicationQuestionService::ANSWERED, $result['status']);
        $this->assertSame('INDICATION', $result['retrieval']['classification']['primary']);
    }

    public function test_an_emergency_the_rules_missed_still_gets_the_poison_center_from_the_interpretation(): void
    {
        $this->dipirona();
        $this->interpretation = ['medicines' => ['dipirona'], 'follow_up' => false, 'question' => 'Meu filho pegou a cartela de dipirona e comeu os comprimidos, o que fazer?', 'intent' => 'EMERGENCY', 'presentation' => null, 'urgency' => 'superdose'];

        $result = $this->ask('O pequeno achou a dipirona e comeu os comprimidos');

        $this->assertSame(['interpretacao_superdose'], $result['urgency']['kinds']);
        $this->assertStringContainsString('0800 722 6001', $result['urgency']['message']);
    }

    public function test_a_generic_combination_caveat_is_dropped_from_a_single_medicine_question(): void
    {
        $validation = app(ExplanationValidator::class)->validateSegments((string) json_encode(['coverage' => 'full', 'segments' => [
            ['kind' => 'fact', 'text' => 'Adultos usam de 10 a 20 gotas por nebulização.', 'evidence_ids' => ['E1']],
            ['kind' => 'limit', 'text' => 'Não ter gravidade classificada não quer dizer que a combinação seja segura.'],
        ]]), ['E1' => 'Adultos usam de 10 a 20 gotas por nebulização, diluídas em soro.'], ['single_medicine' => true]);

        $this->assertSame([], $validation['limits']);
        $this->assertSame([['kind' => 'limit', 'reason' => 'combination_off_topic']], $validation['rejected']);
    }

    /**
     * "Serve para dor de cabeça?" com uma bula que diz só "analgésico": repetir o que a pessoa perguntou (o remédio, a dor de
     * cabeça) não conta contra a ancoragem, mas o restante da afirmação continua tendo de estar no trecho.
     */
    public function test_words_from_the_question_do_not_count_against_grounding_but_the_rest_must_be_in_the_excerpt(): void
    {
        $evidence = ['E1' => 'Este medicamento é indicado como analgésico (para dor) e antitérmico (para febre).'];
        $validate = fn (string $text): array => (new ExplanationValidator)->validateSegments((string) json_encode(['coverage' => 'full', 'segments' => [['kind' => 'fact', 'text' => $text, 'evidence_ids' => ['E1']]]]), $evidence, ['asked_text' => 'DIPIRONA A dipirona serve para dor de cabeça?', 'max_fact_words' => 100]);

        $this->assertCount(1, $validate('Sim, a dipirona é indicada como analgésico, para dor em geral, o que inclui a dor de cabeça.')['facts']);
        $this->assertSame([['kind' => 'fact', 'reason' => 'not_grounded_in_cited_evidence']], $validate('A dipirona cura enxaqueca crônica e protege o cérebro contra derrame.')['rejected']);
    }

    /**
     * Avaliação de 10/11/2026 (N1): a Novalgina é marca de dipirona e os trechos vêm da bula de dipirona. "A Novalgina, que
     * tem dipirona, é analgésico e serve para dor de cabeça" não pode ser recusada só por citar o princípio ativo.
     */
    public function test_a_brand_question_is_grounded_on_the_active_ingredient_leaflet(): void
    {
        $novalgina = $this->medicine('NOVALGINA', 'DIPIRONA');
        $this->leaflet($novalgina, ['PARA QUE ESTE MEDICAMENTO É INDICADO?' => 'Este medicamento é indicado como analgésico (para dor) e antitérmico (para febre).']);
        $this->interpretation = ['medicines' => ['Novalgina'], 'follow_up' => true, 'question' => 'A Novalgina serve para dor de cabeça?', 'intent' => 'INDICATION', 'presentation' => null, 'urgency' => null];
        $this->answer = '{"coverage":"full","segments":[{"kind":"fact","text":"Sim, a dipirona da Novalgina funciona como analgésico para a dor de cabeça.","evidence_ids":["ALL"]}]}';

        $result = $this->ask('Tá mas serve para dor de cabeça?', [['question' => 'E Novalgina? Serve para dor de cabeça?', 'answer' => 'A novalgina também é indicada para baixar a febre.', 'medicine_ids' => [$novalgina->id]]]);

        $this->assertSame(MedicationQuestionService::ANSWERED, $result['status']);
        $this->assertStringStartsWith('Sim, a dipirona da Novalgina funciona como analgésico', (string) $result['answer']);
    }

    public function test_the_interpretation_is_recorded_even_when_no_medicine_is_found(): void
    {
        $this->interpretation = ['medicines' => ['Zentrovax'], 'follow_up' => false, 'question' => 'Para que serve o Zentrovax?', 'intent' => 'INDICATION', 'presentation' => null, 'urgency' => null];

        $result = $this->ask('Para que serve o Zentrovax?');

        $this->assertSame(MedicationQuestionService::NO_MEDICINE, $result['status']);
        $this->assertSame(['Zentrovax'], $result['retrieval']['interpretation']['medicines']);
    }
}
