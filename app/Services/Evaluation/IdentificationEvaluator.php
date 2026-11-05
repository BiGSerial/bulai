<?php

namespace App\Services\Evaluation;

use App\Models\Knowledge\AnvisaMedicine;
use App\Services\Interactions\MedicationSearchService;
use Illuminate\Support\Facades\File;

/** Avalia (1) se a busca devolve o produto digitado e (2) se o produto é ligado aos princípios ativos esperados. */
final class IdentificationEvaluator
{
    public function __construct(private readonly MedicationSearchService $search) {}

    /** @return array<string, mixed> */
    public function run(string $datasetPath): array
    {
        $dataset = json_decode((string) File::get($datasetPath), true, flags: JSON_THROW_ON_ERROR);
        $outcomes = ['exact' => 0, 'partial' => 0, 'wrong' => 0, 'unresolved' => 0, 'product_missing' => 0];
        $tp = $fp = $fn = 0;
        $searchTop1 = $searchTop5 = 0;
        $errors = [];

        foreach ($dataset['items'] as $item) {
            $medicine = AnvisaMedicine::query()->with('ingredients')->where('registration_number', $item['registration_number'])->first();
            if ($medicine === null) {
                $outcomes['product_missing']++;

                continue;
            }

            $names = array_map(fn (array $group): string => $group['name'], $this->search->search($item['query'], 5));
            $searchTop1 += ($names[0] ?? null) === $medicine->product_name ? 1 : 0;
            $searchTop5 += in_array($medicine->product_name, $names, true) ? 1 : 0;

            $predicted = $medicine->ingredients->pluck('normalized_name')->sort()->values()->all();
            $expected = collect($item['expected_ingredients'])->sort()->values()->all();
            $hit = count(array_intersect($predicted, $expected));
            $tp += $hit;
            $fp += count($predicted) - $hit;
            $fn += count($expected) - $hit;

            $outcome = match (true) {
                $predicted === [] => 'unresolved',
                $predicted === $expected => 'exact',
                $hit > 0 => 'partial',
                default => 'wrong',
            };
            $outcomes[$outcome]++;
            if ($outcome !== 'exact') {
                $errors[] = ['query' => $item['query'], 'product' => $medicine->product_name, 'expected' => $expected, 'predicted' => $predicted, 'outcome' => $outcome];
            }
        }

        $total = count($dataset['items']);

        return [
            'items' => $total,
            'outcomes' => $outcomes,
            'exact_match_rate' => Metrics::round($total > 0 ? $outcomes['exact'] / $total : null),
            'ambiguity_or_unresolved_rate' => Metrics::round($total > 0 ? $outcomes['unresolved'] / $total : null),
            'ingredient_level' => ['tp' => $tp, 'fp' => $fp, 'fn' => $fn] + array_map(fn (?float $v): ?float => Metrics::round($v), Metrics::prf($tp, $fp, $fn)),
            'search' => ['top1_rate' => Metrics::round($total > 0 ? $searchTop1 / $total : null), 'top5_rate' => Metrics::round($total > 0 ? $searchTop5 / $total : null)],
            'errors' => $errors,
        ];
    }
}
