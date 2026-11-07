<?php

namespace Tests\Feature\Consulta;

use App\Data\Ai\TextGenerationRequest;
use App\Data\Ai\TextGenerationResponse;
use App\Jobs\BuildActiveIngredientCatalog;
use App\Jobs\ImportDrugInteractions;
use App\Livewire\Admin\Documents\Show as DocumentShow;
use App\Livewire\Admin\Knowledge\UnansweredQuestions\Index as UnansweredIndex;
use App\Livewire\Consulta\Bula;
use App\Livewire\Consulta\Perguntar;
use App\Models\Consultation\MedicationQuestion;
use App\Models\Knowledge\ActiveIngredient;
use App\Models\Knowledge\ActiveIngredientAlias;
use App\Models\Knowledge\AnvisaMedicine;
use App\Models\Knowledge\DrugInteractionImport;
use App\Models\Knowledge\KnowledgeDocument;
use App\Models\Knowledge\KnowledgeLeafletFact;
use App\Models\Knowledge\KnowledgeSource;
use App\Models\User;
use App\Services\Ai\AiProviderManager;
use App\Services\Ai\TextGenerationProvider;
use App\Services\Knowledge\ActiveIngredientCatalogBuilder;
use App\Services\Knowledge\DrugInteractionCsvImporter;
use App\Services\Knowledge\KnowledgeDocumentIngredientLinker;
use App\Services\Knowledge\MedicineLeafletFinder;
use App\Services\Patient\MedicationMentionDetector;
use App\Services\Patient\MedicationQuestionService;
use App\Services\Patient\QuestionIntentDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Perguntar confiável e humano: detecção robusta mesmo com o catálogo sem vínculos, bula achada pelo nome, intenção da
 * pergunta → seção da bula, consulta à bula inteira com evidência auditável, resposta honesta com revisão humana e
 * tradução de princípios ativos por IA para as interações.
 */
