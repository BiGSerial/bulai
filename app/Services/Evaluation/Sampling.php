<?php

namespace App\Services\Evaluation;

/** Amostragem determinística (embaralhamento com semente) para limitar execuções caras sem viés de ordem do dataset. */
final class Sampling
{
    /**
     * @template T
     *
     * @param  list<T>  $items
     * @return list<T>
     */
    public static function seeded(array $items, ?int $limit, int $seed): array
    {
        if ($limit === null || $limit >= count($items)) {
            return $items;
        }

        $keyed = [];
        foreach ($items as $index => $item) {
            $keyed[] = [hash('crc32b', $seed.':'.$index), $item];
        }
        usort($keyed, fn (array $a, array $b): int => strcmp($a[0], $b[0]));

        return array_slice(array_column($keyed, 1), 0, $limit);
    }
}
