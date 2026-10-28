<?php

namespace App\Models\Knowledge;

use App\Models\User;
use Database\Factories\Knowledge\DrugInteractionImportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['dataset_name', 'original_filename', 'sha256', 'status', 'uploaded_by', 'stored_path', 'error_reference', 'rows_read', 'rows_inserted', 'rows_updated', 'rows_skipped', 'rows_rejected', 'metadata', 'started_at', 'completed_at'])]
class DrugInteractionImport extends Model
{
    /** @use HasFactory<DrugInteractionImportFactory> */
    use HasFactory;

    public const ACTIVE_STATUSES = ['QUEUED', 'PROCESSING'];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'started_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    /** Importações em andamento; as sem atualização recente são tratadas como abandonadas. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', self::ACTIVE_STATUSES)->where('updated_at', '>', now()->subHours(2));
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function interactions(): HasMany
    {
        return $this->hasMany(DrugInteraction::class, 'import_id');
    }
}
