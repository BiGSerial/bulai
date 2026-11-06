<?php

namespace App\Jobs;

use App\Models\Knowledge\KnowledgeDocument;
use App\Services\Knowledge\KnowledgeDocumentIngredientLinker;
use App\Services\Knowledge\KnowledgeDocumentProcessor;
use App\Services\Retrieval\KnowledgeIndexer;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessKnowledgeDocument implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public int $uniqueFor = 600;

    public function __construct(
        public readonly int $documentId,
        public readonly bool $force = false,
    ) {
        $this->timeout = (int) config('knowledge.processing.job_timeout_seconds');
    }

    /**
     * Execute the job.
     */
    public function handle(KnowledgeDocumentProcessor $processor, ?KnowledgeDocumentIngredientLinker $linker = null, ?KnowledgeIndexer $indexer = null): void
    {
        $processor->process($this->documentId, $this->force);

        $document = KnowledgeDocument::query()->find($this->documentId);
        if ($document?->content_status !== KnowledgeDocument::STATUS_READY) {
            return;
        }

        // Sem o vínculo com o princípio ativo o Perguntar não encontra a bula; sem vetores a busca fica só textual.
        try {
            ($linker ?? app(KnowledgeDocumentIngredientLinker::class))->link($document);
            ($indexer ?? app(KnowledgeIndexer::class))->index(documentId: $document->id);
        } catch (Throwable $exception) {
            Log::warning('knowledge_document_post_processing_failed', ['document_id' => $document->id, 'exception' => $exception]);
        }
    }

    public function uniqueId(): string
    {
        return (string) $this->documentId;
    }
}
