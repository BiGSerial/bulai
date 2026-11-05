<?php

namespace App\Models\Consultation;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Consulta de interações de um usuário: guarda só a lista de medicamentos e o resultado, com prazo de retenção. */
#[Fillable(['uuid', 'user_id', 'status', 'medicines', 'pipeline', 'run_id', 'error_code', 'started_at', 'completed_at', 'duration_ms', 'expires_at'])]
class InteractionConsultation extends Model
{
    public const PENDING = 'PENDING';

    public const RUNNING = 'RUNNING';

    public const COMPLETED = 'COMPLETED';

    public const FAILED = 'FAILED';

    protected function casts(): array
    {
        return [
            'medicines' => 'array',
            'pipeline' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
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

    public function results(): HasMany
    {
        return $this->hasMany(InteractionPairResult::class, 'consultation_id')->orderBy('position');
    }

    /** Acrescenta `pair_count`: quantas combinações de medicamentos já têm resultado. */
    public function scopeWithPairCount(Builder $query): Builder
    {
        return $query->withCount('results as pair_count');
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [self::COMPLETED, self::FAILED], true);
    }
}
