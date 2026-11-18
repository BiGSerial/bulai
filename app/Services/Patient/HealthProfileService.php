<?php

namespace App\Services\Patient;

use App\Models\Consultation\MedicationQuestion;
use App\Models\Knowledge\AnvisaMedicine;
use App\Models\PatientAllergy;
use App\Models\PatientMedication;
use App\Models\PatientPrescription;
use App\Models\PatientProfile;
use App\Models\User;
use App\Models\UserAuditEvent;
use App\Services\Knowledge\IngredientNameNormalizer;
use App\Services\Treatment\DoseScheduleParser;
use App\Services\Treatment\TreatmentScheduleService;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Regras de "Meus remédios" (perfil de saúde) usadas pelo site (Livewire `Perfil` e `EditaPosologia`) e pelo aplicativo
 * nativo (`/api/mobile/v1/perfil`): consentimento, alergias, receitas, remédios e o formulário "Dose e horários".
 * Os erros de validação saem como `ValidationException` com as mesmas frases nas duas telas.
 */
final class HealthProfileService
{
    /** Formulário vazio de "Dose e horários". */
    public const EMPTY_POSOLOGY = ['dosage' => '', 'frequency' => '', 'started_at' => null, 'ends_at' => null, 'prescription_id' => null, 'notes' => '', 'times' => [], 'as_needed' => false, 'reminders_enabled' => true, 'interval_hours' => null, 'schedule_mode' => PatientMedication::MODE_FIXED];

    /** Atalhos de "quando tomar": o mesmo texto que a pessoa escreveria (o `DoseScheduleParser` sugere os horários). */
    public const FREQUENCY_PRESETS = ['1 vez ao dia', '2 vezes ao dia', '3 vezes ao dia', 'De 8 em 8 horas', 'De 12 em 12 horas', 'Só se precisar'];

    /** Atalhos de dose mais comuns; a pessoa pode escrever outra. */
    public const DOSE_PRESETS = ['1 comprimido', '2 comprimidos', '½ comprimido', '1 cápsula', '10 gotas', '5 ml'];

    /** @return array{frequencies: list<string>, doses: list<string>} */
    public static function presets(): array
    {
        return ['frequencies' => self::FREQUENCY_PRESETS, 'doses' => self::DOSE_PRESETS];
    }

    public function __construct(
        private readonly IngredientNameNormalizer $normalizer,
        private readonly DoseScheduleParser $parser,
        private readonly TreatmentScheduleService $schedule,
    ) {}

    public function hasConsent(User $user): bool
    {
        return PatientProfile::query()->where('user_id', $user->id)->whereNotNull('health_consent_at')->exists();
    }

    public function acceptConsent(User $user, ?string $ip = null): void
    {
        PatientProfile::query()->updateOrCreate(['user_id' => $user->id], ['health_consent_at' => now()]);
        $this->audit($user, 'health_consent_given', [], $ip);
    }

    /** Apaga alergias, remédios, receitas e perguntas e revoga o consentimento. Devolve quantos registros saíram. */
    public function deleteHealthData(User $user, ?string $ip = null): int
    {
        $count = PatientAllergy::query()->where('user_id', $user->id)->delete()
            + PatientMedication::query()->where('user_id', $user->id)->delete()
            + PatientPrescription::query()->where('user_id', $user->id)->delete()
            + MedicationQuestion::query()->where('user_id', $user->id)->delete();
        PatientProfile::query()->where('user_id', $user->id)->delete();
        $this->audit($user, 'health_data_deleted', ['records' => $count], $ip);

        return $count;
    }

    /** @throws ValidationException */
    public function addAllergy(User $user, string $substance, ?string $reaction): PatientAllergy
    {
        $substance = trim($substance);
        $normalized = $this->normalizer->normalize($substance);
        if (mb_strlen($substance) < 3 || mb_strlen($substance) > 120 || $normalized === '') {
            throw ValidationException::withMessages(['substance' => 'Informe o nome da substância ou classe (de 3 a 120 caracteres).']);
        }
        $reaction = trim((string) $reaction);

        return PatientAllergy::query()->updateOrCreate(
            ['user_id' => $user->id, 'normalized_substance' => $normalized],
            ['substance' => $substance, 'reaction' => $reaction !== '' ? mb_substr($reaction, 0, 255) : null],
        );
    }

