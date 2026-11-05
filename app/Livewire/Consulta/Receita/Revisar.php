<?php

namespace App\Livewire\Consulta\Receita;

use App\Models\Knowledge\AnvisaMedicine;
use App\Models\PatientPrescription;
use App\Services\Interactions\MedicationSearchService;
use App\Services\Treatment\DoseScheduleParser;
use App\Services\Treatment\TreatmentScheduleService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
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
     * @var list<array{name_as_written: string, medicine_id: int|null, medicine_name: string|null, dosage: string, frequency: string, duration: string, notes: string, confidence: float, candidates: list<array{id: int, name: string, ingredients: string, holder: string, identified: bool}>, skip: bool, busca: string, times: list<string>, as_needed: bool}>
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
        $this->cabecalho = [
            'prescritor' => $this->prescription->prescriber_name === 'Receita em leitura' ? '' : (string) $this->prescription->prescriber_name,
            'registro' => (string) $this->prescription->prescriber_registration,
            'data' => $this->prescription->issued_at?->format('Y-m-d'),
        ];
        $parser = app(DoseScheduleParser::class);
        $this->itens = array_map(function (array $item) use ($parser): array {
            $first = $item['candidates'][0] ?? null;
            $schedule = $parser->parse($item['frequency'] ?? null);

            return [
                'name_as_written' => (string) $item['name_as_written'],
                'medicine_id' => $first['id'] ?? null,
                'medicine_name' => $first['name'] ?? null,
                'dosage' => (string) ($item['dosage'] ?? ''),
                'frequency' => (string) ($item['frequency'] ?? ''),
                'duration' => (string) ($item['duration'] ?? ''),
                'notes' => (string) ($item['notes'] ?? ''),
                'confidence' => (float) ($item['confidence'] ?? 0),
                'candidates' => array_values($item['candidates'] ?? []),
                'skip' => false,
                'busca' => '',
                'times' => $schedule['times'] ?? [],
                'as_needed' => (bool) ($schedule['as_needed'] ?? false),
            ];
        }, $this->prescription->extraction['items'] ?? []);
    }

    /** Ao corrigir "quando tomar", os horários sugeridos são refeitos a partir do novo texto. */
    public function updatedItens(mixed $value, string $key): void
    {
        if (preg_match('/^(\d+)\.frequency$/', $key, $m) !== 1 || ! isset($this->itens[(int) $m[1]])) {
            return;
        }
        $schedule = app(DoseScheduleParser::class)->parse((string) $value);
        $this->itens[(int) $m[1]]['times'] = $schedule['times'] ?? [];
        $this->itens[(int) $m[1]]['as_needed'] = (bool) ($schedule['as_needed'] ?? false);
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

    public function confirmar(TreatmentScheduleService $schedule): ?RedirectResponse
    {
        $this->aviso = null;
        if ($this->prescription->status !== PatientPrescription::STATUS_REVIEW) {
            return null;
        }
        try {
            $this->validate([
                'cabecalho.prescritor' => ['required', 'string', 'min:3', 'max:120'],
                'cabecalho.registro' => ['nullable', 'string', 'max:40'],
                'cabecalho.data' => ['nullable', 'date', 'before_or_equal:today'],
                'itens.*.dosage' => ['nullable', 'string', 'max:120'],
                'itens.*.frequency' => ['nullable', 'string', 'max:120'],
                'itens.*.duration' => ['nullable', 'string', 'max:80'],
                'itens.*.notes' => ['nullable', 'string', 'max:255'],
                'itens.*.times' => ['array', 'max:12'],
                'itens.*.times.*' => ['nullable', 'string', 'max:5'],
            ], ['cabecalho.prescritor.required' => 'Informe quem prescreveu a receita.', 'cabecalho.prescritor.min' => 'O nome de quem prescreveu precisa ter pelo menos 3 letras.']);
        } catch (ValidationException $exception) {
            $this->aviso = $exception->validator->errors()->first();

            return null;
        }

        $ativos = array_values(array_filter($this->itens, fn (array $item): bool => ! $item['skip']));
        $issued = filled($this->cabecalho['data']) ? Carbon::parse($this->cabecalho['data']) : null;
        $this->prescription->update([
            'prescriber_name' => trim($this->cabecalho['prescritor']),
            'prescriber_registration' => filled($this->cabecalho['registro']) ? trim($this->cabecalho['registro']) : null,
            'issued_at' => $issued,
            'notes' => $this->prescription->notes,
            'status' => PatientPrescription::STATUS_CONFIRMED,
            'confirmed_at' => now(),
            'extraction' => ($this->prescription->extraction ?? []) + ['confirmed_items' => count($ativos), 'skipped_items' => count($this->itens) - count($ativos), 'unmatched_items' => count(array_filter($ativos, fn (array $item): bool => $item['medicine_id'] === null))],
        ]);

        $start = $issued ?? now()->startOfDay();
        $semCatalogo = 0;
        foreach ($ativos as $item) {
            $semCatalogo += $item['medicine_id'] === null ? 1 : 0;
            $schedule->register(auth()->user(), [
                'medicine_id' => $item['medicine_id'],
                'name_as_written' => $item['name_as_written'],
                'prescription_id' => $this->prescription->id,
                'dosage' => $item['dosage'],
                'frequency' => $item['frequency'],
                'duration' => $item['duration'],
                'dose_times' => $item['as_needed'] ? [] : TreatmentScheduleService::cleanTimes($item['times']),
                'started_at' => $start,
                'notes' => trim(implode(' · ', array_filter([filled($item['duration']) ? 'Duração: '.trim($item['duration']) : null, filled($item['notes']) ? trim($item['notes']) : null]))) ?: null,
            ]);
        }

        $mensagem = count($ativos).' remédio(s) da receita entraram no seu tratamento.';
        if ($semCatalogo > 0) {
            $mensagem .= ' '.($semCatalogo === 1 ? '1 deles ainda não está no nosso catálogo: nossa equipe vai conferir.' : "{$semCatalogo} deles ainda não estão no nosso catálogo: nossa equipe vai conferir.");
        }

        return redirect()->route('consulta.hoje')->with('accountFeedback', $mensagem);
    }

    public function render(MedicationSearchService $search): View
    {
        $this->prescription->refresh();
        $this->carregar();
        if (in_array($this->prescription->status, [PatientPrescription::STATUS_PENDING, PatientPrescription::STATUS_PROCESSING], true)
            && $this->prescription->updated_at->lt(now()->subSeconds((int) config('bulai.consultation.stale_after_seconds')))) {
            $this->prescription->update(['status' => PatientPrescription::STATUS_FAILED, 'error_code' => 'PRESCRIPTION_TIMEOUT']);
        }

        $buscas = [];
        foreach ($this->itens as $index => $item) {
            $buscas[$index] = strlen(trim($item['busca'])) >= 3 ? $search->search($item['busca'], 5) : [];
        }

        return view('livewire.consulta.receita.revisar', [
            'status' => $this->prescription->status,
            'legibilidade' => $this->prescription->extraction['legibility'] ?? null,
            'buscas' => $buscas,
            'lendo' => in_array($this->prescription->status, [PatientPrescription::STATUS_PENDING, PatientPrescription::STATUS_PROCESSING], true),
        ]);
    }
}
