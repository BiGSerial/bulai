<?php

namespace App\Jobs;

use App\Models\Knowledge\KnowledgeDocument;
use App\Models\Knowledge\KnowledgeProcessingEvent;
use App\Services\Ai\AiProviderException;
use App\Services\Knowledge\ProcessingTimeline;
use App\Services\Retrieval\KnowledgeIndexer;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Gera (ou refaz) só o índice de busca de um documento: a etapa `embed` da linha do tempo é reiniciada e os trechos
 * sem vetor (ou todos, com `reindex`) são enviados ao provedor de embeddings. Separado do processamento do PDF para
 * que o tempo do OCR e o dos embeddings não disputem o mesmo limite de job, e para que uma falha só no índice possa
 * ser refeita sem reprocessar o documento.
 */
class IndexKnowledgeDocumentEmbeddings implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const ERROR_JOB_FAILED = 'INDEX_JOB_FAILED';

    public int $tries = 1;

    public int $timeout;

    public int $uniqueFor = 900;

    public function __construct(
        public readonly int $documentId,
        public readonly bool $reindex = false,
    ) {
        $this->timeout = (int) config('knowledge.processing.index_job_timeout_seconds');
    }

    public function handle(KnowledgeIndexer $indexer, ProcessingTimeline $timeline): void
    {
        $document = KnowledgeDocument::query()->find($this->documentId);
        if ($document === null || $document->content_status !== KnowledgeDocument::STATUS_READY) {
            return;
        }

        $document->update(['embedding_status' => KnowledgeDocument::STATUS_PROCESSING]);
        $timeline->resetStep($document->id, KnowledgeProcessingEvent::STEP_EMBED);

        try {
            $indexer->index(reindex: $this->reindex, documentId: $document->id);
        } catch (AiProviderException $exception) {
            $document->update(['embedding_status' => KnowledgeDocument::STATUS_FAILED]);
            Log::warning('knowledge_document_indexing_failed', ['document_id' => $document->id, 'error_code' => $exception->errorCode]);
        }
    }

    /** Job morto pelo limite de tempo ou por exceção inesperada: o índice fica marcado como falho, nunca como "gerando" para sempre. */
    public function failed(?Throwable $exception): void
    {
        KnowledgeDocument::query()->whereKey($this->documentId)->where('embedding_status', KnowledgeDocument::STATUS_PROCESSING)
            ->update(['embedding_status' => KnowledgeDocument::STATUS_FAILED]);
        app(ProcessingTimeline::class)->failRunning($this->documentId, self::ERROR_JOB_FAILED);
        Log::warning('knowledge_document_indexing_job_failed', ['document_id' => $this->documentId, 'exception' => $exception]);
    }

    public function uniqueId(): string
    {
        return (string) $this->documentId;
    }
}
