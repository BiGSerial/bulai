<?php

namespace App\Livewire\Admin\Analytics\Questions;

use App\Models\Consultation\MedicationQuestion;
use App\Services\Analytics\AnalyticsService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Auditoria das perguntas livres respondidas pela IA (lista anonimizada). */
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
        return view('livewire.admin.analytics.questions.index', [
            'questions' => $analytics->recentQuestions($this->status !== '' ? $this->status : null),
            'statuses' => MedicationQuestion::query()->distinct()->orderBy('status')->pluck('status')->all(),
        ])->title('Perguntas · Analytics · BULAI');
    }
}
