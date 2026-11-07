<?php

namespace App\Jobs;

use App\Models\Knowledge\KnowledgeDocument;
use App\Models\Knowledge\KnowledgeProcessingEvent;
use App\Services\Knowledge\KnowledgeDocumentIngredientLinker;
use App\Services\Knowledge\ProcessingTimeline;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Refaz só o vínculo de um documento com os princípios ativos (etapa `link` da linha do tempo), sem reler o PDF.
 * É por esse vínculo que o Perguntar encontra a bula de um remédio; bulas processadas antes do vinculador existir
 * (ou com o catálogo ANVISA importado depois) ficam sem ele.
 */
class LinkKnowledgeDocumentIngredients implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public int $uniqueFor = 300;

    public function __construct(public readonly int $documentId) {}

    public function handle(KnowledgeDocumentIngredientLinker $linker, ProcessingTimeline $timeline): void
    {
        $document = KnowledgeDocument::query()->find($this->documentId);
        if ($document === null || $document->content_status !== KnowledgeDocument::STATUS_READY) {
            return;
        }

        $timeline->resetStep($document->id, KnowledgeProcessingEvent::STEP_LINK);
        $event = $timeline->start($document->id, KnowledgeProcessingEvent::STEP_LINK);
        try {
            $timeline->finish($event, ['ingredients' => $linker->link($document)]);
        } catch (Throwable $exception) {
            $timeline->fail($event, 'LINK_FAILED');
            Log::warning('knowledge_document_link_failed', ['document_id' => $document->id, 'exception' => $exception]);
        }
    }

    public function uniqueId(): string
    {
        return (string) $this->documentId;
    }
}
