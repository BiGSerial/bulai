<?php

namespace App\Services\Treatment;

use App\Models\Knowledge\AnvisaMedicine;
use App\Models\MedicationDoseEvent;
use App\Models\PatientMedication;
use App\Models\UnmatchedMedicineReport;
use App\Models\User;
use App\Services\Knowledge\AnvisaCatalogNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Programação do tratamento do paciente:
 *  - registra remédios da receita COM ou SEM correspondência no catálogo (sem correspondência vira pendência do admin);
 *  - monta a agenda do dia (cada horário programado de cada remédio ativo, com o que já foi marcado);
 *  - registra "tomei"/"pulei"; resolve pendências ligando o remédio ao catálogo.
 *
 * Horários são do paciente (fuso em `patient_profiles.timezone`); `scheduled_for` e `recorded_at` são gravados no fuso do app
 * (`APP_TIMEZONE`), que é como o Eloquent lê colunas `timestamp` sem fuso.
 */
final class TreatmentScheduleService
{
    public const DOSE_PENDING = 'pending';

    public const DOSE_DUE = 'due';

    public const DOSE_LATE = 'late';

    public const DOSE_TAKEN = 'taken';

    public const DOSE_SKIPPED = 'skipped';

    public function __construct(
        private readonly DoseScheduleParser $parser,
        private readonly AnvisaCatalogNormalizer $normalizer,
    ) {}

    /**
     * Registra (ou atualiza) um remédio do tratamento. Sem `medicine_id`, o remédio entra pelo nome escrito e
     * abre uma pendência para os administradores. Os horários vêm da frequência quando não forem informados.
     *
     * @param  array{medicine_id?: int|null, name_as_written?: string|null, prescription_id?: int|null, dosage?: string|null, frequency?: string|null, duration?: string|null, dose_times?: list<string>|null, interval_minutes?: int|null, schedule_mode?: string|null, started_at?: Carbon|string|null, ends_at?: Carbon|string|null, notes?: string|null}  $data
     */
    public function register(User $user, array $data): PatientMedication
    {
        $medicineId = isset($data['medicine_id']) && $data['medicine_id'] !== null && AnvisaMedicine::query()->whereKey((int) $data['medicine_id'])->exists() ? (int) $data['medicine_id'] : null;
        $name = $this->text($data['name_as_written'] ?? null, 120);
        $frequency = $this->text($data['frequency'] ?? null, 120);
        $schedule = $this->parser->parse($frequency);
        $times = isset($data['dose_times']) && is_array($data['dose_times']) ? self::cleanTimes($data['dose_times']) : ($schedule['times'] ?? []);
        $start = isset($data['started_at']) && $data['started_at'] !== null ? Carbon::parse($data['started_at'])->startOfDay() : now()->startOfDay();
        $ends = isset($data['ends_at']) && $data['ends_at'] !== null ? Carbon::parse($data['ends_at'])->startOfDay() : null;
        if ($ends === null && isset($data['duration'])) {
            $days = self::durationInDays($data['duration']);
            $ends = $days !== null ? $start->copy()->addDays($days) : null;
        }

        // Intervalo ("de 8 em 8 horas"): programação dinâmica por padrão; o paciente pode preferir horários fixos.
        $intervalMinutes = isset($data['interval_minutes']) ? max(0, (int) $data['interval_minutes']) : (int) ($schedule['interval_hours'] ?? 0) * 60;
        $mode = ($data['schedule_mode'] ?? null) === PatientMedication::MODE_FIXED || $intervalMinutes <= 0 ? PatientMedication::MODE_FIXED : PatientMedication::MODE_INTERVAL;

        $attributes = [
            'name_as_written' => $name,
            'catalog_status' => $medicineId === null ? PatientMedication::CATALOG_UNMATCHED : PatientMedication::CATALOG_MATCHED,
            'prescription_id' => $data['prescription_id'] ?? null,
            'dosage' => $this->text($data['dosage'] ?? null, 120),
            'frequency' => $frequency,
            'dose_times' => $times,
            'interval_minutes' => $intervalMinutes > 0 ? $intervalMinutes : null,
            'schedule_mode' => $mode,
            'as_needed' => (bool) ($schedule['as_needed'] ?? false),
            'started_at' => $start,
            'ends_at' => $ends,
            'notes' => $this->text($data['notes'] ?? null, 255),
        ];

        return DB::transaction(function () use ($user, $medicineId, $name, $frequency, $attributes): PatientMedication {
            if ($medicineId !== null) {
                $medication = PatientMedication::query()->updateOrCreate(['user_id' => $user->id, 'medicine_id' => $medicineId], $attributes);
                UnmatchedMedicineReport::query()->where('patient_medication_id', $medication->id)->open()->update(['status' => UnmatchedMedicineReport::STATUS_RESOLVED, 'resolved_medicine_id' => $medicineId, 'resolved_at' => now()]);

                return $medication;
            }

            $normalized = $this->normalizer->text($name);
            $medication = PatientMedication::query()->where('user_id', $user->id)->whereNull('medicine_id')
                ->where('name_as_written', $name)->first();
            $medication = $medication === null
                ? PatientMedication::query()->create(['user_id' => $user->id, 'medicine_id' => null] + $attributes)
                : tap($medication)->update($attributes);
            UnmatchedMedicineReport::query()->firstOrCreate(
                ['patient_medication_id' => $medication->id, 'status' => UnmatchedMedicineReport::STATUS_OPEN],
                ['user_id' => $user->id, 'name_as_written' => (string) $name, 'normalized_name' => mb_substr($normalized, 0, 120), 'dosage' => $attributes['dosage'], 'frequency' => $frequency],
            );

            return $medication;
        });
    }

