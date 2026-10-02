<?php

namespace App\Models\Knowledge;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'ingestion_batch_id',
    'source_id',
    'external_key',
    'source_document_id',
    'title',
    'document_type',
    'language',
    'original_filename',
    'normalized_filename',
    'storage_path',
    'storage_disk',
    'storage_key',
    'mime_type',
    'file_size',
    'sha256',
    'metadata',
    'content_status',
    'content_hash',
    'file_status',
    'embedding_status',
    'processed_at',
])]
class KnowledgeDocument extends Model
{
    public const STATUS_PENDING = 'PENDING';

    public const STATUS_PROCESSING = 'PROCESSING';

    public const STATUS_READY = 'READY';

    public const STATUS_FAILED = 'FAILED';

    /**
     * @return array<string, string>
     */
    public static function documentTypeOptions(): array
    {
        return [
            'bula' => 'Bula',
            'bula_paciente' => 'Bula do paciente',
            'bula_profissional' => 'Bula do profissional',
            'documento_regulatorio' => 'Documento regulatório',
            'outro' => 'Outro',
        ];
    }

    public function documentTypeLabel(): string
    {
        return self::documentTypeOptions()[$this->document_type] ?? (string) $this->document_type;
    }

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(KnowledgeSource::class, 'source_id');
    }

    public function ingestionBatch(): BelongsTo
    {
        return $this->belongsTo(IngestionBatch::class);
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(KnowledgeChunk::class, 'document_id')->orderBy('chunk_index');
    }
}
