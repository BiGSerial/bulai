<?php

namespace App\Livewire\Consulta;

use App\Models\Consultation\InteractionConsultation;
use App\Models\Consultation\MedicationQuestion;
use App\Models\UserAuditEvent;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithPagination;

/** Histórico do próprio usuário (consultas de interação e perguntas), com exclusão individual ou total e aviso de retenção. */
class Historico extends Component
{
    use WithPagination;

    /** Segmento visível: perguntas | consultas (perguntas primeiro: é o uso mais frequente). */
    public string $aba = 'perguntas';

    public function mostrar(string $aba): void
    {
        $this->aba = in_array($aba, ['perguntas', 'consultas'], true) ? $aba : 'perguntas';
    }

    public function apagar(string $uuid): void
    {
        $deleted = InteractionConsultation::query()->where('user_id', auth()->id())->where('uuid', $uuid)->delete();
        $this->registrar('consultations_deleted', $deleted);
    }

    public function apagarPergunta(string $uuid): void
    {
        $deleted = MedicationQuestion::query()->where('user_id', auth()->id())->where('uuid', $uuid)->delete();
        $this->registrar('questions_deleted', $deleted);
    }

    public function apagarTudo(): void
    {
        $this->registrar('consultations_deleted', InteractionConsultation::query()->where('user_id', auth()->id())->delete());
        $this->registrar('questions_deleted', MedicationQuestion::query()->where('user_id', auth()->id())->delete());
        $this->resetPage();
        $this->resetPage('perguntas');
    }

    private function registrar(string $event, int $count): void
    {
        if ($count > 0) {
            UserAuditEvent::query()->create(['user_id' => auth()->id(), 'actor_id' => auth()->id(), 'event' => $event, 'details' => ['count' => $count], 'ip' => request()->ip()]);
        }
    }

    public function render(): View
    {
        return view('livewire.consulta.historico', [
            'consultations' => InteractionConsultation::query()->where('user_id', auth()->id())->withPairCount()->latest()->paginate(10),
            'questions' => MedicationQuestion::query()->where('user_id', auth()->id())->latest()->paginate(10, pageName: 'perguntas'),
            'retentionDays' => (int) config('bulai.consultation.retention_days'),
            'questionRetentionDays' => (int) config('bulai.questions.retention_days'),
        ]);
    }
}
