<?php

namespace Tests\Feature\Knowledge;

use App\Data\Ai\TextGenerationRequest;
use App\Data\Ai\TextGenerationResponse;
use App\Jobs\ImportExternalLabel;
use App\Livewire\Consulta\Perguntar;
use App\Models\Knowledge\ActiveIngredient;
use App\Models\Knowledge\AnvisaMedicine;
use App\Models\Knowledge\ExternalLabel;
use App\Models\Knowledge\ExternalLabelSection;
use App\Models\Knowledge\KnowledgeDocument;
use App\Models\Knowledge\KnowledgeSource;
use App\Models\User;
use App\Services\Ai\AiProviderManager;
use App\Services\Ai\TextGenerationProvider;
use App\Services\Interactions\PairAnalyzer;
use App\Services\Interactions\PairVerdict;
use App\Services\Knowledge\External\ExternalEvidenceService;
use App\Services\Knowledge\External\ExternalLabelImporter;
use App\Services\Patient\MedicationQuestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Bula dos EUA (FDA) como complemento: openFDA como fonte principal e DailyMed como reserva, só interações e reações
 * adversas, tradução conferida, origem guardada, crédito na resposta, e uso apenas quando as bulas brasileiras não cobrem.
 */
final class ExternalLabelsTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, string> trecho em inglês => tradução roteirizada */
    public array $translations = [];

    public string $answer = '{"coverage":"none","segments":[]}';

    /** @var list<array{purpose: string|null, prompt: string}> */
    public array $requests = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['bulai.external_labels.enabled' => true, 'bulai.generation.enabled' => true, 'ai.text.default' => 'scripted', 'bulai.retrieval.mode' => 'lexical', 'bulai.external_labels.chunk_chars' => 400]);
        $test = $this;
        $manager = new AiProviderManager;
        $manager->extend(AiProviderManager::TEXT, 'scripted', fn () => new class($test) implements TextGenerationProvider
        {
            public function __construct(private readonly ExternalLabelsTest $test) {}

            public function generate(TextGenerationRequest $request): TextGenerationResponse
            {
                $purpose = $request->metadata['purpose'] ?? null;
                $this->test->requests[] = ['purpose' => $purpose, 'prompt' => $request->prompt];
                if ($purpose === 'external_label_translation') {
                    $original = trim(str_replace(['<trecho>', '</trecho>'], '', $request->prompt));

                    return new TextGenerationResponse($this->test->translations[$original] ?? 'tradução sem roteiro', 'scripted', null);
                }
                preg_match_all('/<evidencia id="([^"]+)"/', $request->prompt, $ids);

                return new TextGenerationResponse(str_replace('["ALL"]', (string) json_encode(array_values(array_diff($ids[1], ['T1']))), $this->test->answer), 'scripted', null);
            }
        });
        $this->app->instance(AiProviderManager::class, $manager);
    }

    private const INTERACTION_EN = 'Serotonergic Drugs: Concomitant use of sertraline with other serotonergic drugs, including tramadol, increases the risk of serotonin syndrome. Monitor patients for signs of serotonin syndrome.';

    private const INTERACTION_PT = 'Medicamentos serotoninérgicos: o uso de sertralina com outros medicamentos serotoninérgicos, incluindo o tramadol, aumenta o risco de síndrome serotoninérgica. Observe sinais de síndrome serotoninérgica.';

    private const ADVERSE_EN = 'The most common adverse reactions (incidence at least 5% and twice placebo) were nausea, diarrhea and insomnia.';

    private const ADVERSE_PT = 'As reações adversas mais comuns (incidência de pelo menos 5% e 2 vezes o placebo) foram náusea, diarreia e insônia.';

    private function ingredient(string $pt, string $en): ActiveIngredient
    {
        $ingredient = ActiveIngredient::factory()->create(['canonical_name' => mb_strtoupper($pt), 'normalized_name' => $pt]);
        DB::table('active_ingredient_aliases')->insert(['ingredient_id' => $ingredient->id, 'alias' => $en, 'normalized_alias' => $en, 'language' => 'en', 'origin' => 'ddinter', 'review_status' => 'reviewed', 'created_at' => now(), 'updated_at' => now()]);

        return $ingredient;
    }

    /** @param array<string, mixed> $overrides */
    private function openFdaLabel(array $overrides = []): array
    {
        return array_replace_recursive([
            'set_id' => 'aaaaaaaa-1111-2222-3333-444444444444', 'version' => '7', 'effective_time' => '20260901',
            'drug_interactions' => [self::INTERACTION_EN], 'adverse_reactions' => [self::ADVERSE_EN],
            'openfda' => ['brand_name' => ['Zoloft'], 'substance_name' => ['SERTRALINE HYDROCHLORIDE'], 'unii' => ['UTI8907Y6X'], 'manufacturer_name' => ['Pfizer'], 'product_type' => ['HUMAN PRESCRIPTION DRUG']],
        ], $overrides);
    }

    private function translateAll(): void
    {
        $this->translations = [self::INTERACTION_EN => self::INTERACTION_PT, self::ADVERSE_EN => self::ADVERSE_PT];
    }

    public function test_the_fda_label_is_imported_from_openfda_with_its_origin_and_checked_translations(): void
    {
        $sertraline = $this->ingredient('sertralina', 'sertraline');
        $this->translateAll();
        Http::fake(['api.fda.gov/*' => Http::response(['results' => [
            $this->openFdaLabel(['set_id' => 'combo-1111-2222-3333-444444444444', 'effective_time' => '20261001', 'openfda' => ['substance_name' => ['SERTRALINE HYDROCHLORIDE', 'OTHER']]]),
            $this->openFdaLabel(),
            $this->openFdaLabel(['set_id' => 'old-11111-2222-3333-444444444444', 'effective_time' => '20190101']),
        ]])]);

        $label = app(ExternalLabelImporter::class)->import($sertraline);

        $this->assertSame([ExternalLabel::STATUS_READY, ExternalLabel::VIA_OPENFDA, 'aaaaaaaa-1111-2222-3333-444444444444', 7, 'Pfizer', '2026-09-01', 'UTI8907Y6X'], [$label->status, $label->retrieved_via, $label->set_id, $label->spl_version, $label->labeler, $label->published_at->toDateString(), $label->unii], 'princípio ativo único, a mais recente; a associação fica de fora');
        $this->assertSame('https://dailymed.nlm.nih.gov/dailymed/drugInfo.cfm?setid=aaaaaaaa-1111-2222-3333-444444444444', $label->source_url);
        $this->assertSame([['34073-7', self::INTERACTION_PT], ['34084-4', self::ADVERSE_PT]], $label->sections->map(fn (ExternalLabelSection $s): array => [$s->section_code, $s->text_translated])->all());
        $this->assertSame([self::INTERACTION_EN, self::ADVERSE_EN], $label->sections->pluck('text_original')->all(), 'o original fica guardado junto da tradução');
        Http::assertSent(fn ($request): bool => str_contains(urldecode($request->url()), 'openfda.generic_name:"sertraline" AND openfda.product_type:"HUMAN PRESCRIPTION DRUG"'));
    }

    public function test_dailymed_is_the_backup_when_openfda_fails(): void
    {
        $sertraline = $this->ingredient('sertralina', 'sertraline');
        $this->translateAll();
        $xml = '<?xml version="1.0"?><document xmlns="urn:hl7-org:v3"><setId root="bbbbbbbb-1111-2222-3333-444444444444"/><versionNumber value="4"/><code code="34391-3"/><effectiveTime value="20261020"/>'
            .'<author><assignedEntity><representedOrganization><name>Lab DM</name></representedOrganization></assignedEntity></author>'
            .'<component><structuredBody><component><section><subject><manufacturedProduct><manufacturedProduct><name>Sertraline</name><ingredient classCode="ACTIM"><ingredientSubstance><code code="UTI8907Y6X"/><name>SERTRALINE HYDROCHLORIDE</name></ingredientSubstance></ingredient></manufacturedProduct></manufacturedProduct></subject></section></component>'
            .'<component><section><code code="34073-7"/><text><paragraph>'.self::INTERACTION_EN.'</paragraph></text></section></component>'
            .'<component><section><code code="34084-4"/><text><paragraph>'.self::ADVERSE_EN.'</paragraph></text></section></component></structuredBody></component></document>';
        Http::fake([
            'api.fda.gov/*' => Http::response('erro', 500),
            'dailymed.nlm.nih.gov/dailymed/services/v2/spls.json*' => Http::response(['data' => [['setid' => 'bbbbbbbb-1111-2222-3333-444444444444', 'title' => 'SERTRALINE', 'spl_version' => 4]]]),
            'dailymed.nlm.nih.gov/dailymed/services/v2/spls/*' => Http::response($xml),
        ]);

        $label = app(ExternalLabelImporter::class)->import($sertraline);

        $this->assertSame([ExternalLabel::STATUS_READY, ExternalLabel::VIA_DAILYMED, 'Lab DM', 'SERTRALINE (SERTRALINE HYDROCHLORIDE)'], [$label->status, $label->retrieved_via, $label->labeler, $label->title]);
        $this->assertSame(2, $label->sections()->where('translation_status', ExternalLabelSection::TRANSLATED)->count());
    }

    public function test_a_translation_that_changes_a_number_is_kept_but_never_used(): void
    {
        $sertraline = $this->ingredient('sertralina', 'sertraline');
        $this->translations = [self::ADVERSE_EN => str_replace('5%', '15%', self::ADVERSE_PT), self::INTERACTION_EN => self::INTERACTION_PT];
        Http::fake(['api.fda.gov/*' => Http::response(['results' => [$this->openFdaLabel()]])]);

        app(ExternalLabelImporter::class)->import($sertraline);

        $this->assertSame(ExternalLabelSection::TRANSLATION_REJECTED, ExternalLabelSection::query()->where('section_code', '34084-4')->value('translation_status'));
        $this->assertSame([], app(ExternalEvidenceService::class)->adverseReactions([$sertraline->id]), 'tradução recusada não vira evidência');
    }

    public function test_an_ingredient_not_approved_in_the_us_is_recorded_as_not_found(): void
    {
        $metamizol = $this->ingredient('dipirona', 'metamizole');
        Http::fake(['api.fda.gov/*' => Http::response(['error' => ['code' => 'NOT_FOUND']], 404), 'dailymed.nlm.nih.gov/*' => Http::response(['data' => []])]);

        $label = app(ExternalLabelImporter::class)->import($metamizol);

        $this->assertSame([ExternalLabel::STATUS_NOT_FOUND, 'metamizole'], [$label->status, $label->searched_name]);
    }

    public function test_the_question_uses_the_fda_adverse_reactions_only_when_the_brazilian_leaflet_lacks_them_and_credits_the_source(): void
    {
        $sertraline = $this->ingredient('sertralina', 'sertraline');
        $medicine = AnvisaMedicine::factory()->create(['product_name' => 'SERTRALINA', 'normalized_product_name' => 'sertralina', 'active_ingredient' => 'SERTRALINA', 'normalized_active_ingredient' => 'sertralina']);
        $medicine->ingredients()->attach($sertraline->id, ['component_text' => 'SERTRALINA', 'method' => 'alias', 'confidence' => 1]);
        $source = KnowledgeSource::query()->create(['name' => 'ANVISA', 'type' => 'document', 'active' => true]);
        $document = KnowledgeDocument::query()->create(['source_id' => $source->id, 'external_key' => 'sert', 'title' => 'SERTRALINA — Bula', 'document_type' => 'bula_paciente', 'language' => 'pt-BR', 'metadata' => [], 'content_status' => 'READY', 'file_status' => 'READY', 'embedding_status' => 'PENDING', 'content_hash' => 'h']);
        $document->chunks()->create(['chunk_index' => 0, 'section' => 'PARA QUE ESTE MEDICAMENTO É INDICADO?', 'page_start' => 1, 'page_end' => 1, 'content' => 'A sertralina é indicada para o tratamento da depressão.', 'metadata' => []]);
        DB::table('knowledge_document_ingredients')->insert(['document_id' => $document->id, 'ingredient_id' => $sertraline->id, 'method' => 'registration', 'confidence' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $label = ExternalLabel::factory()->create(['ingredient_id' => $sertraline->id, 'title' => 'ZOLOFT (SERTRALINE HYDROCHLORIDE)']);
        ExternalLabelSection::factory()->create(['external_label_id' => $label->id, 'text_original' => self::ADVERSE_EN, 'text_translated' => self::ADVERSE_PT]);
        $this->answer = '{"coverage":"full","segments":[{"kind":"fact","text":"Na bula dos Estados Unidos, as reações adversas mais comuns da sertralina foram náusea, diarreia e insônia.","evidence_ids":["ALL"]}]}';
        $user = User::factory()->create();

        $result = app(MedicationQuestionService::class)->askFreeform($user, 'Quais os efeitos colaterais da sertralina?');

        $this->assertSame(MedicationQuestionService::ANSWERED, $result['status']);
        $this->assertContains(ExternalEvidenceService::DOCUMENT_TYPE, array_column($result['evidences'], 'document_type'));
        $foreign = collect($result['evidences'])->firstWhere('document_type', ExternalEvidenceService::DOCUMENT_TYPE);
        $this->assertSame(['ZOLOFT (SERTRALINE HYDROCHLORIDE) — bula dos EUA (FDA), tradução automática', ExternalLabel::CREDIT, 'REAÇÕES ADVERSAS (bula dos EUA)'], [$foreign['document_title'], $foreign['source'], $foreign['section']]);
        $this->assertStringContainsString('vêm da bula dos Estados Unidos (FDA), traduzida', collect($this->requests)->where('purpose', 'medication_question')->last()['prompt']);
        $this->assertSame(['consulted' => true, 'references' => 1], $result['retrieval']['external']);
        $this->assertStringContainsString('Origem: FDA, via openFDA e DailyMed (U.S. National Library of Medicine)', Perguntar::fonte($result + ['evidences' => array_map(fn (array $e): array => $e + ['supports_claims' => true], $result['evidences'])], ['SERTRALINA'], [], ['SERTRALINA']));

        $this->requests = [];
        $this->answer = '{"coverage":"full","segments":[{"kind":"fact","text":"A sertralina é indicada para o tratamento da depressão.","evidence_ids":["ALL"]}]}';
        $indication = app(MedicationQuestionService::class)->askFreeform($user, 'Para que serve a sertralina?');
        $this->assertNotContains(ExternalEvidenceService::DOCUMENT_TYPE, array_column($indication['evidences'], 'document_type'), 'indicação vem da bula brasileira; a dos EUA só complementa interações e efeitos adversos');
    }

    public function test_a_pair_without_brazilian_evidence_uses_only_fda_excerpts_that_name_the_other_drug(): void
    {
        $sertraline = $this->ingredient('sertralina', 'sertraline');
        $tramadol = $this->ingredient('tramadol', 'tramadol');
        $omeprazole = $this->ingredient('omeprazol', 'omeprazole');
        $label = ExternalLabel::factory()->create(['ingredient_id' => $sertraline->id]);
        ExternalLabelSection::factory()->create(['external_label_id' => $label->id, 'section_code' => ExternalLabelSection::INTERACTIONS, 'section_name' => 'INTERAÇÕES MEDICAMENTOSAS', 'text_original' => self::INTERACTION_EN, 'text_translated' => self::INTERACTION_PT]);
        ExternalLabel::factory()->create(['ingredient_id' => $tramadol->id, 'status' => ExternalLabel::STATUS_NOT_FOUND]);
        ExternalLabel::factory()->create(['ingredient_id' => $omeprazole->id, 'status' => ExternalLabel::STATUS_NOT_FOUND]);
        $snapshot = fn (ActiveIngredient $i, int $id): array => ['id' => $id, 'name' => $i->canonical_name, 'ingredients' => [['id' => $i->id, 'name' => $i->canonical_name]]];

        $pair = app(PairAnalyzer::class)->analyze($snapshot($sertraline, 1), $snapshot($tramadol, 2));
        $unrelated = app(ExternalEvidenceService::class)->forPair([$sertraline->id], [$omeprazole->id]);

        $this->assertSame(PairVerdict::InsufficientEvidence, $pair['verdict'], 'sem registro estruturado, o trecho não vira conclusão');
        $this->assertSame([self::INTERACTION_PT], array_column($pair['evidences'], 'excerpt'));
        $this->assertStringContainsString('bula dos EUA (FDA), traduzidos automaticamente', implode(' ', $pair['limitations']));
        $this->assertSame([], $unrelated, 'trecho que não cita o outro remédio não é usado');
    }

    public function test_an_ingredient_without_a_foreign_label_is_queued_for_import(): void
    {
        Queue::fake();
        $sertraline = $this->ingredient('sertralina', 'sertraline');

        $this->assertSame([], app(ExternalEvidenceService::class)->adverseReactions([$sertraline->id]));

        Queue::assertPushed(ImportExternalLabel::class, fn (ImportExternalLabel $job): bool => $job->ingredientId === $sertraline->id);
    }

    public function test_the_command_imports_by_name_and_reports_the_origin(): void
    {
        $this->ingredient('sertralina', 'sertraline');
        $this->translateAll();
        Http::fake(['api.fda.gov/*' => Http::response(['results' => [$this->openFdaLabel()]])]);

        $this->artisan('knowledge:import-fda-labels', ['ingredients' => ['sertralina'], '--sync' => true])
            ->expectsOutputToContain('sertraline: ready · ZOLOFT (SERTRALINE HYDROCHLORIDE) (2 trechos)')
            ->assertSuccessful();
    }
}
