<?php

namespace App\Livewire\Admin\Knowledge\Pending;

use App\Models\Knowledge\IngestionBatchItem;
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

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
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

        return view('livewire.admin.knowledge.pending.index', ['items' => $items])
            ->title('Pendências - BULA-IA');
    }
}
