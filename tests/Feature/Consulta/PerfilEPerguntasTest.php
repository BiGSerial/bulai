<?php

namespace Tests\Feature\Consulta;

use App\Data\Ai\TextGenerationRequest;
use App\Data\Ai\TextGenerationResponse;
use App\Livewire\Consulta\Historico;
use App\Livewire\Consulta\Perfil;
use App\Livewire\Consulta\Perguntar;
use App\Livewire\Consulta\Pesquisa;
use App\Livewire\Consulta\Resultado;
use App\Models\Consultation\MedicationQuestion;
use App\Models\Knowledge\ActiveIngredient;
use App\Models\Knowledge\AnvisaMedicine;
use App\Models\Knowledge\DrugInteraction;
use App\Models\Knowledge\KnowledgeDocument;
use App\Models\Knowledge\KnowledgeSource;
use App\Models\PatientAllergy;
use App\Models\PatientMedication;
use App\Models\PatientPrescription;
use App\Models\PatientProfile;
use App\Models\User;
use App\Models\UserAuditEvent;
use App\Services\Ai\AiProviderManager;
use App\Services\Ai\TextGenerationProvider;
use App\Services\Interactions\InteractionConsultationService;
use App\Services\Patient\AllergyChecker;
use App\Services\Patient\MedicationQuestionService;
use App\Services\Patient\TreatmentContextService;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

final class PerfilEPerguntasTest extends TestCase
{
    use RefreshDatabase;

    public ?string $scripted = null;

    protected function setUp(): void
    {
        parent::setUp();
        config(['bulai.consultation.execution' => 'sync', 'bulai.generation.enabled' => true, 'ai.text.default' => 'scripted', 'bulai.retrieval.mode' => 'lexical']);
        $test = $this;
        $manager = new AiProviderManager;
        $manager->extend(AiProviderManager::TEXT, 'scripted', fn () => new class($test) implements TextGenerationProvider
        {
            public function __construct(private readonly PerfilEPerguntasTest $test) {}

            public function generate(TextGenerationRequest $request): TextGenerationResponse
            {
                return new TextGenerationResponse((string) $this->test->scripted, 'scripted', null);
            }
        });
        $this->app->instance(AiProviderManager::class, $manager);
    }

    private function consentedUser(): User
    {
        $user = User::factory()->create();
        PatientProfile::query()->create(['user_id' => $user->id, 'health_consent_at' => now()]);

        return $user;
    }

    private function medicine(string $name, string $ingredient, bool $identified = true): AnvisaMedicine
    {
        $medicine = AnvisaMedicine::factory()->create(['product_name' => $name, 'normalized_product_name' => strtolower($name), 'active_ingredient' => $ingredient, 'normalized_active_ingredient' => strtolower($ingredient)]);
        if ($identified) {
            $medicine->ingredients()->attach(ActiveIngredient::factory()->create(['canonical_name' => $ingredient, 'normalized_name' => strtolower($ingredient)])->id, ['component_text' => $ingredient, 'method' => 'alias', 'confidence' => 1]);
        }

        return $medicine;
    }

    public function test_health_data_requires_explicit_consent_and_can_be_fully_erased_with_an_audit_trail(): void
    {
        $user = User::factory()->create();

        $component = Livewire::actingAs($user)->test(Perfil::class)->assertSee('Concordo em guardar')
            ->set('substancia', 'dipirona')->call('adicionarAlergia');
        $this->assertSame(0, PatientAllergy::count());

        $component->call('aceitarConsentimento')->assertDontSee('Concordo em guardar')
            ->set('substancia', 'Dipirona')->set('reacao', 'urticária')->call('adicionarAlergia')->assertSee('Dipirona')->assertSee('urticária');
        $medicine = $this->medicine('ALFA', 'ALFASUBSTANCIA');
        $component->call('adicionarMedicamento', $medicine->id);
        $this->assertTrue($user->fresh()->hasHealthConsent());
        $this->assertSame(1, PatientMedication::count());

        $component->call('apagarDadosDeSaude')->assertSee('foram apagados');
        $this->assertSame([0, 0, 0], [PatientAllergy::count(), PatientMedication::count(), PatientProfile::count()]);
        $this->assertFalse($user->fresh()->hasHealthConsent());
        $this->assertSame(['health_consent_given', 'health_data_deleted'], UserAuditEvent::where('user_id', $user->id)->orderBy('id')->pluck('event')->all());
    }

