<?php

namespace App\Livewire\Admin\Documents;

use App\Models\Knowledge\KnowledgeDocument;
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

    #[Url(as: 'file', except: '')]
    public string $fileStatus = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedFileStatus(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'fileStatus']);
        $this->resetPage();
    }

    public function render(): View
    {
        $documents = KnowledgeDocument::query()
            ->with('source')
            ->withCount('chunks')
            ->when($this->search !== '', function (Builder $query): void {
                $search = '%'.$this->search.'%';

                $query->where(function (Builder $query) use ($search): void {
                    $query
                        ->whereLike('title', $search)
                        ->orWhereLike('external_key', $search)
                        ->orWhereLike('document_type', $search);
                });
            })
            ->when($this->fileStatus !== '', function (Builder $query): void {
                $query->where('file_status', $this->fileStatus);
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(20);

        return view('livewire.admin.documents.index', [
            'documents' => $documents,
            'fileStatuses' => ['PENDING', 'PROCESSING', 'READY', 'FAILED'],
        ])->title('Documentos - BULA-IA');
    }
}
