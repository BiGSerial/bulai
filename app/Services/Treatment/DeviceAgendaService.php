<?php

namespace App\Services\Treatment;

use App\Models\PatientMedication;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Agenda das doses para o aplicativo Android: hoje e amanhã no fuso do paciente, com o que já foi marcado.
 * O aparelho agenda um alarme exato para cada dose com `remind = true` e ainda pendente; sincroniza de novo depois de
 * cada marcação (remédios de programação dinâmica mudam a cadeia a partir do horário real em que a dose foi tomada).
 */
final class DeviceAgendaService
{
    public const DAYS_AHEAD = 2;

    public function __construct(private readonly TreatmentScheduleService $schedule) {}

    /**
     * @return array{generated_at: string, timezone: string, reminders_enabled: bool, reminders_blocked_reason: string|null, lead_minutes: int, late_after_minutes: int, unread_notices: int, doses: list<array{key: string, medication_id: int, name: string, dosage: string|null, time: string, scheduled_for: string, status: string, dynamic: bool, remind: bool}>}
     */
    public function payload(User $user, ?CarbonImmutable $now = null): array
    {
        $user->loadMissing('patientProfile');
        $timezone = $user->treatmentTimezone();
        $now = ($now ?? CarbonImmutable::now())->setTimezone($timezone);
        $blockedReason = match (true) {
            ! $user->hasHealthConsent() => 'consent',
            ! (bool) $user->patientProfile?->reminders_enabled => 'disabled',
            default => null,
        };

        $doses = [];
        for ($offset = 0; $offset < self::DAYS_AHEAD; $offset++) {
            foreach ($this->schedule->agenda($user, $now->addDays($offset), $now) as $dose) {
                /** @var PatientMedication $medication */
                $medication = $dose['medication'];
                $scheduledUtc = $dose['scheduled_for']->utc()->toIso8601String();
                $doses[] = [
                    'key' => $medication->id.'|'.$scheduledUtc,
                    'medication_id' => $medication->id,
                    'name' => $medication->displayName(),
                    'dosage' => $medication->dosage,
                    'time' => $dose['time'],
                    'scheduled_for' => $dose['scheduled_for']->toIso8601String(),
                    'status' => $dose['status'],
                    'dynamic' => $dose['dynamic'],
                    'remind' => $blockedReason === null && $medication->reminders_enabled && ! $medication->as_needed,
                ];
            }
        }

        return [
            'generated_at' => $now->toIso8601String(),
            'timezone' => $timezone,
            'reminders_enabled' => $blockedReason === null,
            'reminders_blocked_reason' => $blockedReason,
            'lead_minutes' => (int) config('bulai.treatment.reminder_lead_minutes'),
            'late_after_minutes' => (int) config('bulai.treatment.late_after_minutes'),
            'unread_notices' => $user->unreadNotifications()->count(),
            'doses' => $doses,
        ];
    }
}
