<?php

namespace App\Livewire\Admin\Knowledge\Sources;

use App\Models\Knowledge\KnowledgeSource;
use App\Services\Knowledge\KnowledgeSourceService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Show extends Component
{
    #[Locked]
    public int $sourceId;

    public function mount(KnowledgeSource $source): void
    {
        $this->sourceId = $source->id;
    }

    public function toggleActive(KnowledgeSourceService $service): void
    {
        $source = KnowledgeSource::query()->findOrFail($this->sourceId);
        $service->setActive($source, ! $source->active);
    }

    public function render(): View
    {
        $source = KnowledgeSource::query()->withCount(['documents', 'batches'])->findOrFail($this->sourceId);

        return view('livewire.admin.knowledge.sources.show', [
            'source' => $source,
            'recentDocuments' => $source->documents()->latest()->limit(10)->get(['id', 'source_id', 'title', 'external_key', 'created_at']),
            'typeLabel' => KnowledgeSource::typeOptions()[$source->type] ?? 'Legada',
        ])->title($source->displayName().' - BULAI');
    }
}
