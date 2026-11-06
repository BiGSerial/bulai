<?php

namespace App\Livewire\Admin\Analytics\Questions;

use App\Models\Consultation\MedicationQuestion;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Detalhe auditável de uma pergunta: pergunta, resposta, contexto de tratamento usado, evidências e métricas. */
class Show extends Component
{
    /** Aba visível. */
    #[Url(as: 'aba', except: 'contexto')]
    public string $tab = 'contexto';

    public function updatedTab(): void
    {
        if (! in_array($this->tab, ['contexto', 'geracao', 'evidencias'], true)) {
            $this->tab = 'contexto';
        }
    }

    public MedicationQuestion $question;

    public function mount(MedicationQuestion $question): void
    {
        $this->updatedTab();
        $this->question = $question;
    }

    public function render(): View
    {
        return view('livewire.admin.analytics.questions.show')
            ->title('Pergunta '.substr($this->question->uuid, 0, 8).' · Analytics · BULAI');
    }
}
