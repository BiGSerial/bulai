<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Receita médica registrada pelo paciente: quem prescreveu, quando e observações. Os medicamentos apontam para ela. */
#[Fillable(['user_id', 'source', 'status', 'prescriber_name', 'prescriber_registration', 'issued_at', 'notes', 'extraction', 'error_code', 'confirmed_at'])]
class PatientPrescription extends Model
{
    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_PHOTO = 'photo';

    public const STATUS_PENDING = 'PENDING';

    public const STATUS_PROCESSING = 'PROCESSING';

    public const STATUS_REVIEW = 'REVIEW';

    public const STATUS_CONFIRMED = 'CONFIRMED';

    public const STATUS_FAILED = 'FAILED';

    protected function casts(): array
    {
        return ['issued_at' => 'date', 'extraction' => 'array', 'confirmed_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function medications(): HasMany
    {
        return $this->hasMany(PatientMedication::class, 'prescription_id');
    }

    /**
     * Nome de quem prescreveu para leitura: a foto da receita costuma vir toda em maiúsculas ("EVELYN CALHAU DE OLIVEIRA"),
     * que vira "Evelyn Calhau de Oliveira". Um nome digitado com maiúsculas e minúsculas fica como está.
     */
    public function prescriberDisplayName(): string
    {
        $name = trim((string) $this->prescriber_name);
        if ($name === '' || $name !== mb_strtoupper($name)) {
            return $name;
        }

        return (string) preg_replace_callback('/(?<=\s)(De|Da|Do|Dos|Das|E)(?=\s)/u', fn (array $match): string => mb_strtolower($match[1]), mb_convert_case(mb_strtolower($name), MB_CASE_TITLE));
    }
}
