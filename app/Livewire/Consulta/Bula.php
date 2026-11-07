<?php

namespace App\Livewire\Consulta;

use App\Models\Consultation\MedicineLeafletSummary;
use App\Models\Knowledge\AnvisaMedicine;
use App\Services\Leaflet\LeafletSummaryService;
use App\Services\Patient\TreatmentContextService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Component;

/**
 * Bula humanizada de um medicamento: resumo em linguagem simples gerado só a partir das bulas cadastradas, mais o bloco
 * "Para você" (alergias e interações com o tratamento registrado, determinístico). Cache compartilhado entre usuários.
 */
class Bula extends Component
{
    public const NOT_IDENTIFIED = 'nao_identificado';

    public const NO_LEAFLET = 'sem_bula';

    public const LOADING = 'carregando';

    public const RATE_LIMITED = 'limite';

    public const READY = 'pronto';

    public AnvisaMedicine $medicine;

    public string $estado = self::LOADING;

    public ?string $summaryUuid = null;

    public function mount(AnvisaMedicine $medicine, LeafletSummaryService $service): void
    {
        $this->medicine = $medicine;
        // Bulas pelo princípio ativo ligado (ligando o catálogo na hora se preciso) ou pelo nome; sem nada, explica qual é o caso.
        if (! $service->isAvailable($medicine)) {
            $this->estado = $medicine->ingredients()->exists() ? self::NO_LEAFLET : self::NOT_IDENTIFIED;

            return;
        }
        $cached = $service->cached($medicine);
        if ($cached !== null) {
            $cached->increment('views');
            $this->pronto($cached);
        }
    }

    /** Chamado por wire:init quando não há cache: gera (ou reaproveita) o resumo. */
    public function gerar(LeafletSummaryService $service): void
    {
        if ($this->estado !== self::LOADING) {
            return;
        }
        $key = 'bula:'.auth()->id();
        if ($service->cached($this->medicine) === null && RateLimiter::tooManyAttempts($key, (int) config('bulai.consultation.rate_limit_per_hour'))) {
            $this->estado = self::RATE_LIMITED;

            return;
        }
        RateLimiter::hit($key, 3600);

        $summary = $service->forMedicine($this->medicine);
        if ($summary === null) {
            $this->estado = self::NO_LEAFLET;

            return;
        }
        $this->pronto($summary);
    }

    private function pronto(MedicineLeafletSummary $summary): void
    {
        $this->summaryUuid = $summary->uuid;
        $this->estado = self::READY;
    }

    public function render(TreatmentContextService $context): View
    {
        $summary = $this->summaryUuid !== null ? MedicineLeafletSummary::query()->where('uuid', $this->summaryUuid)->first() : null;

        return view('livewire.consulta.bula', [
            'summary' => $summary,
            'secoes' => $summary === null ? [] : $this->secoes($summary),
            'contexto' => $context->for(auth()->user(), $this->medicine),
            'detentor' => trim((string) preg_replace('/^\d{14}\s*-\s*/', '', (string) $this->medicine->registration_holder)),
        ])->title($this->medicine->product_name.' · Bula humanizada');
    }

    /**
     * Seções com afirmações válidas e as fontes (documento, seção, página) que as sustentam, sem trechos brutos.
     *
     * @return array{com: list<array{key: string, label: string, claims: list<string>, sources: list<string>}>, sem: list<string>}
     */
    private function secoes(MedicineLeafletSummary $summary): array
    {
        $byChunk = [];
        foreach ($summary->evidences ?? [] as $evidence) {
            $byChunk[$evidence['chunk_id']] = $evidence['document_title'].' · '.($evidence['section'] ?? 'seção não identificada').' · p. '.($evidence['page_start'] ?? '?');
        }

        $com = [];
        $sem = [];
        // Ordem das seções vem da configuração (o jsonb do PostgreSQL não preserva a ordem das chaves).
        foreach (array_keys((array) config('bulai.leaflet.sections')) as $key) {
            $section = $summary->sections[$key] ?? null;
            if ($section === null) {
                continue;
            }
            if ($section['claims'] === []) {
                $sem[] = $section['label'];

                continue;
            }
            $sources = [];
            foreach ($section['claims'] as $claim) {
                foreach ($claim['evidence_chunk_ids'] as $chunkId) {
                    $sources[] = $byChunk[$chunkId] ?? 'Bula cadastrada';
                }
            }
            $com[] = ['key' => $key, 'label' => $section['label'], 'claims' => array_column($section['claims'], 'text'), 'sources' => array_values(array_unique($sources))];
        }

        return ['com' => $com, 'sem' => $sem];
    }
}
