<?php

namespace App\Livewire\Admin\Knowledge\Batches;

use App\Models\Knowledge\IngestionBatch;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Andamento de uma importação (contadores e barra). É o único trecho da tela que se atualiza sozinho:
 * faz polling enquanto a importação está em andamento e para quando ela termina.
 */
class Progress extends Component
{
    public IngestionBatch $batch;

    public function mount(IngestionBatch $batch): void
    {
        $this->batch = $batch;
    }

    public function render(): View
    {
        $this->batch->refresh();
        $total = max(1, $this->batch->total_items);
        $done = $this->batch->associated_items + $this->batch->duplicate_items + $this->batch->ignored_items + $this->batch->error_items;

        return view('livewire.shared.batch-progress', [
            'active' => $this->batch->status !== IngestionBatch::STATUS_COMPLETED,
            'percent' => (int) round(min($total, $done + $this->batch->pending_items) / $total * 100),
            'counters' => [
                ['Incorporados', $this->batch->associated_items, 'success'],
                ['Pendentes', $this->batch->pending_items, $this->batch->pending_items > 0 ? 'warning' : 'default'],
                ['Duplicados', $this->batch->duplicate_items, 'default'],
                ['Não incorporados', $this->batch->ignored_items, 'default'],
                ['Com erro', $this->batch->error_items, $this->batch->error_items > 0 ? 'danger' : 'default'],
            ],
        ]);
    }
}
