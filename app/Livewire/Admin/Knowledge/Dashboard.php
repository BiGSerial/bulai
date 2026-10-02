<?php

namespace App\Livewire\Admin\Knowledge;

use App\Models\Knowledge\IngestionBatch;
use App\Models\Knowledge\IngestionBatchItem;
use App\Models\Knowledge\KnowledgeDocument;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class Dashboard extends Component
{
    public function render(): View
    {
        return view('livewire.admin.knowledge.dashboard', [
            'metrics' => [
                'documents' => KnowledgeDocument::query()->count(),
                'documentsWithFile' => KnowledgeDocument::query()->where(fn ($query) => $query->whereNotNull('storage_key')->orWhereNotNull('storage_path'))->count(),
                'documentsWithoutFile' => KnowledgeDocument::query()->whereNull('storage_key')->whereNull('storage_path')->count(),
                'batches' => IngestionBatch::query()->count(),
                'pending' => IngestionBatchItem::query()->where('status', IngestionBatchItem::STATUS_PENDING)->count(),
                'errors' => IngestionBatchItem::query()->where('status', IngestionBatchItem::STATUS_ERROR)->count(),
            ],
        ])->title('Visão geral · BULAI');
    }
}
