<?php

namespace App\Livewire\Admin\Knowledge\Sources;

use App\Models\Knowledge\KnowledgeSource;
use App\Services\Knowledge\KnowledgeSourceService;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    /** Colunas ordenáveis (lista branca). */
    private const SORTABLE = ['name' => 'name', 'documents' => 'documents_count', 'batches' => 'batches_count', 'country' => 'country'];

    public string $search = '';

    public string $status = 'all';

    public string $sort = 'name';

    public string $direction = 'asc';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'status']);
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

    public function toggleActive(int $sourceId, KnowledgeSourceService $service): void
    {
        $source = KnowledgeSource::query()->findOrFail($sourceId);
        $service->setActive($source, ! $source->active);
    }

    public function render(): View
    {
        $search = trim($this->search);

        return view('livewire.admin.knowledge.sources.index', [
            'sources' => KnowledgeSource::query()
                ->withCount(['documents', 'batches'])
                ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search): void {
                    $query->where('name', 'ilike', "%{$search}%")
                        ->orWhere('collection', 'ilike', "%{$search}%")
                        ->orWhere('organization', 'ilike', "%{$search}%")
                        ->orWhere('country', 'ilike', "%{$search}%");
                }))
                ->when($this->status === 'active', fn ($query) => $query->where('active', true))
                ->when($this->status === 'inactive', fn ($query) => $query->where('active', false))
                ->orderBy(self::SORTABLE[$this->sort] ?? 'name', $this->direction === 'desc' ? 'desc' : 'asc')
                ->orderBy('collection')
                ->orderBy('id')
                ->paginate(25),
        ])->title('Origens · BULAI');
    }
}
