<?php

namespace App\Livewire\Admin\Analytics\Leaflets;

use App\Models\Consultation\MedicineLeafletSummary;
use App\Services\Analytics\AnalyticsService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Auditoria das bulas humanizadas geradas (cache por conjunto de princípios ativos). */
class Index extends Component
{
    use WithPagination;

    #[Url(as: 'status', except: '')]
    public string $status = '';

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function render(AnalyticsService $analytics): View
    {
        return view('livewire.admin.analytics.leaflets.index', [
            'summaries' => $analytics->recentLeaflets($this->status !== '' ? $this->status : null),
            'statuses' => [MedicineLeafletSummary::STATUS_GENERATED, MedicineLeafletSummary::STATUS_PARTIAL, MedicineLeafletSummary::STATUS_INVALID, MedicineLeafletSummary::STATUS_UNAVAILABLE],
        ])->title('Bulas humanizadas · Analytics · BULAI');
    }
}
