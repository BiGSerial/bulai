<?php

namespace App\Services\Audit;

use Illuminate\Support\Facades\DB;

/**
 * Quanto tempo e quantas execuções cada etapa do pipeline de ingestão gasta (linha do tempo de `knowledge_processing_events`):
 * por etapa (execuções, falhas, média, p50, p95, máximo) e por documento (da primeira à última etapa). Só leitura.
 */
final class PipelineTimingService
{
    /** @return array{generated_at: string, steps: list<array<string, mixed>>, documents: array<string, mixed>, failures: list<array<string, mixed>>} */
    public function timings(): array
    {
        $duration = 'coalesce(duration_ms, extract(epoch from (finished_at - started_at)) * 1000)';
        $steps = DB::table('knowledge_processing_events')->selectRaw("step, count(*) as runs, count(*) filter (where state = 'failed') as failed, count(*) filter (where state = 'running') as running, avg({$duration}) as avg_ms, percentile_cont(0.5) within group (order by {$duration}) as p50_ms, percentile_cont(0.95) within group (order by {$duration}) as p95_ms, max({$duration}) as max_ms")
            ->groupBy('step')->orderByRaw('min(started_at)')->get()->map(fn (object $row): array => [
                'step' => $row->step, 'runs' => (int) $row->runs, 'failed' => (int) $row->failed, 'running' => (int) $row->running,
                'avg_ms' => $this->round($row->avg_ms), 'p50_ms' => $this->round($row->p50_ms), 'p95_ms' => $this->round($row->p95_ms), 'max_ms' => $this->round($row->max_ms),
            ])->all();

        $perDocument = DB::selectOne('select count(*) as documents, avg(span) as avg_ms, percentile_cont(0.5) within group (order by span) as p50_ms, percentile_cont(0.95) within group (order by span) as p95_ms, max(span) as max_ms from (select extract(epoch from (max(coalesce(finished_at, started_at)) - min(started_at))) * 1000 as span from knowledge_processing_events group by document_id) as t');
        $chunks = DB::selectOne('select count(*) as chunks, count(distinct document_id) as documents from knowledge_chunks');

        return [
            'generated_at' => now()->toIso8601String(),
            'steps' => $steps,
            'documents' => [
                'with_timeline' => (int) $perDocument->documents,
                'total_span_avg_ms' => $this->round($perDocument->avg_ms), 'total_span_p50_ms' => $this->round($perDocument->p50_ms),
                'total_span_p95_ms' => $this->round($perDocument->p95_ms), 'total_span_max_ms' => $this->round($perDocument->max_ms),
                'chunks_indexed' => (int) $chunks->chunks,
                'chunks_per_document_avg' => (int) $chunks->documents > 0 ? round((int) $chunks->chunks / (int) $chunks->documents, 1) : null,
            ],
            'failures' => DB::table('knowledge_processing_events')->where('state', 'failed')->orderByDesc('id')->limit(20)->get(['document_id', 'step', 'started_at', 'detail'])
                ->map(fn (object $row): array => ['document_id' => (int) $row->document_id, 'step' => $row->step, 'at' => $row->started_at, 'error_code' => json_decode((string) $row->detail, true)['error_code'] ?? null])->all(),
        ];
    }

    private function round(mixed $value): ?float
    {
        return $value === null ? null : round((float) $value, 1);
    }
}
