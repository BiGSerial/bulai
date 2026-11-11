<?php

namespace App\Models\Knowledge;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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

    /**
     * Nome do medicamento de que a bula trata, para a tela: o produto informado (metadado `product_name`) ou o título antes
     * de " — ". Título genérico do arquivo ("Bula 1793933368611") não é nome: devolve vazio.
     */
    public function medicineName(): string
    {
        $name = trim((string) (((array) $this->metadata)['product_name'] ?? explode(' — ', (string) $this->title)[0]));

        return preg_match('/^(bula[\s_-]*)?\d{6,}(\.pdf)?$/i', $name) === 1 ? '' : $name;
    }

    /** Nome para a tela: o do medicamento ou, sem ele, o título. */
    public function displayName(): string
    {
        return $this->medicineName() !== '' ? $this->medicineName() : (string) $this->title;
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

    /** Linha do tempo da execução corrente do processamento (ver ProcessingTimeline). */
    public function processingEvents(): HasMany
    {
        return $this->hasMany(KnowledgeProcessingEvent::class, 'document_id')->orderBy('started_at')->orderBy('id');
    }

    public function ingredients(): BelongsToMany
    {
        return $this->belongsToMany(ActiveIngredient::class, 'knowledge_document_ingredients', 'document_id', 'ingredient_id')
            ->withPivot(['method', 'confidence'])
            ->withTimestamps();
    }
}
