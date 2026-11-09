<?php

namespace App\Livewire\Admin\Analytics\AiUsage;

use App\Services\Analytics\AiConsumptionService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Consumo de IA: cada provedor separado, médias por usuário e ranking de usuários por custo. */
class Index extends Component
{
    use WithPagination;

    #[Url(as: 'periodo', except: '30d')]
    public string $period = '30d';

    #[Url(as: 'provedor', except: '')]
    public string $provider = '';

    public function updated(): void
    {
        if (! array_key_exists($this->period, AiConsumptionService::PERIODS)) {
            $this->period = '30d';
        }
        $this->resetPage();
    }

    public function render(AiConsumptionService $consumption): View
    {
        [$from, $to] = AiConsumptionService::period($this->period);
        $provider = $this->provider !== '' ? $this->provider : null;

        return view('livewire.admin.analytics.ai-usage.index', [
            'providers' => $consumption->byProvider($from, $to, $provider),
            'averages' => $consumption->averages($from, $to, $provider),
            'users' => $consumption->byUser($from, $to, $provider),
            'providerOptions' => $consumption->providers(),
            'periods' => AiConsumptionService::PERIODS,
        ])->title('Consumo de IA · Analytics · BULAI');
    }
}
