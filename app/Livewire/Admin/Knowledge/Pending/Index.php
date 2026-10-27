<?php

namespace App\Livewire\Admin\Knowledge\Pending;

use App\Jobs\ClassifyPendingDocument;
use App\Jobs\ReanalyzePendingKnowledgeBatch;
use App\Models\Knowledge\IngestionBatchItem;
use App\Services\Knowledge\KnowledgePendingItemReanalysisService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public ?string $feedback = null;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function reanalyzeAll(KnowledgePendingItemReanalysisService $service): void
    {
        $batchIds = IngestionBatchItem::query()->where('status', IngestionBatchItem::STATUS_PENDING)
            ->distinct()->pluck('ingestion_batch_id');
        $queued = 0;
        foreach ($batchIds as $batchId) {
            $queued += $service->markBatchQueued($batchId, auth()->user());
            ReanalyzePendingKnowledgeBatch::dispatch($batchId, auth()->id());
        }
        $this->feedback = $queued === 1 ? '1 item enviado para reanálise.' : "{$queued} itens enviados para reanálise.";
    }

    /** Reanalisa somente este documento (em vez de todos os pendentes). */
    public function reanalyzeItem(KnowledgePendingItemReanalysisService $service, int $itemId): void
    {
        $item = IngestionBatchItem::query()->where('status', IngestionBatchItem::STATUS_PENDING)->find($itemId);
        if ($item === null || ! $service->queueItem($item->id, auth()->user())) {
            $this->feedback = 'Este item já está em análise ou foi resolvido.';

            return;
        }
        ClassifyPendingDocument::dispatch($item->ingestion_batch_id, $item->id, false, auth()->id());
        $this->feedback = 'Documento enviado para reanálise.';
    }

    public function render(): View
    {
        $pendingQuery = IngestionBatchItem::query()
            ->where('status', IngestionBatchItem::STATUS_PENDING)
            ->whereNotNull('metadata->reanalysis_progress->state');
        $activity = [
            'queued' => (clone $pendingQuery)->where('metadata->reanalysis_progress->state', 'queued')->count(),
            'processing' => (clone $pendingQuery)->where('metadata->reanalysis_progress->state', 'processing')->count(),
            'completed' => (clone $pendingQuery)->where('metadata->reanalysis_progress->state', 'completed')->count(),
            'failed' => (clone $pendingQuery)->where('metadata->reanalysis_progress->state', 'failed')->count(),
        ];
        $activity['active'] = $activity['queued'] + $activity['processing'];
        $activity['total'] = $activity['active'] + $activity['completed'] + $activity['failed'];
        $activity['stale'] = (clone $pendingQuery)
            ->whereIn('metadata->reanalysis_progress->state', ['queued', 'processing'])
            ->where('updated_at', '<', now()->subMinutes((int) config('knowledge.processing.progress_stale_minutes', 5)))
            ->count();
        $activity['last_update'] = (clone $pendingQuery)->max('updated_at');

        $items = IngestionBatchItem::query()
            ->where('status', IngestionBatchItem::STATUS_PENDING)
            ->with('ingestionBatch.source')
            ->when($this->search !== '', function (Builder $query): void {
                $search = '%'.$this->search.'%';
                $query->where(function (Builder $query) use ($search): void {
                    $query->whereLike('original_filename', $search)
                        ->orWhereLike('external_key', $search)
                        ->orWhereLike('source_document_id', $search);
                });
            })
            ->orderByDesc('created_at')->orderByDesc('id')->paginate(20);

        return view('livewire.admin.knowledge.pending.index', ['items' => $items, 'activity' => $activity])
            ->title('Pendências · BULAI');
    }
}
