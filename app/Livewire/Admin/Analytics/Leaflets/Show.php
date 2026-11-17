<?php

namespace App\Livewire\Admin\Analytics\Leaflets;

use App\Models\Consultation\MedicineLeafletSummary;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Detalhe auditável de uma bula humanizada: o texto como o paciente lê, com a legibilidade (Flesch), seções com afirmações, evidências e métricas. */
class Show extends Component
{
    /** Aba visível. */
    #[Url(as: 'aba', except: 'texto')]
    public string $tab = 'texto';

    public function updatedTab(): void
    {
        if (! in_array($this->tab, ['texto', 'secoes', 'geracao', 'evidencias'], true)) {
            $this->tab = 'texto';
        }
    }

    public MedicineLeafletSummary $summary;

    public function mount(MedicineLeafletSummary $summary): void
    {
        $this->updatedTab();
        $this->summary = $summary;
    }

    public function render(): View
    {
        return view('livewire.admin.analytics.leaflets.show', [
            'sectionLabels' => collect(config('bulai.leaflet.sections'))->map(fn (array $section): string => $section['label'])->all(),
        ])->title('Bula humanizada '.substr($this->summary->uuid, 0, 8).' · Analytics · BULAI');
    }
}
