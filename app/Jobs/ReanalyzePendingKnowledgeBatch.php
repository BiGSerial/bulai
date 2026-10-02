<?php

namespace App\Jobs;

use App\Models\Knowledge\IngestionBatchItem;
use App\Models\User;
use App\Services\Knowledge\KnowledgePendingItemReanalysisService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ReanalyzePendingKnowledgeBatch implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public int $uniqueFor = 600;

    public function __construct(public readonly int $batchId, public readonly ?int $actorId = null) {}

    /**
     * Execute the job.
     */
    public function handle(KnowledgePendingItemReanalysisService $service): void
    {
        $actor = $this->actorId === null ? null : User::query()->find($this->actorId);
        IngestionBatchItem::query()->where('ingestion_batch_id', $this->batchId)->where('status', IngestionBatchItem::STATUS_PENDING)
            ->orderBy('id')->pluck('id')->each(fn (int $itemId) => $service->reanalyze($this->batchId, $itemId, $actor));
    }

    public function uniqueId(): string
    {
        return (string) $this->batchId;
    }
}
