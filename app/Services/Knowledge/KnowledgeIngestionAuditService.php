<?php

namespace App\Services\Knowledge;

use App\Models\Knowledge\IngestionBatch;
use App\Models\Knowledge\IngestionBatchItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class KnowledgeIngestionAuditService
{
    /** @param array<string, mixed> $details */
    public function append(IngestionBatchItem $item, string $event, ?User $actor = null, array $details = []): void
    {
        $metadata = $item->metadata ?? [];
        $history = is_array($metadata['audit_history'] ?? null) ? $metadata['audit_history'] : [];
        $history[] = ['event' => $event, 'occurred_at' => now()->toISOString(), 'actor_id' => $actor?->id,
            'actor_name' => $actor?->name, 'details' => $details];
        $metadata['audit_history'] = $history;
        $item->forceFill(['metadata' => $metadata])->save();
    }

    public function ignore(int $batchId, int $itemId, string $reason, ?string $notes, User $actor): string
    {
        return DB::transaction(function () use ($batchId, $itemId, $reason, $notes, $actor): string {
            $item = IngestionBatchItem::query()->where('ingestion_batch_id', $batchId)->whereKey($itemId)->lockForUpdate()->firstOrFail();
            if ($item->status !== IngestionBatchItem::STATUS_PENDING) {
                return 'ALREADY_RESOLVED';
            }
            $item->forceFill(['status' => IngestionBatchItem::STATUS_IGNORED, 'reason' => 'not_incorporated'])->save();
            $this->append($item, 'not_incorporated', $actor, ['reason' => $reason, 'notes' => $notes]);
            $this->recalculateBatchCounters($batchId);

            return IngestionBatchItem::STATUS_IGNORED;
        });
    }

    public function recalculateBatchCounters(int $batchId): void
    {
        $batch = IngestionBatch::query()->whereKey($batchId)->lockForUpdate()->firstOrFail();
        $batch->forceFill([
            'total_items' => $batch->items()->count(),
            'associated_items' => $batch->items()->where('status', IngestionBatchItem::STATUS_ASSOCIATED)->count(),
            'duplicate_items' => $batch->items()->where('status', IngestionBatchItem::STATUS_DUPLICATE)->count(),
            'pending_items' => $batch->items()->where('status', IngestionBatchItem::STATUS_PENDING)->count(),
            'error_items' => $batch->items()->where('status', IngestionBatchItem::STATUS_ERROR)->count(),
            'ignored_items' => $batch->items()->where('status', IngestionBatchItem::STATUS_IGNORED)->count(),
        ])->save();
    }
}
