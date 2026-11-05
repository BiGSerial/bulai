<?php

namespace App\Services\Evaluation;

/** Métricas puras (sem acesso a banco), cobertas por testes unitários. */
final class Metrics
{
    /**
     * @param  list<array{gold: string, predicted: string}>  $pairs
     * @param  list<string>  $labels
     * @return array<string, array<string, int>> matriz [gold][predicted]
     */
    public static function confusionMatrix(array $pairs, array $labels): array
    {
        $matrix = [];
        foreach ($labels as $gold) {
            foreach ($labels as $predicted) {
                $matrix[$gold][$predicted] = 0;
            }
        }
        foreach ($pairs as $pair) {
            if (isset($matrix[$pair['gold']][$pair['predicted']])) {
                $matrix[$pair['gold']][$pair['predicted']]++;
            }
        }

        return $matrix;
    }

    /** @return array{precision: float|null, recall: float|null, f1: float|null} */
    public static function prf(int $tp, int $fp, int $fn): array
    {
        $precision = $tp + $fp > 0 ? $tp / ($tp + $fp) : null;
        $recall = $tp + $fn > 0 ? $tp / ($tp + $fn) : null;
        $f1 = $precision !== null && $recall !== null && $precision + $recall > 0 ? 2 * $precision * $recall / ($precision + $recall) : null;

        return ['precision' => $precision, 'recall' => $recall, 'f1' => $f1];
    }

    /**
     * @param  list<string>  $ranked  ids recuperados em ordem
     * @param  list<string>  $relevant  ids relevantes
     */
    public static function precisionAtK(array $ranked, array $relevant, int $k): float
    {
        if ($k <= 0) {
            return 0.0;
        }

        return count(array_intersect(array_slice($ranked, 0, $k), $relevant)) / $k;
    }

    /**
     * @param  list<string>  $ranked
     * @param  list<string>  $relevant
     */
    public static function recallAtK(array $ranked, array $relevant, int $k): ?float
    {
        return $relevant === [] ? null : count(array_intersect(array_slice($ranked, 0, $k), $relevant)) / count($relevant);
    }

    /**
     * @param  list<string>  $ranked
     * @param  list<string>  $relevant
     */
    public static function reciprocalRank(array $ranked, array $relevant): float
    {
        foreach ($ranked as $position => $id) {
            if (in_array($id, $relevant, true)) {
                return 1 / ($position + 1);
            }
        }

        return 0.0;
    }

    /** @param list<float|null> $values */
    public static function mean(array $values): ?float
    {
        $values = array_values(array_filter($values, fn (?float $value): bool => $value !== null));

        return $values === [] ? null : array_sum($values) / count($values);
    }

    public static function round(?float $value, int $precision = 4): ?float
    {
        return $value === null ? null : round($value, $precision);
    }
}
