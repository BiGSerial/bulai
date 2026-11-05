<?php

namespace App\Livewire\Admin\Analytics\Consultations;

use App\Models\Consultation\InteractionConsultation;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/** Detalhe auditável de uma consulta: pipeline, cada par com veredito, gravidade, explicação, evidências e métricas. */
class Show extends Component
{
    public InteractionConsultation $consultation;

    public function mount(InteractionConsultation $consultation): void
    {
        $this->consultation = $consultation;
    }

    public function render(): View
    {
        return view('livewire.admin.analytics.consultations.show', [
            'pairs' => $this->consultation->results()->get(),
        ])->title('Consulta '.substr($this->consultation->uuid, 0, 8).' · Analytics · BULAI');
    }
}
