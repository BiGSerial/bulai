<?php

namespace App\Services\Queue;

use App\Models\Knowledge\IngestionBatchItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;

/** Fotografia da fila de análise: worker, jobs no banco e itens em andamento/travados. */
final class QueueStatusService
{
    public function __construct(private readonly WorkerHeartbeat $heartbeat) {}

    /**
     * @return array{
     *     worker: array{state: string, last_seen_at: ?int, host: ?string, busy: bool},
     *     jobs: array{waiting: int, running: int, failed: int},
     *     items: list<array{id: int, batch_id: int, filename: string, state: string, percent: int, label: string, updated_at: ?string, stale: bool, has_job: bool}>,
     *     stale: int
     * }
     */
    public function snapshot(): array
    {
        $jobs = ['waiting' => 0, 'running' => 0, 'failed' => 0];
        $jobItemIds = [];
        $driver = $this->driver();
        if ($driver === 'database') {
            $table = $this->table();
            $jobs['waiting'] = DB::table($table)->whereNull('reserved_at')->count();
            $jobs['running'] = DB::table($table)->whereNotNull('reserved_at')->count();
            $jobItemIds = $this->itemIdsInQueue($table);
        } elseif ($driver === 'redis') {
            $redis = $this->redis();
            $key = $this->redisKey();
            $jobs['waiting'] = (int) $redis->llen($key) + (int) $redis->zcard($key.':delayed');
            $jobs['running'] = (int) $redis->zcard($key.':reserved');
            foreach (array_slice((array) $redis->lrange($key, 0, 499), 0, 500) as $payload) {
                if (($id = $this->itemIdOf((string) $payload)) !== null) {
                    $jobItemIds[] = $id;
                }
            }
        }
        if (Schema::hasTable('failed_jobs')) {
            $jobs['failed'] = DB::table('failed_jobs')->count();
        }

        $staleBefore = now()->subMinutes((int) config('knowledge.processing.progress_stale_minutes', 5));
        $items = IngestionBatchItem::query()
            ->where('status', IngestionBatchItem::STATUS_PENDING)
            ->whereIn('metadata->reanalysis_progress->state', ['queued', 'processing'])
            ->orderBy('updated_at')->limit(100)->get()
            ->map(fn (IngestionBatchItem $item): array => [
                'id' => $item->id,
                'batch_id' => $item->ingestion_batch_id,
                'filename' => (string) $item->original_filename,
                'state' => (string) data_get($item->metadata, 'reanalysis_progress.state'),
                'percent' => (int) data_get($item->metadata, 'reanalysis_progress.percent', 0),
                'label' => (string) data_get($item->metadata, 'reanalysis_progress.label', ''),
                'updated_at' => $item->updated_at?->toISOString(),
                'stale' => $item->updated_at !== null && $item->updated_at->lt($staleBefore),
                'has_job' => in_array($item->id, $jobItemIds, true),
            ])->all();

        return [
            'worker' => $this->heartbeat->status(),
            'jobs' => $jobs,
            'items' => $items,
            'stale' => count(array_filter($items, fn (array $item): bool => $item['stale'])),
        ];
    }

    /** Remove da fila os jobs ainda não iniciados deste item (evita análise duplicada ou indesejada). */
    public function removeWaitingJobs(int $itemId): int
    {
        $removed = 0;
        if ($this->driver() === 'redis') {
            $redis = $this->redis();
            $key = $this->redisKey();
            foreach ((array) $redis->lrange($key, 0, -1) as $payload) {
                if ($this->itemIdOf((string) $payload) === $itemId) {
                    $removed += (int) $redis->lrem($key, 1, $payload);
                }
            }

            return $removed;
        }
        $table = $this->table();
        if ($this->driver() !== 'database') {
            return 0;
        }
        foreach (DB::table($table)->whereNull('reserved_at')->get(['id', 'payload']) as $row) {
            if ($this->itemIdOf((string) $row->payload) === $itemId) {
                $removed += DB::table($table)->where('id', $row->id)->whereNull('reserved_at')->delete();
            }
        }

        return $removed;
    }

    private function driver(): ?string
    {
        $driver = config('queue.connections.'.config('queue.default').'.driver');
        if ($driver === 'database' && ! Schema::hasTable($this->table())) {
            return null;
        }

        return is_string($driver) ? $driver : null;
    }

    private function table(): string
    {
        return (string) config('queue.connections.database.table', 'jobs');
    }

    private function redis(): mixed
    {
        return Redis::connection(config('queue.connections.'.config('queue.default').'.connection', 'default'));
    }

    private function redisKey(): string
    {
        return 'queues:'.config('queue.connections.'.config('queue.default').'.queue', 'default');
    }

    private function itemIdOf(string $payload): ?int
    {
        $command = json_decode($payload, true)['data']['command'] ?? '';

        return is_string($command) && preg_match('/s:6:"itemId";i:(\d+);/', $command, $match) === 1 ? (int) $match[1] : null;
    }

    /** @return list<int> */
    private function itemIdsInQueue(string $table): array
    {
        $ids = [];
        foreach (DB::table($table)->limit(500)->pluck('payload') as $payload) {
            if (($id = $this->itemIdOf((string) $payload)) !== null) {
                $ids[] = $id;
            }
        }

        return $ids;
    }
}