    public function test_health_records_are_private_to_each_user(): void
    {
        $owner = $this->consentedUser();
        PatientAllergy::query()->create(['user_id' => $owner->id, 'substance' => 'Penicilina', 'normalized_substance' => 'penicilina']);
        $other = $this->consentedUser();

        Livewire::actingAs($other)->test(Perfil::class)->assertDontSee('Penicilina')->call('removerAlergia', PatientAllergy::value('id'));
        $this->assertSame(1, PatientAllergy::count());
    }

    public function test_allergies_match_by_substance_by_english_name_and_by_class_but_not_by_unrelated_drugs(): void
    {
        $checker = app(AllergyChecker::class);
        $make = fn (string ...$names) => collect($names)->map(fn ($n) => new PatientAllergy(['substance' => $n, 'normalized_substance' => strtolower($n)]));
        $amox = $this->medicine('AMOXIL', 'AMOXICILINA TRI-HIDRATADA', false);
        $dipirona = $this->medicine('NOVALGINA', 'DIPIRONA SÓDICA', false);
        $ibuprofeno = $this->medicine('ALIVIUM', 'IBUPROFENO', false);

        $this->assertSame('classe', $checker->check($make('Penicilina'), $amox)[0]['via']);
        $this->assertSame('ingrediente', $checker->check($make('amoxicilina'), $amox)[0]['via']);
        $this->assertSame('ingrediente', $checker->check($make('Dipirona'), $dipirona)[0]['via']);
        $this->assertSame('classe', $checker->check($make('anti-inflamatórios'), $ibuprofeno)[0]['via']);
        $this->assertSame('classe', $checker->check($make('AINEs'), $ibuprofeno)[0]['via']);
        $this->assertSame([], $checker->check($make('Penicilina'), $dipirona));
        $this->assertSame([], $checker->check($make('Dipirona'), $ibuprofeno));
    }

    public function test_allergy_alert_shows_when_selecting_and_on_the_result_page_only_with_consent(): void
    {
        $user = $this->consentedUser();
        PatientAllergy::query()->create(['user_id' => $user->id, 'substance' => 'Penicilina', 'normalized_substance' => 'penicilina']);
        $amox = $this->medicine('AMOXIL', 'AMOXICILINA');
        $other = $this->medicine('BETA', 'BETASUBSTANCIA');

        Livewire::actingAs($user)->test(Pesquisa::class)->call('adicionar', $amox->id)->assertSee('Você registrou alergia a Penicilina')->call('adicionar', $other->id);
        $consultation = app(InteractionConsultationService::class)->create($user, [$amox->id, $other->id]);
        Livewire::actingAs($user)->test(Resultado::class, ['consultation' => $consultation])->assertSee('Atenção à sua alergia: AMOXIL');

        PatientProfile::query()->update(['health_consent_at' => null]);
        Livewire::actingAs($user->fresh())->test(Resultado::class, ['consultation' => $consultation])->assertDontSee('Atenção à sua alergia');
    }

    public function test_current_medications_can_prefill_the_search(): void
    {
        $user = $this->consentedUser();
        foreach ([$this->medicine('ALFA', 'ALFASUB'), $this->medicine('BETA', 'BETASUB')] as $medicine) {
            PatientMedication::query()->create(['user_id' => $user->id, 'medicine_id' => $medicine->id]);
        }

        $this->actingAs($user)->get('/consulta?usar=1')->assertOk()->assertSee('ALFA')->assertSee('BETA');
    }

