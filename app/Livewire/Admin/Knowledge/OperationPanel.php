<?php

namespace App\Livewire\Admin\Knowledge;

use App\Services\Health\ServiceHealthChecker;
use App\Services\Queue\QueueStatusService;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/** Bloco "Operação" da Visão geral: processador da fila, jobs e serviços, atualizado sozinho (polling + evento em tempo real). */
class OperationPanel extends Component
{
    public function render(QueueStatusService $queue, ServiceHealthChecker $checker): View
    {
        $snapshot = $queue->snapshot();

        return view('livewire.shared.operation-panel', [
            'worker' => $snapshot['worker'],
            'jobs' => $snapshot['jobs'],
            'inProgress' => count($snapshot['items']),
            'stale' => $snapshot['stale'],
            'services' => $checker->all(),
            'textProvider' => config('ai.text.default') ?: null,
            'visionProvider' => config('ai.vision.default') ?: null,
        ]);
    }
}
