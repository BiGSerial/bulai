<?php

namespace Tests\Feature\Consulta;

use App\Models\CaregiverAuditEvent;
use App\Models\CaregiverLink;
use App\Models\Consultation\InteractionConsultation;
use App\Models\Consultation\InteractionPairResult;
use App\Models\Knowledge\ActiveIngredient;
use App\Models\Knowledge\AnvisaMedicine;
use App\Models\MobileDevice;
use App\Models\PatientMedication;
use App\Models\PatientProfile;
use App\Models\User;
use App\Models\UserAuditEvent;
use Database\Factories\Consultation\InteractionPairResultFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Meus remédios, interações, lista de bulas e "Dose e horários" por quem cuida, no aplicativo nativo. */
final class AppAndroidPerfilTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, string> */
    private function bearer(User $user): array
    {
        return ['Authorization' => 'Bearer '.MobileDevice::issueFor($user, ['installation_id' => (string) Str::uuid(), 'device_name' => 'Pixel 8'])['token']];
    }

    private function medicine(string $name, string $ingredient = 'SUBSTANCIA X'): AnvisaMedicine
    {
        $medicine = AnvisaMedicine::factory()->create(['product_name' => $name, 'normalized_product_name' => strtolower($name), 'active_ingredient' => $ingredient, 'normalized_active_ingredient' => strtolower($ingredient)]);
        $medicine->ingredients()->attach(ActiveIngredient::factory()->create(['canonical_name' => $ingredient, 'normalized_name' => strtolower($ingredient)])->id, ['component_text' => $ingredient, 'method' => 'alias', 'confidence' => 1]);

        return $medicine;
    }

    public function test_the_profile_needs_consent_then_keeps_medications_with_dose_and_times_prescriptions_and_allergies(): void
    {
        $user = User::factory()->create();
        $headers = $this->bearer($user);
        $losartana = $this->medicine('LOSARTANA', 'LOSARTANA');

        $this->getJson(route('api.mobile.profile'), $headers)->assertOk()->assertJsonPath('health_consent', false)->assertJsonPath('posology_presets.frequencies.1', '2 vezes ao dia');
        $this->postJson(route('api.mobile.profile.medications.store'), ['medicine_id' => $losartana->id], $headers)->assertStatus(409)->assertJsonPath('code', 'health_consent_required');

        $this->postJson(route('api.mobile.profile.consent'), [], $headers)->assertOk()->assertJsonPath('health_consent', true);
        $this->assertTrue(UserAuditEvent::query()->where('user_id', $user->id)->where('event', 'health_consent_given')->exists());

        $id = $this->postJson(route('api.mobile.profile.medications.store'), ['medicine_id' => $losartana->id], $headers)->assertCreated()
            ->assertJsonPath('medications.0.name', 'LOSARTANA')->json('medication_id');
        $this->postJson(route('api.mobile.profile.medications.store'), ['name' => 'Chá caseiro'], $headers)->assertCreated()->assertJsonPath('medications.1.in_catalog', false)
            ->assertJsonPath('message', '“Chá caseiro” entrou na sua lista. Ainda não está no nosso catálogo: nossa equipe vai conferir.')
            ->assertJsonPath('medications.1.schedule.missing', true);
        $this->postJson(route('api.mobile.profile.medications.store'), ['name' => 'ab'], $headers)->assertUnprocessable()->assertJsonValidationErrors('name');

        // "de 12 em 12 horas" sugere os horários e conta a partir de quando tomar; o app salva o formulário inteiro.
        $sugestao = $this->postJson(route('api.mobile.posology.suggest'), ['frequency' => 'de 12 em 12 horas', 'posology' => ['times' => []]], $headers)->assertOk()->json('posology');
        $this->assertSame(12, $sugestao['interval_hours']);
        $this->assertSame(PatientMedication::MODE_INTERVAL, $sugestao['schedule_mode']);
        // Um atalho tocado refaz os horários do zero (escrever só sugere quando ainda não há horários).
        $atalho = ['frequency' => '3 vezes ao dia', 'posology' => ['times' => ['09:00'], 'schedule_mode' => 'interval', 'interval_hours' => 12]];
        $this->postJson(route('api.mobile.posology.suggest'), $atalho, $headers)->assertOk()->assertJsonPath('posology.times', ['09:00']);
        $this->postJson(route('api.mobile.posology.suggest'), $atalho + ['preset' => true], $headers)->assertOk()
            ->assertJsonPath('posology.times', ['06:00', '14:00', '22:00'])->assertJsonPath('posology.frequency', '3 vezes ao dia')->assertJsonPath('posology.schedule_mode', 'fixed');
        $this->putJson(route('api.mobile.profile.medications.update', $id), ['posology' => ['dosage' => '1 comprimido', 'frequency' => '2x ao dia', 'times' => ['08:00', '20:00'], 'schedule_mode' => 'fixed', 'reminders_enabled' => true]], $headers)
            ->assertOk()->assertJsonPath('medications.0.dosage', '1 comprimido')->assertJsonPath('medications.0.when', '08:00 · 20:00')
            ->assertJsonPath('medications.0.schedule.chips', ['08:00', '20:00'])->assertJsonPath('message', 'Dose e horários de LOSARTANA salvos.');
        // A versão anterior do app não manda `continuous`: sem data de término vale uso contínuo. A nova exige o prazo.
        $this->assertTrue(PatientMedication::query()->findOrFail($id)->continuous_use);
        $this->getJson(route('api.mobile.profile'), $headers)->assertJsonPath('medications.0.duration_label', 'Uso contínuo')->assertJsonPath('medications.0.needs_duration', false)
            ->assertJsonPath('medications.1.needs_duration', true)->assertJsonPath('posology_presets.durations', [7, 10, 14, 30]);
        $this->putJson(route('api.mobile.profile.medications.update', $id), ['posology' => ['schedule_mode' => 'fixed', 'continuous' => false, 'ends_at' => null]], $headers)
            ->assertUnprocessable()->assertJsonPath('message', 'Diga até quando vai tomar, ou marque “Uso contínuo”.');
        $this->putJson(route('api.mobile.profile.medications.update', $id), ['posology' => ['dosage' => '1 comprimido', 'times' => ['08:00', '20:00'], 'schedule_mode' => 'fixed', 'continuous' => false, 'started_at' => '2026-11-05', 'ends_at' => '2027-01-10']], $headers)
            ->assertOk()->assertJsonPath('medications.0.duration_label', 'Até 10/01/2027')->assertJsonPath('medications.0.posology.continuous', false);
        $this->putJson(route('api.mobile.profile.medications.update', $id), ['posology' => ['started_at' => '2026-11-10', 'ends_at' => '2026-11-01', 'schedule_mode' => 'fixed']], $headers)
            ->assertUnprocessable()->assertJsonPath('message', 'A data de término deve ser igual ou posterior ao início.');

        $this->postJson(route('api.mobile.profile.prescriptions.store'), ['prescriber' => 'Dra. Ana', 'issued_at' => '01/11/2026'], $headers)->assertCreated()
            ->assertJsonPath('prescriptions.0.prescriber', 'Dra. Ana')->assertJsonPath('prescriptions.0.issued_label', '01/11/2026');
        $this->postJson(route('api.mobile.profile.allergies.store'), ['substance' => 'Dipirona', 'reaction' => 'urticária'], $headers)->assertCreated()->assertJsonPath('allergies.0.substance', 'Dipirona');
        $this->postJson(route('api.mobile.profile.allergies.store'), ['substance' => 'ab'], $headers)->assertUnprocessable()->assertJsonValidationErrors('substance');

        // Itens de outra pessoa não são tocados.
        $other = $this->bearer(User::factory()->create());
        $this->deleteJson(route('api.mobile.profile.medications.destroy', $id), [], $other)->assertOk();
        $this->assertTrue(PatientMedication::query()->whereKey($id)->exists());
        $this->putJson(route('api.mobile.profile.medications.update', $id), ['posology' => ['schedule_mode' => 'fixed']], $other)->assertStatus(409);

        $this->deleteJson(route('api.mobile.profile.medications.destroy', $id), [], $headers)->assertOk()->assertJsonCount(1, 'medications');
        $this->deleteJson(route('api.mobile.profile.health-data.destroy'), [], $headers)->assertOk()->assertJsonPath('health_consent', false);
        $this->assertSame(0, PatientMedication::query()->where('user_id', $user->id)->count());
    }

    public function test_interactions_start_from_the_medicines_in_use_show_allergy_alerts_and_the_same_band_as_the_site(): void
    {
        config(['bulai.consultation.execution' => 'sync']);
        $user = User::factory()->create();
        PatientProfile::query()->create(['user_id' => $user->id, 'health_consent_at' => now()]);
        $headers = $this->bearer($user);
        $alfa = $this->medicine('ALFA', 'DIPIRONA');
        $beta = $this->medicine('BETA');
        PatientMedication::query()->create(['user_id' => $user->id, 'medicine_id' => $alfa->id, 'catalog_status' => PatientMedication::CATALOG_MATCHED]);
        $this->postJson(route('api.mobile.profile.allergies.store'), ['substance' => 'Dipirona'], $headers)->assertCreated();

        $this->getJson(route('api.mobile.interactions'), $headers)->assertOk()->assertJsonPath('mine.0.name', 'ALFA')
            ->assertJsonPath('mine.0.alerts.0', 'Você registrou alergia a Dipirona (contém dipirona)');
        $this->getJson(route('api.mobile.interactions.medicine', $beta), $headers)->assertOk()->assertJsonPath('alerts', []);
        $this->getJson(route('api.mobile.interactions.medicine', ['medicine' => $beta->id, 'selected' => $alfa->id.','.$beta->id]), $headers)
            ->assertUnprocessable()->assertJsonPath('message', 'Este medicamento já está na lista.');
        $this->getJson(route('api.mobile.medicines.search', ['q' => 'beta', 'exclude' => (string) $beta->id]), $headers)->assertOk()->assertJsonPath('items', []);
        $this->postJson(route('api.mobile.interactions.store'), ['medicine_ids' => [$alfa->id]], $headers)->assertUnprocessable();

        $uuid = $this->postJson(route('api.mobile.interactions.store'), ['medicine_ids' => [$alfa->id, $beta->id]], $headers)->assertCreated()
            ->assertJsonPath('medicines', ['ALFA', 'BETA'])->assertJsonPath('total', 1)->json('uuid');
        $consultation = InteractionConsultation::query()->where('uuid', $uuid)->sole();
        InteractionPairResult::query()->where('consultation_id', $consultation->id)->delete();
        InteractionPairResultFactory::new()->create(['consultation_id' => $consultation->id, 'label_a' => 'ALFA', 'label_b' => 'BETA']);

        $this->getJson(route('api.mobile.interactions.show', $uuid), $headers)->assertOk()
            ->assertJsonPath('pairs.0.level', 'major')->assertJsonPath('pairs.0.tone', 'danger')
            ->assertJsonPath('pairs.0.phrase', 'Pode ter interação forte. Fale com seu médico antes de usar os dois.')
            ->assertJsonPath('pairs.0.sources', 'DDInter')
            ->assertJsonPath('allergy_alerts.0.medicine', 'ALFA');
        $this->getJson(route('api.mobile.interactions'), $headers)->assertJsonPath('history.0.uuid', $uuid);

        $this->getJson(route('api.mobile.interactions.show', $uuid), $this->bearer(User::factory()->create()))->assertNotFound();
        $this->deleteJson(route('api.mobile.interactions.destroy', $uuid), [], $headers)->assertOk();
        $this->assertFalse(InteractionConsultation::query()->where('uuid', $uuid)->exists());
    }

    public function test_the_hourly_limit_answers_429(): void
    {
        config(['bulai.consultation.rate_limit_per_hour' => 1, 'bulai.consultation.execution' => 'sync']);
        $headers = $this->bearer(User::factory()->create());
        $pedido = ['medicine_ids' => [$this->medicine('A', 'AAA')->id, $this->medicine('B', 'BBB')->id]];
        $this->postJson(route('api.mobile.interactions.store'), $pedido, $headers)->assertCreated();
        $this->postJson(route('api.mobile.interactions.store'), $pedido, $headers)->assertStatus(429)->assertJsonPath('code', 'rate_limited');
    }

    public function test_the_leaflet_list_searches_the_catalog_with_three_letters(): void
    {
        $this->medicine('PARACETAMOL', 'PARACETAMOL');
        $headers = $this->bearer(User::factory()->create());

        $this->getJson(route('api.mobile.leaflets'), $headers)->assertOk()->assertJsonPath('available', [])->assertJsonPath('results', []);
        $this->getJson(route('api.mobile.leaflets', ['q' => 'parac']), $headers)->assertOk()
            ->assertJsonPath('results.0.name', 'PARACETAMOL')->assertJsonPath('results.0.has_leaflet', false);
    }

    public function test_a_caregiver_with_permission_changes_dose_and_times_and_it_goes_to_the_link_audit(): void
    {
        $patient = User::factory()->create();
        PatientProfile::query()->create(['user_id' => $patient->id, 'health_consent_at' => now()]);
        $caregiver = User::factory()->create();
        $link = CaregiverLink::factory()->create(['patient_id' => $patient->id, 'caregiver_id' => $caregiver->id, 'status' => CaregiverLink::STATUS_ACTIVE, 'can_view_medications' => true, 'can_manage_medications' => false]);
        $medication = PatientMedication::query()->create(['user_id' => $patient->id, 'name_as_written' => 'Losartana', 'catalog_status' => PatientMedication::CATALOG_UNMATCHED, 'dose_times' => ['08:00']]);
        $headers = $this->bearer($caregiver);

        $this->getJson(route('api.mobile.caring.medications', $link), $headers)->assertOk()->assertJsonPath('can_edit', false)->assertJsonPath('medications.0.when', '08:00');
        $posology = ['posology' => ['times' => ['09:00'], 'schedule_mode' => 'fixed', 'reminders_enabled' => true]];
        $this->putJson(route('api.mobile.caring.medications.update', [$link, $medication]), $posology, $headers)->assertForbidden();

        $link->update(['can_manage_medications' => true]);
        $this->putJson(route('api.mobile.caring.medications.update', [$link, $medication]), $posology, $headers)->assertOk()
            ->assertJsonPath('message', 'Dose e horários de Losartana salvos.')->assertJsonPath('medications.0.when', '09:00');
        $this->assertTrue(CaregiverAuditEvent::query()->where('caregiver_link_id', $link->id)->where('event', 'medication_updated')->exists());

        $this->getJson(route('api.mobile.caring.medications', $link), $this->bearer(User::factory()->create()))->assertForbidden();
    }
}
