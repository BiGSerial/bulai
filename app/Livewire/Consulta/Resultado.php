<?php

namespace App\Livewire\Consulta;

use App\Models\Consultation\InteractionConsultation;
use App\Models\Knowledge\AnvisaMedicine;
use App\Services\Interactions\InteractionConsultationService;
use App\Services\Patient\AllergyChecker;
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

        $alertas = [];
        $user = auth()->user();
        if ($user->hasHealthConsent() && $user->allergies()->exists()) {
            $allergies = $user->allergies()->get();
            foreach (AnvisaMedicine::query()->whereIn('id', array_column($consultation->medicines, 'id'))->get() as $medicine) {
                foreach (app(AllergyChecker::class)->check($allergies, $medicine) as $match) {
                    $alertas[] = ['medicine' => $medicine->product_name] + $match;
                }
            }
        }

        return view('livewire.consulta.resultado', [
            'alertas' => $alertas,
            'consultation' => $consultation,
            'pairs' => $consultation->results()->get(),
            'total' => max(0, count($consultation->medicines) * (count($consultation->medicines) - 1) / 2),
        ]);
    }
}
