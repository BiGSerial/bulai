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

    /** "atuais": só a versão mais recente de cada remédio; "todas": inclui as substituídas (histórico). */
    #[Url(as: 'versoes', except: 'atuais')]
    public string $versoes = 'atuais';

    public function updatedVersoes(): void
    {
        $this->versoes = $this->versoes === 'todas' ? 'todas' : 'atuais';
        $this->resetPage();
    }

    public function render(AnalyticsService $analytics): View
    {
        return view('livewire.admin.analytics.leaflets.index', [
            'summaries' => $analytics->recentLeaflets($this->status !== '' ? $this->status : null, onlyLatest: $this->versoes !== 'todas'),
            'latestIds' => MedicineLeafletSummary::query()->selectRaw('max(id) as id')->groupBy('ingredient_key')->pluck('id')->map(fn ($id): int => (int) $id)->all(),
            'statuses' => [MedicineLeafletSummary::STATUS_GENERATED, MedicineLeafletSummary::STATUS_PARTIAL, MedicineLeafletSummary::STATUS_INVALID, MedicineLeafletSummary::STATUS_UNAVAILABLE],
        ])->title('Bulas humanizadas · Analytics · BULAI');
    }
}
