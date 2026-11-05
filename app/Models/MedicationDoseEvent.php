<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** O que o paciente marcou em um horário programado: tomou ou pulou. `scheduled_for` é guardado em UTC. */
#[Fillable(['user_id', 'patient_medication_id', 'scheduled_for', 'status', 'recorded_at'])]
class MedicationDoseEvent extends Model
{
    public const STATUS_TAKEN = 'TAKEN';

    public const STATUS_SKIPPED = 'SKIPPED';

    protected function casts(): array
    {
        return ['scheduled_for' => 'datetime', 'recorded_at' => 'datetime'];
    }

    public function medication(): BelongsTo
    {
        return $this->belongsTo(PatientMedication::class, 'patient_medication_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
