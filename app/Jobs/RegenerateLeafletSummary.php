<?php

namespace App\Jobs;

use App\Models\Consultation\MedicineLeafletSummary;
use App\Services\Leaflet\LeafletSummaryService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Refaz, a pedido do Admin, a bula humanizada de um conjunto de princípios ativos na versão atual do prompt, com a medição
 * de legibilidade e a reescrita até o alvo (`LeafletSummaryService::regenerate`). Fica na fila: com as reescritas, a
 * geração pode passar de um minuto.
 */
class RegenerateLeafletSummary implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public int $uniqueFor = 600;

    public function __construct(public readonly int $summaryId) {}

    public function uniqueId(): string
    {
        return (string) $this->summaryId;
    }

    public function handle(LeafletSummaryService $service): void
    {
        $summary = MedicineLeafletSummary::query()->find($this->summaryId);
        if ($summary !== null) {
            $service->regenerate($summary);
        }
    }
}
