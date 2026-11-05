<?php

namespace Tests\Feature\Consulta;

use App\Models\Knowledge\AnvisaMedicine;
use App\Models\MedicationDoseEvent;
use App\Models\PatientMedication;
use App\Models\PatientProfile;
use App\Models\UnmatchedMedicineReport;
use App\Models\User;
use App\Services\Treatment\TreatmentScheduleService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/** Fundação da programação do tratamento: registro com/sem catálogo, agenda do dia no fuso do paciente e marcações. */
final class ProgramacaoDoTratamentoTest extends TestCase
{
    use RefreshDatabase;

    private function patient(string $timezone = 'America/Sao_Paulo'): User
    {
        $user = User::factory()->create();
        PatientProfile::query()->create(['user_id' => $user->id, 'health_consent_at' => now(), 'timezone' => $timezone]);

        return $user;
    }

    public function test_a_medicine_outside_the_catalog_is_registered_by_name_and_flagged_for_the_admins(): void
    {
        $user = $this->patient();
        $service = app(TreatmentScheduleService::class);

        $medication = $service->register($user, ['name_as_written' => 'Xarope Novo 10mg', 'dosage' => '5 ml', 'frequency' => 'de 12 em 12 horas', 'duration' => 'por 7 dias', 'started_at' => '2026-11-05']);

        $this->assertNull($medication->medicine_id);
        $this->assertSame([PatientMedication::CATALOG_UNMATCHED, 'Xarope Novo 10mg', ['08:00', '20:00'], '2026-11-12'], [$medication->catalog_status, $medication->displayName(), $medication->dose_times, $medication->ends_at->format('Y-m-d')]);
        $report = UnmatchedMedicineReport::query()->sole();
        $this->assertSame([$user->id, $medication->id, 'xarope novo 10mg', UnmatchedMedicineReport::STATUS_OPEN, '5 ml'], [$report->user_id, $report->patient_medication_id, $report->normalized_name, $report->status, $report->dosage]);

        // Registrar o mesmo nome de novo atualiza em vez de duplicar (e não abre outra pendência).
        $service->register($user, ['name_as_written' => 'Xarope Novo 10mg', 'frequency' => '1x ao dia']);
        $this->assertSame(1, PatientMedication::query()->count());
        $this->assertSame(1, UnmatchedMedicineReport::query()->count());
        $this->assertSame(['08:00'], $medication->refresh()->dose_times);
    }

    public function test_admin_resolution_links_the_medicine_to_the_catalog_and_merges_with_an_existing_entry(): void
    {
        $user = $this->patient();
        $admin = User::factory()->create();
        $medicine = AnvisaMedicine::factory()->create(['product_name' => 'AMOXIL']);
        $service = app(TreatmentScheduleService::class);

        $unmatched = $service->register($user, ['name_as_written' => 'Amoxil 500', 'frequency' => '8/8h', 'dosage' => '1 cápsula']);
        $service->mark($user, $unmatched, CarbonImmutable::parse('2026-11-05 06:00', 'America/Sao_Paulo'), MedicationDoseEvent::STATUS_TAKEN);
        $report = UnmatchedMedicineReport::query()->sole();

        $resolved = $service->resolve($report, $medicine, $admin, 'Mesmo produto');
        $this->assertSame([$medicine->id, PatientMedication::CATALOG_MATCHED, 'AMOXIL', ['06:00', '14:00', '22:00']], [$resolved->medicine_id, $resolved->catalog_status, $resolved->displayName(), $resolved->dose_times]);
        $this->assertSame([UnmatchedMedicineReport::STATUS_RESOLVED, $medicine->id, $admin->id, 'Mesmo produto'], [$report->refresh()->status, $report->resolved_medicine_id, $report->resolved_by, $report->resolution_note]);

        // Se o paciente já tinha o remédio do catálogo, o registro pelo nome é fundido nele (sem violar a unicidade).
        $second = $service->register($user, ['name_as_written' => 'Amoxil BD', 'frequency' => '2x ao dia']);
        $secondReport = UnmatchedMedicineReport::query()->open()->sole();
        $merged = $service->resolve($secondReport, $medicine, $admin);
        $this->assertSame($resolved->id, $merged->id);
        $this->assertNull(PatientMedication::query()->find($second->id));
        $this->assertSame(1, PatientMedication::query()->where('user_id', $user->id)->count());
        $this->assertSame(1, MedicationDoseEvent::query()->where('patient_medication_id', $merged->id)->count());
    }

    public function test_the_daily_agenda_follows_the_patient_timezone_and_reflects_what_was_marked(): void
    {
        $user = $this->patient('America/Sao_Paulo');
        $medicine = AnvisaMedicine::factory()->create(['product_name' => 'LOSARTANA']);
        $service = app(TreatmentScheduleService::class);
        $losartana = $service->register($user, ['medicine_id' => $medicine->id, 'frequency' => '12/12h', 'started_at' => '2026-11-01']);
        $service->register($user, ['name_as_written' => 'Vitamina', 'frequency' => 'se precisar']);
        $service->register($user, ['name_as_written' => 'Antigo', 'frequency' => '1x ao dia', 'started_at' => '2026-10-01', 'ends_at' => '2026-10-10']);
        $service->register($user, ['name_as_written' => 'Futuro', 'frequency' => '1x ao dia', 'started_at' => '2026-12-01']);

        $now = CarbonImmutable::parse('2026-11-05 09:30', 'America/Sao_Paulo');
        $agenda = $service->agenda($user, $now, $now);
        $this->assertSame([['08:00', 'LOSARTANA', 'late'], ['20:00', 'LOSARTANA', 'pending']], $agenda->map(fn (array $row): array => [$row['time'], $row['medication']->displayName(), $row['status']])->all());
        $this->assertSame('2026-11-05T11:00:00+00:00', $agenda->first()['scheduled_for']->utc()->toIso8601String());

        $service->mark($user, $losartana, $agenda->first()['scheduled_for'], MedicationDoseEvent::STATUS_TAKEN);
        $agenda = $service->agenda($user, $now, $now);
        $this->assertSame(['taken', 'pending'], $agenda->pluck('status')->all());
        $this->assertSame('20:00', $service->nextDose($user, $now)['time']);

        $service->mark($user, $losartana, $agenda->last()['scheduled_for'], MedicationDoseEvent::STATUS_SKIPPED);
        $this->assertSame('08:00', $service->nextDose($user, $now)['time']);
        $this->assertTrue($service->nextDose($user, $now)['scheduled_for']->isSameDay($now->addDay()));

        $service->unmark($user, $losartana, $agenda->last()['scheduled_for']);
        $this->assertSame('due', $service->agenda($user, $now, $now->setTime(19, 55))->last()['status']);
    }

    public function test_marking_is_private_to_the_owner_and_cleans_dose_times(): void
    {
        $owner = $this->patient();
        $other = $this->patient();
        $medication = app(TreatmentScheduleService::class)->register($owner, ['name_as_written' => 'Remédio', 'dose_times' => ['7:05', '20:00', '20:00', 'xx', '25:00']]);
        $this->assertSame(['07:05', '20:00'], $medication->dose_times);

        $this->expectExceptionObject(new NotFoundHttpException);
        app(TreatmentScheduleService::class)->mark($other, $medication, CarbonImmutable::now(), MedicationDoseEvent::STATUS_TAKEN);
    }
}
