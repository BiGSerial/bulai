<?php

namespace App\Services\Treatment;

use App\Models\PatientMedication;
use App\Models\User;
use App\Notifications\MedicationReminderNotification;
use App\Notifications\TreatmentEndingNotification;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Lembretes do tratamento, rodados pelo agendador a cada 5 minutos (`treatment:send-reminders`).
 *
 * Para cada paciente com consentimento e lembretes ligados: cada dose do dia (fuso do paciente) cuja hora está na janela
 * [horário − lead, horário + atraso] e ainda não foi marcada recebe UM lembrete (chave `dose:{remédio}:{horário UTC}`).
 * No último dia de um tratamento com data de término, um aviso único por dia (chave `end:{remédio}:{data}`),
 * a partir das 08:00 locais, para não acordar ninguém.
 */
final class ReminderService
{
    public const ENDING_NOTICE_HOUR = 8;

    public function __construct(private readonly TreatmentScheduleService $schedule) {}

    /**
     * @return array{patients: int, doses: int, endings: int}
     */
    public function run(?CarbonImmutable $now = null): array
    {
        $now = $now ?? CarbonImmutable::now();
        $totals = ['patients' => 0, 'doses' => 0, 'endings' => 0];

        User::query()
            ->whereHas('patientProfile', fn ($query) => $query->whereNotNull('health_consent_at')->where('reminders_enabled', true))
            ->whereHas('currentMedications', fn ($query) => $query->where('reminders_enabled', true)->where('as_needed', false))
            ->with('patientProfile')
            ->orderBy('id')
            ->chunkById(100, function ($users) use ($now, &$totals): void {
                foreach ($users as $user) {
                    $totals['patients']++;
                    $sent = $this->remind($user, $now);
                    $totals['doses'] += $sent['doses'];
                    $totals['endings'] += $sent['endings'];
                }
            });

        return $totals;
    }

    /**
     * @return array{doses: int, endings: int}
     */
    public function remind(User $user, CarbonImmutable $now): array
    {
        $byEmail = (bool) $user->patientProfile?->reminders_email;
        $local = $now->setTimezone($user->treatmentTimezone());
        $sent = ['doses' => 0, 'endings' => 0];

        foreach ($this->schedule->agenda($user, $local, $local) as $dose) {
            /** @var PatientMedication $medication */
            $medication = $dose['medication'];
            if ($dose['status'] !== TreatmentScheduleService::DOSE_DUE || $dose['event'] !== null || ! $medication->reminders_enabled) {
                continue;
            }
            if ($this->alreadySent(MedicationReminderNotification::keyFor($medication, $dose['scheduled_for']))) {
                continue;
            }
            $user->notify(new MedicationReminderNotification($medication, $dose['scheduled_for'], $byEmail));
            $sent['doses']++;
        }

        if ($local->hour >= self::ENDING_NOTICE_HOUR) {
            $ending = PatientMedication::query()->with('medicine:id,product_name')->where('user_id', $user->id)
                ->where('reminders_enabled', true)->whereDate('ends_at', $local->toDateString())->orderBy('id')->get();
            foreach ($ending as $medication) {
                if ($this->alreadySent(TreatmentEndingNotification::keyFor($medication, $local))) {
                    continue;
                }
                $user->notify(new TreatmentEndingNotification($medication, $local, $byEmail));
                $sent['endings']++;
            }
        }

        return $sent;
    }

    private function alreadySent(string $key): bool
    {
        return DatabaseNotification::query()->where('dedupe_key', $key)->exists();
    }
}
