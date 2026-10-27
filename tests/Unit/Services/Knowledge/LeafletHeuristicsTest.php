<?php

namespace Tests\Unit\Services\Knowledge;

use App\Data\Knowledge\ExtractedPage;
use App\Data\Knowledge\ExtractedPdf;
use App\Services\Knowledge\AnvisaLeafletMetadataExtractor;
use App\Services\Knowledge\ChemicalFormulaDetector;
use App\Services\Knowledge\LeafletTextHeuristics;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Regressões do PDF real da Glifage (capa com ® em linha própria, composição com líderes e "equivalente a…"). */
final class LeafletHeuristicsTest extends TestCase
{
    public function test_cover_title_block_with_registered_mark_on_its_own_line_gives_brand_generic_and_holder(): void
    {
        $result = app(AnvisaLeafletMetadataExtractor::class)->identify($this->glifage());

        $this->assertSame('Glifage', $result->fields['product_name']);
        $this->assertSame('cloridrato de metformina', $result->fields['active_ingredient']);
        $this->assertSame('Merck S/A', $result->fields['holder']);
        $this->assertSame('bula_profissional', $result->fields['document_type']);
        $this->assertSame('Glifage — Bula do profissional de saúde', $result->fields['title']);
        $this->assertSame('cover_brand', $result->evidence['product_name']['pattern']);
        $this->assertSame('cover_generic_name', $result->evidence['active_ingredient']['pattern']);
        $this->assertSame(75, $result->score, 'Marca+genérico repetidos em duas páginas somam o bônus de convergência.');
    }

    public function test_composition_leaders_footnote_marks_and_equivalent_notes_never_become_names(): void
    {
        $result = app(AnvisaLeafletMetadataExtractor::class)->identify($this->glifage());

        foreach (array_merge($result->candidates['product_name'], $result->candidates['active_ingredient']) as $candidate) {
            $this->assertDoesNotMatchRegularExpression('/\.{2,}|\*|\bequivalente\b|\d+\s*mg/iu', $candidate, "Candidato com lixo de composição: {$candidate}");
        }
        $this->assertContains('cloridrato de metformina', $result->candidates['active_ingredient']);
        $this->assertContains('metformina', $result->candidates['active_ingredient'], 'A base equivalente fica como candidato de baixo peso.');
    }

    public function test_composition_formula_lines_find_the_salt_even_without_a_cover(): void
    {
        $pdf = new ExtractedPdf([new ExtractedPage(2, $this->fixture('anvisa-glifage-page2.txt'))]);

        $result = app(AnvisaLeafletMetadataExtractor::class)->identify($pdf);

        $this->assertSame('cloridrato de metformina', $result->fields['active_ingredient']);
        $this->assertNotSame('*equivalente a 390 mg de metformina', $result->fields['active_ingredient']);
    }

    public function test_excerpts_carry_cover_composition_and_holder_with_more_text(): void
    {
        $result = app(AnvisaLeafletMetadataExtractor::class)->identify($this->glifage());

        $types = array_column($result->excerpts, 'type');
        $this->assertContains('cover_block', $types);
        $this->assertContains('composition', $types);
        $composition = collect($result->excerpts)->firstWhere('type', 'composition');
        $this->assertStringContainsString('Cada comprimido revestido de Glifage® 1 g', $composition['text']);
        $this->assertGreaterThan(1200, array_sum(array_map(fn (array $excerpt): int => mb_strlen($excerpt['text']), $result->excerpts)));
    }

    #[DataProvider('formulas')]
    public function test_chemical_formula_detector(string $text, ?string $expected): void
    {
        $hits = (new ChemicalFormulaDetector)->detect([new ExtractedPage(5, $text)]);

        $this->assertSame($expected, $hits[0]['formula'] ?? null);
    }

    /** @return array<string, array{string, ?string}> */
    public static function formulas(): array
    {
        return [
            'labeled with salt' => ['Descrição. Fórmula molecular: C4H11N5·HCl, peso molecular 165,63.', 'C4H11N5·HCl'],
            'unlabeled' => ['A codeína (C18H21NO3) é um alcaloide.', 'C18H21NO3'],
            'subscripts' => ['Fórmula molecular: C₈H₉NO₂.', 'C8H9NO2'],
            'acronym HbA1c is not a formula' => ['HbA1c basal igual ou acima de 7%', null],
            'acronym AUC0-t is not a formula' => ['As AUC0-t foram reduzidas; UKPDS 34 e DPP-4 e TSH', null],
            'plain words are not formulas' => ['Comprimidos revestidos de cloridrato de metformina', null],
        ];
    }

    public function test_extractor_reports_a_single_molecular_formula_and_sends_its_context_to_the_ai(): void
    {
        $pdf = new ExtractedPdf([
            new ExtractedPage(1, $this->fixture('anvisa-glifage-page1.txt')),
            new ExtractedPage(2, $this->fixture('anvisa-glifage-page2.txt')),
            new ExtractedPage(3, 'O cloridrato de metformina tem fórmula molecular C4H11N5·HCl e peso molecular 165,63.'),
        ]);

        $result = app(AnvisaLeafletMetadataExtractor::class)->identify($pdf);

        $this->assertSame('C4H11N5·HCl', $result->fields['molecular_formula']);
        $this->assertSame(['C4H11N5·HCl'], $result->candidates['molecular_formula']);
        $this->assertContains('formula_context', array_column($result->excerpts, 'type'));
    }

    public function test_class_acronyms_are_never_taken_as_the_active_ingredient(): void
    {
        $pdf = new ExtractedPdf([new ExtractedPage(2, "I - IDENTIFICAÇÃO DO MEDICAMENTO\nomeprazol sódico 40mg\n(IBP)\nAPRESENTAÇÕES\n")]);

        $result = app(AnvisaLeafletMetadataExtractor::class)->identify($pdf);

        $this->assertNotContains('IBP', $result->candidates['active_ingredient']);
        $this->assertNotSame('IBP', $result->fields['active_ingredient']);
    }

    public function test_brand_and_substance_recognition(): void
    {
        $text = new LeafletTextHeuristics;

        $this->assertTrue($text->looksLikeBrand('GLIFAGE®', true));
        $this->assertTrue($text->looksLikeBrand('Atrovent®', false));
        $this->assertFalse($text->looksLikeBrand('Bula para o profissional de saúde', true));
        $this->assertFalse($text->looksLikeBrand('Comprimidos revestidos', true));
        $this->assertTrue($text->looksLikeSubstanceName('cloridrato de metformina'));
        $this->assertTrue($text->looksLikeSubstanceName('omeprazol sódico'));
        $this->assertTrue($text->looksLikeSubstanceName('amoxicilina + clavulanato de potássio'));
        $this->assertFalse($text->looksLikeSubstanceName('Merck S/A'));
        $this->assertFalse($text->looksLikeSubstanceName('500 mg / 850 mg / 1 g'));
        $this->assertTrue($text->isCompositionLine('cloridrato de metformina* ..500 mg'));
        $this->assertTrue($text->isCompositionLine('(*equivalente a 390 mg de metformina)'));
        $this->assertSame('cloridrato de metformina', $text->sanitizeSubstance('cloridrato de metformina* .......................500 mg'));
    }

    private function glifage(): ExtractedPdf
    {
        return new ExtractedPdf([
            new ExtractedPage(1, $this->fixture('anvisa-glifage-page1.txt')),
            new ExtractedPage(2, $this->fixture('anvisa-glifage-page2.txt')),
        ]);
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(base_path("tests/Fixtures/knowledge/{$name}"));
    }
}
