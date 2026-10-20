<?php

namespace App\Services\Knowledge;

use App\Data\Knowledge\ExtractedPage;
use App\Data\Knowledge\ExtractedPdf;
use App\Data\Knowledge\ExtractionQuality;

/**
 * Avaliação determinística (sem LLM) da confiabilidade de uma extração textual.
 * Cada sinal ruim registra um motivo e reduz o score; os limites vêm de config('document_ocr.quality').
 */
final class HeuristicExtractionQualityEvaluator implements DocumentExtractionQualityEvaluator
{
    private const PENALTIES = [
        'NO_PAGES' => 1.0,
        'NO_TEXT' => 1.0,
        'LOW_CHARACTER_COUNT' => 0.4,
        'LOW_CHARACTERS_PER_PAGE' => 0.2,
        'HIGH_EMPTY_PAGE_RATIO' => 0.3,
        'EXCESSIVE_LINE_FRAGMENTATION' => 0.25,
        'HIGH_INVALID_CHARACTER_RATIO' => 0.4,
        'LOW_ALPHABETIC_RATIO' => 0.3,
        'LOW_WORD_COUNT' => 0.25,
    ];

    public function evaluate(ExtractedPdf $document): ExtractionQuality
    {
        $pages = $document->pages;
        $pageCount = count($pages);
        $texts = array_map(fn (ExtractedPage $page): string => $page->text, $pages);
        $all = implode("\n", $texts);
        $nonWhitespace = (int) preg_match_all('/\S/u', $all);
        $emptyPages = count(array_filter($texts, fn (string $text): bool => trim($text) === ''));
        $invalid = (int) preg_match_all('/[\x{FFFD}\x00-\x08\x0B\x0C\x0E-\x1F\x7F\p{Co}]/u', $all);
        $letters = (int) preg_match_all('/\p{L}/u', $all);
        $words = (int) preg_match_all('/\p{L}{2,}/u', $all);
        $lines = array_values(array_filter(array_map('trim', preg_split('/\n/u', $all) ?: []), fn (string $line): bool => $line !== ''));
        $shortLines = count(array_filter($lines, fn (string $line): bool => mb_strlen($line) <= 3));

        $metrics = [
            'pages' => $pageCount,
            'characters' => $nonWhitespace,
            'characters_per_page' => $pageCount > 0 ? round($nonWhitespace / $pageCount, 2) : 0.0,
            'empty_page_ratio' => $pageCount > 0 ? round($emptyPages / $pageCount, 4) : 1.0,
            'invalid_character_ratio' => $nonWhitespace > 0 ? round($invalid / $nonWhitespace, 4) : 0.0,
            'alphabetic_ratio' => $nonWhitespace > 0 ? round($letters / $nonWhitespace, 4) : 0.0,
            'short_line_ratio' => $lines !== [] ? round($shortLines / count($lines), 4) : 0.0,
            'word_count' => $words,
        ];

        $reasons = $this->reasons($metrics);
        $score = max(0.0, 1.0 - array_sum(array_map(fn (string $reason): float => self::PENALTIES[$reason], $reasons)));
        $isAcceptable = ! in_array('NO_PAGES', $reasons, true)
            && ! in_array('NO_TEXT', $reasons, true)
            && $score >= (float) config('document_ocr.quality.min_score');

        return new ExtractionQuality(round($score, 4), $isAcceptable, $reasons, $metrics);
    }

    /**
     * @param  array<string, int|float>  $metrics
     * @return list<string>
     */
    private function reasons(array $metrics): array
    {
        $limits = config('document_ocr.quality');

        if ($metrics['pages'] === 0) {
            return ['NO_PAGES'];
        }

        if ($metrics['characters'] === 0) {
            return ['NO_TEXT'];
        }

        $checks = [
            'LOW_CHARACTER_COUNT' => $metrics['characters'] < $limits['min_characters'],
            'LOW_CHARACTERS_PER_PAGE' => $metrics['characters_per_page'] < $limits['min_characters_per_page'],
            'HIGH_EMPTY_PAGE_RATIO' => $metrics['empty_page_ratio'] > $limits['max_empty_page_ratio'],
            'EXCESSIVE_LINE_FRAGMENTATION' => $metrics['short_line_ratio'] > $limits['max_short_line_ratio'],
            'HIGH_INVALID_CHARACTER_RATIO' => $metrics['invalid_character_ratio'] > $limits['max_invalid_character_ratio'],
            'LOW_ALPHABETIC_RATIO' => $metrics['alphabetic_ratio'] < $limits['min_alphabetic_ratio'],
            'LOW_WORD_COUNT' => $metrics['word_count'] < $limits['min_word_count'],
        ];

        return array_keys(array_filter($checks));
    }
}
