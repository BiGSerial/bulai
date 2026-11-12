<?php

namespace App\Services\Audit;

use App\Services\Analytics\AnalyticsService;
use App\Services\Analytics\KnowledgeInventoryService;
use App\Services\Evaluation\Metrics;
use Carbon\CarbonInterface;

/**
 * Dossiê para o TCC: junta, em uma resposta, tudo o que o trabalho propõe medir: tamanho da base, tempos e quantidades do
 * pipeline e do uso, embeddings, qualidade e coerência do que foi gravado, fidelidade das citações, legibilidade das
 * respostas e o quadro de experimentos reproduzíveis. Cada bloco também tem seu próprio endpoint (docs/api.md).
 */
final class TccDossierService
{
    public function __construct(
        private readonly KnowledgeInventoryService $inventory,
        private readonly AnalyticsService $analytics,
        private readonly PipelineTimingService $pipeline,
        private readonly EmbeddingAuditService $embeddings,
        private readonly DataQualityAuditService $quality,
        private readonly ReadabilityService $readability,
        private readonly EvaluationRunsService $evaluations,
    ) {}

    /** @return array<string, mixed> */
    public function build(?CarbonInterface $from = null, ?CarbonInterface $to = null): array
    {
        $overview = AuditSection::run(fn (): array => $this->analytics->overview($from, $to));
        $usage = AuditSection::failed($overview) ? ['usage' => $overview, 'grounding' => $overview] : [
            'usage' => [
                'consultations' => $this->usage($overview['consultations'], $overview['consultations']['by_status']['COMPLETED'] ?? 0),
                'questions' => $this->usage($overview['questions'], $overview['questions']['by_status']['answered'] ?? 0),
                'leaflets' => ['total' => $overview['leaflets']['total'], 'views' => $overview['leaflets']['views'], 'cache_hits' => $overview['leaflets']['cache_hits'], 'duration_ms' => $overview['leaflets']['duration']],
                'pairs' => ['total' => $overview['consultations']['pairs'], 'by_verdict' => $overview['pairs']['by_verdict'], 'by_severity' => $overview['pairs']['by_severity'], 'timings_ms' => $overview['pairs']['timings']],
                'ai' => ['calls' => $overview['ai']['calls'], 'failed' => $overview['ai']['failed'], 'input_tokens' => $overview['ai']['input_tokens'], 'output_tokens' => $overview['ai']['output_tokens'], 'estimated_cost_usd' => $overview['ai']['estimated_cost_usd'], 'latency_avg_ms' => $overview['ai']['latency_avg_ms'], 'latency_p95_ms' => $overview['ai']['latency_p95_ms']],
            ],
            'grounding' => [
                'pairs' => $this->grounding($overview['pairs']['grounding']),
                'questions' => $this->grounding($overview['questions']['grounding']),
                'leaflets' => $this->grounding($overview['leaflets']['grounding']),
            ],
        ];

        return [
            'generated_at' => now()->toIso8601String(),
            'environment' => ['app_env' => config('app.env'), 'timezone' => config('app.timezone'), 'retrieval_mode' => config('bulai.retrieval.mode'), 'text_provider' => config('ai.text.default'), 'embedding_provider' => config('ai.embedding.default')],
            'period' => AuditSection::failed($overview) ? null : $overview['period'],
            'knowledge_base' => AuditSection::run(fn (): array => $this->inventory->inventory()),
            ...$usage,
            'ingestion_timings' => AuditSection::run(fn (): array => $this->pipeline->timings()),
            'embeddings' => AuditSection::run(function (): array {
                $embeddings = $this->embeddings->audit();

                return ['coverage' => $embeddings['coverage'], 'summary' => $embeddings['summary'], 'failed_checks' => array_values(array_filter($embeddings['checks'], fn (array $check): bool => ! $check['ok'])), 'checks_not_run' => $embeddings['checks_not_run'], 'coherence' => $embeddings['coherence']];
            }),
            'data_quality' => AuditSection::run(function (): array {
                $quality = $this->quality->audit();
                $completed = array_filter($quality['groups'], fn (array $checks): bool => ! AuditSection::failed($checks));

                return ['summary' => $quality['summary'], 'failed_checks' => array_values(array_filter(array_merge([], ...array_values($completed)), fn (array $check): bool => ! $check['ok'])), 'failed_groups' => array_diff_key($quality['groups'], $completed), 'citations' => $quality['citations'], 'chunks' => $quality['chunks']];
            }),
            'readability' => AuditSection::run(fn (): array => $this->readability->answers()),
            'experiments' => AuditSection::run(fn (): array => $this->evaluations->experiments()),
            'note' => 'Métricas sobre dados reais do ambiente consultado; não substituem a validação clínica nem a avaliação de usabilidade com pessoas (docs/tcc/22).',
        ];
    }

    /**
     * @param  array{total: int, duration: array{avg: float|null, p50: float|null, p95: float|null}, distinct_users: int}  $block
     * @return array<string, mixed>
     */
    private function usage(array $block, int $successful): array
    {
        return ['total' => $block['total'], 'distinct_users' => $block['distinct_users'], 'duration_ms' => $block['duration'], 'success' => Metrics::wilson($successful, $block['total'])];
    }

    /**
     * @param  array{accepted: int, rejected: int, rate: float|null}  $grounding
     * @return array<string, mixed>
     */
    private function grounding(array $grounding): array
    {
        return ['claims_accepted' => $grounding['accepted'], 'claims_rejected' => $grounding['rejected'], 'rate' => Metrics::wilson($grounding['accepted'], $grounding['accepted'] + $grounding['rejected'])];
    }
}