    /**
     * @param  array{prescriber?: string|null, registration?: string|null, issued_at?: string|null, notes?: string|null}  $data
     *
     * @throws ValidationException
     */
    public function addPrescription(User $user, array $data): PatientPrescription
    {
        $valid = Validator::make($data, [
            'prescriber' => ['required', 'string', 'min:3', 'max:120'],
            'registration' => ['nullable', 'string', 'max:40'],
            'issued_at' => ['nullable', 'date', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [], ['prescriber' => 'prescritor', 'registration' => 'registro', 'issued_at' => 'data da receita', 'notes' => 'observações'])->validate();

        return PatientPrescription::query()->create([
            'user_id' => $user->id,
            'prescriber_name' => trim($valid['prescriber']),
            'prescriber_registration' => filled($valid['registration'] ?? null) ? trim($valid['registration']) : null,
            'issued_at' => ($valid['issued_at'] ?? null) ?: null,
            'notes' => filled($valid['notes'] ?? null) ? trim($valid['notes']) : null,
        ]);
    }

    /** Remédio do catálogo na lista do paciente (o mesmo remédio não entra duas vezes). */
    public function addCatalogMedication(User $user, int $medicineId): ?PatientMedication
    {
        if (! AnvisaMedicine::query()->whereKey($medicineId)->exists()) {
            return null;
        }

        return PatientMedication::query()->firstOrCreate(['user_id' => $user->id, 'medicine_id' => $medicineId], ['catalog_status' => PatientMedication::CATALOG_MATCHED]);
    }

    /**
     * O catálogo não tem o remédio: entra pelo nome digitado e abre a pendência para a administração.
     *
     * @throws ValidationException
     */
    public function addMedicationByName(User $user, string $name): PatientMedication
    {
        $name = trim($name);
        if (mb_strlen($name) < 3 || mb_strlen($name) > 120) {
            throw ValidationException::withMessages(['name' => 'Digite o nome do remédio (de 3 a 120 letras) para cadastrar.']);
        }

        return $this->schedule->register($user, ['name_as_written' => $name]);
    }

    /**
     * O formulário "Dose e horários" preenchido com o que está salvo.
     *
     * @return array{dosage: string, frequency: string, started_at: string|null, ends_at: string|null, prescription_id: int|null, notes: string, times: list<string>, as_needed: bool, reminders_enabled: bool, interval_hours: int|null, schedule_mode: string}
     */
    public function posologyOf(PatientMedication $medication): array
    {
        return [
            'dosage' => (string) $medication->dosage,
            'frequency' => (string) $medication->frequency,
            'started_at' => $medication->started_at?->format('Y-m-d'),
            'ends_at' => $medication->ends_at?->format('Y-m-d'),
            'prescription_id' => $medication->prescription_id,
            'notes' => (string) $medication->notes,
            'times' => TreatmentScheduleService::cleanTimes((array) ($medication->dose_times ?? [])),
            'as_needed' => (bool) $medication->as_needed,
            'reminders_enabled' => (bool) $medication->reminders_enabled,
            'interval_hours' => (int) $medication->interval_minutes > 0 ? intdiv((int) $medication->interval_minutes, 60) : null,
            'schedule_mode' => $medication->isDynamic() ? PatientMedication::MODE_INTERVAL : PatientMedication::MODE_FIXED,
        ];
    }

    /**
     * O que muda no formulário quando a pessoa escreve "quando tomar" ("de 8 em 8 horas", "se tiver dor"): horários
     * sugeridos (só se ainda não há horários), "só quando precisar", o intervalo e o modo de contar.
     *
     * @param  array<string, mixed>  $posology
     * @return array<string, mixed>
     */
    public function applyFrequency(array $posology, string $frequency): array
    {
        $parsed = $this->parser->parse($frequency);
        if ($parsed === null) {
            return ['interval_hours' => null, 'schedule_mode' => PatientMedication::MODE_FIXED] + $posology;
        }
        $times = ($posology['times'] ?? []) === [] || $parsed['as_needed'] ? $parsed['times'] : $posology['times'];

        return [
            'as_needed' => $parsed['as_needed'],
            'times' => $times,
            // Frequência por intervalo: contar a partir de quando o paciente toma é o padrão (ele pode trocar para fixos).
            'interval_hours' => $parsed['interval_hours'],
            'schedule_mode' => $parsed['interval_hours'] !== null ? PatientMedication::MODE_INTERVAL : PatientMedication::MODE_FIXED,
        ] + $posology;
    }

    /**
     * Atalho de "quando tomar" escolhido (ou frequência reescrita do zero): troca o texto e refaz os horários sugeridos,
     * em vez de manter os que já estavam.
     *
     * @param  array<string, mixed>  $posology
     * @return array<string, mixed>
     */
    public function choosePreset(array $posology, string $frequency): array
    {
        return $this->applyFrequency(['frequency' => $frequency, 'times' => []] + $posology, $frequency);
    }

    /**
     * Salva "Dose e horários". A receita precisa ser do dono do remédio, mesmo quando quem edita é quem cuida dele.
     *
     * @param  array<string, mixed>  $posology
     * @return array<string, mixed> campos que mudaram (sem `updated_at`)
     *
     * @throws ValidationException
     */
    public function updatePosology(PatientMedication $medication, array $posology): array
    {
        $data = Validator::make(['posologia' => $posology], [
            'posologia.dosage' => ['nullable', 'string', 'max:120'],
            'posologia.frequency' => ['nullable', 'string', 'max:120'],
            'posologia.started_at' => ['nullable', 'date'],
            'posologia.ends_at' => ['nullable', 'date', 'after_or_equal:posologia.started_at'],
            'posologia.prescription_id' => ['nullable', 'integer'],
            'posologia.notes' => ['nullable', 'string', 'max:255'],
            'posologia.times' => ['array', 'max:12'],
            'posologia.times.*' => ['nullable', 'string', 'max:5'],
            'posologia.as_needed' => ['boolean'],
            'posologia.reminders_enabled' => ['boolean'],
            'posologia.interval_hours' => ['nullable', 'integer', 'min:1', 'max:24'],
            'posologia.schedule_mode' => ['required', 'in:'.PatientMedication::MODE_FIXED.','.PatientMedication::MODE_INTERVAL],
        ], ['posologia.ends_at.after_or_equal' => 'A data de término deve ser igual ou posterior ao início.'])->validate()['posologia'];

        $prescriptionId = filled($data['prescription_id'] ?? null) ? (int) $data['prescription_id'] : null;
        if ($prescriptionId !== null && ! PatientPrescription::query()->where('user_id', $medication->user_id)->whereKey($prescriptionId)->exists()) {
            $prescriptionId = null;
        }
        $asNeeded = (bool) ($data['as_needed'] ?? false);
        $intervalMinutes = filled($data['interval_hours'] ?? null) && ! $asNeeded ? (int) $data['interval_hours'] * 60 : null;
        $dynamic = $intervalMinutes !== null && $data['schedule_mode'] === PatientMedication::MODE_INTERVAL;
        $times = $asNeeded ? [] : TreatmentScheduleService::cleanTimes((array) ($data['times'] ?? []));

        $medication->update([
            'interval_minutes' => $intervalMinutes,
            'schedule_mode' => $dynamic ? PatientMedication::MODE_INTERVAL : PatientMedication::MODE_FIXED,
            'dosage' => filled($data['dosage'] ?? null) ? trim($data['dosage']) : null,
            'frequency' => filled($data['frequency'] ?? null) ? trim($data['frequency']) : null,
            'started_at' => ($data['started_at'] ?? null) ?: null,
            'ends_at' => ($data['ends_at'] ?? null) ?: null,
            'prescription_id' => $prescriptionId,
            'notes' => filled($data['notes'] ?? null) ? trim($data['notes']) : null,
            // No modo dinâmico só o primeiro horário importa: é onde a cadeia começa antes da primeira marcação.
            'dose_times' => $dynamic ? array_slice($times, 0, 1) : $times,
            'as_needed' => $asNeeded,
            'reminders_enabled' => (bool) ($data['reminders_enabled'] ?? true),
        ]);
        $changes = $medication->getChanges();
        unset($changes['updated_at']);

        return $changes;
    }

    /** @param array<string, mixed> $details */
    private function audit(User $user, string $event, array $details, ?string $ip): void
    {
        UserAuditEvent::query()->create(['user_id' => $user->id, 'actor_id' => $user->id, 'event' => $event, 'details' => $details, 'ip' => $ip]);
    }
}
