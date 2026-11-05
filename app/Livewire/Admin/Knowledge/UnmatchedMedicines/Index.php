<?php

namespace App\Livewire\Admin\Knowledge\UnmatchedMedicines;

use App\Models\Knowledge\AnvisaMedicine;
use App\Models\UnmatchedMedicineReport;
use App\Notifications\UnmatchedMedicineResolvedNotification;
use App\Services\Interactions\MedicationSearchService;
use App\Services\Knowledge\AnvisaCatalogNormalizer;
use App\Services\Treatment\TreatmentScheduleService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Remédios que pacientes cadastraram pelo nome e o catálogo ANVISA não reconheceu.
 *
 * Uma linha por nome (várias pendências de pacientes diferentes se agrupam). "Ligar ao catálogo" resolve todas as
 * pendências abertas daquele nome de uma vez e avisa cada paciente; "Descartar" mantém os remédios só pelo nome.
 */
class Index extends Component
{
    use WithPagination;

    public const STATUSES = ['open' => UnmatchedMedicineReport::STATUS_OPEN, 'resolved' => UnmatchedMedicineReport::STATUS_RESOLVED, 'dismissed' => UnmatchedMedicineReport::STATUS_DISMISSED];

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: 'open')]
    public string $status = 'open';

    /** Nome normalizado cujo painel "Ligar ao catálogo" está aberto. */
    public ?string $openName = null;

    public string $catalogSearch = '';

    public string $note = '';

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
        $this->fechar();
    }

    /** Abre (ou fecha) o painel de busca no catálogo para um nome. */
    public function abrir(string $normalizedName): void
    {
        if ($this->openName === $normalizedName) {
            $this->fechar();

            return;
        }
        $this->openName = $normalizedName;
        $this->catalogSearch = self::plainName($normalizedName);
        $this->note = '';
    }

    /** Nome sem concentração/forma ("amoxil 500 mg capsulas" → "amoxil") para a primeira busca no catálogo. */
    public static function plainName(string $normalizedName): string
    {
        $words = preg_split('/\s+/', trim($normalizedName)) ?: [];
        $plain = array_filter($words, fn (string $word): bool => preg_match('/\d|^(mg|g|ml|mcg|ui|comprimidos?|caps?ulas?|cp|cps|gotas|xarope|solucao|frasco|caixa|sache|pomada|creme|gel)$/', $word) !== 1);

        return $plain !== [] ? implode(' ', $plain) : trim($normalizedName);
    }

    public function fechar(): void
    {
        $this->reset('openName', 'catalogSearch', 'note');
    }

    /** Liga TODAS as pendências abertas do nome em aberto ao medicamento escolhido e avisa cada paciente. */
    public function ligar(TreatmentScheduleService $schedule, int $medicineId): void
    {
        $medicine = AnvisaMedicine::query()->find($medicineId);
        if ($this->openName === null || $medicine === null) {
            $this->dispatch('notify', message: 'Escolha um medicamento do catálogo para ligar.', tone: 'warning');

            return;
        }
        $this->validate(['note' => ['nullable', 'string', 'max:255']]);

        $reports = $this->openReportsFor($this->openName)->with('user')->get();
        $notified = [];
        foreach ($reports as $report) {
            $medication = $schedule->resolve($report, $medicine, auth()->user(), $this->note);
            if ($report->user !== null && ! in_array($medication->id, $notified, true)) {
                $report->user->notify(new UnmatchedMedicineResolvedNotification($medication));
                $notified[] = $medication->id;
            }
        }

        $count = $reports->count();
        $this->fechar();
        $this->dispatch('notify', message: $count === 1
            ? "1 remédio ligado a {$medicine->product_name}. O paciente foi avisado."
            : "{$count} remédios ligados a {$medicine->product_name}. Os pacientes foram avisados.", tone: 'success');
    }

    /** Mantém os remédios só pelo nome e fecha as pendências abertas daquele nome. */
    public function descartar(string $normalizedName): void
    {
        $count = $this->openReportsFor($normalizedName)->update([
            'status' => UnmatchedMedicineReport::STATUS_DISMISSED,
            'resolved_by' => auth()->id(),
            'resolution_note' => 'Sem correspondência no catálogo',
            'resolved_at' => now(),
        ]);
        if ($this->openName === $normalizedName) {
            $this->fechar();
        }
        $this->dispatch('notify', message: $count === 1 ? 'Pendência descartada. O remédio continua no tratamento do paciente, só pelo nome.' : "{$count} pendências descartadas. Os remédios continuam nos tratamentos, só pelo nome.", tone: 'info');
    }

    public function render(MedicationSearchService $search, AnvisaCatalogNormalizer $normalizer): View
    {
        $status = self::STATUSES[$this->status] ?? UnmatchedMedicineReport::STATUS_OPEN;
        $term = $normalizer->text($this->search);

        $groups = UnmatchedMedicineReport::query()
            ->where('status', $status)
            ->when($term !== '', fn (Builder $query) => $query->whereLike('normalized_name', '%'.$term.'%'))
            ->selectRaw('normalized_name, (array_agg(name_as_written order by id))[1] as name_as_written, count(distinct user_id) as patients, count(*) as reports, min(created_at) as first_at, max(id) as last_id')
            ->groupBy('normalized_name')
            ->orderBy('first_at')->orderBy('normalized_name')
            ->paginate(20);

        $names = $groups->getCollection()->pluck('normalized_name')->all();
        $examples = UnmatchedMedicineReport::query()->where('status', $status)->whereIn('normalized_name', $names)->get(['normalized_name', 'dosage', 'frequency'])
            ->groupBy('normalized_name')
            ->map(fn (Collection $rows): string => $rows->map(fn (UnmatchedMedicineReport $row): string => collect([$row->dosage, $row->frequency])->filter()->join(' '))->filter()->unique()->take(2)->join(' · '));
        $outcomes = $status === UnmatchedMedicineReport::STATUS_OPEN ? collect() : UnmatchedMedicineReport::query()->with(['resolvedMedicine:id,product_name', 'resolver:id,name'])
            ->whereIn('id', $groups->getCollection()->pluck('last_id')->all())->get()->keyBy('normalized_name');

        $candidates = $this->openName !== null && strlen(trim($this->catalogSearch)) >= 3 ? $search->search($this->catalogSearch, 6) : [];
        $totals = ['open' => UnmatchedMedicineReport::query()->open()->count()];

        return view('livewire.admin.knowledge.unmatched-medicines.index', [
            'groups' => $groups,
            'examples' => $examples,
            'outcomes' => $outcomes,
            'candidates' => $candidates,
            'totals' => $totals,
            'isOpen' => $status === UnmatchedMedicineReport::STATUS_OPEN,
        ])->title('Remédios não encontrados · BULAI');
    }

    private function openReportsFor(string $normalizedName): Builder
    {
        return UnmatchedMedicineReport::query()->open()->where('normalized_name', $normalizedName);
    }
}
