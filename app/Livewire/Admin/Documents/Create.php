<?php

namespace App\Livewire\Admin\Documents;

use App\Models\Knowledge\KnowledgeDocument;
use App\Models\Knowledge\KnowledgeSource;
use App\Services\Knowledge\KnowledgeDocumentLifecycleService;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Create extends Component
{
    public ?int $sourceId = null;

    public string $title = '';

    public string $documentType = 'bula';

    public string $externalKey = '';

    public string $sourceDocumentId = '';

    public function save(KnowledgeDocumentLifecycleService $service): void
    {
        $validated = $this->validate([
            'sourceId' => ['required', 'integer', Rule::exists('knowledge_sources', 'id')->where('active', true)],
            'title' => ['required', 'string', 'max:255'],
            'documentType' => ['required', Rule::in(array_keys(KnowledgeDocument::documentTypeOptions()))],
            'externalKey' => ['nullable', 'string', 'max:255'],
            'sourceDocumentId' => ['nullable', 'string', 'max:255'],
        ]);
        $document = $service->createManual((int) $validated['sourceId'], [
            'title' => $validated['title'], 'document_type' => $validated['documentType'],
            'external_key' => $validated['externalKey'] ?: null, 'source_document_id' => $validated['sourceDocumentId'] ?: null,
        ]);
        $this->redirectRoute('admin.knowledge.documents.show', $document, navigate: true);
    }

    public function render(): View
    {
        return view('livewire.admin.documents.create', ['sources' => KnowledgeSource::query()->active()->orderBy('name')->orderBy('collection')->get()])
            ->title('Criar documento · BULAI');
    }
}
