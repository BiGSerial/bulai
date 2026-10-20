<?php

namespace App\Models\Knowledge;

use App\Models\User;
use Database\Factories\Knowledge\AnvisaCatalogImportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['source_id', 'dataset_name', 'original_filename', 'sha256', 'encoding', 'delimiter', 'status', 'rows_read', 'rows_inserted', 'rows_updated', 'rows_skipped', 'rows_rejected', 'metadata', 'uploaded_by', 'stored_path', 'error_reference', 'started_at', 'completed_at'])]
class AnvisaCatalogImport extends Model
{
    /** @use HasFactory<AnvisaCatalogImportFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['metadata' => 'array', 'started_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public const ACTIVE_STATUSES = ['QUEUED', 'PROCESSING'];

    /** Importações em andamento; as sem atualização recente são tratadas como abandonadas. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', self::ACTIVE_STATUSES)->where('updated_at', '>', now()->subHours(2));
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(KnowledgeSource::class);
    }

    public function medicines(): HasMany
    {
        return $this->hasMany(AnvisaMedicine::class, 'catalog_import_id');
    }
}
