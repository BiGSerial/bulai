<?php

namespace App\Models;

use App\Models\Knowledge\AnvisaMedicine;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pendência para os administradores: um paciente cadastrou um remédio que o catálogo ANVISA não reconheceu.
 * Resolver = ligar ao medicamento certo do catálogo (o remédio do paciente passa a ter bula e interações);
 * descartar = manter o remédio do paciente apenas pelo nome.
 */
#[Fillable(['user_id', 'patient_medication_id', 'name_as_written', 'normalized_name', 'dosage', 'frequency', 'status', 'resolved_medicine_id', 'resolved_by', 'resolution_note', 'resolved_at'])]
class UnmatchedMedicineReport extends Model
{
    public const STATUS_OPEN = 'OPEN';

    public const STATUS_RESOLVED = 'RESOLVED';

    public const STATUS_DISMISSED = 'DISMISSED';

    protected function casts(): array
    {
        return ['resolved_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function medication(): BelongsTo
    {
        return $this->belongsTo(PatientMedication::class, 'patient_medication_id');
    }

    public function resolvedMedicine(): BelongsTo
    {
        return $this->belongsTo(AnvisaMedicine::class, 'resolved_medicine_id');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN);
    }
}
