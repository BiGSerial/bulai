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
        $overview = $this->analytics->overview($from, $to);
        $quality = $this->quality->audit();
        $embeddings = $this->embeddings->audit();

        return [
            'generated_at' => now()->toIso8601String(),
            'environment' => ['app_env' => config('app.env'), 'timezone' => config('app.timezone'), 'retrieval_mode' => config('bulai.retrieval.mode'), 'text_provider' => config('ai.text.default'), 'embedding_provider' => config('ai.embedding.default')],
            'period' => $overview['period'],
            'knowledge_base' => $this->inventory->inventory(),
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
            'ingestion_timings' => $this->pipeline->timings(),
            'embeddings' => ['coverage' => $embeddings['coverage'], 'summary' => $embeddings['summary'], 'failed_checks' => array_values(array_filter($embeddings['checks'], fn (array $check): bool => ! $check['ok'])), 'coherence' => $embeddings['coherence']],
            'data_quality' => ['summary' => $quality['summary'], 'failed_checks' => array_values(array_filter(array_merge(...array_values($quality['groups'])), fn (array $check): bool => ! $check['ok'])), 'citations' => $quality['citations'], 'chunks' => $quality['chunks']],
            'readability' => $this->readability->answers(),
            'experiments' => $this->evaluations->experiments(),
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
