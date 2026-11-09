<?php

namespace App\Livewire\Admin\Analytics\AiUsage;

use App\Models\User;
use App\Services\Analytics\AiConsumptionService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Consumo de IA de um usuário: totais, por modelo, por tela de origem, por dia e cada chamada com o `usage` da API. */
class Show extends Component
{
    use WithPagination;

    public User $user;

    #[Url(as: 'periodo', except: '30d')]
    public string $period = '30d';

    #[Url(as: 'provedor', except: '')]
    public string $provider = '';

    public function mount(User $user): void
    {
        $this->user = $user;
    }

    public function updated(): void
    {
        if (! array_key_exists($this->period, AiConsumptionService::PERIODS)) {
            $this->period = '30d';
        }
        $this->resetPage('chamadas');
    }

    public function render(AiConsumptionService $consumption): View
    {
        [$from, $to] = AiConsumptionService::period($this->period);
        $provider = $this->provider !== '' ? $this->provider : null;

        return view('livewire.admin.analytics.ai-usage.show', [
            'usage' => $consumption->forUser($this->user->id, $from, $to, $provider),
            'calls' => $consumption->callsForUser($this->user->id, $from, $to, $provider),
            'providerOptions' => $consumption->providers(),
            'periods' => AiConsumptionService::PERIODS,
        ])->title('Consumo de IA de '.$this->user->name.' · Analytics · BULAI');
    }
}
