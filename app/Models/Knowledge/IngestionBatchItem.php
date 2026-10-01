<?php

namespace App\Models\Knowledge;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'ingestion_batch_id',
    'knowledge_document_id',
    'external_key',
    'source_document_id',
    'original_filename',
    'normalized_filename',
    'sha256',
    'mime_type',
    'file_size',
    'storage_path',
    'storage_disk',
    'storage_key',
    'status',
    'resolution_method',
    'reason',
    'metadata',
])]
class IngestionBatchItem extends Model
{
    public const STATUS_ASSOCIATED = 'ASSOCIATED';

    public const STATUS_DUPLICATE = 'DUPLICATE';

    public const STATUS_PENDING = 'PENDING';

    public const STATUS_ERROR = 'ERROR';

    public const METHOD_EXTERNAL_KEY = 'external_key';

    public const METHOD_SHA256 = 'sha256_known';

    public const METHOD_SOURCE_DOCUMENT_ID = 'source_document_id';

    public const METHOD_NORMALIZED_FILENAME = 'normalized_filename';

    public const METHOD_MANUAL = 'manual';

    public const METHOD_AUTOMATIC_CREATION = 'automatic_creation';

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function ingestionBatch(): BelongsTo
    {
        return $this->belongsTo(IngestionBatch::class);
    }

    public function knowledgeDocument(): BelongsTo
    {
        return $this->belongsTo(KnowledgeDocument::class);
    }
}
