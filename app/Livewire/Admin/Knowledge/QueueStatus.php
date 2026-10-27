<?php

namespace App\Livewire\Admin\Knowledge;

use App\Jobs\ClassifyPendingDocument;
use App\Models\Knowledge\IngestionBatchItem;
use App\Services\Health\ServiceHealthChecker;
use App\Services\Health\ServiceRestarter;
use App\Services\Knowledge\KnowledgePendingItemReanalysisService;
use App\Services\Queue\QueueStatusService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;

/** Fila de análises e serviços de OCR/IA: o que está rodando, o que travou e como destravar. */
class QueueStatus extends Component
{
    public ?string $feedback = null;

    public string $feedbackType = 'info';

    public function requeueItem(KnowledgePendingItemReanalysisService $service, QueueStatusService $queue, int $itemId): void
    {
        $item = IngestionBatchItem::query()->where('status', IngestionBatchItem::STATUS_PENDING)->find($itemId);
        if ($item === null) {
            $this->notify('info', 'Este item já foi resolvido.');

            return;
        }
        $queue->removeWaitingJobs($item->id);
        // O lock de unicidade do job pode ter ficado preso por um worker que morreu.
        Cache::lock('laravel_unique_job:'.ClassifyPendingDocument::class.$item->ingestion_batch_id.':'.$item->id)->forceRelease();
        $service->queueItem($item->id, auth()->user(), replaceActive: true);
        ClassifyPendingDocument::dispatch($item->ingestion_batch_id, $item->id, false, auth()->id());
        $this->notify('success', 'Item reenfileirado.');
    }

    public function cancelItem(KnowledgePendingItemReanalysisService $service, QueueStatusService $queue, int $itemId): void
    {
        $queue->removeWaitingJobs($itemId);
        $service->cancel($itemId, auth()->user());
        $this->notify('info', 'Análise cancelada. O item segue pendente para revisão manual.');
    }

    public function requeueStale(KnowledgePendingItemReanalysisService $service, QueueStatusService $queue): void
    {
        $count = 0;
        foreach ($queue->snapshot()['items'] as $item) {
            if ($item['stale']) {
                $this->requeueItem($service, $queue, $item['id']);
                $count++;
            }
        }
        $this->notify('success', $count === 1 ? '1 item parado foi reenfileirado.' : "{$count} itens parados foram reenfileirados.");
    }

    public function restartService(ServiceRestarter $restarter, string $service): void
    {
        if (! in_array($service, ServiceRestarter::SERVICES, true)) {
            return;
        }
        $result = $restarter->restart($service);
        $this->notify($result['ok'] ? 'success' : 'danger', $result['message']);
    }

    public function render(QueueStatusService $queue, ServiceHealthChecker $health, ServiceRestarter $restarter): View
    {
        return view('livewire.admin.knowledge.queue-status', [
            'queue' => $queue->snapshot(),
            'services' => $health->all(),
            'restartable' => ['local_ai' => $restarter->canRestart('local_ai'), 'ocr' => $restarter->canRestart('ocr')],
        ])->title('Fila e serviços · BULAI');
    }

    private function notify(string $type, string $message): void
    {
        [$this->feedbackType, $this->feedback] = [$type, $message];
    }
}
