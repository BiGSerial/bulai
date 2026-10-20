<?php

namespace Tests\Unit\Services\Knowledge;

use App\Data\Knowledge\ExtractedPage;
use App\Data\Knowledge\ExtractedPdf;
use App\Services\Knowledge\HeuristicExtractionQualityEvaluator;
use Tests\TestCase;

final class HeuristicExtractionQualityEvaluatorTest extends TestCase
{
    public function test_substantial_text_is_acceptable(): void
    {
        $quality = (new HeuristicExtractionQualityEvaluator)->evaluate($this->pdf([$this->prose(), $this->prose()]));

        $this->assertTrue($quality->isAcceptable);
        $this->assertSame([], $quality->reasons);
        $this->assertSame(1.0, $quality->score);
        $this->assertSame(2, $quality->metrics['pages']);
    }

    public function test_document_without_pages_or_text_is_rejected(): void
    {
        $evaluator = new HeuristicExtractionQualityEvaluator;

        $this->assertSame(['NO_PAGES'], $evaluator->evaluate(new ExtractedPdf([]))->reasons);
        $noText = $evaluator->evaluate($this->pdf(['', '  ']));
        $this->assertSame(['NO_TEXT'], $noText->reasons);
        $this->assertFalse($noText->isAcceptable);
        $this->assertSame(0.0, $noText->score);
    }

    public function test_fragmented_and_corrupted_text_is_rejected_with_reasons(): void
    {
        $garbage = str_repeat("a\nb\n\u{FFFD}\n", 120);

        $quality = (new HeuristicExtractionQualityEvaluator)->evaluate($this->pdf([$garbage]));

        $this->assertFalse($quality->isAcceptable);
        $this->assertContains('EXCESSIVE_LINE_FRAGMENTATION', $quality->reasons);
        $this->assertContains('HIGH_INVALID_CHARACTER_RATIO', $quality->reasons);
    }

    public function test_empty_pages_and_small_volume_lower_the_score(): void
    {
        $quality = (new HeuristicExtractionQualityEvaluator)->evaluate($this->pdf(['Texto curto.', '', '']));

        $this->assertFalse($quality->isAcceptable);
        $this->assertContains('LOW_CHARACTER_COUNT', $quality->reasons);
        $this->assertContains('HIGH_EMPTY_PAGE_RATIO', $quality->reasons);
    }

    public function test_thresholds_are_configurable(): void
    {
        config()->set('document_ocr.quality', [...config('document_ocr.quality'), 'min_characters' => 1, 'min_characters_per_page' => 1, 'min_word_count' => 1]);

        $quality = (new HeuristicExtractionQualityEvaluator)->evaluate($this->pdf(['Texto curto mas aceito.']));

        $this->assertTrue($quality->isAcceptable);
    }

    /** @param list<string> $texts */
    private function pdf(array $texts): ExtractedPdf
    {
        return new ExtractedPdf(array_map(fn (string $text, int $index): ExtractedPage => new ExtractedPage($index + 1, $text), $texts, array_keys($texts)));
    }

    private function prose(): string
    {
        return str_repeat("Este medicamento é indicado para o tratamento de dores e febre em adultos e crianças.\nUsar conforme orientação médica.\n", 8);
    }
}
