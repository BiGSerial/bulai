<?php

namespace App\Services\Knowledge;

use App\Data\Knowledge\KnowledgeChunkDraft;
use App\Data\Knowledge\KnowledgeSection;
use App\Data\Knowledge\KnowledgeTextBlock;

class KnowledgeChunker
{
    /** @param list<KnowledgeSection> $sections @return list<KnowledgeChunkDraft> */
    public function chunk(array $sections): array
    {
        $target = (int) config('knowledge.chunking.target_characters');
        $maximum = (int) config('knowledge.chunking.max_characters');
        $overlap = min((int) config('knowledge.chunking.overlap_characters'), max(0, $target - 1));
        $drafts = [];

        foreach ($sections as $section) {
            /** @var list<array{page: int, text: string}> $current */
            $current = [];

            foreach ($section->blocks as $block) {
                foreach ($this->splitBlock($block, $maximum) as $piece) {
                    $currentLength = $this->contentLength($current);
                    if ($current !== [] && $currentLength + 2 + mb_strlen($piece['text']) > $target) {
                        $drafts[] = $this->makeDraft(count($drafts), $section, $current);
                        $current = $this->overlapEntries($current, $overlap);
                    }

                    if ($this->contentLength($current) + 2 + mb_strlen($piece['text']) > $maximum) {
                        $current = [];
                    }

                    $current[] = $piece;
                }
            }

            if ($current !== []) {
                $drafts[] = $this->makeDraft(count($drafts), $section, $current);
            }
        }

        return $drafts;
    }

    /** @return list<array{page: int, text: string}> */
    private function splitBlock(KnowledgeTextBlock $block, int $maximum): array
    {
        $remaining = $block->text;
        $pieces = [];

        while (mb_strlen($remaining) > $maximum) {
            $candidate = mb_substr($remaining, 0, $maximum);
            $boundary = max(
                (int) mb_strrpos($candidate, '. '),
                (int) mb_strrpos($candidate, '; '),
                (int) mb_strrpos($candidate, ' '),
            );
            $cut = $boundary >= (int) ($maximum * 0.6) ? $boundary + 1 : $maximum;
            $pieces[] = ['page' => $block->page, 'text' => trim(mb_substr($remaining, 0, $cut))];
            $remaining = trim(mb_substr($remaining, $cut));
        }

        if ($remaining !== '') {
            $pieces[] = ['page' => $block->page, 'text' => $remaining];
        }

        return $pieces;
    }

    /** @param list<array{page: int, text: string}> $entries */
    private function makeDraft(int $index, KnowledgeSection $section, array $entries): KnowledgeChunkDraft
    {
        $pages = array_column($entries, 'page');

        return new KnowledgeChunkDraft(
            index: $index,
            section: $section->name,
            pageStart: min($pages),
            pageEnd: max($pages),
            content: implode("\n\n", array_column($entries, 'text')),
            metadata: array_filter([
                'section_original' => $section->originalTitle,
                'extraction_method' => 'prinsfrank/pdfparser',
            ]),
        );
    }

    /** @param list<array{page: int, text: string}> $entries @return list<array{page: int, text: string}> */
    private function overlapEntries(array $entries, int $characters): array
    {
        if ($characters === 0) {
            return [];
        }

        $content = implode("\n\n", array_column($entries, 'text'));
        $tail = trim(mb_substr($content, -$characters));
        $space = mb_strpos($tail, ' ');
        if ($space !== false) {
            $tail = trim(mb_substr($tail, $space + 1));
        }

        return $tail === '' ? [] : [['page' => $entries[array_key_last($entries)]['page'], 'text' => $tail]];
    }

    /** @param list<array{page: int, text: string}> $entries */
    private function contentLength(array $entries): int
    {
        return mb_strlen(implode("\n\n", array_column($entries, 'text')));
    }
}
