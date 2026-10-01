<?php

namespace Tests\Unit\Services\Knowledge;

use App\Data\Knowledge\ExtractedPage;
use App\Data\Knowledge\ExtractedPdf;
use App\Models\Knowledge\KnowledgeSource;
use App\Services\Knowledge\AnvisaLeafletMetadataExtractor;
use Tests\TestCase;

class AnvisaLeafletMetadataExtractorTest extends TestCase
{
    public function test_extracts_real_anvisa_leaflet_identity_with_deterministic_score(): void
    {
        $result = app(AnvisaLeafletMetadataExtractor::class)->identify($this->atroventFixture());

        $this->assertSame('Atrovent® N e Atrovent®', $result->fields['product_name']);
        $this->assertSame('brometo de ipratrópio', $result->fields['active_ingredient']);
        $this->assertSame('1.0367.0004', $result->fields['registration_number']);
        $this->assertSame('103670004', $result->fields['registration_number_normalized']);
        $this->assertSame('bula_profissional', $result->fields['document_type']);
        $this->assertSame('Atrovent® N e Atrovent® — Bula do profissional de saúde', $result->fields['title']);
        $this->assertSame(100, $result->score);
        $this->assertTrue($result->automaticCreationEligible);
        $this->assertSame(3, $result->pagesAnalyzed);
    }

    public function test_identifies_patient_leaflet_and_keeps_incomplete_identity_ineligible(): void
    {
        $pdf = new ExtractedPdf([new ExtractedPage(1, "NOVALGINA\ndipirona monoidratada\nAPRESENTAÇÕES\nI - INFORMAÇÕES AO PACIENTE")]);
        $result = app(AnvisaLeafletMetadataExtractor::class)->identify($pdf);

        $this->assertSame('bula_paciente', $result->fields['document_type']);
        $this->assertSame(55, $result->score);
        $this->assertFalse($result->automaticCreationEligible);
    }

    public function test_multiple_registration_numbers_are_reported_as_ambiguous(): void
    {
        $pdf = new ExtractedPdf([new ExtractedPage(1, "PRODUTO\nsubstância ativa\nAPRESENTAÇÕES\nBula para o profissional"), new ExtractedPage(2, "Registro: 1.2345.6789\nRegistro: 1.9876.5432")]);
        $result = app(AnvisaLeafletMetadataExtractor::class)->identify($pdf);

        $this->assertSame(['registration_number'], $result->ambiguousFields);
        $this->assertNull($result->sourceDocumentId());
        $this->assertFalse($result->automaticCreationEligible);
    }

    public function test_supports_only_anvisa_bulario_origins(): void
    {
        $extractor = app(AnvisaLeafletMetadataExtractor::class);

        $this->assertTrue($extractor->supports(new KnowledgeSource(['name' => 'ANVISA', 'collection' => 'Bulário Eletrônico'])));
        $this->assertFalse($extractor->supports(new KnowledgeSource(['name' => 'DailyMed', 'collection' => 'Labels'])));
    }

    private function atroventFixture(): ExtractedPdf
    {
        $pages = explode("\n===PAGE===\n", file_get_contents(dirname(__DIR__, 3).'/Fixtures/knowledge/anvisa-atrovent-extract.txt'));

        return new ExtractedPdf(array_map(fn (string $text, int $index): ExtractedPage => new ExtractedPage($index + 1, $text), $pages, array_keys($pages)));
    }
}
