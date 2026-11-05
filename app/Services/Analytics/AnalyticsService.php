<?php

namespace App\Services\Analytics;

use App\Models\AiUsageLog;
use App\Models\Consultation\InteractionConsultation;
use App\Models\Consultation\InteractionPairResult;
use App\Models\Consultation\MedicationQuestion;
use App\Models\Consultation\MedicineLeafletSummary;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Agregações analíticas sobre tudo o que a IA respondeu ao usuário (consultas de interação, perguntas livres e bulas
 * humanizadas) e sobre o consumo dos provedores de IA. Lê apenas colunas técnicas: nunca nome ou e-mail de usuário.
 *
 * Fórmulas: taxa de ancoragem = claims aceitas / (aceitas + rejeitadas); percentis por `percentile_cont` (PostgreSQL);
 * custo = soma de `estimated_cost_usd` (estimado pelo provedor na hora da chamada, conforme `config/ai.php`).
 */
final class AnalyticsService
{
    /**
     * @return array{
     *   period: array{from: string|null, to: string|null},
     *   consultations: array{total: int, by_status: array<string, int>, pairs: int, distinct_users: int, duration: array{avg: float|null, p50: float|null, p95: float|null}, by_retrieval_mode: array<string, int>, by_text_provider: array<string, int>},
     *   pairs: array{by_verdict: array<string, int>, by_severity: array<string, int>, by_generation_status: array<string, int>, rejection_reasons: array<string, int>, grounding: array{accepted: int, rejected: int, rate: float|null}, timings: array{lookup_ms: float|null, retrieval_ms: float|null, generation_ms: float|null}, with_explanation: int, with_evidence: int, evidence_methods: array<string, int>},
     *   questions: array{total: int, by_status: array<string, int>, distinct_users: int, duration: array{avg: float|null, p50: float|null, p95: float|null}, grounding: array{accepted: int, rejected: int, rate: float|null}, rejection_reasons: array<string, int>, with_treatment_context: int, with_treatment_interaction: int},
     *   leaflets: array{total: int, by_status: array<string, int>, views: int, cache_hits: int, duration: array{avg: float|null, p50: float|null, p95: float|null}, grounding: array{accepted: int, rejected: int, rate: float|null}},
     *   ai: array{calls: int, succeeded: int, failed: int, input_tokens: int, output_tokens: int, estimated_cost_usd: float, latency_avg_ms: float|null, latency_p95_ms: float|null, by_model: list<array{provider: string, model: string|null, capability: string|null, calls: int, input_tokens: int, output_tokens: int, estimated_cost_usd: float, latency_avg_ms: float|null}>, failures_by_error: array<string, int>, daily: list<array{day: string, calls: int, failed: int, input_tokens: int, output_tokens: int, estimated_cost_usd: float}>}
     * }
     */
    public function overview(?CarbonInterface $from = null, ?CarbonInterface $to = null): array
    {
        return [
            'period' => ['from' => $from?->toDateTimeString(), 'to' => $to?->toDateTimeString()],
            'consultations' => $this->consultations($from, $to),
            'pairs' => $this->pairs($from, $to),
            'questions' => $this->questions($from, $to),
            'leaflets' => $this->leaflets($from, $to),
            'ai' => $this->aiUsage($from, $to),
        ];
    }

