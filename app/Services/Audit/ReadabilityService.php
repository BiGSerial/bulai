<?php

namespace App\Services\Audit;

use App\Models\Consultation\MedicationQuestion;
use App\Services\Evaluation\Metrics;

/**
 * Legibilidade das respostas em relação aos trechos de bula que as sustentam (TCC, seção 3.5.5): Índice de Facilidade de
 * Leitura de Flesch adaptado ao português (Martins et al., 1996): 248,835 − 1,015·(palavras/frases) − 84,6·(sílabas/palavras).
 * Frases delimitadas por . ! ? ; : e quebras de linha; sílabas por núcleos vocálicos (heurística). A mesma rotina vale para os
 * dois textos de cada par, então a comparação é válida mesmo que a contagem absoluta tenha pequenos desvios.
 */
final class ReadabilityService
{
    /** @return array{index: float|null, words: int, sentences: int, syllables: int, band: string|null} */
    public function flesch(string $text): array
    {
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags($text)) ?? '');
        $sentences = array_filter(preg_split('/[.!?;:\n]+/u', $text) ?: [], fn (string $part): bool => preg_match('/\p{L}/u', $part) === 1);
        preg_match_all('/\p{L}+/u', $text, $matches);
        $words = $matches[0];
        if ($words === [] || $sentences === []) {
            return ['index' => null, 'words' => count($words), 'sentences' => count($sentences), 'syllables' => 0, 'band' => null];
        }
        $syllables = array_sum(array_map(fn (string $word): int => $this->syllables($word), $words));
        $index = 248.835 - 1.015 * (count($words) / count($sentences)) - 84.6 * ($syllables / count($words));

        return ['index' => round($index, 1), 'words' => count($words), 'sentences' => count($sentences), 'syllables' => $syllables, 'band' => $this->band($index)];
    }

    /** Faixas de Martins et al.: valores negativos contam como "muito difícil". */
    public function band(float $index): string
    {
        return match (true) {
            $index >= 75 => 'muito fácil',
            $index >= 50 => 'fácil',
            $index >= 25 => 'difícil',
            default => 'muito difícil',
        };
    }

    /**
     * Compara cada resposta gravada com os trechos citados como fonte.
     *
     * @return array{pairs: int, answer: array<string, mixed>, sources: array<string, mixed>, delta: array<string, mixed>, easier_than_source: array{rate: float|null, low: float|null, high: float|null, n: int}, bands: array{answer: array<string, int>, sources: array<string, int>}, method: string}
     */
    public function answers(int $limit = 200): array
    {
        $answers = [];
        $sources = [];
        $deltas = [];
        $bands = ['answer' => [], 'sources' => []];

        MedicationQuestion::query()->where('status', 'answered')->whereNotNull('answer')->latest('id')->limit($limit)->get(['answer', 'evidences'])
            ->each(function (MedicationQuestion $question) use (&$answers, &$sources, &$deltas, &$bands): void {
                $cited = collect($question->evidences ?? [])->filter(fn ($evidence): bool => is_array($evidence) && ! empty($evidence['supports_claims']));
                $sourceText = $cited->pluck('excerpt')->filter()->implode("\n");
                $answer = $this->flesch((string) $question->answer);
                $source = $this->flesch($sourceText);
                if ($answer['index'] === null || $source['index'] === null) {
                    return;
                }
                $answers[] = $answer['index'];
                $sources[] = $source['index'];
                $deltas[] = $answer['index'] - $source['index'];
                $bands['answer'][$answer['band']] = ($bands['answer'][$answer['band']] ?? 0) + 1;
                $bands['sources'][$source['band']] = ($bands['sources'][$source['band']] ?? 0) + 1;
            });

        return [
            'pairs' => count($answers),
            'answer' => $this->distribution($answers),
            'sources' => $this->distribution($sources),
            'delta' => $this->distribution($deltas),
            'easier_than_source' => Metrics::wilson(count(array_filter($deltas, fn (float $delta): bool => $delta > 0)), count($deltas)),
            'bands' => $bands,
            'method' => 'Flesch pt (Martins et al., 1996); frases por . ! ? ; : e quebra de linha; sílabas por núcleos vocálicos. Trechos = evidências que sustentam afirmações aceitas.',
        ];
    }

    /**
     * @param  list<float>  $values
     * @return array{min: float|null, median: float|null, mean: float|null, max: float|null}
     */
    private function distribution(array $values): array
    {
        if ($values === []) {
            return ['min' => null, 'median' => null, 'mean' => null, 'max' => null];
        }
        sort($values);
        $middle = intdiv(count($values), 2);
        $median = count($values) % 2 === 1 ? $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;

        return ['min' => round($values[0], 1), 'median' => round($median, 1), 'mean' => round(array_sum($values) / count($values), 1), 'max' => round($values[count($values) - 1], 1)];
    }

    private function syllables(string $word): int
    {
        $word = mb_strtolower($word);
        preg_match_all('/[aeiouáéíóúâêôãõàü]+/u', $word, $groups);

        return max(1, count($groups[0]));
    }
}
