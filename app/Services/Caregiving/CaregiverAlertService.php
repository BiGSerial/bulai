<?php

namespace App\Services\Caregiving;

use App\Models\CaregiverLink;
use App\Models\PatientMedication;
use App\Notifications\CaregiverLateDoseNotification;
use App\Services\Treatment\TreatmentScheduleService;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;

/**
 * Avisos de dose atrasada para quem cuida, rodados junto com os lembretes (`treatment:send-reminders`, a cada 5 min).
 *
 * Para cada vínculo ATIVO com a permissão "receber avisos" e paciente com consentimento de saúde: a agenda do paciente
 * (a mesma `TreatmentScheduleService::agenda()` dos lembretes, no fuso dele) é montada uma vez; cada dose que ficou
 * atrasada (`late`, sem marcação, remédio com lembrete ligado) há no máximo `caregiver_alert_window_minutes` gera UM
 * aviso por vínculo (chave `cuidador:{vínculo}:{remédio}:{horário UTC}`). A janela evita avisar doses antigas de uma vez
 * quando o vínculo é aprovado ou o agendador volta depois de parado.
 */
final class CaregiverAlertService
{
    public function __construct(private readonly TreatmentScheduleService $schedule) {}

    /**
     * @return array{patients: int, alerts: int}
     */
    public function run(?CarbonImmutable $now = null): array
    {
        $now = $now ?? CarbonImmutable::now();
        $totals = ['patients' => 0, 'alerts' => 0];

        CaregiverLink::query()->active()->where(CaregiverLink::PERMISSION_ALERTS, true)
            ->whereHas('patient.patientProfile', fn ($query) => $query->whereNotNull('health_consent_at'))
            ->whereHas('caregiver', fn ($query) => $query->where('active', true))
            ->with(['patient.patientProfile', 'caregiver'])
            ->orderBy('patient_id')->orderBy('id')
            ->get()
            ->groupBy('patient_id')
            ->each(function (Collection $links) use ($now, &$totals): void {
                $totals['patients']++;
                $totals['alerts'] += $this->alertLinks($links, $now);
            });

        return $totals;
    }

    /** @param Collection<int, CaregiverLink> $links vínculos de um mesmo paciente */
    private function alertLinks(Collection $links, CarbonImmutable $now): int
    {
        $patient = $links->first()->patient;
        $local = $now->setTimezone($patient->treatmentTimezone());
        $lateAfter = (int) config('bulai.treatment.late_after_minutes');
        $window = (int) config('bulai.treatment.caregiver_alert_window_minutes');
        // Logo depois da meia-noite, a dose atrasada pode ser da agenda de ontem.
        $days = $local->lessThan($local->startOfDay()->addMinutes($lateAfter + $window)) ? [$local->subDay(), $local] : [$local];
        $sent = 0;

        foreach ($days as $day) {
            foreach ($this->schedule->agenda($patient, $day, $local) as $dose) {
                /** @var PatientMedication $medication */
                $medication = $dose['medication'];
                $lateSince = $dose['scheduled_for']->addMinutes($lateAfter);
                if ($dose['status'] !== TreatmentScheduleService::DOSE_LATE || $dose['event'] !== null || ! $medication->reminders_enabled || $local->greaterThan($lateSince->addMinutes($window))) {
                    continue;
                }
                foreach ($links as $link) {
                    $key = CaregiverLateDoseNotification::keyFor($link, $medication, $dose['scheduled_for']);
                    if (DatabaseNotification::query()->where('dedupe_key', $key)->exists()) {
                        continue;
                    }
                    $link->caregiver->notify(new CaregiverLateDoseNotification($link, $medication, $dose['scheduled_for']));
                    $sent++;
                }
            }
        }

        return $sent;
    }
}
