<?php

namespace App\Models\Consultation;

use App\Models\Knowledge\AnvisaMedicine;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pergunta livre sobre um medicamento e a resposta dada: evidências, contexto de tratamento usado, métricas de geração
 * e recuperação. Registro analítico/auditável com prazo de retenção; o usuário pode apagar o próprio histórico.
 */
#[Fillable([
    'uuid', 'user_id', 'medicine_id', 'medicine_name', 'medicines', 'question', 'status', 'answer', 'evidences', 'treatment_context', 'guard',
    'generation', 'retrieval', 'pipeline', 'duration_ms', 'expires_at',
])]
class MedicationQuestion extends Model
{
    /** Entrada recusada pela triagem (`InputGuard`); nada foi enviado ao modelo. */
    public const STATUS_BLOCKED = 'blocked';

    protected function casts(): array
    {
        return [
            'evidences' => 'array',
            'treatment_context' => 'array',
            'medicines' => 'array',
            'guard' => 'array',
            'generation' => 'array',
            'retrieval' => 'array',
            'pipeline' => 'array',
            'expires_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function medicine(): BelongsTo
    {
        return $this->belongsTo(AnvisaMedicine::class, 'medicine_id');
    }
}
