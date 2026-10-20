<?php

namespace App\Livewire\Admin\Knowledge;

use App\Services\Health\ServiceHealthChecker;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/** Indicador visível da saúde da IA local e do OCR; atualizado por polling (ver docs/realtime-options.md). */
class ServiceHealth extends Component
{
    public function render(ServiceHealthChecker $checker): View
    {
        return view('livewire.shared.service-health', ['services' => $checker->all()]);
    }
}