    /**
     * Liga um remédio sem correspondência ao catálogo (ação do administrador) e fecha a pendência.
     * Se o paciente já tinha esse medicamento do catálogo, os dados de posologia migram para ele e o duplicado some.
     */
    public function resolve(UnmatchedMedicineReport $report, AnvisaMedicine $medicine, User $admin, ?string $note = null): PatientMedication
    {
        return DB::transaction(function () use ($report, $medicine, $admin, $note): PatientMedication {
            $medication = $report->medication()->firstOrFail();
            $existing = PatientMedication::query()->where('user_id', $medication->user_id)->where('medicine_id', $medicine->id)->whereKeyNot($medication->id)->first();
            if ($existing !== null) {
                $existing->update(array_filter([
                    'prescription_id' => $medication->prescription_id, 'dosage' => $medication->dosage, 'frequency' => $medication->frequency,
                    'dose_times' => $medication->dose_times, 'interval_minutes' => $medication->interval_minutes, 'started_at' => $medication->started_at, 'ends_at' => $medication->ends_at, 'notes' => $medication->notes,
                ], fn ($value): bool => $value !== null) + ['catalog_status' => PatientMedication::CATALOG_MATCHED, 'name_as_written' => $medication->name_as_written, 'schedule_mode' => $medication->schedule_mode]);
                MedicationDoseEvent::query()->where('patient_medication_id', $medication->id)->update(['patient_medication_id' => $existing->id]);
                UnmatchedMedicineReport::query()->where('patient_medication_id', $medication->id)->update(['patient_medication_id' => $existing->id]);
                $medication->delete();
                $medication = $existing;
            } else {
                $medication->update(['medicine_id' => $medicine->id, 'catalog_status' => PatientMedication::CATALOG_MATCHED]);
            }
            $report->update(['status' => UnmatchedMedicineReport::STATUS_RESOLVED, 'resolved_medicine_id' => $medicine->id, 'resolved_by' => $admin->id, 'resolution_note' => $this->text($note, 255), 'resolved_at' => now()]);

            return $medication->refresh();
        });
    }

    /**
     * Agenda de um dia (fuso do paciente): uma linha por horário de cada remédio ativo com horários definidos.
     *
     * Remédios com programação dinâmica (`isDynamic()`) não usam horários fixos: a cadeia de doses é projetada a partir
     * do horário REAL em que a última dose foi marcada como tomada (ou do horário programado da última dose pulada),
     * somando o intervalo. Marcar "Tomei" às 09:30 um remédio de 8 em 8 horas leva a próxima dose para 17:30.
     * Cada linha traz `dynamic` (bool) e `taken_at` (horário real da marcação, quando tomada).
     *
     * @return Collection<int, array{medication: PatientMedication, time: string, scheduled_for: CarbonImmutable, status: string, event: MedicationDoseEvent|null, dynamic: bool, taken_at: CarbonImmutable|null}>
     */
    public function agenda(User $user, ?CarbonImmutable $day = null, ?CarbonImmutable $now = null): Collection
    {
        $timezone = $user->treatmentTimezone();
        $now = ($now ?? CarbonImmutable::now())->setTimezone($timezone);
        $day = ($day ?? $now)->setTimezone($timezone)->startOfDay();
        $medications = PatientMedication::query()->with('medicine:id,product_name')->where('user_id', $user->id)
            ->where(fn ($query) => $query->whereNull('started_at')->orWhereDate('started_at', '<=', $day->toDateString()))
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhereDate('ends_at', '>=', $day->toDateString()))
            ->orderBy('id')->get();
        $events = MedicationDoseEvent::query()->where('user_id', $user->id)
            ->whereBetween('scheduled_for', [$this->stored($day), $this->stored($day->endOfDay())])->orderBy('scheduled_for')->get();
        $byKey = $events->keyBy(fn (MedicationDoseEvent $event): string => $event->patient_medication_id.'|'.$event->scheduled_for->utc()->toIso8601String());

