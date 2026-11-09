<?php

namespace App\Services\Ai;

use App\Data\Ai\TokenUsage;

/**
 * Custo estimado em USD a partir da tabela de preços do driver (config/ai.php, `pricing.models`, USD por milhão de tokens).
 *
 * Entrada fora do cache, leitura de cache, gravação de cache e saída têm preços próprios. Sem o preço de uma parte
 * que houve na chamada, devolve null: preferimos não estimar a estimar errado.
 */
final class AiCostEstimator
{
    /** @param array<string, mixed> $pricing */
    public static function estimate(array $pricing, string $model, ?TokenUsage $usage): ?float
    {
        $prices = $pricing['models'][$model] ?? null;
        if ($usage === null || $usage->inputTokens === null || $usage->outputTokens === null || ! is_array($prices)) {
            return null;
        }

        $cacheRead = $usage->cacheReadTokens ?? 0;
        $cacheWrite = $usage->cacheWriteTokens ?? 0;
        $parts = [
            'input' => max(0, $usage->inputTokens - $cacheRead - $cacheWrite),
            'cache_read' => $cacheRead,
            'cache_write' => $cacheWrite,
            'output' => $usage->outputTokens,
        ];

        $total = 0.0;
        foreach ($parts as $part => $tokens) {
            if ($tokens === 0) {
                continue;
            }
            if (! is_numeric($prices[$part] ?? null)) {
                return null;
            }
            $total += $tokens * (float) $prices[$part];
        }

        return round($total / 1_000_000, 6);
    }

    /** @param array<string, mixed> $pricing */
    public static function reference(array $pricing): ?string
    {
        return is_string($pricing['reference'] ?? null) ? $pricing['reference'] : null;
    }
}