    private function leaflet(AnvisaMedicine $medicine, string $content): void
    {
        $source = KnowledgeSource::query()->firstOrCreate(['name' => 'ANVISA', 'organization' => null], ['type' => 'document', 'active' => true]);
        $document = KnowledgeDocument::query()->create(['source_id' => $source->id, 'external_key' => uniqid('d_'), 'title' => $medicine->product_name.' — Bula', 'document_type' => 'bula_paciente', 'language' => 'pt-BR', 'metadata' => [], 'content_status' => 'READY', 'file_status' => 'READY', 'embedding_status' => 'PENDING']);
        $document->chunks()->create(['chunk_index' => 0, 'section' => 'COMO DEVO USAR', 'page_start' => 3, 'page_end' => 3, 'content' => $content, 'metadata' => []]);
        DB::table('knowledge_document_ingredients')->insert(['document_id' => $document->id, 'ingredient_id' => $medicine->ingredients()->value('active_ingredients.id'), 'method' => 'registration', 'confidence' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_question_outcomes_never_answer_from_memory(): void
    {
        $service = app(MedicationQuestionService::class);
        $this->assertSame(MedicationQuestionService::NOT_IDENTIFIED, $service->ask($this->medicine('XPTO', 'XPTOSUB', false), 'posso tomar em jejum?')['status']);
        $semBula = $this->medicine('SEMBULA', 'SEMBULASUB');
        $this->assertSame(MedicationQuestionService::NO_LEAFLET, $service->ask($semBula, 'posso tomar em jejum?')['status']);

        $comBula = $this->medicine('OMEPRAZOL', 'OMEPRAZOLSUB');
        $this->leaflet($comBula, 'O comprimido deve ser tomado pela manhã em jejum, com um copo de água.');
        $this->assertSame(MedicationQuestionService::NO_EVIDENCE, $service->ask($comBula, 'qual a cor da embalagem?')['status']);

        $this->scripted = 'não é json';
        $excerpts = $service->ask($comBula, 'devo tomar em jejum pela manhã?');
        $this->assertSame(MedicationQuestionService::EXCERPTS_ONLY, $excerpts['status']);
        $this->assertSame(3, $excerpts['evidences'][0]['page_start']);
        $this->assertNull($excerpts['answer']);

        $this->scripted = json_encode(['claims' => [['text' => 'A bula orienta tomar o comprimido pela manhã, em jejum, com um copo de água.', 'evidence_ids' => ['E1']]]]);
        $answered = $service->ask($comBula, 'devo tomar em jejum pela manhã?');
        $this->assertSame(MedicationQuestionService::ANSWERED, $answered['status']);
        $this->assertStringContainsString('em jejum', $answered['answer']);
        $this->assertSame([0], $answered['evidences'][0]['supports_claims']);

        $this->scripted = json_encode(['claims' => [['text' => 'Você deve suspender o uso e trocar por outro remédio em jejum pela manhã.', 'evidence_ids' => ['E1']]]]);
        $this->assertSame(MedicationQuestionService::EXCERPTS_ONLY, $service->ask($comBula, 'devo tomar em jejum pela manhã?')['status']);
    }

    public function test_question_page_detects_the_medicine_in_the_text_throttles_and_records_the_question_with_metrics(): void
    {
        $user = User::factory()->create();
        $medicine = $this->medicine('OMEPRAZOL', 'OMEPRAZOLSUB');
        $this->leaflet($medicine, 'O comprimido deve ser tomado em jejum.');
        $this->scripted = json_encode(['claims' => [['text' => 'A bula diz que o comprimido deve ser tomado em jejum.', 'evidence_ids' => ['E1']]]]);

        Livewire::actingAs($user)->test(Perguntar::class)
            ->call('perguntar')->assertSee('Escreva sua pergunta')
            ->set('pergunta', 'omeprazol em jejum?')->call('perguntar')
            ->assertSee('Falando de:')->assertSee('OMEPRAZOL')
            ->assertSee('A bula diz que o comprimido deve ser tomado em jejum.')->assertSee('Ver na bula')->assertSee('Li isso na bula de OMEPRAZOL.')
            ->assertDontSee('página 3')->assertDontSee('Limitações')
            ->assertSee('Guardei esta conversa no seu');

        $question = MedicationQuestion::query()->sole();
        $this->assertSame([$user->id, $medicine->id, 'OMEPRAZOL', 'omeprazol em jejum?', MedicationQuestionService::ANSWERED], [$question->user_id, $question->medicine_id, $question->medicine_name, $question->question, $question->status]);
        $this->assertSame('omeprazol', $question->medicines[0]['matched_text']);
        $this->assertSame('ok', $question->guard['category']);
        $this->assertSame('A bula diz que o comprimido deve ser tomado em jejum.', $question->answer);
        $this->assertSame(3, $question->evidences[0]['page_start']);
        $this->assertSame([0], $question->evidences[0]['supports_claims']);
        $this->assertSame(['generated', 1, 'interaction-explanation-v1:qa'], [$question->generation['status'], $question->generation['claims_accepted'], $question->generation['prompt_version']]);
        $this->assertSame('lexical', $question->retrieval['mode']);
        $this->assertSame('scripted', $question->pipeline['text_provider']);
        $this->assertFalse($question->treatment_context['consented']);
        $this->assertNotNull($question->duration_ms);
        $this->assertTrue($question->expires_at->isAfter(now()->addDays((int) config('bulai.questions.retention_days') - 1)));

        config(['bulai.consultation.rate_limit_per_hour' => 1]);
        Livewire::actingAs($user)->test(Perguntar::class)->set('pergunta', 'outra pergunta sobre omeprazol')->call('perguntar')->assertSee('limite de perguntas por hora');
        $this->assertSame(1, MedicationQuestion::count());
    }

    public function test_question_page_prefills_the_medicine_from_the_query_string_and_shows_the_treatment_panel(): void
    {
        $user = $this->consentedUser();
        $medicine = $this->medicine('OMEPRAZOL', 'OMEPRAZOLSUB');
        PatientMedication::query()->create(['user_id' => $user->id, 'medicine_id' => $medicine->id, 'dosage' => '20 mg', 'frequency' => '1x ao dia']);

        $this->actingAs($user)->get('/perguntar?medicamento='.$medicine->id)->assertOk()->assertSee('Sobre OMEPRAZOL:')
            ->assertSee('No seu tratamento')->assertSee('OMEPRAZOL está entre os medicamentos que você usa')->assertSee('20 mg, 1x ao dia');

        Livewire::actingAs($user)->withQueryParams(['medicamento' => $medicine->id])->test(Perguntar::class)
            ->set('pergunta', 'Sobre OMEPRAZOL: posso tomar em jejum?')->call('perguntar')
            ->assertSee('No seu tratamento')->assertSee('OMEPRAZOL está entre os medicamentos que você usa')->assertSee('20 mg, 1x ao dia')
            ->assertSee(route('consulta.bula', $medicine, false));

        Livewire::actingAs(User::factory()->create())->test(Perguntar::class)->set('pergunta', 'posso tomar omeprazol em jejum?')->call('perguntar')->assertSee('registre seu perfil de saúde');
    }

    private function interaction(AnvisaMedicine $a, AnvisaMedicine $b, string $severity): void
    {
        DrugInteraction::factory()->create([
            'drug_a_ingredient_id' => $a->ingredients()->value('active_ingredients.id'),
            'drug_b_ingredient_id' => $b->ingredients()->value('active_ingredients.id'),
            'severity' => $severity,
        ]);
    }

    public function test_treatment_context_classifies_interactions_duplicates_and_allergies_only_with_consent(): void
    {
        $user = $this->consentedUser();
        $asked = $this->medicine('MAREVAN', 'VARFARINA');
        $major = $this->medicine('ZOCOR', 'SINVASTATINA');
        $minor = $this->medicine('TYLENOL', 'PARACETAMOL');
        $sameIngredient = AnvisaMedicine::factory()->create(['product_name' => 'COUMADIN', 'normalized_product_name' => 'coumadin', 'active_ingredient' => 'VARFARINA', 'normalized_active_ingredient' => 'varfarina']);
        $sameIngredient->ingredients()->attach($asked->ingredients()->value('active_ingredients.id'), ['component_text' => 'VARFARINA', 'method' => 'alias', 'confidence' => 1]);
        $unknown = $this->medicine('MISTERIO', 'MISTERIOSUB', false);
        $ended = $this->medicine('ANTIGO', 'ANTIGOSUB');
        $this->interaction($asked, $major, 'major');
        $this->interaction($minor, $asked, 'minor');
        $this->interaction($asked, $ended, 'major');
        foreach ([$major, $minor, $sameIngredient, $unknown] as $medicine) {
            PatientMedication::query()->create(['user_id' => $user->id, 'medicine_id' => $medicine->id]);
        }
        PatientMedication::query()->create(['user_id' => $user->id, 'medicine_id' => $ended->id, 'ends_at' => now()->subDay()]);
        PatientAllergy::query()->create(['user_id' => $user->id, 'substance' => 'varfarina', 'normalized_substance' => 'varfarina']);

        $context = app(TreatmentContextService::class)->for($user, $asked);
        $this->assertTrue($context['consented']);
        $this->assertFalse($context['in_use']);
        $this->assertSame('major', $context['highest_severity']);
        $this->assertSame([['ZOCOR', 'major'], ['TYLENOL', 'minor'], ['COUMADIN', 'duplicate']], array_map(fn (array $i): array => [$i['medicine'], $i['severity']], $context['interactions']));
        $this->assertSame(['MISTERIO'], $context['unresolved']);
        $this->assertSame('ingrediente', $context['allergy_alerts'][0]['via']);
        $this->assertSame(4, $context['medications_count']);

        $facts = app(TreatmentContextService::class)->asEvidenceText($context, 'MAREVAN');
        $this->assertStringContainsString('gravidade maior entre MAREVAN e ZOCOR', $facts);
        $this->assertStringContainsString('mesmo princípio ativo', $facts);
        $this->assertStringContainsString('alergia a varfarina', $facts);
        $this->assertStringNotContainsString($user->email, $facts);

        Livewire::actingAs($user)->test(Perguntar::class)->set('pergunta', 'posso tomar marevan?')->call('perguntar')
            ->assertSee('Com ZOCOR')->assertSee('Gravidade: Maior')->assertSee('Com TYLENOL')->assertSee('Gravidade: Menor')
            ->assertSee('possível duplicidade')->assertSee('Você registrou alergia a varfarina')->assertSee('Não foi possível verificar: MISTERIO')->assertDontSee('ANTIGO');

        PatientProfile::query()->update(['health_consent_at' => null]);
        $context = app(TreatmentContextService::class)->for($user->fresh(), $asked);
        $this->assertSame([false, [], []], [$context['consented'], $context['interactions'], $context['allergy_alerts']]);
        $this->assertNull(app(TreatmentContextService::class)->asEvidenceText($context, 'MAREVAN'));
    }

    public function test_answers_can_cite_treatment_facts_and_the_record_keeps_the_context(): void
    {
        $user = $this->consentedUser();
        $asked = $this->medicine('MAREVAN', 'VARFARINA');
        $other = $this->medicine('ZOCOR', 'SINVASTATINA');
        $this->interaction($asked, $other, 'major');
        PatientMedication::query()->create(['user_id' => $user->id, 'medicine_id' => $other->id]);
        $this->leaflet($asked, 'Varfarina pode ter o efeito aumentado por sinvastatina; o médico deve acompanhar.');
        $this->scripted = json_encode(['claims' => [
            ['text' => 'A bula diz que a varfarina pode ter o efeito aumentado por sinvastatina e que o médico deve acompanhar.', 'evidence_ids' => ['E1']],
            ['text' => 'A base de interações registra interação de gravidade maior entre MAREVAN e ZOCOR, que está em uso.', 'evidence_ids' => ['T1']],
        ]]);

        $result = app(MedicationQuestionService::class)->ask($asked, 'posso tomar com sinvastatina?', $user);
        $this->assertSame(MedicationQuestionService::ANSWERED, $result['status']);
        $this->assertStringContainsString('gravidade maior entre MAREVAN e ZOCOR', $result['answer']);
        $this->assertTrue($result['generation']['treatment_context']);
        $treatment = collect($result['evidences'])->firstWhere('document_type', 'treatment_context');
        $this->assertSame([0, 'structured', [1]], [$treatment['chunk_id'], $treatment['method'], $treatment['supports_claims']]);

        $record = MedicationQuestion::query()->where('uuid', $result['uuid'])->sole();
        $this->assertSame('major', $record->treatment_context['highest_severity']);
        $this->assertSame('ZOCOR', $record->treatment_context['interactions'][0]['medicine']);

        $this->actingAs($user)->get(route('consulta.pergunta', $record))->assertOk()->assertSee('posso tomar com sinvastatina?')->assertSee('Com ZOCOR')->assertSee('Também olhei os remédios e as alergias do seu perfil');
        $this->actingAs(User::factory()->create())->get(route('consulta.pergunta', $record))->assertNotFound();
    }

    public function test_prescriptions_and_posology_can_be_managed_and_are_removed_with_the_health_data(): void
    {
        $user = $this->consentedUser();
        $medicine = $this->medicine('OMEPRAZOL', 'OMEPRAZOLSUB');

        $component = Livewire::actingAs($user)->test(Perfil::class)
            // Os três segmentos (Em uso · Receitas · Alergias) são renderizados juntos; a troca é no cliente, sem recarregar.
            ->assertSee('Em uso')->assertSee('Receitas')->assertSee('Alergias')
            ->assertSeeHtml('id="painel-remedios"')->assertSeeHtml('id="painel-receitas"')->assertSeeHtml('id="painel-alergias"')
            ->set('prescritor', 'Dr')->call('adicionarReceita')->assertSee('prescritor')
            ->set('prescritor', 'Dra. Ana Souza')->set('registroPrescritor', 'CRM-SP 9988')->set('dataReceita', now()->subDay()->format('Y-m-d'))->call('adicionarReceita')
            ->assertSee('Dra. Ana Souza')->assertSee('CRM-SP 9988');
        $prescription = PatientPrescription::query()->sole();
        $this->assertSame($user->id, $prescription->user_id);

        $component->call('adicionarMedicamento', $medicine->id)->assertSet('editandoId', PatientMedication::value('id'))
            ->set('posologia.dosage', '20 mg')->set('posologia.frequency', 'em jejum, 1x ao dia')->set('posologia.started_at', '2026-11-01')->set('posologia.ends_at', '2026-10-01')->call('salvarPosologia')->assertSee('posterior ao início')
            ->set('posologia.ends_at', '')->set('posologia.prescription_id', (string) $prescription->id)->call('salvarPosologia')
            ->assertSet('editandoId', null)->assertSee('20 mg · em jejum, 1x ao dia')->assertSee('receita de Dra. Ana Souza')->assertSee(route('consulta.bula', $medicine, false));

        $medication = PatientMedication::query()->sole();
        $this->assertSame(['20 mg', 'em jejum, 1x ao dia', $prescription->id, '2026-11-01'], [$medication->dosage, $medication->frequency, $medication->prescription_id, $medication->started_at->format('Y-m-d')]);
        $this->assertTrue($medication->isActive());

        $component->call('removerReceita', $prescription->id)->assertDontSee('CRM-SP 9988');
        $this->assertNull($medication->fresh()->prescription_id);

        MedicationQuestion::query()->create(['uuid' => '0f0f0f0f-0000-4000-8000-000000000009', 'user_id' => $user->id, 'medicine_name' => 'X', 'question' => 'pergunta', 'status' => 'answered']);
        $component->call('apagarDadosDeSaude')->assertSee('foram apagados');
        $this->assertSame([0, 0, 0], [PatientMedication::count(), PatientPrescription::count(), MedicationQuestion::count()]);
    }

    public function test_question_history_is_private_deletable_audited_and_purged_after_retention(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $make = fn (User $user, string $uuid, string $question, ?\DateTimeInterface $expires = null) => MedicationQuestion::query()->create(['uuid' => $uuid, 'user_id' => $user->id, 'medicine_name' => 'OMEPRAZOL', 'question' => $question, 'status' => MedicationQuestionService::NO_EVIDENCE, 'expires_at' => $expires ?? now()->addDays(10)]);
        $make($owner, '0f0f0f0f-0000-4000-8000-000000000001', 'posso tomar em jejum?');
        $make($owner, '0f0f0f0f-0000-4000-8000-000000000002', 'faz mal com leite?');
        $make($other, '0f0f0f0f-0000-4000-8000-000000000003', 'pergunta de outra pessoa');

        $component = Livewire::actingAs($owner)->test(Historico::class)->assertSee('posso tomar em jejum?')->assertSee('faz mal com leite?')->assertDontSee('outra pessoa')
            ->call('apagarPergunta', '0f0f0f0f-0000-4000-8000-000000000003');
        $this->assertSame(3, MedicationQuestion::count());

        $component->call('apagarPergunta', '0f0f0f0f-0000-4000-8000-000000000001')->assertDontSee('posso tomar em jejum?');
        $component->call('apagarTudo')->assertDontSee('faz mal com leite?');
        $this->assertSame(['0f0f0f0f-0000-4000-8000-000000000003'], MedicationQuestion::query()->pluck('uuid')->all());
        $this->assertSame([1, 1], UserAuditEvent::query()->where('user_id', $owner->id)->where('event', 'questions_deleted')->orderBy('id')->pluck('details')->map(fn ($d) => $d['count'])->all());

        $make($other, '0f0f0f0f-0000-4000-8000-000000000004', 'antiga', now()->subMinute());
        $this->artisan('consultations:purge-expired')->expectsOutputToContain('1 pergunta(s) vencida(s) apagada(s).')->assertSuccessful();
        $this->assertSame(['0f0f0f0f-0000-4000-8000-000000000003'], MedicationQuestion::query()->pluck('uuid')->all());
    }

    public function test_patient_pages_require_login_and_admin_and_patient_areas_link_to_each_other(): void
    {
        foreach (['/perfil', '/perguntar'] as $url) {
            $this->get($url)->assertRedirectToRoute('login');
        }
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($admin)->get('/inicio')->assertOk()->assertSee('Área administrativa');
        $this->actingAs($admin)->get('/admin/knowledge')->assertOk()->assertSee('App do paciente');
        $manifest = json_decode(file_get_contents(public_path('manifest.webmanifest')), true);
        $this->assertStringStartsWith('/inicio', $manifest['start_url']);
    }
}
