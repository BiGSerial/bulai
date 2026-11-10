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
use App\Models\Knowledge\KnowledgeLeafletFact;
use App\Models\Knowledge\KnowledgeSource;
use App\Models\User;
use App\Services\Ai\AiProviderManager;
use App\Services\Ai\TextGenerationProvider;
use App\Services\Evaluation\ConversationEvaluator;
use App\Services\Interactions\ExplanationValidator;
use App\Services\Patient\MedicationQuestionService;
use App\Services\Patient\QuestionIntentDetector;
use App\Services\Patient\UrgencyDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Motor conversacional do Perguntar: os oito cenários de aceitação (pergunta geral, segurança individual, interação sem citar o
 * outro remédio, contexto informado, evidência só no PDF, ausência real, acompanhamento e falha de geração) com um provedor
 * roteirizado, mais as garantias de segurança (nada de trecho bruto como resposta, nada de afirmação sem fonte, urgência primeiro).
 * A qualidade da linguagem com o modelo real é medida por `evaluation:conversation` (docs/tcc/30).
 */
final class ConversaHumanizadaTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> respostas consumidas em ordem; esgotada a fila vale `$scripted` */
    public array $queue = [];

    public string $scripted = '{"coverage":"none","segments":[]}';

    /** @var list<array{prompt: string, system: string|null}> */
    public array $requests = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['bulai.generation.enabled' => true, 'ai.text.default' => 'scripted', 'bulai.retrieval.mode' => 'lexical']);
        $test = $this;
        $manager = new AiProviderManager;
        $manager->extend(AiProviderManager::TEXT, 'scripted', fn () => new class($test) implements TextGenerationProvider
        {
            public function __construct(private readonly ConversaHumanizadaTest $test) {}

            public function generate(TextGenerationRequest $request): TextGenerationResponse
            {
                $this->test->requests[] = ['prompt' => $request->prompt, 'system' => $request->system];
                $content = $this->test->queue !== [] ? array_shift($this->test->queue) : $this->test->scripted;
                // "ALL" = cita todas as evidências que realmente vieram no prompt (a ordem da recuperação não é o assunto destes testes).
                preg_match_all('/<evidencia id="([^"]+)"/', $request->prompt, $ids);
                $content = str_replace('["ALL"]', (string) json_encode($ids[1]), $content);

                return new TextGenerationResponse($content, 'scripted', null);
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

    /** @param array<string, string> $sections seção => texto */
    private function leaflet(AnvisaMedicine $medicine, array $sections, string $type = 'bula_paciente'): KnowledgeDocument
    {
        $source = KnowledgeSource::query()->firstOrCreate(['name' => 'ANVISA', 'organization' => null], ['type' => 'document', 'active' => true]);
        $document = KnowledgeDocument::query()->create(['source_id' => $source->id, 'external_key' => uniqid('d_'), 'title' => $medicine->product_name.' — Bula', 'document_type' => $type, 'language' => 'pt-BR', 'metadata' => [], 'content_status' => 'READY', 'file_status' => 'READY', 'embedding_status' => 'PENDING', 'content_hash' => 'h-'.uniqid(), 'sha256' => hash('sha256', uniqid('pdf', true))]);
        $index = 0;
        foreach ($sections as $section => $content) {
            $document->chunks()->create(['chunk_index' => $index, 'section' => $section, 'page_start' => $index + 2, 'page_end' => $index + 2, 'content' => $content, 'metadata' => []]);
            $index++;
        }
        DB::table('knowledge_document_ingredients')->insert(['document_id' => $document->id, 'ingredient_id' => $medicine->ingredients()->value('active_ingredients.id'), 'method' => 'registration', 'confidence' => 1, 'created_at' => now(), 'updated_at' => now()]);

        return $document;
    }

    /** @param list<array{kind: string, text: string, evidence_ids?: list<string>}> $segments */
    private function reply(string $coverage, array $segments): string
    {
        return (string) json_encode(['coverage' => $coverage, 'segments' => $segments]);
    }

    private function dipirona(): AnvisaMedicine
    {
        $medicine = $this->medicine('DIPIRONA', 'DIPIRONA');
        $this->leaflet($medicine, [
            'INDICAÇÕES' => 'A dipirona é indicada para o alívio da dor e para reduzir a febre.',
            'CONTRAINDICAÇÕES' => 'A dipirona não deve ser usada por pessoas com alergia à dipirona ou a outros analgésicos, nem por quem tem problemas na produção das células do sangue.',
            'ADVERTÊNCIAS E PRECAUÇÕES' => 'Antes de usar a dipirona informe ao médico se você tem pressão baixa, doença nos rins ou no fígado.',
        ]);

        return $medicine;
    }

    private function ask(User $user, string $question): array
    {
        return app(MedicationQuestionService::class)->askFreeform($user, $question);
    }

    public function test_scenario_1_a_general_question_gets_a_direct_explanation_without_an_interrogation(): void
    {
        $this->dipirona();
        $this->scripted = $this->reply('full', [['kind' => 'fact', 'text' => 'A dipirona é indicada para o alívio da dor e para reduzir a febre.', 'evidence_ids' => ['ALL']]]);

        $result = $this->ask(User::factory()->create(), 'Para que serve dipirona?');

        $this->assertSame([MedicationQuestionService::ANSWERED, 'full', [], ['para_que_serve']], [$result['status'], $result['coverage'], $result['follow_up'], $result['intent']]);
        $this->assertSame('A dipirona é indicada para o alívio da dor e para reduzir a febre.', $result['answer']);
        $this->assertSame(['INDICAÇÕES'], collect($result['evidences'])->pluck('section')->all(), 'só a seção pedida, não a bula toda');
        $this->assertCount(1, $this->requests, 'uma geração');
    }

    public function test_scenario_2_individual_safety_gives_general_facts_states_the_limit_and_asks_only_what_is_missing(): void
    {
        $this->dipirona();
        $this->scripted = $this->reply('full', [
            ['kind' => 'fact', 'text' => 'A dipirona é indicada para o alívio da dor e para reduzir a febre.', 'evidence_ids' => ['ALL']],
            ['kind' => 'fact', 'text' => 'A dipirona não deve ser usada por pessoas com alergia à dipirona ou a outros analgésicos.', 'evidence_ids' => ['ALL']],
            ['kind' => 'limit', 'text' => 'Se você pode tomar depende da sua saúde, e isso quem confirma é um médico ou farmacêutico.'],
            ['kind' => 'ask', 'text' => 'Você já teve alergia à dipirona ou a outros analgésicos?'],
            ['kind' => 'ask', 'text' => 'Você usa algum outro remédio ou tem alguma doença importante?'],
        ]);

        $result = $this->ask(User::factory()->create(), 'Posso tomar dipirona?');

        $this->assertSame([MedicationQuestionService::ANSWERED, 'full', ['seguranca_de_uso']], [$result['status'], $result['coverage'], $result['intent']]);
        $this->assertSame(2, count($result['follow_up']));
        $paragraphs = explode("\n\n", (string) $result['answer']);
        $this->assertCount(3, $paragraphs, 'fatos, limite e perguntas em parágrafos');
        $this->assertStringStartsWith('A dipirona é indicada', $paragraphs[0]);
        $this->assertStringContainsString('depende da sua saúde', $paragraphs[1]);
        $this->assertStringEndsWith('?', $paragraphs[2]);
        $this->assertSame(['INDICAÇÕES', 'CONTRAINDICAÇÕES', 'ADVERTÊNCIAS E PRECAUÇÕES'], collect($result['evidences'])->pluck('section')->filter()->unique()->values()->all(), 'segurança de uso consulta indicação, contraindicações e cuidados');
        $this->assertStringContainsString('NUNCA conclui que é seguro', (string) $this->requests[0]['system']);
        $this->assertStringContainsString('Assunto identificado: Segurança de uso', $this->requests[0]['prompt']);
    }

    public function test_scenario_3_an_unnamed_other_medicine_is_asked_for_and_the_ask_is_not_repeated_in_the_next_turn(): void
    {
        $this->dipirona();
        $this->scripted = $this->reply('partial', [
            ['kind' => 'fact', 'text' => 'Antes de usar a dipirona, a bula pede avisar o médico se há pressão baixa, doença nos rins ou no fígado.', 'evidence_ids' => ['ALL']],
            ['kind' => 'ask', 'text' => 'Qual é o outro remédio que você quer tomar junto com a dipirona?'],
        ]);
        $user = User::factory()->create();

        $first = $this->ask($user, 'Posso tomar dipirona com outro remédio?');
        $this->assertContains('interacoes', $first['intent']);
        $this->assertSame(['Qual é o outro remédio que você quer tomar junto com a dipirona?'], $first['follow_up']);
        $this->assertStringContainsString('sem dizer qual', (string) $this->requests[0]['system'], 'a regra de perguntar qual é o outro remédio está no prompt');

        // A mesma pergunta repetida pelo modelo na troca seguinte é descartada (já foi feita na conversa).
        $this->scripted = $this->reply('partial', [
            ['kind' => 'fact', 'text' => 'Antes de usar a dipirona, a bula pede avisar o médico se há pressão baixa, doença nos rins ou no fígado.', 'evidence_ids' => ['ALL']],
            ['kind' => 'ask', 'text' => 'Qual é o outro remédio que você quer tomar junto com a dipirona?'],
        ]);
        $second = app(MedicationQuestionService::class)->askFreeform($user, 'não sei', [], [['question' => 'Posso tomar dipirona com outro remédio?', 'answer' => $first['answer'], 'medicine_ids' => array_column($first['medicines'], 'id'), 'topics' => $first['intent']]]);
        $this->assertSame([], $second['follow_up']);
        $this->assertArrayHasKey('ask_already_answered_or_asked', $second['generation']['rejection_reasons']);
    }

    public function test_scenario_4_context_informed_by_the_user_uses_the_structured_interaction_and_does_not_ask_what_is_known(): void
    {
        $dipirona = $this->dipirona();
        $sertralina = $this->medicine('SERTRALINA', 'SERTRALINA');
        DrugInteraction::factory()->create(['drug_a_ingredient_id' => $dipirona->ingredients()->value('active_ingredients.id'), 'drug_b_ingredient_id' => $sertralina->ingredients()->value('active_ingredients.id'), 'severity' => 'moderate']);
        $this->scripted = $this->reply('partial', [
            ['kind' => 'fact', 'text' => 'A base de interações registra uma interação de gravidade moderada entre DIPIRONA e SERTRALINA.', 'evidence_ids' => ['T1']],
            ['kind' => 'fact', 'text' => 'Antes de usar a dipirona, a bula pede avisar o médico se há pressão baixa, doença nos rins ou no fígado.', 'evidence_ids' => ['ALL']],
            ['kind' => 'limit', 'text' => 'A bula da dipirona que tenho não trata da sertralina, então não consigo confirmar mais do que isso.'],
        ]);

        $result = $this->ask(User::factory()->create(), 'Estou tomando sertralina. Posso tomar dipirona?');

        $this->assertSame(['SERTRALINA', 'DIPIRONA'], collect($result['medicines'])->pluck('name')->sort()->reverse()->values()->all());
        $this->assertContains('interacoes', $result['intent']);
        $this->assertStringContainsString('gravidade moderada entre', (string) $this->requests[0]['prompt'], 'o fato estruturado chega ao modelo como evidência T1');
        $this->assertSame(MedicationQuestionService::ANSWERED, $result['status']);
        $this->assertContains(0, collect($result['evidences'])->firstWhere('document_type', 'treatment_context')['supports_claims'], 'a evidência estruturada sustenta ao menos uma afirmação');
        $this->assertStringContainsString('a primeira frase reconhece isso', (string) $this->requests[0]['system']);
    }

    public function test_scenario_5_when_the_excerpts_do_not_cover_the_question_the_original_leaflet_is_read_and_verified_before_giving_up(): void
    {
        $medicine = $this->medicine('OMEPRAZOL', 'OMEPRAZOL');
        $document = $this->leaflet($medicine, ['POSOLOGIA' => 'Tomar um comprimido pela manhã.', 'REAÇÕES ADVERSAS' => 'Reações comuns: dor de cabeça, diarreia e náusea. Reação rara: tontura.']);
        $chunk = $document->chunks()->where('section', 'REAÇÕES ADVERSAS')->sole();
        $this->queue = [
            $this->reply('none', [['kind' => 'limit', 'text' => 'Nos trechos que tenho não consegui confirmar isso.']]),
            (string) json_encode(['found' => true, 'items' => [['chunk' => $chunk->id, 'excerpt' => 'Reações comuns: dor de cabeça, diarreia e náusea.', 'summary' => 'Dor de cabeça é comum.', 'confidence' => 0.9]]]),
            $this->reply('full', [['kind' => 'fact', 'text' => 'O omeprazol pode causar dor de cabeça, diarreia e náusea, que são reações comuns.', 'evidence_ids' => ['ALL']]]),
        ];

        $result = $this->ask(User::factory()->create(), 'O omeprazol dá dor de cabeça?');

        $this->assertSame(MedicationQuestionService::ANSWERED, $result['status']);
        $this->assertStringContainsString('dor de cabeça, diarreia e náusea', (string) $result['answer']);
        $this->assertSame(3, count($this->requests));
        $fact = KnowledgeLeafletFact::query()->sole();
        $this->assertSame(['REAÇÕES ADVERSAS', 3, 'pdf_fallback_ai', 'pending', $document->content_hash, $document->sha256], [$fact->section, $fact->page_start, $fact->extraction_method, $fact->review_status, $fact->document_version, $fact->document_sha256]);
        $this->assertNotNull($result['retrieval']['leaflet_fallback']['document_id'] ?? null);

        // Versão nova do documento (bula reprocessada): a evidência antiga não é reaproveitada.
        $document->forceFill(['content_hash' => 'outra-versao'])->save();
        $this->queue = [$this->reply('none', []), (string) json_encode(['found' => false, 'items' => []])];
        $again = $this->ask(User::factory()->create(), 'O omeprazol dá dor de cabeça?');
        $this->assertSame(0, $again['retrieval']['per_medicine'][0]['stored_facts']);
    }

    public function test_scenario_6_real_absence_is_said_specifically_never_filled_with_irrelevant_text_and_goes_to_human_review(): void
    {
        $this->dipirona();
        $this->queue = [
            $this->reply('none', [
                ['kind' => 'fact', 'text' => 'Antes de usar a dipirona, a bula pede avisar o médico se há pressão baixa, doença nos rins ou no fígado.', 'evidence_ids' => ['ALL']],
                ['kind' => 'limit', 'text' => 'A bula da dipirona que tenho não fala de exame de vista, então não consigo confirmar isso. Um médico ou farmacêutico pode orientar.'],
            ]),
            (string) json_encode(['found' => false, 'items' => []]),
        ];

        $user = User::factory()->create();
        $result = $this->ask($user, 'A dipirona atrapalha o exame de vista?');

        $this->assertSame(MedicationQuestionService::NO_EVIDENCE, $result['status']);
        $this->assertStringContainsString('não fala de exame de vista', (string) $result['answer'], 'a limitação específica do modelo, não uma frase genérica');
        $this->assertStringNotContainsString('rins ou no fígado', (string) $result['answer'], 'o fato sem relação foi descartado');
        $this->assertSame([], $result['evidences'], 'nenhum trecho como resposta');
        $this->assertSame(['declared_irrelevant' => 1], $result['generation']['rejection_reasons']);
        $this->assertSame([MedicationQuestion::REVIEW_PENDING, 'no_evidence'], [MedicationQuestion::query()->sole()->review_status, MedicationQuestion::query()->sole()->review_reason]);
        Livewire::actingAs($user)->test(Perguntar::class)->set('pergunta', 'A dipirona atrapalha o exame de vista?')->call('perguntar')->assertDontSee('estas partes que falam disso');
    }

    /**
     * A pergunta enviada aparece na hora como balão da pessoa (antes da resposta), e a chegada da resposta rola a tela até o
     * começo da fala do BULA-IA, não até a pergunta.
     */
    public function test_the_sent_question_shows_at_once_and_the_screen_scrolls_to_the_start_of_the_answer(): void
    {
        $this->dipirona();
        $this->scripted = $this->reply('full', [['kind' => 'fact', 'text' => 'A dipirona é indicada para o alívio da dor e para reduzir a febre.', 'evidence_ids' => ['ALL']]]);

        $component = Livewire::actingAs(User::factory()->create())->test(Perguntar::class)
            ->assertSeeHtml('data-testid="pergunta-pendente"')
            ->assertSeeHtml('wire:target="perguntar"')
            ->assertSeeHtml("\$dispatch('pergunta-enviada'")
            ->assertSeeHtml('x-on:pergunta-enviada.window');

        $component->set('pergunta', 'Para que serve a dipirona?')->call('perguntar')
            ->assertSeeHtml('data-testid="resposta-atual"')
            ->assertSeeHtml("scrollIntoView({ block: 'start'");
    }

    public function test_scenario_7_a_follow_up_keeps_the_subject_and_searches_with_the_previous_question(): void
    {
        $this->dipirona();
        $this->scripted = $this->reply('full', [['kind' => 'fact', 'text' => 'A dipirona não deve ser usada por quem tem alergia a ela ou a outros analgésicos.', 'evidence_ids' => ['ALL']]]);
        $user = User::factory()->create();
        $component = Livewire::actingAs($user)->test(Perguntar::class)->set('pergunta', 'Posso tomar dipirona?')->call('perguntar');
        $this->assertSame(['seguranca_de_uso'], $component->get('resposta')['intent']);

        $this->scripted = $this->reply('full', [['kind' => 'fact', 'text' => 'A bula diz que a dipirona não deve ser usada por pessoas com alergia à dipirona ou a outros analgésicos, por isso a alergia é o ponto mais importante.', 'evidence_ids' => ['ALL']]]);
        $component->set('pergunta', 'Mas por quê?')->call('perguntar');

        $second = $component->get('resposta');
        $this->assertSame(['seguranca_de_uso'], $second['intent'], 'a intenção da troca anterior é herdada');
        $this->assertTrue($second['retrieval']['inherited_intent']);
        $this->assertSame('conversa', $second['medicines'][0]['matched_text']);
        $prompt = $this->requests[array_key_last($this->requests)]['prompt'];
        $this->assertStringContainsString('<conversa>', $prompt);
        $this->assertStringContainsString('Paciente perguntou: "Posso tomar dipirona?"', $prompt);
        $this->assertStringContainsString('<pergunta>Mas por quê?</pergunta>', $prompt);
        $this->assertStringContainsString('Segurança de uso', $prompt);
    }

    public function test_scenario_8_a_reply_that_fails_validation_gets_one_controlled_rewrite_and_then_a_safe_fallback(): void
    {
        $this->dipirona();
        // 1) frase solta + 2) reformulada corretamente → vale a segunda, com o motivo enviado ao modelo.
        $this->queue = [
            $this->reply('full', [['kind' => 'fact', 'text' => 'Entre eles estão a alergia à dipirona ou a outros analgésicos e problemas na produção das células do sangue.', 'evidence_ids' => ['ALL']]]),
            $this->reply('full', [['kind' => 'fact', 'text' => 'A dipirona não deve ser usada por pessoas com alergia à dipirona ou a outros analgésicos, nem por quem tem problemas na produção das células do sangue.', 'evidence_ids' => ['ALL']]]),
        ];
        $result = $this->ask(User::factory()->create(), 'Quem não pode tomar dipirona?');
        $this->assertSame([MedicationQuestionService::ANSWERED, 2], [$result['status'], $result['generation']['attempts']]);
        $this->assertStringStartsWith('A dipirona não deve', (string) $result['answer']);
        $this->assertStringContainsString('REFORMULE', $this->requests[1]['prompt']);
        $this->assertStringContainsString('frase solta', $this->requests[1]['prompt']);

        // Sem evidência que sustente (afirmação sem relação com o trecho) e sem JSON válido: nunca vira trecho bruto.
        $this->requests = [];
        $this->queue = [
            $this->reply('full', [['kind' => 'fact', 'text' => 'A dipirona cura qualquer infecção bacteriana rapidamente.', 'evidence_ids' => ['ALL']]]),
            'isto não é json',
            (string) json_encode(['found' => false, 'items' => []]),
        ];
        $failed = $this->ask(User::factory()->create(), 'Para que serve dipirona?');
        $this->assertSame(MedicationQuestionService::NO_EVIDENCE, $failed['status']);
        $this->assertSame([], $failed['evidences']);
        $this->assertNull($failed['answer']);
        $this->assertSame(2, $failed['generation']['attempts']);
        $this->assertArrayHasKey('not_grounded_in_cited_evidence', $failed['generation']['rejection_reasons']);
    }

    public function test_the_validator_enforces_the_segment_contract(): void
    {
        $validator = app(ExplanationValidator::class);
        $evidence = ['E1' => 'A dipirona não deve ser usada por quem tem alergia à dipirona ou a outros analgésicos.'];
        $raw = fn (array $segments, string $coverage = 'partial'): string => (string) json_encode(['coverage' => $coverage, 'segments' => $segments]);

        $result = $validator->validateSegments($raw([
            ['kind' => 'fact', 'text' => 'Quem tem alergia à dipirona ou a outros analgésicos não deve usar a dipirona.', 'evidence_ids' => ['E1']],
            ['kind' => 'fact', 'text' => 'A dipirona é totalmente segura para qualquer pessoa em qualquer situação.', 'evidence_ids' => ['E1']],
            ['kind' => 'fact', 'text' => 'Você deve suspender o remédio e trocar por outro analgésico.', 'evidence_ids' => ['E1']],
            ['kind' => 'limit', 'text' => 'Tome 500 mg de dipirona de 6 em 6 horas.'],
            ['kind' => 'ask', 'text' => 'Você tem alergia a algum analgésico'],
            ['kind' => 'ask', 'text' => 'Você usa outros remédios?'],
            ['kind' => 'ask', 'text' => 'Você usa outros remédios?', 'evidence_ids' => []],
            ['kind' => 'poema', 'text' => 'Versos sobre a dipirona.'],
        ]), $evidence);

        $this->assertCount(1, $result['facts']);
        $this->assertSame(['Você usa outros remédios?', 'Você usa outros remédios?'], $result['asks']);
        $this->assertSame([], $result['limits']);
        $this->assertEqualsCanonicalizing(['not_grounded_in_cited_evidence', 'forbidden_recommendation', 'limit_with_dose', 'ask_without_question_mark', 'unknown_segment_kind'], array_column($result['rejected'], 'reason'));
        $this->assertSame('partial', $result['coverage']);

        // Sem perguntas quando há urgência; formato antigo ainda aceito; sem fatos a cobertura é nenhuma.
        $urgent = $validator->validateSegments($raw([['kind' => 'ask', 'text' => 'Você usa outros remédios?']]), $evidence, ['allow_asks' => false]);
        $this->assertSame(['asks_disabled'], array_column($urgent['rejected'], 'reason'));
        $legacy = $validator->validateSegments((string) json_encode(['claims' => [['text' => 'Quem tem alergia à dipirona não deve usar a dipirona.', 'evidence_ids' => ['E1']]]]), $evidence);
        $this->assertSame([1, 'full'], [count($legacy['facts']), $legacy['coverage']]);
        $this->assertTrue($validator->validateSegments('lixo', $evidence)['malformed']);
    }

    public function test_urgency_is_oriented_first_without_waiting_for_documents_and_without_asking_questions(): void
    {
        $this->dipirona();
        $detector = app(UrgencyDetector::class);
        $this->assertSame(['overdose'], $detector->detect('tomei dois comprimidos a mais de dipirona')['kinds']);
        $this->assertContains('alergia_grave', $detector->detect('meu rosto inchou depois do remédio')['kinds']);
        $this->assertNull($detector->detect('posso tomar dipirona com café?'));

        $this->scripted = $this->reply('partial', [
            ['kind' => 'fact', 'text' => 'A bula da dipirona diz que ela não deve ser usada por quem tem alergia a ela ou a outros analgésicos.', 'evidence_ids' => ['ALL']],
            ['kind' => 'ask', 'text' => 'Você tem alguma doença importante?'],
        ]);
        $user = User::factory()->create();
        Livewire::actingAs($user)->test(Perguntar::class)->set('pergunta', 'tomei dois comprimidos a mais de dipirona')->call('perguntar')
            ->assertSeeHtml('data-testid="urgencia"')->assertSee('0800 722 6001')->assertSee('192')->assertDontSee('Você tem alguma doença importante?');
        $this->assertStringContainsString('possível urgência', $this->requests[0]['prompt']);
        $this->assertSame(['overdose'], MedicationQuestion::query()->sole()->generation['urgency']);
    }

    public function test_the_screen_shows_a_conversation_with_collapsed_deduplicated_sources_and_no_raw_blocks(): void
    {
        $dipirona = $this->dipirona();
        $dipirona2 = AnvisaMedicine::factory()->create(['product_name' => 'DIPIRONA', 'normalized_product_name' => 'dipirona', 'active_ingredient' => 'DIPIRONA MONOIDRATADA', 'normalized_active_ingredient' => 'dipirona monoidratada']);
        $this->scripted = $this->reply('full', [
            ['kind' => 'fact', 'text' => 'A dipirona não deve ser usada por quem tem alergia a ela ou a outros analgésicos.', 'evidence_ids' => ['ALL']],
            ['kind' => 'fact', 'text' => 'Quem tem problemas na produção das células do sangue também não deve usar a dipirona.', 'evidence_ids' => ['ALL']],
            ['kind' => 'limit', 'text' => 'Se você pode tomar depende da sua saúde e de um médico ou farmacêutico.'],
            ['kind' => 'ask', 'text' => 'Você já teve alergia a algum analgésico?'],
        ]);

        $component = Livewire::actingAs(User::factory()->create())->test(Perguntar::class)->set('pergunta', 'Posso tomar dipirona?')->call('perguntar');
        $component->assertSee('Se você pode tomar depende da sua saúde')->assertSee('Você já teve alergia a algum analgésico?')->assertSee('Baseado na bula do paciente de DIPIRONA.')->assertSee('Fontes utilizadas')
            ->assertSeeHtml('whitespace-pre-line');
        $this->assertSame(['DIPIRONA'], array_column($component->get('resposta')['medicines'], 'name'), 'o mesmo remédio uma só vez');
        $html = $component->html();
        $this->assertSame(1, substr_count(preg_replace('/<!--.*?-->/s', '', $html), 'CONTRAINDICAÇÕES · página 3'), 'a mesma seção e página aparece uma vez nas fontes');
        $this->assertStringContainsString('<details', $html);
        $this->assertNotNull($dipirona->id);
        $this->assertNotNull($dipirona2->id);
    }

    public function test_the_assistant_being_unavailable_is_not_confused_with_missing_evidence_and_shows_no_excerpt(): void
    {
        $this->dipirona();
        config(['ai.text.default' => 'inexistente']);
        $manager = new AiProviderManager;
        $this->app->instance(AiProviderManager::class, $manager);

        $result = $this->ask(User::factory()->create(), 'Para que serve dipirona?');

        $this->assertContains($result['status'], [MedicationQuestionService::UNAVAILABLE, MedicationQuestionService::NO_EVIDENCE]);
        $this->assertSame([], $result['evidences']);
        $this->assertNull($result['answer']);
    }

    public function test_the_intent_plan_covers_composite_questions_and_inherits_in_follow_ups(): void
    {
        $intents = app(QuestionIntentDetector::class);
        $this->assertSame(['para_que_serve', 'nao_use_se', 'riscos'], $intents->plan('Posso tomar ibuprofeno?')['sections']);
        $this->assertSame(['para_que_serve'], $intents->plan('Para que serve o ibuprofeno?')['sections']);
        $this->assertSame(['para_que_serve', 'riscos', 'efeitos_colaterais', 'interacoes'], $intents->plan('Qual a diferença entre ibuprofeno e paracetamol?', 2)['sections']);
        $ambiguous = $intents->plan('ibuprofeno');
        $this->assertSame([true, ['para_que_serve', 'riscos']], [$ambiguous['ambiguous'], $ambiguous['sections']]);
        $followUp = $intents->plan('Mas por quê?', 1, ['efeitos_colaterais']);
        $this->assertSame([true, ['efeitos_colaterais'], false], [$followUp['inherited'], $followUp['sections'], $followUp['ambiguous']]);
    }

    public function test_the_evaluation_rubric_separates_five_dimensions_and_catches_the_old_failures(): void
    {
        $evaluator = app(ConversationEvaluator::class);
        $base = ['scenario' => 'C2', 'status' => 'answered', 'medicines' => ['X'], 'generation' => ['claims_accepted' => 2], 'raw_excerpts_shown' => 0, 'expects' => ['facts' => true, 'asks' => true, 'limit' => true]];

        // Resposta boa: fatos, limite declarado e uma pergunta; negar "é seguro" não é concluir segurança.
        $good = $evaluator->score($base + ['answer' => "O ibuprofeno serve para aliviar a dor.\n\nNão dá para dizer que é seguro para você; isso depende da sua saúde e de um médico ou farmacêutico.\n\nVocê tem alergia a algum remédio?"]);
        $this->assertSame(['relevancia' => true, 'ancoragem' => true, 'seguranca' => true, 'clareza' => true, 'naturalidade' => true], $good['scores']);

        // Resposta antiga: sem pergunta, sem limite, abre com frase solta.
        $bad = $evaluator->score($base + ['answer' => 'Entre eles estão a aspirina e o paracetamol e outros remédios para a dor.']);
        $this->assertSame([false, true, false, true, false], array_values($bad['scores']));
        $this->assertContains('abre com frase solta', $bad['notes']);

        // Conclusão de segurança individual e trecho bruto reprovam.
        $unsafe = $evaluator->score($base + ['answer' => 'Sim, é seguro para você. Pode tomar sem problema. Depende de nada.']);
        $this->assertFalse($unsafe['scores']['seguranca']);
        $raw = $evaluator->score(['status' => 'excerpts_only', 'answer' => null, 'raw_excerpts_shown' => 2, 'expects' => ['facts' => true]]);
        $this->assertFalse($raw['scores']['ancoragem']);
        $this->assertSame(['passed' => 1, 'total' => 3], $evaluator->summarize([['score' => $good], ['score' => $bad], ['score' => $unsafe]])['seguranca']);
    }
}
