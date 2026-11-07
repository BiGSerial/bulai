<?php

namespace App\Livewire\Admin\Documents;

use App\Jobs\IndexKnowledgeDocumentEmbeddings;
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

    public ?string $reindexFeedback = null;

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

    /** Enfileira só o índice de busca dos documentos prontos que ainda não o têm (aguardando ou com falha); nada do PDF é lido de novo. */
    public function generatePendingSearchIndexes(): void
    {
        $ids = $this->pendingIndexQuery()->orderBy('id')->pluck('id');
        foreach ($ids as $id) {
            IndexKnowledgeDocumentEmbeddings::dispatch((int) $id);
        }
        $this->reindexFeedback = $ids->isEmpty()
            ? 'Nenhum documento pronto está sem índice de busca.'
            : ($ids->count() === 1 ? 'Índice de busca de 1 documento enfileirado.' : "Índice de busca de {$ids->count()} documentos enfileirado.").' Os trechos e os PDFs não mudam; a fila gera um documento por vez. Acompanhe em Fila e serviços.';
    }

    /** Documentos com trechos prontos cujo índice de busca está aguardando ou falhou. @return Builder<KnowledgeDocument> */
    private function pendingIndexQuery(): Builder
    {
        return KnowledgeDocument::query()
            ->where('content_status', KnowledgeDocument::STATUS_READY)
            ->whereNotIn('embedding_status', [KnowledgeDocument::STATUS_READY, KnowledgeDocument::STATUS_PROCESSING]);
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
            'pendingIndexCount' => $this->pendingIndexQuery()->count(),
        ])->title('Documentos · BULAI');
    }
}
