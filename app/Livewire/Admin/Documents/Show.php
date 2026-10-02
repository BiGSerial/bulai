<?php

namespace App\Livewire\Admin\Documents;

use App\Jobs\ProcessKnowledgeDocument;
use App\Models\Knowledge\KnowledgeDocument;
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

    public function render(): View
    {
        $this->document->load(['source', 'ingestionBatch'])->loadCount('chunks');

        return view('livewire.admin.documents.show', [
            'chunks' => $this->document->chunks()->paginate(10),
            'processing' => $this->document->metadata['processing'] ?? [],
        ])->title($this->document->title.' · BULAI');
    }
}
