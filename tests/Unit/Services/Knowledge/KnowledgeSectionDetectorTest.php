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

    /** Bula do paciente: os títulos são perguntas numeradas, às vezes quebradas em duas linhas. */
    public function test_patient_leaflet_question_headings_open_their_own_sections(): void
    {
        $sections = (new KnowledgeSectionDetector)->detect(new ExtractedPdf([
            new ExtractedPage(3, "INFORMAÇÕES AO PACIENTE:\n1. PARA QUE ESTE MEDICAMENTO É INDICADO?\nEste medicamento é indicado como analgésico e antitérmico."),
            new ExtractedPage(5, "4. O QUE DEVO SABER ANTES DE\nUSAR ESTE MEDICAMENTO?\nInforme ao médico se você tem asma.\nINTERAÇÕES MEDICAMENTOSAS\nA dipirona pode reduzir o nível de ciclosporina."),
            new ExtractedPage(6, "6. COMO DEVO USAR ESTE MEDICAMENTO?\nAdultos: 20 a 40 gotas até 4 vezes ao dia.\nVOCÊ SABIA? É SEGURO.\nTexto."),
        ]));

        $this->assertSame(
            ['PARA QUE ESTE MEDICAMENTO É INDICADO?', 'O QUE DEVO SABER ANTES DE USAR ESTE MEDICAMENTO?', 'INTERAÇÕES MEDICAMENTOSAS', 'COMO DEVO USAR ESTE MEDICAMENTO?'],
            array_map(fn ($section) => $section->name, $sections),
        );
        $this->assertSame('Adultos: 20 a 40 gotas até 4 vezes ao dia. VOCÊ SABIA? É SEGURO. Texto.', $sections[3]->blocks[0]->text, 'frase em maiúsculas terminada em ponto continua texto');
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
