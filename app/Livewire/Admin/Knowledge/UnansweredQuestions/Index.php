<?php

namespace App\Livewire\Admin\Knowledge\UnansweredQuestions;

use App\Models\Consultation\MedicationQuestion;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Perguntas de pacientes que o BULA-IA não conseguiu responder mesmo depois de esgotar as fontes (sem bula cadastrada,
 * sem princípio ativo no catálogo, ou bula inteira sem trecho claro). Uma linha por remédio e motivo, com as perguntas
 * agrupadas, para a equipe adquirir fontes confiáveis (cadastrar a bula, ligar o catálogo) e depois marcar como resolvida.
 */
class Index extends Component
{
    use WithPagination;

    public const REASONS = [
        'no_leaflet' => ['Sem bula cadastrada', 'Cadastre a bula do remédio em Adicionar documentos.'],
        'not_identified' => ['Sem princípio ativo no catálogo', 'O catálogo ANVISA não tem princípio ativo ligado a este remédio: rode a ligação de princípios ativos ou cadastre a bula (o vínculo pelo nome passa a valer).'],
        'no_evidence' => ['Bula sem resposta clara', 'A bula cadastrada (inclusive lida inteira) não trouxe trecho que responda. Confira se falta a bula do paciente ou uma versão mais completa.'],
    ];

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: 'pending')]
    public string $status = MedicationQuestion::REVIEW_PENDING;

    public ?string $feedback = null;

    /** Defesa em profundidade: além do middleware da rota, cada requisição confere o perfil de administrador. */
    public function boot(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    /** Marca como resolvidas (fonte adquirida) todas as perguntas pendentes deste remédio e motivo. */
    public function resolver(?int $medicineId, string $medicineName, string $reason): void
    {
        $this->feedback = $this->decide($medicineId, $medicineName, $reason, MedicationQuestion::REVIEW_RESOLVED).' marcada(s) como resolvida(s).';
    }

    /** Descarta (não há fonte a adquirir: pergunta fora do escopo, remédio inexistente etc.). */
    public function descartar(?int $medicineId, string $medicineName, string $reason): void
    {
        $this->feedback = $this->decide($medicineId, $medicineName, $reason, MedicationQuestion::REVIEW_DISMISSED).' descartada(s).';
    }

    private function decide(?int $medicineId, string $medicineName, string $reason, string $status): int
    {
        return MedicationQuestion::query()->pendingReview()
            ->where('review_reason', $reason)
            ->when($medicineId !== null, fn (Builder $query) => $query->where('medicine_id', $medicineId), fn (Builder $query) => $query->whereNull('medicine_id')->where('medicine_name', $medicineName))
            ->update(['review_status' => $status, 'reviewed_by' => auth()->id(), 'reviewed_at' => now()]);
    }

    public function render(): View
    {
        $groups = MedicationQuestion::query()
            ->selectRaw('medicine_id, medicine_name, review_reason, count(*) as total, max(created_at) as last_at, min(created_at) as first_at, count(distinct user_id) as patients')
            ->where('review_status', $this->status)
            ->when(trim($this->search) !== '', fn (Builder $query) => $query->whereLike('medicine_name', '%'.trim($this->search).'%'))
            ->groupBy('medicine_id', 'medicine_name', 'review_reason')
            ->orderByDesc('total')->orderByDesc('last_at')
            ->paginate(25);

        $examples = [];
        foreach ($groups as $group) {
            $examples[$group->medicine_id.'|'.$group->medicine_name.'|'.$group->review_reason] = MedicationQuestion::query()
                ->where('review_status', $this->status)->where('review_reason', $group->review_reason)
                ->when($group->medicine_id !== null, fn (Builder $query) => $query->where('medicine_id', $group->medicine_id), fn (Builder $query) => $query->whereNull('medicine_id')->where('medicine_name', $group->medicine_name))
                ->latest('id')->limit(3)->get(['uuid', 'question']);
        }
        $totals = DB::table('medication_questions')->whereNotNull('review_status')->selectRaw('review_status, count(*) as n')->groupBy('review_status')->pluck('n', 'review_status')->map(fn ($n): int => (int) $n)->all();

        return view('livewire.admin.knowledge.unanswered-questions.index', [
            'groups' => $groups,
            'examples' => $examples,
            'totals' => $totals + [MedicationQuestion::REVIEW_PENDING => 0, MedicationQuestion::REVIEW_RESOLVED => 0, MedicationQuestion::REVIEW_DISMISSED => 0],
            'isPending' => $this->status === MedicationQuestion::REVIEW_PENDING,
        ])->title('Perguntas sem resposta · BULAI');
    }
}
