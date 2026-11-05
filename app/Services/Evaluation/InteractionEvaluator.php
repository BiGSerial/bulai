<?php

namespace App\Services\Evaluation;

use App\Models\Knowledge\AnvisaMedicine;
use App\Services\Interactions\InteractionSeverity;
use App\Services\Interactions\PairAnalyzer;
use App\Services\Interactions\PairVerdict;
use Illuminate\Support\Facades\File;

/**
 * Avalia o veredito do sistema por par de produtos contra o rótulo do dataset. Abstenções (inconclusivo, medicamento
 * não identificado, erro técnico) ficam fora da matriz binária e são reportadas à parte, junto com o recall conservador
 * que as trata como erro.
 */
final class InteractionEvaluator
{
    public function __construct(private readonly PairAnalyzer $analyzer) {}

    /** @return array<string, mixed> */
    public function run(string $datasetPath, string $retrievalMode, ?int $limit = null, bool $withGeneration = false): array
    {
        config(['bulai.generation.enabled' => $withGeneration]);
        $dataset = json_decode((string) File::get($datasetPath), true, flags: JSON_THROW_ON_ERROR);
        $items = array_slice($dataset['items'], 0, $limit);

        $binary = ['tp' => 0, 'fp' => 0, 'tn' => 0, 'fn' => 0];
        $abstained = ['by_verdict' => [], 'gold_positive' => 0, 'gold_negative' => 0];
        $severityPairs = [];
        $missedMajor = [];
        $falsePositives = [];
        $verdicts = [];
        $cache = [];

        foreach ($items as $item) {
            $a = $cache[$item['a_registration']] ??= $this->snapshot($item['a_registration']);
            $b = $cache[$item['b_registration']] ??= $this->snapshot($item['b_registration']);
            $result = $this->analyzer->analyze($a, $b, $retrievalMode);
            $verdict = $result['verdict'];
            $verdicts[$verdict->value] = ($verdicts[$verdict->value] ?? 0) + 1;

            if (! $verdict->isConclusive() && $verdict !== PairVerdict::ContradictoryEvidence) {
                $abstained['by_verdict'][$verdict->value] = ($abstained['by_verdict'][$verdict->value] ?? 0) + 1;
                $abstained[$item['gold_interaction'] ? 'gold_positive' : 'gold_negative']++;
                if ($item['gold_severity'] === 'major') {
                    $missedMajor[] = ['a' => $item['a_name'], 'b' => $item['b_name'], 'reason' => 'abstention:'.$verdict->value];
                }

                continue;
            }

            $predictedPositive = $verdict !== PairVerdict::NotFoundInSources;
            $key = match (true) {
                $item['gold_interaction'] && $predictedPositive => 'tp',
                $item['gold_interaction'] => 'fn',
                $predictedPositive => 'fp',
                default => 'tn',
            };
            $binary[$key]++;
            if ($key === 'fn' && $item['gold_severity'] === 'major') {
                $missedMajor[] = ['a' => $item['a_name'], 'b' => $item['b_name'], 'reason' => 'false_negative'];
            }
            if ($key === 'fp') {
                $falsePositives[] = ['a' => $item['a_name'], 'b' => $item['b_name'], 'predicted_severity' => $result['severity']?->value];
            }
            if ($key === 'tp' && $item['gold_severity'] !== 'unknown') {
                $severityPairs[] = ['gold' => $item['gold_severity'], 'predicted' => ($result['severity'] ?? InteractionSeverity::Unknown)->value];
            }
        }

        $labels = ['major', 'moderate', 'minor', 'unknown'];
        $answered = array_sum($binary);
        $goldPositive = $binary['tp'] + $binary['fn'] + $abstained['gold_positive'];

        return [
            'items' => count($items),
            'verdict_distribution' => $verdicts,
            'binary' => $binary + array_map(fn (?float $v): ?float => Metrics::round($v), Metrics::prf($binary['tp'], $binary['fp'], $binary['fn'])),
            'abstention' => ['rate' => Metrics::round(count($items) > 0 ? ($abstained['gold_positive'] + $abstained['gold_negative']) / count($items) : null)] + $abstained,
            'conservative_recall' => Metrics::round($goldPositive > 0 ? $binary['tp'] / $goldPositive : null),
            'answered' => $answered,
            'severity_confusion_matrix' => Metrics::confusionMatrix($severityPairs, $labels),
            'severity_agreement' => Metrics::round($severityPairs === [] ? null : count(array_filter($severityPairs, fn (array $p): bool => $p['gold'] === $p['predicted'])) / count($severityPairs)),
            'major_interactions_missed' => $missedMajor,
            'false_positives' => array_slice($falsePositives, 0, 25),
        ];
    }

    /** @return array{id: int|null, name: string, ingredients: list<array{id: int, name: string}>} */
    private function snapshot(string $registration): array
    {
        $medicine = AnvisaMedicine::query()->with('ingredients')->where('registration_number', $registration)->firstOrFail();

        return ['id' => $medicine->id, 'name' => $medicine->product_name, 'ingredients' => $medicine->ingredients->map(fn ($i): array => ['id' => $i->id, 'name' => $i->canonical_name])->values()->all()];
    }
}
