<?php

namespace App\Livewire\Consulta;

use App\Models\MedicationDoseEvent;
use App\Models\PatientMedication;
use App\Services\Treatment\TreatmentScheduleService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Route;
use Livewire\Component;

/**
 * Início do app do paciente: saudação com a data, o "cartão do dia" (progresso das doses e a próxima, com "Tomei" em
 * um toque) e as ações principais, uma por linha (sênior-primeiro).
 */
class Inicio extends Component
{
    /** "Tomei" direto do cartão do dia (só a próxima dose, só do próprio paciente). */
    public function tomei(TreatmentScheduleService $schedule, int $medicationId, string $horario): void
    {
        $medication = PatientMedication::query()->where('user_id', auth()->id())->find($medicationId);
        if ($medication === null) {
            return;
        }
        $schedule->mark(auth()->user(), $medication, CarbonImmutable::parse($horario), MedicationDoseEvent::STATUS_TAKEN);
        $this->dispatch('notify', message: 'Anotado: você tomou '.$medication->displayName().'.');
    }

    public function render(TreatmentScheduleService $schedule): View
    {
        $user = auth()->user();
        $consentido = $user->hasHealthConsent();
        $agenda = $consentido ? $schedule->agenda($user) : collect();
        $proxima = $consentido ? $schedule->nextDose($user) : null;
        $proximaTexto = match (true) {
            $proxima === null => 'Veja os horários e marque o que já tomou',
            $proxima['status'] === TreatmentScheduleService::DOSE_LATE => 'Atrasado: '.$proxima['time'].' · '.$proxima['medication']->displayName(),
            $proxima['scheduled_for']->isToday() => 'Próximo: '.$proxima['time'].' · '.$proxima['medication']->displayName(),
            default => 'Amanhã: '.$proxima['time'].' · '.$proxima['medication']->displayName(),
        };
        $hoje = CarbonImmutable::now($user->treatmentTimezone());

        return view('livewire.consulta.inicio', [
            'nome' => trim(explode(' ', trim($user->name))[0] ?? ''),
            'dataHoje' => mb_convert_case($hoje->translatedFormat('l, j \d\e F'), MB_CASE_TITLE, 'UTF-8'),
            'receitaPorFoto' => Route::has('consulta.receita.nova'),
            'consentido' => $consentido,
            'temRemedios' => $consentido && PatientMedication::query()->where('user_id', $user->id)->exists(),
            'proxima' => $proxima,
            'proximaDose' => $proximaTexto,
            'doseAtrasada' => $proxima !== null && $proxima['status'] === TreatmentScheduleService::DOSE_LATE,
            'podeMarcar' => $proxima !== null && in_array($proxima['status'], [TreatmentScheduleService::DOSE_DUE, TreatmentScheduleService::DOSE_LATE], true),
            'total' => $agenda->count(),
            'tomadas' => $agenda->where('status', TreatmentScheduleService::DOSE_TAKEN)->count(),
            'feitas' => $agenda->whereIn('status', [TreatmentScheduleService::DOSE_TAKEN, TreatmentScheduleService::DOSE_SKIPPED])->count(),
            'naoLidos' => $user->unreadNotifications()->count(),
        ]);
    }
}
