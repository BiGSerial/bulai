<?php

namespace App\Models\Knowledge;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Evidência extraída da bula inteira pela IA (ou pela análise da bula) quando a busca por trechos não respondeu:
 * trecho literal com seção, página, confiança e método, ligada ao documento, à versão dele e ao trecho de origem.
 * Nunca é "uma resposta da IA": é um ponteiro auditável para a bula, revisável pela administração.
 */
#[Fillable([
    'document_id', 'chunk_id', 'medicine_id', 'topic', 'section', 'page_start', 'excerpt', 'summary', 'confidence',
    'extraction_method', 'review_status', 'document_sha256', 'document_version', 'model', 'run_id', 'question', 'extracted_at',
])]
class KnowledgeLeafletFact extends Model
{
    public const METHOD_PDF_FALLBACK_AI = 'pdf_fallback_ai';

    public const REVIEW_PENDING = 'pending';

    public const REVIEW_VERIFIED = 'verified';

    public const REVIEW_REJECTED = 'rejected';

    protected function casts(): array
    {
        return [
            'confidence' => 'float',
            'extracted_at' => 'datetime',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(KnowledgeDocument::class, 'document_id');
    }

    public function chunk(): BelongsTo
    {
        return $this->belongsTo(KnowledgeChunk::class, 'chunk_id');
    }

    public function medicine(): BelongsTo
    {
        return $this->belongsTo(AnvisaMedicine::class, 'medicine_id');
    }

    public function reviewLabel(): string
    {
        return match ($this->review_status) {
            self::REVIEW_VERIFIED => 'Verificada',
            self::REVIEW_REJECTED => 'Rejeitada',
            default => 'Aguardando revisão',
        };
    }
}
