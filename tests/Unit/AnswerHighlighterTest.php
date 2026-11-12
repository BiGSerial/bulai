<?php

namespace Tests\Unit;

use App\Services\Patient\AnswerHighlighter;
use PHPUnit\Framework\TestCase;

class AnswerHighlighterTest extends TestCase
{
    public function test_medicine_and_component_names_are_bold_ignoring_case_and_accents(): void
    {
        $html = (string) (new AnswerHighlighter)->html('A losartana pode subir o potássio. O Ibuprofeno também.', ['LOSARTANA POTÁSSICA', 'IBUPROFENO']);

        $this->assertSame('A <strong>losartana</strong> pode subir o potássio. O <strong>Ibuprofeno</strong> também.', $html);
    }

    public function test_critical_phrases_are_bold_and_the_model_text_is_escaped(): void
    {
        $html = (string) (new AnswerHighlighter)->html('Não use se tiver <b>febre</b>; procure atendimento médico.', []);

        $this->assertSame('<strong>Não use</strong> se tiver &lt;b&gt;febre&lt;/b&gt;; <strong>procure atendimento médico</strong>.', $html);
    }

    public function test_the_sentence_about_the_alert_is_bold_as_a_whole(): void
    {
        $html = (string) (new AnswerHighlighter)->html('A dipirona alivia a dor. Ela pode interagir com a varfarina, que você usa.', ['DIPIRONA'], ['VARFARINA']);

        $this->assertSame('A <strong>dipirona</strong> alivia a dor. <strong>Ela pode interagir com a varfarina, que você usa.</strong>', $html);
    }
}
