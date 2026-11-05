<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Relatório gerado pela administração: tipo, formato, parâmetros, estado e arquivo no disco `reports`. */
#[Fillable(['uuid', 'type', 'format', 'status', 'parameters', 'path', 'size', 'rows', 'generated_by', 'error_code', 'completed_at'])]
class AdminReport extends Model
{
    public const STATUS_PENDING = 'PENDING';

    public const STATUS_PROCESSING = 'PROCESSING';

    public const STATUS_COMPLETED = 'COMPLETED';

    public const STATUS_FAILED = 'FAILED';

    protected function casts(): array
    {
        return ['parameters' => 'array', 'size' => 'integer', 'rows' => 'integer', 'completed_at' => 'datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function generator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_FAILED], true);
    }

    public function fileName(): string
    {
        return $this->type.'-'.$this->created_at?->format('Ymd-His').'.'.$this->format;
    }
}