    /**
     * Linhas anonimizadas (uuid, datas, status e métricas) para exportação: uma por par analisado.
     *
     * @return list<array<string, mixed>>
     */
    public function pairRows(?CarbonInterface $from = null, ?CarbonInterface $to = null): array
    {
        $rows = [];
        $this->period(InteractionPairResult::query()->with('consultation:id,uuid,status,medicines,pipeline,duration_ms,created_at'), $from, $to, 'created_at')
            ->orderBy('id')->chunkById(500, function ($results) use (&$rows): void {
                foreach ($results as $result) {
                    /** @var InteractionPairResult $result */
                    $rows[] = [
                        'consultation_uuid' => $result->consultation?->uuid,
                        'consultation_status' => $result->consultation?->status,
                        'consultation_created_at' => $result->consultation?->created_at?->toIso8601String(),
                        'consultation_duration_ms' => $result->consultation?->duration_ms,
                        'retrieval_mode' => $result->consultation?->pipeline['retrieval_mode'] ?? null,
                        'position' => $result->position,
                        'verdict' => $result->verdict?->value,
                        'severity' => $result->severity?->value,
                        'has_explanation' => $result->explanation !== null,
                        'evidence_count' => count($result->evidences ?? []),
                        'generation_status' => $result->generation['status'] ?? null,
                        'claims_accepted' => $result->generation['claims_accepted'] ?? null,
                        'claims_rejected' => $result->generation['claims_rejected'] ?? null,
                        'input_tokens' => $result->generation['input_tokens'] ?? null,
                        'output_tokens' => $result->generation['output_tokens'] ?? null,
                        'lookup_ms' => $result->timings['lookup_ms'] ?? null,
                        'retrieval_ms' => $result->timings['retrieval_ms'] ?? null,
                        'generation_ms' => $result->timings['generation_ms'] ?? null,
                        'error_code' => $result->error_code,
                    ];
                }
            });

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function questionRows(?CarbonInterface $from = null, ?CarbonInterface $to = null): array
    {
        $rows = [];
        $this->period(MedicationQuestion::query(), $from, $to)->orderBy('id')->chunkById(500, function ($questions) use (&$rows): void {
            foreach ($questions as $question) {
                /** @var MedicationQuestion $question */
                $rows[] = [
                    'question_uuid' => $question->uuid,
                    'created_at' => $question->created_at?->toIso8601String(),
                    'status' => $question->status,
                    'duration_ms' => $question->duration_ms,
                    'retrieval_mode' => $question->retrieval['mode'] ?? ($question->pipeline['retrieval_mode'] ?? null),
                    'evidence_count' => count($question->evidences ?? []),
                    'generation_status' => $question->generation['status'] ?? null,
                    'claims_accepted' => $question->generation['claims_accepted'] ?? null,
                    'claims_rejected' => $question->generation['claims_rejected'] ?? null,
                    'input_tokens' => $question->generation['input_tokens'] ?? null,
                    'output_tokens' => $question->generation['output_tokens'] ?? null,
                    'treatment_consented' => $question->treatment_context['consented'] ?? null,
                    'treatment_in_use' => $question->treatment_context['in_use'] ?? null,
                    'treatment_interactions' => count($question->treatment_context['interactions'] ?? []),
                    'treatment_highest_severity' => $question->treatment_context['highest_severity'] ?? null,
                ];
            }
        });

        return $rows;
    }

    /**
     * @return array{total: int, by_status: array<string, int>, pairs: int, distinct_users: int, duration: array{avg: float|null, p50: float|null, p95: float|null}, by_retrieval_mode: array<string, int>, by_text_provider: array<string, int>}
     */
    private function consultations(?CarbonInterface $from, ?CarbonInterface $to): array
    {
        $base = fn (): Builder => $this->period(DB::table('interaction_consultations'), $from, $to);

        return [
            'total' => $base()->count(),
            'by_status' => $this->groupCount($base(), 'status'),
            'pairs' => $this->pairBase($from, $to)->count(),
            'distinct_users' => (int) $base()->whereNotNull('user_id')->distinct()->count('user_id'),
            'duration' => $this->durationStats($base()->whereNotNull('duration_ms'), 'duration_ms'),
            'by_retrieval_mode' => $this->groupCount($base(), "coalesce(pipeline->>'retrieval_mode', 'desconhecido')", 'retrieval_mode'),
            'by_text_provider' => $this->groupCount($base(), "coalesce(pipeline->>'text_provider', 'nenhum')", 'text_provider'),
        ];
    }

    /**
     * @return array{by_verdict: array<string, int>, by_severity: array<string, int>, by_generation_status: array<string, int>, rejection_reasons: array<string, int>, grounding: array{accepted: int, rejected: int, rate: float|null}, timings: array{lookup_ms: float|null, retrieval_ms: float|null, generation_ms: float|null}, with_explanation: int, with_evidence: int, evidence_methods: array<string, int>}
     */
    private function pairs(?CarbonInterface $from, ?CarbonInterface $to): array
    {
        $base = fn (): Builder => $this->pairBase($from, $to);
        $timings = $base()->selectRaw("avg((p.timings->>'lookup_ms')::numeric) as lookup_ms, avg((p.timings->>'retrieval_ms')::numeric) as retrieval_ms, avg((p.timings->>'generation_ms')::numeric) as generation_ms")->first();

        return [
            'by_verdict' => $this->groupCount($base(), 'p.verdict', 'verdict'),
            'by_severity' => $this->groupCount($base()->whereNotNull('p.severity'), 'p.severity', 'severity'),
            'by_generation_status' => $this->groupCount($base()->whereNotNull('p.generation'), "coalesce(p.generation->>'status', 'desconhecido')", 'generation_status'),
            'rejection_reasons' => $this->jsonObjectSums($base(), 'p.generation', 'rejection_reasons'),
            'grounding' => $this->grounding($base(), 'p.generation'),
            'timings' => [
                'lookup_ms' => $this->float($timings?->lookup_ms),
                'retrieval_ms' => $this->float($timings?->retrieval_ms),
                'generation_ms' => $this->float($timings?->generation_ms),
            ],
            'with_explanation' => $base()->whereNotNull('p.explanation')->count(),
            'with_evidence' => $base()->whereRaw("jsonb_typeof(p.evidences) = 'array' and jsonb_array_length(p.evidences) > 0")->count(),
            'evidence_methods' => $this->evidenceMethods($base()),
        ];
    }

    /**
     * @return array{total: int, by_status: array<string, int>, distinct_users: int, duration: array{avg: float|null, p50: float|null, p95: float|null}, grounding: array{accepted: int, rejected: int, rate: float|null}, rejection_reasons: array<string, int>, with_treatment_context: int, with_treatment_interaction: int}
     */
    private function questions(?CarbonInterface $from, ?CarbonInterface $to): array
    {
        $base = fn (): Builder => $this->period(DB::table('medication_questions'), $from, $to);

        return [
            'total' => $base()->count(),
            'by_status' => $this->groupCount($base(), 'status'),
            'distinct_users' => (int) $base()->whereNotNull('user_id')->distinct()->count('user_id'),
            'duration' => $this->durationStats($base()->whereNotNull('duration_ms'), 'duration_ms'),
            'grounding' => $this->grounding($base(), 'generation'),
            'rejection_reasons' => $this->jsonObjectSums($base(), 'generation', 'rejection_reasons'),
            'with_treatment_context' => $base()->whereRaw("(treatment_context->>'consented')::boolean = true")->count(),
            'with_treatment_interaction' => $base()->whereRaw("jsonb_typeof(treatment_context->'interactions') = 'array' and jsonb_array_length(treatment_context->'interactions') > 0")->count(),
        ];
    }

    /**
     * @return array{total: int, by_status: array<string, int>, views: int, cache_hits: int, duration: array{avg: float|null, p50: float|null, p95: float|null}, grounding: array{accepted: int, rejected: int, rate: float|null}}
     */
    private function leaflets(?CarbonInterface $from, ?CarbonInterface $to): array
    {
        $base = fn (): Builder => $this->period(DB::table('medicine_leaflet_summaries'), $from, $to);
        $total = $base()->count();
        $views = (int) $base()->sum('views');

        return [
            'total' => $total,
            'by_status' => $this->groupCount($base(), 'status'),
            'views' => $views,
            // Cada resumo é gerado uma vez; as demais visualizações são atendidas pelo cache (sem nova chamada ao modelo).
            'cache_hits' => max(0, $views - $total),
            'duration' => $this->durationStats($base()->whereNotNull('duration_ms'), 'duration_ms'),
            'grounding' => $this->grounding($base(), 'generation'),
        ];
    }

    /**
     * @return array{calls: int, succeeded: int, failed: int, input_tokens: int, output_tokens: int, estimated_cost_usd: float, latency_avg_ms: float|null, latency_p95_ms: float|null, by_model: list<array{provider: string, model: string|null, capability: string|null, calls: int, input_tokens: int, output_tokens: int, estimated_cost_usd: float, latency_avg_ms: float|null}>, failures_by_error: array<string, int>, daily: list<array{day: string, calls: int, failed: int, input_tokens: int, output_tokens: int, estimated_cost_usd: float}>}
     */
    private function aiUsage(?CarbonInterface $from, ?CarbonInterface $to): array
    {
        $base = fn (): Builder => $this->period(DB::table('ai_usage_logs'), $from, $to);
        $totals = $base()->selectRaw('count(*) as calls, coalesce(sum(input_tokens), 0) as input_tokens, coalesce(sum(output_tokens), 0) as output_tokens, coalesce(sum(estimated_cost_usd), 0) as cost, avg(latency_ms) as latency_avg, percentile_cont(0.95) within group (order by latency_ms) as latency_p95')->first();

        $byModel = $base()->selectRaw('provider, model, capability, count(*) as calls, coalesce(sum(input_tokens), 0) as input_tokens, coalesce(sum(output_tokens), 0) as output_tokens, coalesce(sum(estimated_cost_usd), 0) as cost, avg(latency_ms) as latency_avg')
            ->groupBy('provider', 'model', 'capability')->orderByDesc('calls')->get()
            ->map(fn (object $row): array => [
                'provider' => (string) $row->provider, 'model' => $row->model, 'capability' => $row->capability,
                'calls' => (int) $row->calls, 'input_tokens' => (int) $row->input_tokens, 'output_tokens' => (int) $row->output_tokens,
                'estimated_cost_usd' => (float) $row->cost, 'latency_avg_ms' => $this->float($row->latency_avg),
            ])->values()->all();

        $daily = DB::table('ai_usage_logs')->where('created_at', '>=', now()->subDays(29)->startOfDay())
            ->selectRaw("to_char(created_at, 'YYYY-MM-DD') as day, count(*) as calls, sum(case when status = ? then 1 else 0 end) as failed, coalesce(sum(input_tokens), 0) as input_tokens, coalesce(sum(output_tokens), 0) as output_tokens, coalesce(sum(estimated_cost_usd), 0) as cost", [AiUsageLog::STATUS_FAILED])
            ->groupBy('day')->orderByDesc('day')->get()
            ->map(fn (object $row): array => ['day' => (string) $row->day, 'calls' => (int) $row->calls, 'failed' => (int) $row->failed, 'input_tokens' => (int) $row->input_tokens, 'output_tokens' => (int) $row->output_tokens, 'estimated_cost_usd' => (float) $row->cost])
            ->values()->all();

        return [
            'calls' => (int) ($totals?->calls ?? 0),
            'succeeded' => $base()->where('status', AiUsageLog::STATUS_SUCCESS)->count(),
            'failed' => $base()->where('status', AiUsageLog::STATUS_FAILED)->count(),
            'input_tokens' => (int) ($totals?->input_tokens ?? 0),
            'output_tokens' => (int) ($totals?->output_tokens ?? 0),
            'estimated_cost_usd' => (float) ($totals?->cost ?? 0),
            'latency_avg_ms' => $this->float($totals?->latency_avg),
            'latency_p95_ms' => $this->float($totals?->latency_p95),
            'by_model' => $byModel,
            'failures_by_error' => $this->groupCount($base()->where('status', AiUsageLog::STATUS_FAILED), "coalesce(error_code, 'desconhecido')", 'error_code'),
            'daily' => $daily,
        ];
    }

    private function pairBase(?CarbonInterface $from, ?CarbonInterface $to): Builder
    {
        return $this->period(DB::table('interaction_pair_results as p')->join('interaction_consultations as c', 'c.id', '=', 'p.consultation_id'), $from, $to, 'c.created_at');
    }

    /**
     * @template TBuilder of Builder|\Illuminate\Database\Eloquent\Builder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    private function period(Builder|\Illuminate\Database\Eloquent\Builder $query, ?CarbonInterface $from, ?CarbonInterface $to, string $column = 'created_at'): Builder|\Illuminate\Database\Eloquent\Builder
    {
        if ($from !== null) {
            $query->where($column, '>=', $from);
        }
        if ($to !== null) {
            $query->where($column, '<=', $to);
        }

        return $query;
    }

    /**
     * Contagem por valor de uma coluna ou expressão SQL (sem entrada do usuário).
     *
     * @return array<string, int>
     */
    private function groupCount(Builder $query, string $expression, ?string $alias = null): array
    {
        $alias ??= str_contains($expression, '.') ? substr($expression, strrpos($expression, '.') + 1) : $expression;

        return $query->selectRaw("{$expression} as {$alias}, count(*) as total")->groupByRaw($expression)->orderByDesc('total')->orderBy($alias)->get()
            ->mapWithKeys(fn (object $row): array => [(string) ($row->{$alias} ?? 'desconhecido') => (int) $row->total])->all();
    }

    /** @return array{avg: float|null, p50: float|null, p95: float|null} */
    private function durationStats(Builder $query, string $column): array
    {
        $row = $query->selectRaw("avg({$column}) as avg, percentile_cont(0.5) within group (order by {$column}) as p50, percentile_cont(0.95) within group (order by {$column}) as p95")->first();

        return ['avg' => $this->float($row?->avg), 'p50' => $this->float($row?->p50), 'p95' => $this->float($row?->p95)];
    }

    /** @return array{accepted: int, rejected: int, rate: float|null} */
    private function grounding(Builder $query, string $jsonColumn): array
    {
        $row = $query->selectRaw("coalesce(sum(({$jsonColumn}->>'claims_accepted')::int), 0) as accepted, coalesce(sum(({$jsonColumn}->>'claims_rejected')::int), 0) as rejected")->first();
        $accepted = (int) ($row?->accepted ?? 0);
        $rejected = (int) ($row?->rejected ?? 0);

        return ['accepted' => $accepted, 'rejected' => $rejected, 'rate' => $accepted + $rejected > 0 ? round($accepted / ($accepted + $rejected), 4) : null];
    }

    /**
     * Soma os valores de um objeto JSON (ex.: `generation->rejection_reasons` = {"motivo": n}) por chave.
     *
     * @return array<string, int>
     */
    private function jsonObjectSums(Builder $query, string $jsonColumn, string $key): array
    {
        return $query->whereRaw("jsonb_typeof({$jsonColumn}->'{$key}') = 'object'")
            ->crossJoin(DB::raw("jsonb_each_text({$jsonColumn}->'{$key}') as reasons(reason, total)"))
            ->selectRaw('reasons.reason as reason, sum(reasons.total::int) as total')->groupBy('reasons.reason')->orderByDesc('total')->get()
            ->mapWithKeys(fn (object $row): array => [(string) $row->reason => (int) $row->total])->all();
    }

    /** @return array<string, int> */
    private function evidenceMethods(Builder $query): array
    {
        return $query->whereRaw("jsonb_typeof(p.evidences) = 'array'")
            ->crossJoin(DB::raw('jsonb_array_elements(p.evidences) as evidence'))
            ->selectRaw("coalesce(evidence->>'method', 'desconhecido') as method, count(*) as total")->groupByRaw("coalesce(evidence->>'method', 'desconhecido')")->orderByDesc('total')->get()
            ->mapWithKeys(fn (object $row): array => [(string) $row->method => (int) $row->total])->all();
    }

    private function float(mixed $value): ?float
    {
        return $value === null ? null : round((float) $value, 1);
    }

    /**
     * Consultas mais recentes (anonimizadas) para a listagem administrativa.
     *
     * @return LengthAwarePaginator<int, InteractionConsultation>
     */
    public function recentConsultations(?string $status = null, int $perPage = 25): LengthAwarePaginator
    {
        return InteractionConsultation::query()->withPairCount()->when($status, fn ($query) => $query->where('status', $status))->latest('id')->paginate($perPage);
    }

    /**
     * @return LengthAwarePaginator<int, MedicationQuestion>
     */
    public function recentQuestions(?string $status = null, int $perPage = 25): LengthAwarePaginator
    {
        return MedicationQuestion::query()->when($status, fn ($query) => $query->where('status', $status))->latest('id')->paginate($perPage);
    }

    /**
     * @return LengthAwarePaginator<int, MedicineLeafletSummary>
     */
    public function recentLeaflets(?string $status = null, int $perPage = 25): LengthAwarePaginator
    {
        return MedicineLeafletSummary::query()->when($status, fn ($query) => $query->where('status', $status))->latest('id')->paginate($perPage);
    }
}
