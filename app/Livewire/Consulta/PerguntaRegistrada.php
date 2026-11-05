<?php

namespace App\Livewire\Consulta;

use App\Models\Consultation\MedicationQuestion;
use App\Services\Safety\GuardVerdict;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/** Leitura de uma pergunta anterior do próprio usuário, com a resposta, o contexto de tratamento e as fontes usadas na época. */
class PerguntaRegistrada extends Component
{
    public MedicationQuestion $question;

    public function mount(MedicationQuestion $question): void
    {
        abort_unless($question->user_id === auth()->id(), 404);
        $this->question = $question;
    }

    public function render(): View
    {
        $context = $this->question->treatment_context;
        $treatments = [];
        if (is_array($context) && array_key_exists('consented', $context)) {
            $treatments[] = ['medicine' => $this->question->medicines[0]['name'] ?? $this->question->medicine_name, 'context' => $context];
            foreach ($context['others'] ?? [] as $other) {
                $treatments[] = $other;
            }
        }
        $category = $this->question->guard['category'] ?? null;

        return view('livewire.consulta.pergunta-registrada', [
            'resposta' => [
                'status' => $this->question->status,
                'answer' => $this->question->answer,
                'evidences' => $this->question->evidences ?? [],
                'medicamento' => $this->question->medicine_name,
                'pergunta' => $this->question->question,
            ],
            'medicamentos' => $this->question->medicines ?? [],
            'tratamentos' => $treatments,
            'bloqueio' => $this->question->status === MedicationQuestion::STATUS_BLOCKED && $category !== null ? (new GuardVerdict($category, ''))->userMessage() : null,
            'risco' => $category === GuardVerdict::HARM_RISK,
        ]);
    }
}
