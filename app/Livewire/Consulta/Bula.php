<?php

namespace App\Livewire\Consulta;

use App\Models\Consultation\MedicineLeafletSummary;
use App\Models\Knowledge\AnvisaMedicine;
use App\Services\Leaflet\LeafletPage;
use App\Services\Patient\TreatmentContextService;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Bula humanizada de um medicamento: resumo em linguagem simples gerado só a partir das bulas cadastradas, mais o bloco
 * "Para você" (alergias e interações com o tratamento registrado, determinístico). Cache compartilhado entre usuários.
 */
class Bula extends Component
{
    public const NOT_IDENTIFIED = LeafletPage::NOT_IDENTIFIED;

    public const NO_LEAFLET = LeafletPage::NO_LEAFLET;

    public const LOADING = LeafletPage::LOADING;

    public const RATE_LIMITED = LeafletPage::RATE_LIMITED;

    public const READY = LeafletPage::READY;

    public AnvisaMedicine $medicine;

    public string $estado = self::LOADING;

    public ?string $summaryUuid = null;

    public function mount(AnvisaMedicine $medicine, LeafletPage $page): void
    {
        $this->medicine = $medicine;
        $this->aplicar($page->open($medicine));
    }

    /** Chamado por wire:init quando não há cache: gera (ou reaproveita) o resumo. */
    public function gerar(LeafletPage $page): void
    {
        if ($this->estado === self::LOADING) {
            $this->aplicar($page->generate(auth()->user(), $this->medicine));
        }
    }

    /** @param array{state: string, summary: MedicineLeafletSummary|null} $resultado */
    private function aplicar(array $resultado): void
    {
        $this->estado = $resultado['state'];
        $this->summaryUuid = $resultado['summary']?->uuid;
    }

    public function render(TreatmentContextService $context): View
    {
        $summary = $this->summaryUuid !== null ? MedicineLeafletSummary::query()->where('uuid', $this->summaryUuid)->first() : null;

        return view('livewire.consulta.bula', [
            'summary' => $summary,
            'secoes' => $summary === null ? [] : LeafletPage::sections($summary),
            'contexto' => $context->for(auth()->user(), $this->medicine),
            'detentor' => LeafletPage::holder($this->medicine),
        ])->title($this->medicine->product_name.' · Bula humanizada');
    }
}
