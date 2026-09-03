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
    'title',
    'document_type',
    'language',
    'original_filename',
    'storage_path',
    'mime_type',
    'file_size',
    'sha256',
    'metadata',
    'content_status',
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