final class PerguntarConfiavelTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> respostas do provedor, consumidas em ordem; esgotada a fila, vale `$scripted` */
    public array $queue = [];

    public ?string $scripted = null;

    public int $providerCalls = 0;

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
            public function __construct(private readonly PerguntarConfiavelTest $test) {}

            public function generate(TextGenerationRequest $request): TextGenerationResponse
            {
                $this->test->providerCalls++;
                $this->test->requests[] = ['prompt' => $request->prompt, 'system' => $request->system];
                $content = $this->test->queue !== [] ? array_shift($this->test->queue) : (string) $this->test->scripted;

                return new TextGenerationResponse($content, 'scripted-model', null);
            }
        });
        $this->app->instance(AiProviderManager::class, $manager);
    }

    private function medicine(string $name, string $ingredient, ?string $linkedIngredient = null, string $status = 'Ativo'): AnvisaMedicine
    {
        $medicine = AnvisaMedicine::factory()->create(['product_name' => $name, 'normalized_product_name' => mb_strtolower($name), 'active_ingredient' => $ingredient, 'normalized_active_ingredient' => mb_strtolower($ingredient), 'registration_status' => $status]);
        if ($linkedIngredient !== null) {
            $active = ActiveIngredient::query()->firstOrCreate(['normalized_name' => $linkedIngredient], ['canonical_name' => ucfirst($linkedIngredient)]);
            $medicine->ingredients()->attach($active->id, ['component_text' => $ingredient, 'method' => 'alias', 'confidence' => 1]);
        }

        return $medicine;
    }

    /** @param array<string, string> $sections seção => texto */
    private function leaflet(string $title, array $sections, string $type = 'bula_paciente', array $metadata = [], ?int $ingredientId = null): KnowledgeDocument
    {
        $source = KnowledgeSource::query()->firstOrCreate(['name' => 'ANVISA', 'organization' => null], ['type' => 'document', 'active' => true]);
        $document = KnowledgeDocument::query()->create(['source_id' => $source->id, 'external_key' => uniqid('d_'), 'title' => $title, 'document_type' => $type, 'language' => 'pt-BR', 'metadata' => $metadata, 'content_status' => 'READY', 'file_status' => 'READY', 'embedding_status' => 'PENDING', 'content_hash' => 'hash-'.uniqid(), 'sha256' => hash('sha256', uniqid('pdf_', true))]);
        $index = 0;
        foreach ($sections as $section => $content) {
            $document->chunks()->create(['chunk_index' => $index, 'section' => $section, 'page_start' => $index + 3, 'page_end' => $index + 3, 'content' => $content, 'metadata' => []]);
            $index++;
        }
        if ($ingredientId !== null) {
            DB::table('knowledge_document_ingredients')->insert(['document_id' => $document->id, 'ingredient_id' => $ingredientId, 'method' => 'registration', 'confidence' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }

        return $document;
    }

    /** @return list<string> */
    private function detected(string $text): array
    {
        return array_map(fn (array $m): string => $m['name'].'='.$m['matched_text'].($m['identified'] ? '' : '|sem-vinculo'), app(MedicationMentionDetector::class)->detect($text));
    }

    public function test_detection_works_with_a_catalog_without_ingredient_links_product_suffixes_and_split_dictation(): void
    {
        foreach (['EMS', 'MEDLEY', 'EUROFARMA'] as $i => $holder) {
            $this->medicine('LOSARTANA POTASSICA', $i === 2 ? 'LOSARTANA POTÁSSICA 50MG' : 'LOSARTANA POTASSICA');
        }
        $this->medicine('LOSARTANA POTASSICA + HIDROCLOROTIAZIDA', 'LOSARTANA POTASSICA + HIDROCLOROTIAZIDA');
        foreach (range(1, 5) as $i) {
            $this->medicine('DIPIRONA', 'DIPIRONA MONOIDRATADA');
        }
        $this->medicine('DIPIRONA SODICA', 'DIPIRONA SODICA');
        $this->medicine('NOVALGINA', 'DIPIRONA');
        $this->medicine('DIPIRONA + CAFEINA', 'DIPIRONA MONOIDRATADA + CAFEINA');
        $this->medicine('NAPRIX', 'RAMIPRIL', 'ramipril');
        $this->medicine('NAPRIX D', 'RAMIPRIL + HIDROCLOROTIAZIDA');
        $this->medicine('NAPRIX A', 'RAMIPRIL + BESILATO DE ANLODIPINO');
        $this->medicine('OMEPRAZOL', 'OMEPRAZOL', 'omeprazole');

        // Centenas de grafias do mesmo ativo sem vínculo não viram "ambíguo" nem expulsam o outro remédio da resposta.
        $this->assertSame(['LOSARTANA POTASSICA=losartana|sem-vinculo', 'DIPIRONA=dipirona|sem-vinculo'], $this->detected('posso tomar losartana com dipirona?'));
        $this->assertSame(['NAPRIX D=naprix d|sem-vinculo'], $this->detected('quais os efeitos colaterais para naprix d?'), 'sufixo de linha de produto');
        $this->assertSame(['NAPRIX A=naprix a|sem-vinculo'], $this->detected('naprix a dá tosse?'));
        $this->assertSame(['NAPRIX=naprix'], $this->detected('naprix dá tosse?'));
        $this->assertSame(['OMEPRAZOL=omeprazol'], $this->detected('ome prazol em jejum?'), 'ditado que parte o nome');

        $this->assertSame(['interacoes', 'como_usar'], app(QuestionIntentDetector::class)->topics('posso tomar losartana junto com dipirona de manhã?', 2));
        $this->assertSame(['efeitos_colaterais'], app(QuestionIntentDetector::class)->topics('Quais os efeitos colatereais para naprix d?'));
        $this->assertSame([], app(QuestionIntentDetector::class)->topics('naprix é bom?'));
    }

    public function test_when_nothing_is_recognized_the_chat_offers_similar_names_to_tap(): void
    {
        $user = User::factory()->create();
        $dorflex = $this->medicine('DORFLEX', 'DIPIRONA + ORFENADRINA + CAFEINA', 'metamizole');
        $this->medicine('DORILAX', 'PARACETAMOL + CARISOPRODOL', 'acetaminophen');

        $component = Livewire::actingAs($user)->test(Perguntar::class)->set('pergunta', 'dorflax dá sono?')->call('perguntar')
            ->assertSee('Não reconheci o nome do remédio. Você quis dizer um destes?')->assertSeeHtml('data-testid="guesses"')->assertSee('DORFLEX')->assertSee('DORILAX');
        $this->assertSame(0, $this->providerCalls);
        $record = MedicationQuestion::query()->sole();
        $this->assertSame([MedicationQuestionService::NO_MEDICINE, null], [$record->status, $record->review_status]);

        $this->leaflet('DORFLEX — Bula do paciente', ['REAÇÕES ADVERSAS' => 'Pode dar sono e tontura em algumas pessoas.'], ingredientId: $dorflex->ingredients()->value('active_ingredients.id'));
        $this->scripted = json_encode(['claims' => [['text' => 'A bula diz que pode dar sono e tontura em algumas pessoas.', 'evidence_ids' => ['E1']]]]);
        $component->call('corrigir', $dorflex->id)->assertSee('A bula diz que pode dar sono e tontura em algumas pessoas.')->assertSee('Li isso na bula do paciente de DORFLEX, parte “REAÇÕES ADVERSAS”, página 3.');
    }

    public function test_a_medicine_without_catalog_link_is_linked_on_the_fly_or_its_leaflet_is_found_by_name(): void
    {
        $user = User::factory()->create();
        $enalapril = ActiveIngredient::query()->create(['normalized_name' => 'enalapril', 'canonical_name' => 'Enalapril']);
        ActiveIngredientAlias::query()->create(['ingredient_id' => $enalapril->id, 'alias' => 'enalapril', 'normalized_alias' => 'enalapril', 'language' => 'pt', 'origin' => 'curated', 'review_status' => 'reviewed']);
        $naprix = $this->medicine('ENALAPRIL GENÉRICO', 'MALEATO DE ENALAPRIL');
        $renitec = $this->medicine('RENITEC', 'MALEATO DE ENALAPRIL');
        $xpto = $this->medicine('XPTO', 'SUBSTANCIA INEXISTENTE');
        $linked = $this->leaflet('enalapril — Bula do paciente', ['REAÇÕES ADVERSAS' => 'Tosse seca é uma reação comum.'], ingredientId: $enalapril->id);
        $byName = $this->leaflet('RENITEC® — Bula do profissional de saúde', ['REAÇÕES ADVERSAS' => 'Tosse seca persistente foi relatada.']);

        // 1. Catálogo sem vínculo, mas com o ativo resolvível pelos aliases: liga na hora e grava.
        $found = app(MedicineLeafletFinder::class)->find($naprix);
        $this->assertSame([[$enalapril->id], [$linked->id], MedicineLeafletFinder::BY_INGREDIENTS, 1], [$found['ingredient_ids'], $found['document_ids'], $found['method'], $found['linked_now']]);
        $this->assertSame('salt_stripped', DB::table('anvisa_medicine_ingredients')->where('medicine_id', $naprix->id)->value('method'));

        // 2. Sem princípio ativo nenhum: a bula é achada pelo nome do produto (RENITEC® no título).
        DB::table('active_ingredient_aliases')->delete();
        DB::table('anvisa_medicine_ingredients')->where('medicine_id', $renitec->id)->delete();
        $found = app(MedicineLeafletFinder::class)->find($renitec->fresh());
        // Pelo nome: a bula RENITEC® (produto) e a genérica de enalapril (mesmo princípio ativo declarado, sem sal).
        $this->assertSame([[], [$linked->id, $byName->id], MedicineLeafletFinder::BY_NAME], [$found['ingredient_ids'], $found['document_ids'], $found['method']]);
        $this->assertSame([[], [], null], array_values(array_slice(app(MedicineLeafletFinder::class)->find($xpto), 0, 3)));

        // 3. No Perguntar e na Bula simples o RENITEC responde; o XPTO entra na fila de revisão.
        $this->scripted = json_encode(['claims' => [['text' => 'A bula diz que pode dar tosse seca.', 'evidence_ids' => ['E1']]]]);
        Livewire::actingAs($user)->test(Perguntar::class)->set('pergunta', 'renitec dá tosse?')->call('perguntar')
            ->assertSee('A bula diz que pode dar tosse seca.')->assertSee('Li isso na bula')->assertSee('de RENITEC, parte “REAÇÕES ADVERSAS”, página 3.');
        $this->assertSame(MedicineLeafletFinder::BY_NAME, MedicationQuestion::query()->latest('id')->first()->retrieval['per_medicine'][0]['found_by']);
        Livewire::actingAs($user)->test(Bula::class, ['medicine' => $renitec->fresh()])->assertSet('estado', Bula::LOADING);
        Livewire::actingAs($user)->test(Bula::class, ['medicine' => $xpto])->assertSet('estado', Bula::NOT_IDENTIFIED);

        Livewire::actingAs($user)->test(Perguntar::class)->set('pergunta', 'xpto dá tosse?')->call('perguntar')
            ->assertSee('Conheço o XPTO, mas ainda não tenho a bula dele comigo.')->assertSee('Registrei sua pergunta para a equipe buscar mais fontes.');
        $record = MedicationQuestion::query()->latest('id')->first();
        $this->assertSame([MedicationQuestionService::NOT_IDENTIFIED, MedicationQuestion::REVIEW_PENDING, 'not_identified'], [$record->status, $record->review_status, $record->review_reason]);
    }

    public function test_the_whole_leaflet_is_consulted_when_excerpts_do_not_answer_and_the_evidence_is_stored_and_reused(): void
    {
        $user = User::factory()->create();
        $medicine = $this->medicine('OMEPRAZOL', 'OMEPRAZOL', 'omeprazole');
        $document = $this->leaflet('OMEPRAZOL — Bula do paciente', [
            'POSOLOGIA' => 'Tomar um comprimido pela manhã, em jejum, com água.',
            'REAÇÕES ADVERSAS' => 'Reações comuns: dor de cabeça, diarreia e náusea. Reação rara: tontura ao levantar.',
        ], ingredientId: $medicine->ingredients()->value('active_ingredients.id'));
        $chunk = $document->chunks()->where('section', 'REAÇÕES ADVERSAS')->sole();

        // Primeira tentativa: o modelo declara insuficiente → bula inteira → trecho literal aceito → resposta com a evidência.
        $this->queue = [
            json_encode(['insufficient' => true, 'claims' => []]),
            json_encode(['found' => true, 'items' => [
                ['chunk' => $chunk->id, 'excerpt' => 'Reações comuns: dor de cabeça, diarreia e náusea.', 'summary' => 'Dor de cabeça é uma reação comum.', 'confidence' => 0.93],
                ['chunk' => $chunk->id, 'excerpt' => 'Texto que não está na bula de jeito nenhum, inventado pelo modelo.', 'summary' => 'inventado', 'confidence' => 0.9],
                ['chunk' => 999999, 'excerpt' => 'Reações comuns: dor de cabeça, diarreia e náusea.', 'summary' => 'trecho errado', 'confidence' => 0.9],
            ]]),
            json_encode(['claims' => [['text' => 'A bula diz que dor de cabeça é uma reação comum, junto com diarreia e náusea.', 'evidence_ids' => ['E1']]]]),
        ];
        $component = Livewire::actingAs($user)->test(Perguntar::class)->set('pergunta', 'omeprazol dá dor de cabeça?')->call('perguntar')
            ->assertSeeHtml('data-testid="fala-intro"')
            ->assertSee('Encontrei na bula de OMEPRAZOL a parte sobre efeitos colaterais mais comuns. Os efeitos relatados podem variar de pessoa para pessoa')
            ->assertSee('A bula diz que dor de cabeça é uma reação comum, junto com diarreia e náusea.')
            ->assertSee('Li isso na bula do paciente de OMEPRAZOL, parte “REAÇÕES ADVERSAS”, página 4.')->assertSee('De onde tirei isso');
        $this->assertSame(3, $this->providerCalls);
        $this->assertStringContainsString('<bula>', $this->requests[1]['prompt']);
        $this->assertStringContainsString('Assunto da pergunta (seção esperada): Efeitos colaterais mais comuns', $this->requests[1]['prompt']);
        $this->assertStringContainsString('seção adequada da bula', (string) $this->requests[1]['system']);
        $this->assertStringContainsString('tratando por "você"', (string) $this->requests[2]['system'], 'prompt humanizado (v2)');

        $fact = KnowledgeLeafletFact::query()->sole();
        $this->assertSame([$document->id, $chunk->id, $medicine->id, 'efeitos_colaterais', 'REAÇÕES ADVERSAS', 4, 'Reações comuns: dor de cabeça, diarreia e náusea.', 0.93, 'pdf_fallback_ai', 'pending', $document->sha256, $document->content_hash, 'scripted-model', 'omeprazol dá dor de cabeça?'],
            [$fact->document_id, $fact->chunk_id, $fact->medicine_id, $fact->topic, $fact->section, $fact->page_start, $fact->excerpt, $fact->confidence, $fact->extraction_method, $fact->review_status, $fact->document_sha256, $fact->document_version, $fact->model, $fact->question]);
        $record = MedicationQuestion::query()->sole();
        $this->assertSame([MedicationQuestionService::ANSWERED, null, 'interaction-explanation-v2:qa', 'leaflet-evidence-v1'], [$record->status, $record->review_status, $record->pipeline['prompt_version'], $record->pipeline['leaflet_fallback']]);
        $this->assertSame([1, 2], [$record->retrieval['leaflet_fallback']['accepted'], $record->retrieval['leaflet_fallback']['per_medicine'][0]['rejected']]);
        $this->assertEquals(['excerpt_not_in_chunk' => 1, 'unknown_chunk' => 1], $record->retrieval['leaflet_fallback']['per_medicine'][0]['documents'][0]['rejection_reasons']);
        $this->assertSame('pdf_fallback_ai', collect($record->evidences)->firstWhere('chunk_id', $chunk->id)['method']);

        // Pergunta seguinte do mesmo assunto: a evidência gravada entra direto; a bula inteira não é lida de novo.
        $this->queue = [json_encode(['claims' => [['text' => 'A bula diz que diarreia está entre as reações comuns.', 'evidence_ids' => ['E1']]]])];
        $component->call('novaPergunta')->set('pergunta', 'omeprazol causa diarreia?')->call('perguntar')->assertSee('A bula diz que diarreia está entre as reações comuns.');
        $this->assertSame(4, $this->providerCalls);
        $latest = MedicationQuestion::query()->latest('id')->first();
        $this->assertSame([1, null], [$latest->retrieval['per_medicine'][0]['stored_facts'], $latest->retrieval['leaflet_fallback']]);

        // Rejeitada pela revisão farmacêutica, a evidência deixa de ser usada.
        $admin = User::factory()->admin()->create();
        Livewire::actingAs($admin)->test(DocumentShow::class, ['document' => $document])
            ->assertSee('Evidências extraídas (1)')->assertSee('Reações comuns: dor de cabeça, diarreia e náusea.')->assertSee('Aguardando revisão')->assertSee('pdf_fallback_ai')
            ->call('reviewFact', $fact->id, 'rejected')->assertSee('Evidência rejeitada');
        $this->assertSame('rejected', $fact->fresh()->review_status);
        $this->queue = [json_encode(['insufficient' => true, 'claims' => []]), json_encode(['found' => false, 'items' => []])];
        $component->call('novaPergunta')->set('pergunta', 'omeprazol causa diarreia?')->call('perguntar')->assertSee('Procurei na bula inteira de OMEPRAZOL');
        $this->assertSame(0, MedicationQuestion::query()->latest('id')->first()->retrieval['per_medicine'][0]['stored_facts']);
    }

    public function test_insufficient_evidence_is_said_honestly_without_irrelevant_excerpts_and_queued_for_human_review(): void
    {
        $user = User::factory()->create();
        $medicine = $this->medicine('NAPRIX', 'RAMIPRIL', 'ramipril');
        $this->leaflet('NAPRIX A — Bula do profissional de saúde', [
            'POSOLOGIA' => 'O anlodipino e o ramipril são efetivos no tratamento da hipertensão arterial em doses diárias de 2,5 mg a 10 mg.',
            'APRESENTAÇÕES' => 'Cápsulas duras com 2,5 mg de ramipril base e 5 mg de besilato de anlodipino com 30 unidades.',
        ], 'bula_profissional', ingredientId: $medicine->ingredients()->value('active_ingredients.id'));
        $this->queue = [json_encode(['insufficient' => true, 'claims' => []]), json_encode(['found' => false, 'items' => []])];

        $component = Livewire::actingAs($user)->test(Perguntar::class)->set('pergunta', 'Quais os efeitos colatereais para naprix?')->call('perguntar')
            ->assertSee('Procurei na bula inteira de NAPRIX, mas não localizei uma informação suficientemente clara sobre efeitos colaterais mais comuns. Prefiro não completar a resposta com suposições.')
            ->assertSee('O que encontrei fala de')->assertSee('Isso não quer dizer que seja seguro')
            ->assertSee('Se você estiver sentindo algum sintoma ou teve uma reação depois de usar o remédio, vale falar com um farmacêutico ou médico.')
            ->assertDontSee('Cápsulas duras')->assertDontSee('são efetivos no tratamento')->assertDontSee('Não consegui montar uma resposta curta');
        $this->assertSame(2, $this->providerCalls);
        $record = MedicationQuestion::query()->sole();
        $this->assertSame([MedicationQuestionService::NO_EVIDENCE, 'pending', 'no_evidence', [], true, false], [$record->status, $record->review_status, $record->review_reason, $record->evidences, $record->retrieval['fallback_consulted'], $record->retrieval['section_matched']]);
        $this->assertSame(0, KnowledgeLeafletFact::query()->count());

        // Fila de revisão humana: agrupada por remédio e motivo; "Resolvida" fecha todas as perguntas do grupo.
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get(route('admin.knowledge.unanswered.index'))->assertOk()->assertSee('Perguntas sem resposta')->assertSee('NAPRIX')->assertSee('Bula sem resposta clara')->assertSee('Quais os efeitos colatereais para naprix?');
        Livewire::actingAs($admin)->test(UnansweredIndex::class)->call('resolver', $medicine->id, 'NAPRIX', 'no_evidence')->assertSee('marcada(s) como resolvida(s)')->assertDontSee('Bula sem resposta clara');
        $this->assertSame([MedicationQuestion::REVIEW_RESOLVED, $admin->id], [$record->fresh()->review_status, $record->fresh()->reviewed_by]);

        // Com a IA fora do ar e trechos da seção certa, a tela mostra só esses trechos (nunca os de outra seção).
        $this->leaflet('NAPRIX — Bula do paciente', ['REAÇÕES ADVERSAS' => 'Tosse seca é uma reação comum ao ramipril.'], ingredientId: $medicine->ingredients()->value('active_ingredients.id'));
        $this->queue = ['não é json', 'não é json'];
        $component->call('novaPergunta')->set('pergunta', 'naprix dá tosse? quais os efeitos colaterais?')->call('perguntar')
            ->assertSee('Não consegui montar uma resposta curta com segurança')->assertSee('Tosse seca é uma reação comum ao ramipril.')->assertDontSee('Cápsulas duras');
        $this->assertSame(MedicationQuestionService::EXCERPTS_ONLY, MedicationQuestion::query()->latest('id')->first()->status);
    }

    public function test_importing_interactions_or_the_catalog_queues_the_ingredient_catalog_build(): void
    {
        Storage::fake(config('knowledge.filesystem_disk'));
        Queue::fake([BuildActiveIngredientCatalog::class]);
        $body = "DDInterID_A,Drug_A,DDInterID_B,Drug_B,Level\nDDInter1,Warfarin,DDInter2,Fluconazole,Major\n";
        Storage::disk(config('knowledge.filesystem_disk'))->put('imports/ddinter.csv', $body);
        $import = DrugInteractionImport::factory()->create(['status' => 'QUEUED', 'stored_path' => 'imports/ddinter.csv', 'sha256' => hash('sha256', $body)]);

        (new ImportDrugInteractions($import->id))->handle(app(DrugInteractionCsvImporter::class));

        $this->assertSame('COMPLETED', $import->fresh()->status);
        Queue::assertPushed(BuildActiveIngredientCatalog::class, fn (BuildActiveIngredientCatalog $job): bool => $job->trigger === 'drug_interactions_import');

        // O job faz o que o comando faz: catálogo de princípios ativos, tradução por IA e vínculo das bulas.
        $this->scripted = json_encode(['translations' => []]);
        (new BuildActiveIngredientCatalog('test'))->handle(app(ActiveIngredientCatalogBuilder::class), app(KnowledgeDocumentIngredientLinker::class));
        $this->assertSame(['fluconazole', 'warfarin'], ActiveIngredient::query()->orderBy('normalized_name')->pluck('normalized_name')->all());
    }

    public function test_names_the_ai_could_not_match_in_ddinter_are_remembered_and_not_sent_again(): void
    {
        $metamizole = ActiveIngredient::query()->create(['normalized_name' => 'metamizole', 'canonical_name' => 'Metamizole']);
        ActiveIngredientAlias::query()->create(['ingredient_id' => $metamizole->id, 'alias' => 'metamizole', 'normalized_alias' => 'metamizole', 'language' => 'en', 'origin' => 'ddinter', 'review_status' => 'reviewed']);
        $this->medicine('NOVALGINA', 'DIPIRONA');
        $this->medicine('OUTRO', 'BROMOPRIDA');
        $this->scripted = json_encode(['translations' => [['pt' => 'dipirona', 'en' => 'metamizole'], ['pt' => 'bromoprida', 'en' => 'bromopride']]]);

        $first = app(ActiveIngredientCatalogBuilder::class)->translateUnresolved();
        $this->assertSame([2, 1, ['bromoprida']], [$first['names'], $first['accepted'], $first['unknown']]);
        $this->assertSame(['bromoprida'], DB::table('ingredient_translation_misses')->pluck('normalized_name')->all());

        // Segunda execução: a bromoprida (que o DDInter não tem) não volta à IA; só resta o que ainda não foi tentado.
        $this->providerCalls = 0;
        $this->medicine('TERCEIRO', 'DIOSMINA');
        $this->scripted = json_encode(['translations' => [['pt' => 'diosmina', 'en' => null]]]);
        $second = app(ActiveIngredientCatalogBuilder::class)->translateUnresolved();
        $this->assertSame([1, 1, 1], [$second['names'], $second['skipped_known_misses'], $this->providerCalls]);
        $this->assertStringNotContainsString('bromoprida', $this->requests[array_key_last($this->requests)]['prompt']);

        // --retry-misses esquece os recusados e tenta tudo de novo.
        $third = app(ActiveIngredientCatalogBuilder::class)->translateUnresolved(retryMisses: true);
        $this->assertSame([2, 0], [$third['names'], $third['skipped_known_misses']]);
    }

    public function test_a_truncated_translation_reply_still_keeps_the_complete_pairs(): void
    {
        $metamizole = ActiveIngredient::query()->create(['normalized_name' => 'metamizole', 'canonical_name' => 'Metamizole']);
        ActiveIngredientAlias::query()->create(['ingredient_id' => $metamizole->id, 'alias' => 'metamizole', 'normalized_alias' => 'metamizole', 'language' => 'en', 'origin' => 'ddinter', 'review_status' => 'reviewed']);
        $this->medicine('NOVALGINA', 'DIPIRONA');
        $this->medicine('OUTRO', 'CARBETOCINA');
        // Resposta cortada no meio pelo limite de tokens: o primeiro par está completo, o segundo não.
        $this->scripted = '{"translations": [{"pt": "dipirona", "en": "metamizole"}, {"pt": "carbetocina", "en": "carbet';

        $stats = app(ActiveIngredientCatalogBuilder::class)->translateUnresolved();

        $this->assertSame([2, 1, 1], [$stats['names'], $stats['proposed'], $stats['accepted']]);
        $this->assertSame(['metamizole'], AnvisaMedicine::query()->where('product_name', 'NOVALGINA')->firstOrFail()->ingredients->pluck('normalized_name')->all());
    }

    public function test_ai_translation_links_portuguese_ingredient_names_to_ddinter_so_interactions_work(): void
    {
        $metamizole = ActiveIngredient::query()->create(['normalized_name' => 'metamizole', 'canonical_name' => 'Metamizole']);
        ActiveIngredientAlias::query()->create(['ingredient_id' => $metamizole->id, 'alias' => 'metamizole', 'normalized_alias' => 'metamizole', 'language' => 'en', 'origin' => 'ddinter', 'review_status' => 'reviewed']);
        ActiveIngredient::query()->create(['normalized_name' => 'zolpidem', 'canonical_name' => 'Zolpidem']);
        $novalgina = $this->medicine('NOVALGINA', 'DIPIRONA');
        $zolpidem = $this->medicine('STILNOX', 'HEMITARTARATO DE ZOLPIDEM');
        $this->medicine('XPTO', 'SUBSTANCIA INEXISTENTE');
        $this->scripted = json_encode(['translations' => [['pt' => 'dipirona', 'en' => 'metamizole'], ['pt' => 'zolpidem', 'en' => 'zolpidem'], ['pt' => 'substancia inexistente', 'en' => 'foobar']]]);

        $stats = app(ActiveIngredientCatalogBuilder::class)->translateUnresolved();

        $this->assertSame([3, 3, 2, 2, ['substancia inexistente']], [$stats['names'], $stats['proposed'], $stats['accepted'], $stats['medicines_linked'], $stats['unknown']]);
        $this->assertSame(['metamizole'], $novalgina->fresh()->ingredients->pluck('normalized_name')->all());
        $this->assertSame(['zolpidem'], $zolpidem->fresh()->ingredients->pluck('normalized_name')->all());
        $alias = ActiveIngredientAlias::query()->where('normalized_alias', 'dipirona')->sole();
        $this->assertSame(['ai', 'pending_review', 'pt'], [$alias->origin, $alias->review_status, $alias->language]);
        $this->assertStringContainsString('- dipirona', $this->requests[0]['prompt']);

        // Na hora da pergunta também: um remédio sem vínculo cujo ativo a IA sabe traduzir é ligado ao responder.
        $this->providerCalls = 0;
        $this->scripted = json_encode(['translations' => [['pt' => 'ambroxol', 'en' => 'ambroxol']]]);
        ActiveIngredient::query()->create(['normalized_name' => 'ambroxol', 'canonical_name' => 'Ambroxol']);
        $mucosolvan = $this->medicine('MUCOSOLVAN', 'CLORIDRATO DE AMBROXOL');
        $found = app(MedicineLeafletFinder::class)->find($mucosolvan);
        $this->assertSame([1, 'ai_translation'], [$found['linked_now'], DB::table('anvisa_medicine_ingredients')->where('medicine_id', $mucosolvan->id)->value('method')]);
        $this->assertSame(1, $this->providerCalls);

        // Desligada (ou sem provedor), nada é proposto.
        config(['bulai.ingredients.ai_translation.enabled' => false]);
        $this->assertSame('disabled', app(ActiveIngredientCatalogBuilder::class)->translateUnresolved()['reason']);
    }
}
