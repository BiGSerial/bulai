<?php

namespace App\Livewire\Admin\Documents;

use App\Jobs\IndexKnowledgeDocumentEmbeddings;
use App\Jobs\ProcessKnowledgeDocument;
use App\Models\Knowledge\KnowledgeDocument;
use App\Models\Knowledge\KnowledgeProcessingEvent;
use App\Services\Knowledge\ProcessingTimeline;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithPagination;

class Show extends Component
{
    use WithPagination;

    public KnowledgeDocument $document;

    public ?string $processingFeedback = null;

    public function mount(KnowledgeDocument $document): void
    {
        $this->document = $document;
    }

    public function processDocument(bool $force = false): void
    {
        ProcessKnowledgeDocument::dispatch($this->document->id, $force);
        $this->processingFeedback = $force ? 'Reprocessamento enfileirado.' : 'Processamento enfileirado.';
        $this->document->refresh();
    }

    /** Refaz só o índice de busca (embeddings): os trechos ficam como estão, nada do PDF é lido de novo. */
    public function rebuildSearchIndex(): void
    {
        if ($this->document->content_status !== KnowledgeDocument::STATUS_READY) {
            $this->processingFeedback = 'O índice de busca só pode ser gerado depois que o texto e os trechos estiverem prontos.';

            return;
        }

        IndexKnowledgeDocumentEmbeddings::dispatch($this->document->id);
        $this->processingFeedback = 'Geração do índice de busca enfileirada. Só os trechos sem vetor são enviados ao serviço de embeddings; os trechos e o PDF não mudam.';
        $this->document->refresh();
    }

    public function render(ProcessingTimeline $timeline): View
    {
        $this->document->load(['source', 'ingestionBatch', 'ingredients'])->loadCount('chunks');
        $events = $timeline->for($this->document->id);

        return view('livewire.admin.documents.show', [
            'chunks' => $this->document->chunks()->paginate(10),
            'processing' => $this->document->metadata['processing'] ?? [],
            'timeline' => $events,
            // Etapa em andamento: a página se atualiza sozinha (wire:poll + evento em tempo real) até concluir.
            'timelineActive' => $events->first(fn (KnowledgeProcessingEvent $event): bool => $event->isRunning()),
            // Última falha do índice de busca: explica o código e oferece refazer só essa etapa.
            'embeddingFailure' => $this->document->embedding_status === KnowledgeDocument::STATUS_FAILED
                ? $events->last(fn (KnowledgeProcessingEvent $event): bool => $event->step === KnowledgeProcessingEvent::STEP_EMBED && $event->state === KnowledgeProcessingEvent::STATE_FAILED)
                : null,
        ])->title($this->document->title.' · BULAI');
    }
}
