<?php

namespace Tests\Unit;

use App\Services\Patient\QuestionConversation;
use PHPUnit\Framework\TestCase;

class FontesLegiveisTest extends TestCase
{
    public function test_the_leaflet_excerpt_is_cleaned_for_reading(): void
    {
        $this->assertSame(
            'Experiência pós-comercialização do medicamento: tontura.',
            QuestionConversation::readableExcerpt("Experiência pós- comercialização   do medica- mento:\n tontura."),
        );
        $this->assertStringEndsWith('…', QuestionConversation::readableExcerpt(str_repeat('palavra ', 100), 40));
        $this->assertLessThanOrEqual(41, mb_strlen(QuestionConversation::readableExcerpt(str_repeat('palavra ', 100), 40)));
    }

    public function test_an_uppercase_section_title_becomes_sentence_case(): void
    {
        $this->assertSame('Reações adversas', QuestionConversation::readableSection('REAÇÕES ADVERSAS'));
        $this->assertSame('Como devo usar este medicamento?', QuestionConversation::readableSection('Como devo usar este medicamento?'));
    }
}
