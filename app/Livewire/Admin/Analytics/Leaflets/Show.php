<?php

namespace App\Livewire\Admin\Analytics\Leaflets;

use App\Models\Consultation\MedicineLeafletSummary;
use App\Models\Knowledge\ActiveIngredient;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Detalhe auditável de uma bula humanizada: seções com afirmações, evidências que as sustentam e métricas. */
class Show extends Component
{
    /** Aba visível. */
    #[Url(as: 'aba', except: 'secoes')]
    public string $tab = 'secoes';

    public function updatedTab(): void
    {
        if (! in_array($this->tab, ['secoes', 'geracao', 'evidencias'], true)) {
            $this->tab = 'secoes';
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
        $ingredientIds = array_filter(array_map('intval', explode('-', $this->summary->ingredient_key)));

        return view('livewire.admin.analytics.leaflets.show', [
            'ingredients' => ActiveIngredient::query()->whereIn('id', $ingredientIds)->orderBy('canonical_name')->pluck('canonical_name')->all(),
            'sectionLabels' => collect(config('bulai.leaflet.sections'))->map(fn (array $section): string => $section['label'])->all(),
        ])->title('Bula humanizada '.substr($this->summary->uuid, 0, 8).' · Analytics · BULAI');
    }
}
