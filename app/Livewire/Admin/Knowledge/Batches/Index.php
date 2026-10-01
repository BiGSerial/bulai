<?php

namespace App\Livewire\Admin\Knowledge\Batches;

use App\Models\Knowledge\IngestionBatch;
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
        $batches = IngestionBatch::query()
            ->with('source')
            ->when($this->search !== '', function (Builder $query): void {
                $search = '%'.$this->search.'%';
                $query->where(function (Builder $query) use ($search): void {
                    $query->whereLike('name', $search)
                        ->orWhereLike('description', $search)
                        ->orWhereHas('source', fn (Builder $source): Builder => $source->whereLike('name', $search));
                });
            })
            ->orderByDesc('created_at')->orderByDesc('id')->paginate(20);

        return view('livewire.admin.knowledge.batches.index', ['batches' => $batches])
            ->title('Importações - BULA-IA');
    }
}
