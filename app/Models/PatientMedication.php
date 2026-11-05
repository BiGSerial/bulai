<?php

namespace App\Models;

use App\Models\Knowledge\AnvisaMedicine;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Remédio em uso pelo paciente, com posologia, horários programados e vínculo opcional a uma receita.
 *
 * Pode existir SEM correspondência no catálogo ANVISA (`medicine_id` nulo, `catalog_status = unmatched`): o paciente
 * cadastra pelo nome como está na receita, o remédio aparece no tratamento e os administradores recebem a pendência
 * (`UnmatchedMedicineReport`) para ligá-lo ao catálogo depois.
 */
#[Fillable(['user_id', 'medicine_id', 'name_as_written', 'catalog_status', 'prescription_id', 'dosage', 'frequency', 'dose_times', 'as_needed', 'reminders_enabled', 'started_at', 'ends_at', 'notes'])]
class PatientMedication extends Model
{
    public const CATALOG_MATCHED = 'matched';

    public const CATALOG_UNMATCHED = 'unmatched';

    protected function casts(): array
    {
        return ['started_at' => 'date', 'ends_at' => 'date', 'dose_times' => 'array', 'as_needed' => 'boolean', 'reminders_enabled' => 'boolean'];
    }

    public function medicine(): BelongsTo
    {
        return $this->belongsTo(AnvisaMedicine::class, 'medicine_id');
    }

    public function prescription(): BelongsTo
    {
        return $this->belongsTo(PatientPrescription::class, 'prescription_id');
    }

    public function doseEvents(): HasMany
    {
        return $this->hasMany(MedicationDoseEvent::class, 'patient_medication_id');
    }

    public function unmatchedReport(): HasOne
    {
        return $this->hasOne(UnmatchedMedicineReport::class, 'patient_medication_id')->latestOfMany();
    }

    /** Só remédios ligados ao catálogo (os que têm bula e interações para cruzar). */
    public function scopeMatched(Builder $query): Builder
    {
        return $query->whereNotNull('medicine_id');
    }

    public function isMatched(): bool
    {
        return $this->medicine_id !== null;
    }

    /** Nome para o paciente: o do catálogo quando ligado; senão, como foi escrito na receita. */
    public function displayName(): string
    {
        return $this->relationLoaded('medicine') || $this->medicine_id !== null
            ? (string) ($this->medicine?->product_name ?? $this->name_as_written ?? 'Remédio')
            : (string) ($this->name_as_written ?? 'Remédio');
    }

    /** Tratamento em curso: sem data de término ou com término no futuro. */
    public function isActive(): bool
    {
        return $this->ends_at === null || $this->ends_at->endOfDay()->isFuture();
    }

    /** Horários programados ("HH:MM"), ordenados; vazio quando o paciente ainda não definiu ou é "se precisar". */
    public function scheduledTimes(): array
    {
        $times = array_values(array_filter((array) ($this->dose_times ?? []), fn ($time): bool => is_string($time) && preg_match('/^\d{2}:\d{2}$/', $time) === 1));
        sort($times);

        return $this->as_needed ? [] : $times;
    }
}
