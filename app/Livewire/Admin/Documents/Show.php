<?php

namespace App\Livewire\Admin\Documents;

use App\Models\Knowledge\KnowledgeDocument;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class Show extends Component
{
    public KnowledgeDocument $document;

    public function mount(KnowledgeDocument $document): void
    {
        $this->document = $document;
    }

    public function render(): View
    {
        $this->document->load(['source', 'ingestionBatch', 'chunks']);

        return view('livewire.admin.documents.show')->title($this->document->title.' - BULA-IA');
    }
}
