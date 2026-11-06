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

    /** Colunas ordenáveis (lista branca). */
    private const SORTABLE = ['name' => 'name', 'files' => 'total_items', 'pending' => 'pending_items', 'errors' => 'error_items', 'created' => 'created_at'];

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: 'all')]
    public string $situation = 'all';

    #[Url(except: 'created')]
    public string $sort = 'created';

    #[Url(as: 'dir', except: 'desc')]
    public string $direction = 'desc';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedSituation(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'situation']);
        $this->resetPage();
    }

    public function sortBy(string $field): void
    {
        if (! isset(self::SORTABLE[$field])) {
            return;
        }
        $this->direction = $this->sort === $field && $this->direction === 'asc' ? 'desc' : 'asc';
        $this->sort = $field;
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
            ->when($this->situation === 'pending', fn (Builder $query) => $query->where('pending_items', '>', 0))
            ->when($this->situation === 'errors', fn (Builder $query) => $query->where('error_items', '>', 0))
            ->when($this->situation === 'running', fn (Builder $query) => $query->where('status', '!=', 'COMPLETED'))
            ->orderBy(self::SORTABLE[$this->sort] ?? 'created_at', $this->direction === 'asc' ? 'asc' : 'desc')
            ->orderByDesc('id')->paginate(25);

        return view('livewire.admin.knowledge.batches.index', ['batches' => $batches])
            ->title('Importações · BULAI');
    }
}
