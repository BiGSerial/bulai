<?php

namespace App\Services\Evaluation;

use App\Models\Knowledge\AnvisaMedicine;
use App\Services\Interactions\PairAnalyzer;
use App\Services\Interactions\PairVerdict;
use Illuminate\Support\Facades\File;

/**
 * Mede o comportamento automático da geração sobre pares com interação documentada E evidência recuperada:
 * explicações aceitas, afirmações aprovadas/rejeitadas (sem suporte, recomendação proibida, referência inválida),
 * saídas malformadas e tokens. NÃO mede correção factual clínica: isso exige revisão por profissional de saúde.
 */
final class GenerationEvaluator
{
    public function __construct(private readonly PairAnalyzer $analyzer) {}

    /** @return array<string, mixed> */
    public function run(string $datasetPath, string $retrievalMode, ?int $limit, int $seed = 42): array
    {
        config(['bulai.generation.enabled' => true]);
        $dataset = json_decode((string) File::get($datasetPath), true, flags: JSON_THROW_ON_ERROR);
        $statuses = [];
        $reasons = [];
        $accepted = $rejected = $inputTokens = $outputTokens = $examined = $milliseconds = 0;
        $pairsWithEvidence = 0;

        $positives = array_values(array_filter($dataset['items'], fn (array $item): bool => $item['gold_interaction']));
        foreach (Sampling::seeded($positives, null, $seed) as $item) {
            if ($limit !== null && $examined >= $limit) {
                continue;
            }
            $result = $this->analyzer->analyze($this->snapshot($item['a_registration']), $this->snapshot($item['b_registration']), $retrievalMode);
            if (! in_array($result['verdict'], [PairVerdict::InteractionFound, PairVerdict::ContradictoryEvidence], true) || empty($result['evidences'])) {
                continue;
            }
            $examined++;
            $pairsWithEvidence++;
            $generation = $result['generation'] ?? [];
            $status = $generation['status'] ?? 'unknown';
            $statuses[$status] = ($statuses[$status] ?? 0) + 1;
            $accepted += $generation['claims_accepted'] ?? 0;
            $rejected += $generation['claims_rejected'] ?? 0;
            $inputTokens += $generation['input_tokens'] ?? 0;
            $outputTokens += $generation['output_tokens'] ?? 0;
            $milliseconds += $result['timings']['generation_ms'] ?? 0;
            foreach ($generation['rejection_reasons'] ?? [] as $reason => $count) {
                $reasons[$reason] = ($reasons[$reason] ?? 0) + $count;
            }
        }

        $claims = $accepted + $rejected;

        return [
            'pairs_examined' => $examined, 'pairs_with_evidence' => $pairsWithEvidence, 'generation_status' => $statuses,
            'explanation_accepted_rate' => Metrics::round($examined > 0 ? ($statuses['generated'] ?? 0) / $examined : null),
            'claims' => ['accepted' => $accepted, 'rejected' => $rejected, 'rejection_rate' => Metrics::round($claims > 0 ? $rejected / $claims : null), 'rejection_reasons' => $reasons],
            'tokens' => ['input' => $inputTokens, 'output' => $outputTokens], 'generation_ms_total' => $milliseconds,
            'note' => 'Métricas automáticas de ancoragem e formato; correção factual exige revisão por profissional de saúde.',
        ];
    }

    /** @return array{id: int|null, name: string, ingredients: list<array{id: int, name: string}>} */
    private function snapshot(string $registration): array
    {
        $medicine = AnvisaMedicine::query()->with('ingredients')->where('registration_number', $registration)->firstOrFail();

        return ['id' => $medicine->id, 'name' => $medicine->product_name, 'ingredients' => $medicine->ingredients->map(fn ($i): array => ['id' => $i->id, 'name' => $i->canonical_name])->values()->all()];
    }
}
