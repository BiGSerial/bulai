<?php

namespace App\Livewire\Consulta;

use App\Models\Consultation\InteractionConsultation;
use App\Services\Interactions\InteractionConsultationService;
use App\Services\Interactions\InteractionResultPresenter;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Livewire\Component;

/** Resultado de uma consulta: um cartão por par de medicamentos, com gravidade, explicação e evidências. */
class Resultado extends Component
{
    public InteractionConsultation $consultation;

    public function mount(InteractionConsultation $consultation): void
    {
        abort_unless($consultation->user_id === auth()->id(), 404);
        $this->consultation = $consultation;
    }

    public function repetir(InteractionConsultationService $service): RedirectResponse
    {
        $new = $service->start(auth()->user(), array_column($this->consultation->medicines, 'id'));

        return redirect()->route('consulta.resultado', $new);
    }

    public function render(InteractionConsultationService $service): View
    {
        $consultation = $service->failStale($this->consultation->refresh());

        $presenter = app(InteractionResultPresenter::class);
        $alertas = $presenter->allergyAlerts(auth()->user(), array_column($consultation->medicines, 'id'));

        return view('livewire.consulta.resultado', [
            'alertas' => $alertas,
            'consultation' => $consultation,
            'presenter' => $presenter,
            'pairs' => $consultation->results()->get(),
            'total' => max(0, count($consultation->medicines) * (count($consultation->medicines) - 1) / 2),
        ]);
    }
}
