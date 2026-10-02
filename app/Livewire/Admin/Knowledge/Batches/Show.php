<?php

namespace App\Livewire\Admin\Knowledge\Batches;

use App\Jobs\ReanalyzePendingKnowledgeBatch;
use App\Models\Knowledge\IngestionBatch;
use App\Services\Knowledge\KnowledgePendingItemReanalysisService;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithPagination;

class Show extends Component
{
    use WithPagination;

    public IngestionBatch $batch;

    public function mount(IngestionBatch $batch): void
    {
        $this->batch = $batch;
    }

    public function reanalyzePending(KnowledgePendingItemReanalysisService $service): void
    {
        if ($this->batch->pending_items < 1) {
            return;
        }
        $service->markBatchQueued($this->batch->id, auth()->user());
        ReanalyzePendingKnowledgeBatch::dispatch($this->batch->id, auth()->id());
        session()->flash('batch-feedback', 'A reanálise das pendências foi enviada para processamento.');
    }

    public function render(): View
    {
        $this->batch->load('source');

        $items = $this->batch->items()
            ->with('knowledgeDocument')
            ->orderByDesc('created_at')->orderByDesc('id')->paginate(20);

        return view('livewire.admin.knowledge.batches.show', ['items' => $items])
            ->title("Importação #{$this->batch->id} · BULAI");
    }
}
