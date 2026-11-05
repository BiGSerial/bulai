<?php

namespace App\Livewire\Consulta;

use App\Services\Treatment\TreatmentScheduleService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Route;
use Livewire\Component;

/** Início do app do paciente: saudação e as ações principais, uma por linha (sênior-primeiro). */
class Inicio extends Component
{
    public function render(TreatmentScheduleService $schedule): View
    {
        $user = auth()->user();
        $proxima = $user->hasHealthConsent() ? $schedule->nextDose($user) : null;
        $proximaTexto = match (true) {
            $proxima === null => 'Veja os horários e marque o que já tomou',
            $proxima['status'] === TreatmentScheduleService::DOSE_LATE => 'Atrasado: '.$proxima['time'].' · '.$proxima['medication']->displayName(),
            $proxima['scheduled_for']->isToday() => 'Próximo: '.$proxima['time'].' · '.$proxima['medication']->displayName(),
            default => 'Amanhã: '.$proxima['time'].' · '.$proxima['medication']->displayName(),
        };

        return view('livewire.consulta.inicio', [
            'nome' => trim(explode(' ', trim($user->name))[0] ?? ''),
            'receitaPorFoto' => Route::has('consulta.receita.nova'),
            'proximaDose' => $proximaTexto,
            'doseAtrasada' => $proxima !== null && $proxima['status'] === TreatmentScheduleService::DOSE_LATE,
        ]);
    }
}
