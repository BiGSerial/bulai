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

    /** Nome das tarefas, em português, para a lista da fila. */
    private const TASK_LABELS = [
        'ProcessKnowledgeDocument' => 'Processar bula',
        'IndexKnowledgeDocumentEmbeddings' => 'Indexar bula para a busca',
        'LinkKnowledgeDocumentIngredients' => 'Vincular princípios ativos',
        'ClassifyPendingDocument' => 'Analisar arquivo importado',
        'ReanalyzePendingKnowledgeBatch' => 'Reanalisar importação',
        'BuildActiveIngredientCatalog' => 'Montar catálogo de princípios ativos',
        'ImportAnvisaCatalog' => 'Importar catálogo ANVISA',
        'ImportDrugInteractions' => 'Importar interações (DDInter)',
        'GenerateReport' => 'Gerar relatório',
        'RunInteractionConsultation' => 'Consulta de interações de um paciente',
        'ExtractPrescription' => 'Ler receita por foto',
        'ImportExternalLabel' => 'Importar bula dos EUA (FDA)',
    ];

    /** Quantas tarefas à espera a lista mostra (o total vem à parte). */
    private const TASKS_SHOWN = 200;

    /**
     * Cada tarefa da fila, uma por linha: as que estão rodando agora e as que esperam, na ordem em que serão processadas,
     * com o assunto (título da bula ou nome do arquivo). Tarefas de pacientes (consulta, receita) mostram só o número
     * interno, nunca dados do paciente.
     *
     * @return array{running: list<array<string, mixed>>, waiting: list<array<string, mixed>>, waiting_total: int}
     */
    public function tasks(): array
    {
        $running = [];
        $waiting = [];
        $total = 0;
        $driver = $this->driver();
        if ($driver === 'redis') {
            $redis = $this->redis();
            $retryAfter = (int) config('queue.connections.'.config('queue.default').'.retry_after', 90);
            foreach ($this->queueNames() as $queue) {
                $key = 'queues:'.$queue;
                foreach ((array) $redis->zrange($key.':reserved', 0, -1, ['withscores' => true]) as $payload => $expiresAt) {
                    $running[] = $this->task((string) $payload, 'running', startedAt: (int) $expiresAt - $retryAfter);
                }
                $total += (int) $redis->llen($key) + (int) $redis->zcard($key.':delayed');
                foreach ((array) $redis->lrange($key, 0, self::TASKS_SHOWN - 1) as $payload) {
                    $waiting[] = $this->task((string) $payload, 'waiting');
                }
                foreach ((array) $redis->zrange($key.':delayed', 0, self::TASKS_SHOWN - 1, ['withscores' => true]) as $payload => $availableAt) {
                    $waiting[] = $this->task((string) $payload, 'delayed', availableAt: (int) $availableAt);
                }
            }
        } elseif ($driver === 'database') {
            $table = $this->table();
            foreach (DB::table($table)->whereNotNull('reserved_at')->orderBy('reserved_at')->get(['payload', 'reserved_at']) as $row) {
                $running[] = $this->task((string) $row->payload, 'running', startedAt: (int) $row->reserved_at);
            }
            $total = DB::table($table)->whereNull('reserved_at')->count();
            foreach (DB::table($table)->whereNull('reserved_at')->orderBy('available_at')->orderBy('id')->limit(self::TASKS_SHOWN)->get(['payload', 'available_at']) as $row) {
                $delayed = (int) $row->available_at > now()->getTimestamp();
                $waiting[] = $this->task((string) $row->payload, $delayed ? 'delayed' : 'waiting', availableAt: $delayed ? (int) $row->available_at : null);
            }
        }

        return ['running' => $this->withSubjects($running), 'waiting' => $this->withSubjects(array_slice($waiting, 0, self::TASKS_SHOWN)), 'waiting_total' => $total];
    }

    /** @return array<string, mixed> */
    private function task(string $payload, string $state, ?int $startedAt = null, ?int $availableAt = null): array
    {
        $decoded = json_decode($payload, true);
        $decoded = is_array($decoded) ? $decoded : [];
        $class = class_basename((string) ($decoded['displayName'] ?? 'Tarefa'));
        $command = (string) ($decoded['data']['command'] ?? '');
        $id = fn (string $property): ?int => preg_match('/s:'.strlen($property).':"'.$property.'";i:(\d+);/', $command, $match) === 1 ? (int) $match[1] : null;

        return [
            'uuid' => (string) ($decoded['uuid'] ?? md5($payload)),
            'job' => $class,
            'label' => self::TASK_LABELS[$class] ?? $class,
            'state' => $state,
            'document_id' => $id('documentId'),
            'item_id' => $id('itemId'),
            'ingredient_id' => $id('ingredientId'),
            'reference' => $id('consultationId') ?? $id('prescriptionId') ?? $id('reportId') ?? $id('importId') ?? $id('catalogImportId') ?? $id('batchId'),
            'attempts' => (int) ($decoded['attempts'] ?? 0),
            'queued_at' => isset($decoded['createdAt']) ? (int) $decoded['createdAt'] : null,
            'started_at' => $startedAt,
            'available_at' => $availableAt,
            'subject' => null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $tasks
     * @return list<array<string, mixed>>
     */
    private function withSubjects(array $tasks): array
    {
        $documents = DB::table('knowledge_documents')->whereIn('id', array_filter(array_column($tasks, 'document_id')))->pluck('title', 'id');
        $files = DB::table('ingestion_batch_items')->whereIn('id', array_filter(array_column($tasks, 'item_id')))->pluck('original_filename', 'id');
        $ingredients = DB::table('active_ingredients')->whereIn('id', array_filter(array_column($tasks, 'ingredient_id')))->pluck('canonical_name', 'id');

        return array_map(function (array $task) use ($documents, $files, $ingredients): array {
            $task['subject'] = match (true) {
                $task['document_id'] !== null => (string) ($documents[$task['document_id']] ?? 'Documento #'.$task['document_id']),
                $task['item_id'] !== null => (string) ($files[$task['item_id']] ?? 'Arquivo #'.$task['item_id']),
                $task['ingredient_id'] !== null => (string) ($ingredients[$task['ingredient_id']] ?? 'Princípio ativo #'.$task['ingredient_id']),
                $task['reference'] !== null => '#'.$task['reference'],
                default => '—',
            };

            return $task;
        }, $tasks);
    }

    /** @return list<string> */
    private function queueNames(): array
    {
        return array_values(array_unique(array_filter([
            (string) config('queue.connections.'.config('queue.default').'.queue', 'default'),
            (string) config('bulai.consultation.queue'),
        ])));
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
