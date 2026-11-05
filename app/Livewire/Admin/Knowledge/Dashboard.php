<?php

namespace App\Livewire\Admin\Knowledge;

use App\Services\Admin\OverviewService;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/** Visão geral da administração: base, uso do aplicativo, IA, pessoas e atenção. A operação (fila/serviços) é um bloco à parte, com polling próprio. */
class Dashboard extends Component
{
    public function render(OverviewService $overview): View
    {
        return view('livewire.admin.knowledge.dashboard', ['summary' => $overview->summary()])->title('Visão geral · BULAI');
    }
}
