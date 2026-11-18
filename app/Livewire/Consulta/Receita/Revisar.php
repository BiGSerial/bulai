<?php

namespace App\Livewire\Consulta\Receita;

use App\Models\Knowledge\AnvisaMedicine;
use App\Models\PatientPrescription;
use App\Services\Interactions\MedicationSearchService;
use App\Services\Patient\PrescriptionReviewService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * Receita por foto, passo 2: acompanha a leitura (poll) e, quando pronta, o paciente confere e corrige cada item
 * (remédio do catálogo, dose, frequência, duração, horários) antes de salvar no tratamento. Nada é gravado sem confirmação.
 *
 * Um item que o catálogo não reconhece NÃO impede a confirmação: ele entra no tratamento pelo nome escrito, sinalizado
 * como "não encontrado", e vira pendência para os administradores ligarem ao catálogo depois.
 */
class Revisar extends Component
{
    public PatientPrescription $prescription;

    public bool $carregado = false;

    /** @var array{prescritor: string, registro: string, data: string|null} */
    public array $cabecalho = ['prescritor' => '', 'registro' => '', 'data' => null];

    /**
     * @var list<array{name_as_written: string, medicine_id: int|null, medicine_name: string|null, dosage: string, frequency: string, duration: string, notes: string, confidence: float, candidates: list<array{id: int, name: string, ingredients: string, holder: string, identified: bool}>, skip: bool, busca: string, times: list<string>, as_needed: bool, interval_hours: int|null, schedule_mode: string}>
     */
    public array $itens = [];

    public ?string $aviso = null;

    public function mount(PatientPrescription $prescription): void
    {
        abort_unless($prescription->user_id === auth()->id(), 404);
        $this->prescription = $prescription;
        $this->carregar();
    }

    /** Copia a extração para o formulário uma única vez, quando a leitura termina. */
    private function carregar(): void
    {
        if ($this->carregado || $this->prescription->status !== PatientPrescription::STATUS_REVIEW) {
            return;
        }
        $this->carregado = true;
        $service = app(PrescriptionReviewService::class);
        $header = $service->header($this->prescription);
        $this->cabecalho = ['prescritor' => $header['prescriber'], 'registro' => $header['registration'], 'data' => $header['date']];
        $this->itens = array_map(fn (array $item): array => $item + ['busca' => ''], $service->items($this->prescription));
    }

    /** Ao corrigir "quando tomar", os horários sugeridos são refeitos a partir do novo texto. */
    public function updatedItens(mixed $value, string $key): void
    {
        if (preg_match('/^(\d+)\.frequency$/', $key, $m) !== 1 || ! isset($this->itens[(int) $m[1]])) {
            return;
        }
        $this->itens[(int) $m[1]] = app(PrescriptionReviewService::class)->suggestedSchedule((string) $value) + $this->itens[(int) $m[1]];
    }

    public function escolher(int $index, int $medicineId): void
    {
        $medicine = AnvisaMedicine::query()->find($medicineId);
        if (! isset($this->itens[$index]) || $medicine === null) {
            return;
        }
        $this->itens[$index]['medicine_id'] = $medicine->id;
        $this->itens[$index]['medicine_name'] = $medicine->product_name;
        $this->itens[$index]['skip'] = false;
        $this->itens[$index]['busca'] = '';
    }

    /** Mantém o item pelo nome escrito, sem ligar ao catálogo (vira pendência para a administração). */
    public function manterPeloNome(int $index): void
    {
        if (! isset($this->itens[$index])) {
            return;
        }
        $this->itens[$index]['medicine_id'] = null;
        $this->itens[$index]['medicine_name'] = null;
        $this->itens[$index]['skip'] = false;
        $this->itens[$index]['busca'] = '';
    }

    public function pular(int $index): void
    {
        if (isset($this->itens[$index])) {
            $this->itens[$index]['skip'] = ! $this->itens[$index]['skip'];
        }
    }

    public function adicionarHorario(int $index): void
    {
        if (! isset($this->itens[$index]) || count($this->itens[$index]['times']) >= 12) {
            return;
        }
        $this->itens[$index]['as_needed'] = false;
        $this->itens[$index]['times'][] = count($this->itens[$index]['times']) === 0 ? '08:00' : '';
    }

    public function tirarHorario(int $index, int $posicao): void
    {
        if (! isset($this->itens[$index]['times'][$posicao])) {
            return;
        }
        unset($this->itens[$index]['times'][$posicao]);
        $this->itens[$index]['times'] = array_values($this->itens[$index]['times']);
    }

    public function descartar(): RedirectResponse
    {
        $this->prescription->delete();

        return redirect()->route('consulta.perfil', ['aba' => 'receitas'])->with('accountFeedback', 'Receita descartada. Nada foi salvo.');
    }

    public function confirmar(PrescriptionReviewService $service): ?RedirectResponse
    {
        $this->aviso = null;
        if ($this->prescription->status !== PatientPrescription::STATUS_REVIEW) {
            return null;
        }
        try {
            $result = $service->confirm(auth()->user(), $this->prescription, [
                'prescriber' => $this->cabecalho['prescritor'], 'registration' => $this->cabecalho['registro'], 'date' => $this->cabecalho['data'],
            ], $this->itens);
        } catch (ValidationException $exception) {
            $this->aviso = $exception->validator->errors()->first();

            return null;
        }

        return redirect()->route('consulta.hoje')->with('accountFeedback', $result['message']);
    }

    public function render(MedicationSearchService $search, PrescriptionReviewService $service): View
    {
        $this->prescription->refresh();
        $this->carregar();
        $service->expireIfStale($this->prescription);

        $buscas = [];
        foreach ($this->itens as $index => $item) {
            $buscas[$index] = strlen(trim($item['busca'])) >= 3 ? $search->search($item['busca'], 5) : [];
        }

        return view('livewire.consulta.receita.revisar', [
            'status' => $this->prescription->status,
            'legibilidade' => $this->prescription->extraction['legibility'] ?? null,
            'ignorado' => ($this->prescription->extraction['injection'] ?? []) !== [],
            'buscas' => $buscas,
            'lendo' => PrescriptionReviewService::isReading($this->prescription),
        ]);
    }
}
