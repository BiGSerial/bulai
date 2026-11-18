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
    /** Fusos oferecidos ao paciente (Brasil); qualquer outro valor é recusado e cai no padrão. */
    public const TIMEZONES = [
        'America/Sao_Paulo' => 'Brasília (São Paulo, Rio, Minas, Sul)',
        'America/Manaus' => 'Manaus (Amazonas, Roraima, Rondônia)',
        'America/Belem' => 'Belém (Pará, Amapá)',
        'America/Fortaleza' => 'Fortaleza (Ceará, Maranhão, Piauí)',
        'America/Recife' => 'Recife (Pernambuco, Paraíba)',
        'America/Bahia' => 'Salvador (Bahia, Sergipe, Alagoas)',
        'America/Cuiaba' => 'Cuiabá (Mato Grosso)',
        'America/Campo_Grande' => 'Campo Grande (Mato Grosso do Sul)',
        'America/Rio_Branco' => 'Rio Branco (Acre)',
        'America/Noronha' => 'Fernando de Noronha',
    ];

    protected function casts(): array
    {
        return ['health_consent_at' => 'datetime', 'reminders_enabled' => 'boolean', 'reminders_email' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
