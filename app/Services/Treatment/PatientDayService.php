<?php

namespace App\Services\Treatment;

use App\Models\CaregiverLink;
use App\Models\PatientMedication;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Dados das telas "Início" e "Remédios do dia" do aplicativo nativo, no formato da API (`/api/mobile/v1`). As regras são
 * as mesmas das telas Livewire (TreatmentScheduleService); aqui só se monta o JSON.
 */
final class PatientDayService
{
    /** Ontem, hoje e amanhã, como no site. */
    public const MIN_OFFSET = -1;

    public const MAX_OFFSET = 1;

    public function __construct(private TreatmentScheduleService $schedule) {}

    /**
     * @return array<string, mixed>
     */
    public function home(User $user): array
    {
        $consent = $user->hasHealthConsent();
        $agenda = $consent ? $this->schedule->annotateAdjustments($this->schedule->agenda($user)) : collect();
        $next = $consent ? $this->schedule->nextDose($user) : null;
        $nextRow = $next === null ? null : $agenda->first(fn (array $dose): bool => $dose['medication']->id === $next['medication']->id && $dose['scheduled_for']->equalTo($next['scheduled_for']));
        $today = CarbonImmutable::now($user->treatmentTimezone());

        return [
            'first_name' => $user->callName(),
            'date_label' => self::dateLabel($today),
            'health_consent' => $consent,
            'has_medications' => $consent && PatientMedication::query()->where('user_id', $user->id)->exists(),
            'next_dose' => $next === null ? null : $this->dose($nextRow ?? $next + ['ajustado' => null]) + ['is_today' => $next['scheduled_for']->isSameDay($today)],
            'progress' => [
                'total' => $agenda->count(),
                'taken' => $agenda->where('status', TreatmentScheduleService::DOSE_TAKEN)->count(),
                'done' => $agenda->whereIn('status', [TreatmentScheduleService::DOSE_TAKEN, TreatmentScheduleService::DOSE_SKIPPED])->count(),
            ],
            'unread_notices' => $user->unreadNotifications()->count(),
            'caring_for' => CaregiverLink::query()->active()->where('caregiver_id', $user->id)->where('can_view_medications', true)
                ->whereHas('patient.patientProfile', fn ($query) => $query->whereNotNull('health_consent_at'))
                ->with('patient:id,name')->orderBy('id')->get()
                ->map(fn (CaregiverLink $link): array => ['link_id' => $link->id, 'name' => $link->patient->name, 'first_name' => CaregiverLink::firstName($link->patient)])
                ->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function day(User $user, int $offset = 0): array
    {
        $offset = max(self::MIN_OFFSET, min(self::MAX_OFFSET, $offset));
        $consent = $user->hasHealthConsent();
        $date = CarbonImmutable::now($user->treatmentTimezone())->startOfDay()->addDays($offset);
        $agenda = $consent ? $this->schedule->annotateAdjustments($this->schedule->agenda($user, $date)) : collect();
        $medications = $consent ? PatientMedication::query()->with('medicine:id,product_name')->where('user_id', $user->id)->orderBy('id')->get() : collect();

        return [
            'offset' => $offset,
            'date' => $date->toDateString(),
            'date_label' => self::dateLabel($date),
            'health_consent' => $consent,
            'has_medications' => $medications->isNotEmpty(),
            'has_dynamic' => $agenda->contains(fn (array $dose): bool => $dose['dynamic']),
            'summary' => [
                'total' => $agenda->count(),
                'done' => $agenda->whereIn('status', [TreatmentScheduleService::DOSE_TAKEN, TreatmentScheduleService::DOSE_SKIPPED])->count(),
                'taken' => $agenda->where('status', TreatmentScheduleService::DOSE_TAKEN)->count(),
                'late' => $agenda->where('status', TreatmentScheduleService::DOSE_LATE)->count(),
            ],
            'doses' => $agenda->map(fn (array $dose): array => $this->dose($dose))->values()->all(),
            'as_needed' => $medications->filter(fn (PatientMedication $item): bool => $item->as_needed && $item->isActive())
                ->map(fn (PatientMedication $item): array => ['medication_id' => $item->id, 'name' => $item->displayName(), 'dosage' => $item->dosage])
                ->values()->all(),
        ];
    }

    /** "Quarta-feira, 18 de novembro". */
    private static function dateLabel(CarbonImmutable $date): string
    {
        return Str::ucfirst($date->locale('pt_BR')->translatedFormat('l, j \d\e F'));
    }

    /**
     * @param  array<string, mixed>  $dose
     * @return array{key: string, medication_id: int, name: string, dosage: string|null, notes: string|null, time: string, period: string, scheduled_for: string, status: string, dynamic: bool, adjusted_from: string|null, taken_at: string|null}
     */
    private function dose(array $dose): array
    {
        /** @var PatientMedication $medication */
        $medication = $dose['medication'];
        $hour = (int) substr($dose['time'], 0, 2);

        return [
            'key' => $medication->id.'|'.$dose['scheduled_for']->utc()->toIso8601String(),
            'medication_id' => $medication->id,
            'name' => $medication->displayName(),
            'dosage' => $medication->dosage,
            'notes' => $medication->notes,
            'time' => $dose['time'],
            'period' => match (true) {
                $hour < 12 => 'manha', $hour < 18 => 'tarde', default => 'noite'
            },
            'scheduled_for' => $dose['scheduled_for']->toIso8601String(),
            'status' => $dose['status'],
            'dynamic' => (bool) ($dose['dynamic'] ?? false),
            'adjusted_from' => $dose['ajustado'] ?? null,
            'taken_at' => ($dose['taken_at'] ?? null)?->toIso8601String(),
        ];
    }
}
