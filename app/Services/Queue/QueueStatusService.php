<?php

namespace App\Services\Queue;

use App\Data\Health\ServiceHealth;
use App\Models\Knowledge\IngestionBatchItem;
use App\Models\Knowledge\KnowledgeProcessingEvent;
use App\Services\Health\ServiceHealthChecker;
use App\Services\Knowledge\ProcessingTimeline;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;

/** Fotografia da fila de análise: worker, jobs no banco, itens em andamento/travados e bulas em processamento. */
final class QueueStatusService
{
    public function __construct(
        private readonly WorkerHeartbeat $heartbeat,
        private readonly ProcessingTimeline $timeline,
        private readonly ServiceHealthChecker $health,
    ) {}

    /**
     * `documents`: uma linha por bula com etapa em andamento (linha do tempo do processamento). Na etapa de OCR a página
     * atual vem do `/status` do serviço, casada pelo `correlation_id`; `progress` fica nulo quando não há casamento.
     *
     * @return array{
     *     worker: array{state: string, last_seen_at: ?int, host: ?string, busy: bool},
     *     jobs: array{waiting: int, running: int, failed: int},
     *     items: list<array{id: int, batch_id: int, filename: string, state: string, percent: int, label: string, updated_at: ?string, stale: bool, has_job: bool}>,
     *     stale: int,
     *     documents: list<array{id: int, title: string, step: string, step_label: string, summary: string, started_at: ?string, updated_at: ?string, elapsed_seconds: int, stale: bool, progress: ?array{page: int, total: ?int}}>
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
            'documents' => $this->documents($staleBefore),
        ];
    }

    /**
     * Bulas com etapa em andamento. O worker PHP não vê a página do OCR (a chamada é síncrona): ela vem da última leitura
     * de saúde do serviço, que vale como sinal de vida da etapa enquanto o `correlation_id` for o mesmo.
     *
     * @return list<array{id: int, title: string, step: string, step_label: string, summary: string, started_at: ?string, updated_at: ?string, elapsed_seconds: int, stale: bool, progress: ?array{page: int, total: ?int}}>
     */
    private function documents(DateTimeInterface $staleBefore): array
    {
        $active = $this->timeline->active();
        if ($active->isEmpty()) {
            return [];
        }
        $ocr = $active->contains(fn (KnowledgeProcessingEvent $event): bool => $event->step === KnowledgeProcessingEvent::STEP_OCR) ? $this->health->ocr() : null;
        $rows = [];
        foreach ($active as $event) {
            if ($event->document === null) {
                continue;
            }
            $matched = $this->ocrProgress($event, $ocr);
            $lastSignal = $event->updated_at ?? $event->started_at;
            if ($matched !== null && $ocr?->respondedAt !== null) {
                $lastSignal = max($lastSignal, Carbon::createFromTimestamp($ocr->respondedAt));
            }
            $summary = $event->summary();
            if ($matched !== null) {
                $summary = 'página '.$matched['page'].' de '.$matched['total'].($ocr?->currentSeconds !== null ? ' · '.$ocr->currentSeconds.' s' : '');
            }

            $rows[] = [
                'id' => $event->document->id,
                'title' => (string) $event->document->title,
                'step' => $event->step,
                'step_label' => $event->stepLabel(),
                'summary' => $summary,
                'started_at' => $event->started_at?->toISOString(),
                'updated_at' => $event->updated_at?->toISOString(),
                'elapsed_seconds' => max(0, (int) $event->started_at->diffInSeconds(now())),
                'stale' => $lastSignal !== null && $lastSignal->lt($staleBefore),
                'progress' => $matched,
            ];
        }

        return $rows;
    }

    /** @return ?array{page: int, total: ?int} */
    private function ocrProgress(KnowledgeProcessingEvent $event, ?ServiceHealth $ocr): ?array
    {
        if ($ocr === null || $event->step !== KnowledgeProcessingEvent::STEP_OCR || $ocr->correlationId === null) {
            return null;
        }
        if (($event->detail['correlation_id'] ?? null) !== $ocr->correlationId || $ocr->progress === null) {
            return null;
        }

        return ['page' => $ocr->progress['page'], 'total' => $ocr->progress['total']];
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
