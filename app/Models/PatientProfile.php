<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Perfil de saúde do paciente. Só é preenchido depois do consentimento explícito para tratar dados de saúde.
 * Guarda também o fuso horário (os horários de dose são do paciente, não do servidor) e as preferências de lembrete.
 */
#[Fillable(['user_id', 'health_consent_at', 'timezone', 'reminders_enabled', 'reminders_email'])]
class PatientProfile extends Model
{
    protected function casts(): array
    {
        return ['health_consent_at' => 'datetime', 'reminders_enabled' => 'boolean', 'reminders_email' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
