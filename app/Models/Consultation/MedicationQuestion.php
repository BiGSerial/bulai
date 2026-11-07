<?php

namespace App\Models\Consultation;

use App\Models\Knowledge\AnvisaMedicine;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pergunta livre sobre um medicamento e a resposta dada: evidências, contexto de tratamento usado, métricas de geração
 * e recuperação. Registro analítico/auditável com prazo de retenção; o usuário pode apagar o próprio histórico.
 *
 * `review_status`: perguntas sem resposta mesmo depois de esgotar as fontes (sem bula, sem princípio ativo, bula inteira
 * sem trecho) entram na fila de revisão humana para a equipe adquirir fontes confiáveis.
 */
#[Fillable([
    'uuid', 'user_id', 'medicine_id', 'medicine_name', 'medicines', 'question', 'status', 'answer', 'evidences', 'treatment_context', 'guard',
    'generation', 'retrieval', 'pipeline', 'duration_ms', 'expires_at', 'review_status', 'review_reason', 'reviewed_by', 'reviewed_at',
])]
class MedicationQuestion extends Model
{
    /** Entrada recusada pela triagem (`InputGuard`); nada foi enviado ao modelo. */
    public const STATUS_BLOCKED = 'blocked';

    public const REVIEW_PENDING = 'pending';

    public const REVIEW_RESOLVED = 'resolved';

    public const REVIEW_DISMISSED = 'dismissed';

    /** Situações de resposta que pedem revisão humana (fontes a adquirir). */
    public const REVIEWABLE_STATUSES = ['no_evidence', 'no_leaflet', 'not_identified'];

    public static function needsReview(string $status): bool
    {
        return in_array($status, self::REVIEWABLE_STATUSES, true);
    }

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
            'reviewed_at' => 'datetime',
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

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** @param Builder<self> $query */
    public function scopePendingReview(Builder $query): void
    {
        $query->where('review_status', self::REVIEW_PENDING);
    }
}