        $rows = [];
        foreach ($medications as $medication) {
            if ($medication->isDynamic()) {
                foreach ($this->dynamicSlots($medication, $day, $events->where('patient_medication_id', $medication->id)->values()) as [$scheduled, $event]) {
                    $rows[] = $this->row($medication, $scheduled, $event, $now, true);
                }

                continue;
            }
            foreach ($medication->scheduledTimes() as $time) {
                [$hour, $minute] = array_map('intval', explode(':', $time));
                $scheduled = $day->setTime($hour, $minute);
                $rows[] = $this->row($medication, $scheduled, $byKey->get($medication->id.'|'.$scheduled->utc()->toIso8601String()), $now, false);
            }
        }
        usort($rows, fn (array $a, array $b): int => [$a['time'], $a['medication']->displayName()] <=> [$b['time'], $b['medication']->displayName()]);

        return collect($rows);
    }

    /**
     * Doses do dia de um remédio dinâmico: as já marcadas no dia (como aconteceram) e, depois da última marcação até
     * o fim do dia, os horários projetados somando o intervalo. Sem marcação alguma, a cadeia começa no primeiro
     * horário programado do dia de início (ou 08:00).
     *
     * @param  Collection<int, MedicationDoseEvent>  $dayEvents  eventos do remédio no dia, por horário
     * @return list<array{0: CarbonImmutable, 1: MedicationDoseEvent|null}>
     */
    private function dynamicSlots(PatientMedication $medication, CarbonImmutable $day, Collection $dayEvents): array
    {
        $interval = (int) $medication->interval_minutes;
        $timezone = $day->getTimezone();
        $endOfDay = $day->endOfDay();
        $after = fn (MedicationDoseEvent $event): CarbonImmutable => ($event->status === MedicationDoseEvent::STATUS_TAKEN ? $event->recorded_at : $event->scheduled_for)->toImmutable()->setTimezone($timezone)->addMinutes($interval);

        // Ponto de partida do dia: a dose seguinte à última marcação anterior ao dia; sem marcação, a cadeia nasce no
        // primeiro horário programado do dia de início e avança em intervalos até alcançar o dia pedido.
        $previous = MedicationDoseEvent::query()->where('patient_medication_id', $medication->id)->where('scheduled_for', '<', $this->stored($day))->orderByDesc('scheduled_for')->first();
        if ($previous !== null) {
            $cursor = $after($previous);
        } else {
            $start = ($medication->started_at?->toImmutable() ?? $medication->created_at?->toImmutable() ?? $day)->setTimezone($timezone)->startOfDay();
            [$hour, $minute] = array_map('intval', explode(':', $medication->scheduledTimes()[0] ?? '08:00'));
            $cursor = $start->setTime($hour, $minute);
        }
        if ($cursor->lessThan($day)) {
            $cursor = $cursor->addMinutes(intdiv((int) ceil($cursor->diffInMinutes($day, true)), $interval) * $interval);
            while ($cursor->lessThan($day)) {
                $cursor = $cursor->addMinutes($interval);
            }
        }

        // Doses projetadas antes de cada marcação do dia (as esquecidas continuam visíveis), a marcação como aconteceu
        // e, depois dela, a cadeia recontada do horário real.
        $slots = [];
        $limit = intdiv(24 * 60, $interval) + 2;
        foreach ($dayEvents as $event) {
            $scheduled = $event->scheduled_for->toImmutable()->setTimezone($timezone);
            for ($i = 0; $i < $limit && $cursor->lessThan($scheduled->subMinutes(intdiv($interval, 2))); $i++, $cursor = $cursor->addMinutes($interval)) {
                $slots[] = [$cursor, null];
            }
            $slots[] = [$scheduled, $event];
            $cursor = $after($event);
        }
        for ($i = 0; $i < $limit && $cursor->lessThanOrEqualTo($endOfDay); $i++, $cursor = $cursor->addMinutes($interval)) {
            $slots[] = [$cursor, null];
        }

        return $slots;
    }

    /** @return array{medication: PatientMedication, time: string, scheduled_for: CarbonImmutable, status: string, event: MedicationDoseEvent|null, dynamic: bool, taken_at: CarbonImmutable|null} */
    private function row(PatientMedication $medication, CarbonImmutable $scheduled, ?MedicationDoseEvent $event, CarbonImmutable $now, bool $dynamic): array
    {
        $status = match (true) {
            $event?->status === MedicationDoseEvent::STATUS_TAKEN => self::DOSE_TAKEN,
            $event?->status === MedicationDoseEvent::STATUS_SKIPPED => self::DOSE_SKIPPED,
            $now->greaterThanOrEqualTo($scheduled->addMinutes((int) config('bulai.treatment.late_after_minutes'))) => self::DOSE_LATE,
            $now->greaterThanOrEqualTo($scheduled->subMinutes((int) config('bulai.treatment.reminder_lead_minutes'))) => self::DOSE_DUE,
            default => self::DOSE_PENDING,
        };
        $takenAt = $event?->status === MedicationDoseEvent::STATUS_TAKEN ? $event->recorded_at->toImmutable()->setTimezone($scheduled->getTimezone()) : null;

        return ['medication' => $medication, 'time' => $scheduled->format('H:i'), 'scheduled_for' => $scheduled, 'status' => $status, 'event' => $event, 'dynamic' => $dynamic, 'taken_at' => $takenAt];
    }

    /** Próxima dose ainda não marcada (hoje ou amanhã), no fuso do paciente. @return array{medication: PatientMedication, time: string, scheduled_for: CarbonImmutable, status: string, event: MedicationDoseEvent|null}|null */
    public function nextDose(User $user, ?CarbonImmutable $now = null): ?array
    {
        $now = ($now ?? CarbonImmutable::now())->setTimezone($user->treatmentTimezone());
        foreach ([$now, $now->addDay()] as $day) {
            $next = $this->agenda($user, $day, $now)->first(fn (array $row): bool => $row['event'] === null && $row['scheduled_for']->greaterThanOrEqualTo($now->subMinutes((int) config('bulai.treatment.late_after_minutes'))));
            if ($next !== null) {
                return $next;
            }
        }

        return null;
    }

    /**
     * Marca um horário como tomado ou pulado (idempotente: remarcar substitui). `recorded_at` é o horário real da
     * marcação: em remédios dinâmicos, é dele que a próxima dose é contada.
     */
    public function mark(User $user, PatientMedication $medication, CarbonImmutable $scheduledFor, string $status, ?CarbonImmutable $recordedAt = null): MedicationDoseEvent
    {
        abort_unless($medication->user_id === $user->id, 404);
        $status = $status === MedicationDoseEvent::STATUS_SKIPPED ? MedicationDoseEvent::STATUS_SKIPPED : MedicationDoseEvent::STATUS_TAKEN;

        return MedicationDoseEvent::query()->updateOrCreate(
            ['patient_medication_id' => $medication->id, 'scheduled_for' => $this->stored($scheduledFor)],
            ['user_id' => $user->id, 'status' => $status, 'recorded_at' => $this->stored($recordedAt ?? CarbonImmutable::now())],
        );
    }

    /** Desfaz a marcação de um horário. */
    public function unmark(User $user, PatientMedication $medication, CarbonImmutable $scheduledFor): void
    {
        MedicationDoseEvent::query()->where('user_id', $user->id)->where('patient_medication_id', $medication->id)->where('scheduled_for', $this->stored($scheduledFor))->delete();
    }

    /**
     * Instante no fuso do app para gravar ou comparar com colunas `timestamp` (sem fuso): o Eloquent formata o Carbon
     * no fuso da instância e lê a coluna no fuso do app, então gravar em UTC só funcionava com APP_TIMEZONE=UTC.
     */
    private function stored(CarbonImmutable $time): CarbonImmutable
    {
        return $time->setTimezone((string) config('app.timezone', 'UTC'));
    }

    /** Interpreta durações como "7 dias", "por 10 dias", "2 semanas", "1 mês"; null quando contínuo ou ilegível. */
    public static function durationInDays(?string $duration): ?int
    {
        if ($duration === null || preg_match('/(\d+)\s*(dia|dias|semana|semanas|mes|mês|meses)\b/iu', $duration, $m) !== 1) {
            return null;
        }
        $n = (int) $m[1];
        $unit = mb_strtolower($m[2]);

        return match (true) {
            str_starts_with($unit, 'sem') => $n * 7,
            str_starts_with($unit, 'me') => $n * 30,
            default => $n,
        };
    }

    /** Mantém só "HH:MM" válidos, sem repetição, em ordem. @param list<mixed> $times @return list<string> */
    public static function cleanTimes(array $times): array
    {
        $clean = [];
        foreach ($times as $time) {
            if (is_string($time) && preg_match('/^(\d{1,2}):(\d{2})$/', trim($time), $m) === 1 && (int) $m[1] < 24 && (int) $m[2] < 60) {
                $clean[] = sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);
            }
        }
        $clean = array_values(array_unique($clean));
        sort($clean);

        return array_slice($clean, 0, 12);
    }

    private function text(?string $value, int $max): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
