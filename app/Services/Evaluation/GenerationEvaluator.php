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

    /**
     * Gera explicações para pares com interação registrada e evidência de bula. Cada par avaliado fica no registro
     * (`pairs`, com os trechos usados), para que outra execução, com outro modelo, repita exatamente os mesmos pares
     * (`$reference`): comparação controlada, com a mesma recuperação e o mesmo gabarito, e com a checagem de que os
     * trechos recuperados foram os mesmos.
     *
     * @param  list<array{a: string, b: string, evidence_chunk_ids?: list<int>}>|null  $reference  pares de uma execução anterior
     * @return array<string, mixed>
     */
    public function run(string $datasetPath, string $retrievalMode, ?int $limit, int $seed = 42, ?array $reference = null): array
    {
        config(['bulai.generation.enabled' => true]);
        $statuses = [];
        $reasons = [];
        $accepted = $rejected = $inputTokens = $outputTokens = $examined = $milliseconds = 0;
        $pairsWithEvidence = 0;

        $pairs = [];
        $sameEvidence = 0;
        if ($reference !== null) {
            $items = array_map(fn (array $pair): array => ['a_registration' => $pair['a'], 'b_registration' => $pair['b'], 'reference_chunks' => $pair['evidence_chunk_ids'] ?? null], $reference);
        } else {
            $dataset = json_decode((string) File::get($datasetPath), true, flags: JSON_THROW_ON_ERROR);
            $items = Sampling::seeded(array_values(array_filter($dataset['items'], fn (array $item): bool => $item['gold_interaction'])), null, $seed);
        }
        foreach ($items as $item) {
            if ($reference === null && $limit !== null && $examined >= $limit) {
                continue;
            }
            $result = $this->analyzer->analyze($this->snapshot($item['a_registration']), $this->snapshot($item['b_registration']), $retrievalMode);
            $chunks = array_values(array_map('intval', array_filter(array_column($result['evidences'] ?? [], 'chunk_id'), fn ($id): bool => $id !== null)));
            sort($chunks);
            // Repetição controlada: o par entra mesmo sem evidência agora, e conta se os trechos foram os mesmos da referência.
            if ($reference === null && (! in_array($result['verdict'], [PairVerdict::InteractionFound, PairVerdict::ContradictoryEvidence], true) || empty($result['evidences']))) {
                continue;
            }
            if (isset($item['reference_chunks'])) {
                $expected = array_map('intval', $item['reference_chunks']);
                sort($expected);
                $sameEvidence += $expected === $chunks ? 1 : 0;
            }
            $pairs[] = ['a' => $item['a_registration'], 'b' => $item['b_registration'], 'evidence_chunk_ids' => $chunks, 'status' => $result['generation']['status'] ?? null];
            if (empty($result['evidences'])) {
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
            'pairs' => $pairs,
            'controlled' => $reference !== null ? ['reference_pairs' => count($reference), 'same_evidence' => $sameEvidence] : null,
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
