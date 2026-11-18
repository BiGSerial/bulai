<?php

namespace Tests\Feature\Consulta;

use App\Models\CaregiverLink;
use App\Models\MedicationDoseEvent;
use App\Models\MobileDevice;
use App\Models\PatientMedication;
use App\Models\PatientProfile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Modo cuidador e resumo em PDF no aplicativo nativo (`/api/mobile/v1/cuidadores`, `/cuidando`, `/resumo.pdf`). */
final class AppAndroidCuidadoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-05 07:30', 'America/Sao_Paulo'));
        Carbon::setTestNow(Carbon::parse('2026-11-05 07:30', 'America/Sao_Paulo'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function person(bool $consent = true): User
    {
        $user = User::factory()->create();
        if ($consent) {
            PatientProfile::query()->create(['user_id' => $user->id, 'health_consent_at' => now(), 'timezone' => 'America/Sao_Paulo', 'reminders_enabled' => true]);
        }

        return $user;
    }

    /** @return array<string, string> */
    private function bearer(User $user): array
    {
        return ['Authorization' => 'Bearer '.MobileDevice::issueFor($user, ['installation_id' => (string) Str::uuid(), 'device_name' => 'Pixel 8'])['token']];
    }

    public function test_the_patient_invites_by_qr_the_caregiver_reads_it_and_sees_or_marks_only_what_was_allowed(): void
    {
        $patient = $this->person();
        $patient->forceFill(['name' => 'Maria Souza'])->save();
        $caregiver = $this->person(consent: false);
        $patientHeaders = $this->bearer($patient);
        $caregiverHeaders = $this->bearer($caregiver);
        $losartana = PatientMedication::query()->create(['user_id' => $patient->id, 'name_as_written' => 'Losartana', 'catalog_status' => PatientMedication::CATALOG_UNMATCHED, 'dose_times' => ['08:00'], 'started_at' => '2026-11-01', 'reminders_enabled' => true]);

        $convite = $this->postJson(route('api.mobile.caregivers.invite'), [], $patientHeaders)->assertCreated()->json('invite');
        $this->assertMatchesRegularExpression('/^\d{4} \d{4}$/', $convite['code']);
        $this->assertStringContainsString('/cuidar?codigo=', $convite['url']);

        // O QR traz o link inteiro: o servidor tira o código dele.
        $this->postJson(route('api.mobile.caring.accept'), ['code' => $convite['url']], $caregiverHeaders)->assertCreated()
            ->assertJsonPath('pending.0.first_name', 'Maria');
        $link = CaregiverLink::query()->sole();
        $this->getJson(route('api.mobile.caregivers'), $patientHeaders)->assertOk()->assertJsonPath('pending.0.id', $link->id)->assertJsonPath('invite', null);

        // Aprovado só para ver: vê o dia, não marca.
        $this->postJson(route('api.mobile.caregivers.approve', $link), ['permissions' => [CaregiverLink::PERMISSION_VIEW => true, CaregiverLink::PERMISSION_MANAGE => false, CaregiverLink::PERMISSION_ALERTS => false]], $patientHeaders)
            ->assertOk()->assertJsonPath('active.0.permissions.can_manage_medications', false);
        $this->getJson(route('api.mobile.caring'), $caregiverHeaders)->assertJsonPath('active.0.can_view', true)->assertJsonPath('active.0.can_mark', false);
        $this->getJson(route('api.mobile.caring.day', $link), $caregiverHeaders)->assertOk()
            ->assertJsonPath('doses.0.name', 'Losartana')->assertJsonPath('can_mark', false)->assertJsonPath('caring_for.first_name', 'Maria');
        $dose = ['medication_id' => $losartana->id, 'scheduled_for' => '2026-11-05T08:00:00-03:00', 'status' => 'taken'];
        $this->postJson(route('api.mobile.caring.mark', $link), $dose, $caregiverHeaders)->assertForbidden();

        // O paciente libera marcar: a dose fica marcada no tratamento dele.
        $this->putJson(route('api.mobile.caregivers.permissions', $link), ['permissions' => [CaregiverLink::PERMISSION_VIEW => true, CaregiverLink::PERMISSION_MANAGE => true]], $patientHeaders)->assertOk();
        $this->postJson(route('api.mobile.caring.mark', $link), $dose, $caregiverHeaders)->assertOk()->assertJsonPath('doses.0.status', 'taken');
        $this->assertSame(MedicationDoseEvent::STATUS_TAKEN, MedicationDoseEvent::query()->where('patient_medication_id', $losartana->id)->sole()->status);

        // Outra pessoa não vê nem mexe; quem cuida pode sair; depois disso não vê mais.
        $this->getJson(route('api.mobile.caring.day', $link), $this->bearer($this->person()))->assertForbidden();
        $this->postJson(route('api.mobile.caregivers.approve', $link), ['permissions' => []], $caregiverHeaders)->assertForbidden();
        $this->deleteJson(route('api.mobile.caring.leave', $link), [], $caregiverHeaders)->assertOk()->assertJsonPath('active', []);
        $this->getJson(route('api.mobile.caring.day', $link), $caregiverHeaders)->assertForbidden();
    }

    public function test_invite_needs_consent_wrong_codes_are_clear_and_the_patient_can_cancel_or_revoke(): void
    {
        $this->postJson(route('api.mobile.caregivers.invite'), [], $this->bearer($this->person(consent: false)))->assertStatus(409)->assertJsonPath('code', 'invite_unavailable');

        $patient = $this->person();
        $headers = $this->bearer($patient);
        $caregiverHeaders = $this->bearer($this->person());
        $this->postJson(route('api.mobile.caring.accept'), ['code' => '123'], $caregiverHeaders)->assertUnprocessable()->assertJsonPath('errors.code.0', 'O código tem 8 números. Confira e digite de novo.');

        $codigo = str_replace(' ', '', $this->postJson(route('api.mobile.caregivers.invite'), [], $headers)->json('invite.code'));
        $this->deleteJson(route('api.mobile.caregivers.invite.cancel'), [], $headers)->assertOk()->assertJsonPath('invite', null);
        $this->postJson(route('api.mobile.caring.accept'), ['code' => $codigo], $caregiverHeaders)->assertUnprocessable();

        $codigo = str_replace(' ', '', $this->postJson(route('api.mobile.caregivers.invite'), [], $headers)->json('invite.code'));
        $this->postJson(route('api.mobile.caring.accept'), ['code' => $codigo], $caregiverHeaders)->assertCreated();
        $link = CaregiverLink::query()->where('status', CaregiverLink::STATUS_PENDING)->sole();
        $this->postJson(route('api.mobile.caregivers.approve', $link), ['permissions' => [CaregiverLink::PERMISSION_VIEW => true]], $headers)->assertOk();
        $this->deleteJson(route('api.mobile.caregivers.revoke', $link), [], $headers)->assertOk()->assertJsonPath('active', [])->assertJsonPath('ended.0.status', CaregiverLink::STATUS_REVOKED);
    }

    public function test_the_medical_summary_pdf_downloads_only_with_consent(): void
    {
        $this->get(route('api.mobile.summary'), $this->bearer($this->person()) + ['Accept' => 'application/json'])->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->getJson(route('api.mobile.summary'), $this->bearer($this->person(consent: false)))->assertStatus(409);
    }
}
