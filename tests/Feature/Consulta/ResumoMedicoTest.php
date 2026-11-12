<?php

namespace Tests\Feature\Consulta;

use App\Models\Knowledge\ActiveIngredient;
use App\Models\Knowledge\AnvisaMedicine;
use App\Models\Knowledge\DrugInteraction;
use App\Models\MedicationDoseEvent;
use App\Models\PatientAllergy;
use App\Models\PatientMedication;
use App\Models\PatientPrescription;
use App\Models\PatientProfile;
use App\Models\User;
use App\Models\UserAuditEvent;
use App\Services\Patient\MedicalSummaryService;
use App\Services\Treatment\TreatmentScheduleService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResumoMedicoTest extends TestCase
{
    use RefreshDatabase;

    private function medicine(string $name, string $ingredient): AnvisaMedicine
    {
        $medicine = AnvisaMedicine::factory()->create(['product_name' => $name, 'normalized_product_name' => strtolower($name), 'active_ingredient' => $ingredient, 'normalized_active_ingredient' => strtolower($ingredient)]);
        $medicine->ingredients()->attach(ActiveIngredient::factory()->create(['canonical_name' => $ingredient, 'normalized_name' => strtolower($ingredient)])->id, ['component_text' => $ingredient, 'method' => 'alias', 'confidence' => 1]);

        return $medicine;
    }

    /** @return array{0: User, 1: PatientMedication} */
    private function patientWithTreatment(): array
    {
        $user = User::factory()->create(['name' => 'Maria da Silva']);
        PatientProfile::query()->create(['user_id' => $user->id, 'health_consent_at' => now(), 'timezone' => 'America/Sao_Paulo']);
        $marevan = $this->medicine('MAREVAN', 'VARFARINA');
        $zocor = $this->medicine('ZOCOR', 'SINVASTATINA');
        $antigo = $this->medicine('LEVOFLOXACINO', 'LEVOFLOXACINO');
        DrugInteraction::factory()->create(['drug_a_ingredient_id' => $marevan->ingredients()->value('active_ingredients.id'), 'drug_b_ingredient_id' => $zocor->ingredients()->value('active_ingredients.id'), 'severity' => 'major']);
        $receita = PatientPrescription::query()->create(['user_id' => $user->id, 'source' => PatientPrescription::SOURCE_MANUAL, 'status' => PatientPrescription::STATUS_CONFIRMED, 'prescriber_name' => 'ANA SOUZA', 'prescriber_registration' => 'CRM-ES 123']);
        $diario = PatientMedication::query()->create(['user_id' => $user->id, 'medicine_id' => $marevan->id, 'prescription_id' => $receita->id, 'dosage' => '1 comprimido', 'dose_times' => ['08:00'], 'started_at' => now()->subDays(10)]);
        PatientMedication::query()->create(['user_id' => $user->id, 'medicine_id' => $zocor->id, 'dosage' => '20 mg', 'as_needed' => true]);
        PatientMedication::query()->create(['user_id' => $user->id, 'medicine_id' => $antigo->id, 'dosage' => '1 CP', 'ends_at' => now()->subDays(5)]);
        PatientAllergy::query()->create(['user_id' => $user->id, 'substance' => 'Penicilina', 'normalized_substance' => 'penicilina', 'reaction' => 'urticária']);

        return [$user, $diario];
    }

    public function test_the_summary_has_allergies_alerts_treatments_and_the_adherence_marked_in_the_app(): void
    {
        [$user, $diario] = $this->patientWithTreatment();
        $agora = CarbonImmutable::parse('today 12:00', 'America/Sao_Paulo');
        $schedule = app(TreatmentScheduleService::class);
        foreach ([1, 2, 3] as $diasAtras) {
            $schedule->mark($user, $diario, $agora->subDays($diasAtras)->setTime(8, 0), MedicationDoseEvent::STATUS_TAKEN);
        }
        $schedule->mark($user, $diario, $agora->subDays(4)->setTime(8, 0), MedicationDoseEvent::STATUS_SKIPPED);

        $resumo = app(MedicalSummaryService::class)->build($user, $agora);

        $this->assertSame([['substancia' => 'Penicilina', 'reacao' => 'urticária']], $resumo['alergias']);
        $this->assertSame([['tipo' => 'interacao', 'texto' => 'MAREVAN + ZOCOR', 'gravidade' => 'Maior']], $resumo['alertas']);
        $this->assertSame(['MAREVAN', 'ZOCOR'], array_column($resumo['em_uso'], 'nome'));
        // 11 dias de 08:00 (início há 10 dias, hoje já passou das 08:00): 3 tomadas, 1 pulada, 7 sem marcação.
        $this->assertSame(['previstas' => 11, 'tomadas' => 3, 'puladas' => 1, 'sem_marcacao' => 7, 'percentual' => 27], $resumo['em_uso'][0]['adesao']);
        $this->assertSame('Ana Souza', $resumo['em_uso'][0]['prescritor']);
        $this->assertNull($resumo['em_uso'][1]['adesao'], 'remédio "se precisar" não tem adesão');
        $this->assertSame('LEVOFLOXACINO', $resumo['encerrados'][0]['nome']);
        $this->assertArrayNotHasKey('duvidas', $resumo, 'as perguntas feitas ao app não vão para o resumo do médico');
    }

    public function test_the_patient_downloads_the_pdf_only_with_consent_and_the_download_is_audited(): void
    {
        [$user] = $this->patientWithTreatment();

        $resposta = $this->actingAs($user)->get(route('consulta.resumo'))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $resposta->getContent());
        $this->assertStringContainsString('resumo-para-o-medico-', (string) $resposta->headers->get('content-disposition'));
        $this->assertSame(1, UserAuditEvent::query()->where('user_id', $user->id)->where('event', 'medical_summary_downloaded')->count());

        $this->actingAs($user)->get(route('consulta.perfil'))->assertSee(route('consulta.resumo'))->assertSee('Resumo para o médico (PDF)');

        $semConsentimento = User::factory()->create();
        $this->actingAs($semConsentimento)->get(route('consulta.resumo'))->assertRedirect(route('consulta.perfil'));
        $this->get(route('logout'));
    }
}
