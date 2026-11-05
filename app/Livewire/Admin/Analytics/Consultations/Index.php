<?php

namespace App\Livewire\Admin\Analytics\Consultations;

use App\Models\Consultation\InteractionConsultation;
use App\Services\Analytics\AnalyticsService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Auditoria das consultas de interação: lista anonimizada (id numérico do usuário, nunca nome/e-mail). */
class Index extends Component
{
    use WithPagination;

    #[Url(as: 'status', except: '')]
    public string $status = '';

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function render(AnalyticsService $analytics): View
    {
        return view('livewire.admin.analytics.consultations.index', [
            'consultations' => $analytics->recentConsultations($this->status !== '' ? $this->status : null),
            'statuses' => [InteractionConsultation::PENDING, InteractionConsultation::RUNNING, InteractionConsultation::COMPLETED, InteractionConsultation::FAILED],
        ])->title('Consultas · Analytics · BULAI');
    }
}
