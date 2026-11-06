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

    /** Colunas ordenáveis (lista branca): rótulo da URL => expressão da consulta. */
    private const SORTABLE = ['title' => 'title', 'type' => 'document_type', 'trechos' => 'chunks_count', 'updated' => 'updated_at', 'created' => 'created_at'];

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'file', except: '')]
    public string $fileStatus = '';

    #[Url(except: 'updated')]
    public string $sort = 'updated';

    #[Url(as: 'dir', except: 'desc')]
    public string $direction = 'desc';

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

    /** Ordena pela coluna; clicar de novo inverte o sentido. */
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
        $column = self::SORTABLE[$this->sort] ?? 'updated_at';
        $direction = $this->direction === 'asc' ? 'asc' : 'desc';
        $documents = KnowledgeDocument::query()
            ->with('source')
            ->withCount('chunks')
            ->when($this->search !== '', function (Builder $query): void {
                $search = '%'.$this->search.'%';

                $query->where(function (Builder $query) use ($search): void {
                    $query
                        ->whereLike('title', $search)
                        ->orWhereLike('external_key', $search)
                        ->orWhereLike('source_document_id', $search)
                        ->orWhereLike('normalized_filename', $search)
                        ->orWhereLike('document_type', $search);
                });
            })
            ->when($this->fileStatus !== '', function (Builder $query): void {
                $query->where('file_status', $this->fileStatus);
            })
            ->orderBy($column, $direction)
            ->orderByDesc('id')
            ->paginate(25);

        return view('livewire.admin.documents.index', [
            'documents' => $documents,
            'fileStatuses' => ['PENDING', 'PROCESSING', 'READY', 'FAILED'],
        ])->title('Documentos · BULAI');
    }
}
