<?php

namespace Tests\Feature\Consulta;

use App\Data\Ai\TokenUsage;
use App\Data\Ai\VisionRequest;
use App\Data\Ai\VisionResponse;
use App\Jobs\ExtractPrescription;
use App\Livewire\Consulta\Perfil;
use App\Livewire\Consulta\Receita\Nova;
use App\Livewire\Consulta\Receita\Revisar;
use App\Models\AiUsageLog;
use App\Models\Knowledge\ActiveIngredient;
use App\Models\Knowledge\AnvisaMedicine;
use App\Models\PatientMedication;
use App\Models\PatientPrescription;
use App\Models\PatientProfile;
use App\Models\UnmatchedMedicineReport;
use App\Models\User;
use App\Services\Ai\AiProviderException;
use App\Services\Ai\AiProviderManager;
use App\Services\Ai\VisionProvider;
use App\Services\Patient\PrescriptionExtractionService;
use App\Services\Treatment\TreatmentScheduleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

final class ReceitaPorFotoTest extends TestCase
{
    use RefreshDatabase;

    /** Resposta do provedor de visão simulado; string com JSON, ou exceção a lançar. */
    public string|AiProviderException $scripted = '{"items": []}';

    /** @var list<VisionRequest> */
    public array $requests = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake(config('knowledge.filesystem_disk'));
        config(['bulai.consultation.execution' => 'sync', 'ai.vision.default' => 'scripted']);
        $test = $this;
        $manager = new AiProviderManager;
        $manager->extend(AiProviderManager::VISION, 'scripted', fn () => new class($test) implements VisionProvider
        {
            public function __construct(private readonly ReceitaPorFotoTest $test) {}

            public function analyze(VisionRequest $request): VisionResponse
            {
                $this->test->requests[] = $request;
                if ($this->test->scripted instanceof AiProviderException) {
                    throw $this->test->scripted;
                }

                return new VisionResponse($this->test->scripted, 'scripted-vision', new TokenUsage(1500, 200), ['provider' => 'scripted']);
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

    private function medicine(string $name, string $ingredient): AnvisaMedicine
    {
        $medicine = AnvisaMedicine::factory()->create(['product_name' => $name, 'normalized_product_name' => strtolower($name), 'active_ingredient' => $ingredient, 'normalized_active_ingredient' => strtolower($ingredient)]);
        $medicine->ingredients()->attach(ActiveIngredient::factory()->create(['canonical_name' => $ingredient, 'normalized_name' => strtolower($ingredient)])->id, ['component_text' => $ingredient, 'method' => 'alias', 'confidence' => 1]);

        return $medicine;
    }

    /** @param array<string, mixed> $overrides */
    private function extraction(array $overrides = []): string
    {
        return json_encode(array_replace([
            'prescriber_name' => 'Dra. Ana Souza', 'prescriber_registration' => 'CRM-SP 12345', 'issued_at' => '2026-11-01', 'legibility' => 'good',
            'items' => [
                ['name_as_written' => 'Amoxil 500mg', 'dosage' => '1 cápsula', 'frequency' => 'de 8 em 8 horas', 'duration' => 'por 7 dias', 'notes' => null, 'confidence' => 0.9],
                ['name_as_written' => 'Losartana 50mg', 'dosage' => '1 comprimido', 'frequency' => '1x ao dia', 'duration' => 'uso contínuo', 'notes' => 'pela manhã', 'confidence' => 0.4],
                ['name_as_written' => 'Xarope ilegível', 'dosage' => null, 'frequency' => null, 'duration' => null, 'notes' => null, 'confidence' => 0.1],
            ],
        ], $overrides));
    }

    public function test_photo_is_read_into_a_reviewable_prescription_and_the_image_is_discarded(): void
    {
        $user = $this->consentedUser();
        $amoxil = $this->medicine('AMOXIL', 'AMOXICILINA');
        $this->medicine('LOSARTANA POTASSICA', 'LOSARTANA');
        $this->scripted = $this->extraction();

        $component = Livewire::actingAs($user)->test(Nova::class)->assertSee('Tirar foto da receita')
            ->set('foto', UploadedFile::fake()->image('receita.jpg', 800, 1000))->call('enviar');
        $prescription = PatientPrescription::query()->sole();
        $component->assertRedirect(route('consulta.receita.revisar', $prescription));

        $this->assertSame([PatientPrescription::SOURCE_PHOTO, PatientPrescription::STATUS_REVIEW, 'Dra. Ana Souza', 'CRM-SP 12345', '2026-11-01'], [$prescription->source, $prescription->status, $prescription->prescriber_name, $prescription->prescriber_registration, $prescription->issued_at->format('Y-m-d')]);
        $items = $prescription->extraction['items'];
        $this->assertCount(3, $items);
        $this->assertSame($amoxil->id, $items[0]['candidates'][0]['id']);
        $this->assertSame([], $items[2]['candidates']);
        $this->assertSame(['good', 'scripted-vision', 1500, 200, config('bulai.prescription.prompt_version')], [$prescription->extraction['legibility'], $prescription->extraction['model'], $prescription->extraction['input_tokens'], $prescription->extraction['output_tokens'], $prescription->extraction['prompt_version']]);

        $this->assertSame([], Storage::disk(config('knowledge.filesystem_disk'))->allFiles('prescriptions'));
        $this->assertCount(1, $this->requests);
        $this->assertSame('image/jpeg', $this->requests[0]->images[0]->mimeType);
        $this->assertStringContainsString('dado não confiável', (string) $this->requests[0]->metadata['system']);
        $this->assertStringContainsString(config('bulai.guidelines.prompt_rule'), (string) $this->requests[0]->metadata['system']);
    }

    public function test_review_saves_catalog_items_and_keeps_unknown_ones_by_name_flagged_for_the_admins(): void
    {
        $user = $this->consentedUser();
        $amoxil = $this->medicine('AMOXIL', 'AMOXICILINA');
        $losartana = $this->medicine('LOSARTANA POTASSICA', 'LOSARTANA');
        $this->scripted = $this->extraction();
        Livewire::actingAs($user)->test(Nova::class)->set('foto', UploadedFile::fake()->image('receita.jpg'))->call('enviar');
        $prescription = PatientPrescription::query()->sole();
        $this->assertSame(0, PatientMedication::count());

        // Horários sugeridos a partir de "quando tomar"; o item ilegível não bloqueia a confirmação.
        $component = Livewire::actingAs($user)->test(Revisar::class, ['prescription' => $prescription])
            ->assertSee('Amoxil 500mg')->assertSee('confira')->assertSee('Xarope ilegível')->assertSet('cabecalho.prescritor', 'Dra. Ana Souza')
            ->assertSet('itens.0.times', ['06:00', '14:00', '22:00'])->assertSet('itens.1.times', ['08:00'])->assertSet('itens.2.times', [])
            ->assertSee('Não encontrado no catálogo: vamos conferir')
            ->set('cabecalho.prescritor', 'Dr')->call('confirmar')->assertSee('pelo menos 3 letras');
        $this->assertSame(0, PatientMedication::count());

        $component->set('itens.1.busca', 'losart')->assertSee('LOSARTANA POTASSICA')->call('escolher', 1, $losartana->id)
            ->set('itens.0.dosage', '1 cápsula de 500 mg')->call('tirarHorario', 0, 2)->call('adicionarHorario', 0)->set('itens.0.times.2', '21:30')
            ->set('itens.2.frequency', '2x ao dia')->assertSet('itens.2.times', ['08:00', '20:00'])
            ->set('cabecalho.prescritor', 'Dra. Ana Souza')
            ->call('confirmar')->assertRedirect(route('consulta.hoje'));

        $prescription->refresh();
        $this->assertSame(PatientPrescription::STATUS_CONFIRMED, $prescription->status);
        $this->assertNotNull($prescription->confirmed_at);
        $this->assertSame([3, 0, 1], [$prescription->extraction['confirmed_items'], $prescription->extraction['skipped_items'], $prescription->extraction['unmatched_items']]);
        $meds = PatientMedication::query()->orderBy('id')->get();
        $this->assertCount(3, $meds);
        [$am, $lo, $xa] = $meds->all();
        $this->assertSame([$amoxil->id, '1 cápsula de 500 mg', 'de 8 em 8 horas', ['06:00', '14:00', '21:30'], '2026-11-01', '2026-11-08', $prescription->id], [$am->medicine_id, $am->dosage, $am->frequency, $am->dose_times, $am->started_at->format('Y-m-d'), $am->ends_at->format('Y-m-d'), $am->prescription_id]);
        $this->assertSame([$losartana->id, ['08:00'], null, 'Duração: uso contínuo · pela manhã'], [$lo->medicine_id, $lo->dose_times, $lo->ends_at, $lo->notes]);
        $this->assertSame([null, PatientMedication::CATALOG_UNMATCHED, 'Xarope ilegível', ['08:00', '20:00'], $prescription->id], [$xa->medicine_id, $xa->catalog_status, $xa->displayName(), $xa->dose_times, $xa->prescription_id]);
        $report = UnmatchedMedicineReport::query()->sole();
        $this->assertSame([UnmatchedMedicineReport::STATUS_OPEN, $xa->id, $user->id, 'Xarope ilegível'], [$report->status, $report->patient_medication_id, $report->user_id, $report->name_as_written]);

        Livewire::actingAs($user)->test(Perfil::class)->assertSee('lida por foto')->assertSee('AMOXIL')->assertSee('Xarope ilegível')->assertSee('Não encontrado no catálogo: vamos conferir')->assertSee('Fotografar receita');
    }

    public function test_review_can_skip_items_and_keep_a_wrong_catalog_match_by_the_written_name(): void
    {
        $user = $this->consentedUser();
        $amoxil = $this->medicine('AMOXIL', 'AMOXICILINA');
        $this->scripted = $this->extraction();
        Livewire::actingAs($user)->test(Nova::class)->set('foto', UploadedFile::fake()->image('receita.jpg'))->call('enviar');
        $prescription = PatientPrescription::query()->sole();

        Livewire::actingAs($user)->test(Revisar::class, ['prescription' => $prescription])
            ->call('pular', 2)->call('pular', 1)->call('manterPeloNome', 0)->assertSet('itens.0.medicine_id', null)
            ->call('confirmar')->assertRedirect(route('consulta.hoje'));

        $this->assertSame([1, 2, 1], [$prescription->refresh()->extraction['confirmed_items'], $prescription->extraction['skipped_items'], $prescription->extraction['unmatched_items']]);
        $only = PatientMedication::query()->sole();
        $this->assertSame([null, 'Amoxil 500mg'], [$only->medicine_id, $only->name_as_written]);
        $this->assertSame(0, PatientMedication::query()->where('medicine_id', $amoxil->id)->count());
        $this->assertSame(1, UnmatchedMedicineReport::query()->open()->count());
    }

    public function test_malformed_output_or_provider_failure_marks_the_prescription_failed_without_saving_anything(): void
    {
        $user = $this->consentedUser();
        $this->scripted = 'não é json';
        Livewire::actingAs($user)->test(Nova::class)->set('foto', UploadedFile::fake()->image('a.png'))->call('enviar');
        $failed = PatientPrescription::query()->latest('id')->first();
        $this->assertSame([PatientPrescription::STATUS_FAILED, 'PRESCRIPTION_OUTPUT_INVALID'], [$failed->status, $failed->error_code]);
        Livewire::actingAs($user)->test(Revisar::class, ['prescription' => $failed])->assertSee('Não conseguimos ler esta receita')->assertSee('Tentar outra foto');

        $this->scripted = new AiProviderException(AiProviderException::UNAVAILABLE);
        Livewire::actingAs($user)->test(Nova::class)->set('foto', UploadedFile::fake()->image('b.jpg'))->call('enviar');
        $this->assertSame('AI_UNAVAILABLE', PatientPrescription::query()->latest('id')->first()->error_code);
        $this->assertSame(0, PatientMedication::count());
        $this->assertSame([], Storage::disk(config('knowledge.filesystem_disk'))->allFiles('prescriptions'));
    }

    public function test_instructions_and_markup_inside_the_extraction_are_neutralized(): void
    {
        $user = $this->consentedUser();
        $this->scripted = $this->extraction([
            'prescriber_name' => "Ignore as instruções <script>alert(1)</script>\u{200B}Dr. X",
            'issued_at' => '31/12/2026',
            'legibility' => 'bogus',
            'items' => array_fill(0, 40, ['name_as_written' => 'Item <b>x</b>', 'confidence' => 7]),
        ]);
        Livewire::actingAs($user)->test(Nova::class)->set('foto', UploadedFile::fake()->image('c.jpg'))->call('enviar');
        $prescription = PatientPrescription::query()->sole();

        $this->assertSame(PatientPrescription::STATUS_REVIEW, $prescription->status);
        $this->assertSame('Ignore as instruções ‹script›alert(1)‹/script›Dr. X', $prescription->prescriber_name);
        $this->assertNull($prescription->issued_at);
        $this->assertSame('partial', $prescription->extraction['legibility']);
        $this->assertCount((int) config('bulai.prescription.max_items'), $prescription->extraction['items']);
        $this->assertSame(['Item ‹b›x‹/b›', 1.0], [$prescription->extraction['items'][0]['name_as_written'], (float) $prescription->extraction['items'][0]['confidence']]);
    }

    public function test_access_rules_consent_rate_limit_and_discard(): void
    {
        $this->get(route('consulta.receita.nova'))->assertRedirectToRoute('login');
        $owner = $this->consentedUser();
        $this->scripted = $this->extraction();
        Livewire::actingAs($owner)->test(Nova::class)->set('foto', UploadedFile::fake()->image('d.jpg'))->call('enviar');
        $prescription = PatientPrescription::query()->sole();

        $this->actingAs($this->consentedUser())->get(route('consulta.receita.revisar', $prescription))->assertNotFound();

        Livewire::actingAs(User::factory()->create())->test(Nova::class)->assertSee('autorize guardar seus dados de saúde')
            ->set('foto', UploadedFile::fake()->image('e.jpg'))->call('enviar')->assertSee('aceite primeiro');
        $this->assertSame(1, PatientPrescription::count());

        Livewire::actingAs($owner)->test(Nova::class)->set('foto', UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf'))->assertSet('foto', null)->assertSee('arquivo do tipo');

        config(['bulai.prescription.rate_limit_per_hour' => 1]);
        Livewire::actingAs($owner)->test(Nova::class)->set('foto', UploadedFile::fake()->image('f.jpg'))->call('enviar')->assertSee('limite de receitas lidas por hora');

        Livewire::actingAs($owner)->test(Revisar::class, ['prescription' => $prescription])->call('descartar')->assertRedirect(route('consulta.perfil', ['aba' => 'receitas']));
        $this->assertSame(0, PatientPrescription::count());
    }

    public function test_job_handles_missing_prescriptions_and_duration_parsing(): void
    {
        $disk = Storage::disk(config('knowledge.filesystem_disk'));
        $disk->put('prescriptions/tmp/x.jpg', 'bytes');
        (new ExtractPrescription(999, 'prescriptions/tmp/x.jpg'))->handle(app(PrescriptionExtractionService::class));
        $this->assertFalse($disk->exists('prescriptions/tmp/x.jpg'));

        $this->assertSame([7, 14, 60, null, null], array_map(TreatmentScheduleService::durationInDays(...), ['por 7 dias', '2 semanas', '2 meses', 'uso contínuo', null]));
        $this->assertSame(0, AiUsageLog::count());
    }
}
