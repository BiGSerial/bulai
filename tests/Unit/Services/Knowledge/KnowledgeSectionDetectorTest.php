<?php

namespace Tests\Unit\Services\Knowledge;

use App\Data\Knowledge\ExtractedPage;
use App\Data\Knowledge\ExtractedPdf;
use App\Services\Knowledge\KnowledgeSectionDetector;
use PHPUnit\Framework\TestCase;

class KnowledgeSectionDetectorTest extends TestCase
{
    public function test_detects_known_numbered_and_case_varied_sections(): void
    {
        $document = new ExtractedPdf([
            new ExtractedPage(1, "1. Composição\nCada comprimido contém 500 mg."),
            new ExtractedPage(2, "ADVERTÊNCIAS E PRECAUÇÕES\nUtilizar conforme orientação."),
        ]);

        $sections = (new KnowledgeSectionDetector)->detect($document);

        $this->assertCount(2, $sections);
        $this->assertSame('COMPOSIÇÃO', $sections[0]->name);
        $this->assertSame('1. Composição', $sections[0]->originalTitle);
        $this->assertSame(1, $sections[0]->blocks[0]->page);
        $this->assertSame('ADVERTÊNCIAS E PRECAUÇÕES', $sections[1]->name);
        $this->assertSame(2, $sections[1]->blocks[0]->page);
    }

    public function test_generic_uppercase_heading_is_preserved_without_inventing_a_category(): void
    {
        $sections = (new KnowledgeSectionDetector)->detect(new ExtractedPdf([
            new ExtractedPage(1, "INFORMAÇÕES AO PACIENTE\nTexto explicativo."),
        ]));

        $this->assertSame('INFORMAÇÕES AO PACIENTE', $sections[0]->name);
        $this->assertSame('INFORMAÇÕES AO PACIENTE', $sections[0]->originalTitle);
    }

    public function test_document_without_heading_keeps_null_section(): void
    {
        $sections = (new KnowledgeSectionDetector)->detect(new ExtractedPdf([
            new ExtractedPage(1, 'Este documento contém apenas um parágrafo comum.'),
        ]));

        $this->assertCount(1, $sections);
        $this->assertNull($sections[0]->name);
        $this->assertNull($sections[0]->originalTitle);
    }
}
