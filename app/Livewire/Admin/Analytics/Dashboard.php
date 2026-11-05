<?php

namespace App\Livewire\Admin\Analytics;

use App\Services\Analytics\AnalyticsService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Métricas das respostas da IA (consultas, perguntas, bulas humanizadas), ancoragem em evidências, desempenho e custo. */
class Dashboard extends Component
{
    public const PERIODS = [7, 30, 90, 0];

    /** Dias do período (0 = tudo). */
    #[Url(as: 'periodo', except: 30)]
    public int $period = 30;

    public function updatedPeriod(): void
    {
        if (! in_array($this->period, self::PERIODS, true)) {
            $this->period = 30;
        }
    }

    public function render(AnalyticsService $analytics): View
    {
        $from = $this->period > 0 ? CarbonImmutable::now()->subDays($this->period)->startOfDay() : null;

        return view('livewire.admin.analytics.dashboard', [
            'overview' => $analytics->overview($from),
            'periods' => [7 => 'Últimos 7 dias', 30 => 'Últimos 30 dias', 90 => 'Últimos 90 dias', 0 => 'Todo o período'],
        ])->title('Analytics · BULAI');
    }
}
