<?php

namespace Tests\Unit\Services\Knowledge;

use App\Data\Knowledge\KnowledgeSection;
use App\Data\Knowledge\KnowledgeTextBlock;
use App\Services\Knowledge\KnowledgeChunker;
use Tests\TestCase;

class KnowledgeChunkerTest extends TestCase
{
    public function test_short_section_produces_one_traceable_chunk(): void
    {
        config()->set('knowledge.chunking', ['target_characters' => 100, 'max_characters' => 140, 'overlap_characters' => 20]);
        $sections = [new KnowledgeSection('COMPOSIÇÃO', '1. COMPOSIÇÃO', [new KnowledgeTextBlock(2, 'Cada comprimido contém 500 mg.')])];

        $chunks = (new KnowledgeChunker)->chunk($sections);

        $this->assertCount(1, $chunks);
        $this->assertSame(0, $chunks[0]->index);
        $this->assertSame('COMPOSIÇÃO', $chunks[0]->section);
        $this->assertSame(2, $chunks[0]->pageStart);
        $this->assertSame(2, $chunks[0]->pageEnd);
    }

    public function test_long_sections_use_overlap_and_preserve_order_and_pages(): void
    {
        config()->set('knowledge.chunking', ['target_characters' => 70, 'max_characters' => 100, 'overlap_characters' => 20]);
        $sections = [new KnowledgeSection('INDICAÇÕES', 'INDICAÇÕES', [
            new KnowledgeTextBlock(1, 'Primeiro parágrafo apresenta a indicação principal do medicamento.'),
            new KnowledgeTextBlock(2, 'Segundo parágrafo acrescenta outra informação clínica importante.'),
        ])];

        $chunks = (new KnowledgeChunker)->chunk($sections);

        $this->assertCount(2, $chunks);
        $this->assertSame([0, 1], array_column($chunks, 'index'));
        $this->assertSame(1, $chunks[0]->pageStart);
        $this->assertSame(2, $chunks[1]->pageEnd);
        $this->assertStringContainsString('medicamento', $chunks[1]->content);
    }

    public function test_chunks_never_cross_section_boundaries(): void
    {
        config()->set('knowledge.chunking', ['target_characters' => 500, 'max_characters' => 600, 'overlap_characters' => 50]);
        $sections = [
            new KnowledgeSection('COMPOSIÇÃO', 'COMPOSIÇÃO', [new KnowledgeTextBlock(1, 'Conteúdo da composição.')]),
            new KnowledgeSection('CONTRAINDICAÇÕES', 'CONTRAINDICAÇÕES', [new KnowledgeTextBlock(2, 'Conteúdo das contraindicações.')]),
        ];

        $chunks = (new KnowledgeChunker)->chunk($sections);

        $this->assertCount(2, $chunks);
        $this->assertSame('COMPOSIÇÃO', $chunks[0]->section);
        $this->assertSame('CONTRAINDICAÇÕES', $chunks[1]->section);
        $this->assertStringNotContainsString('contraindicações', $chunks[0]->content);
    }

    public function test_chunk_spanning_pages_records_the_full_page_range(): void
    {
        config()->set('knowledge.chunking', ['target_characters' => 500, 'max_characters' => 600, 'overlap_characters' => 50]);
        $sections = [new KnowledgeSection('POSOLOGIA', 'POSOLOGIA', [
            new KnowledgeTextBlock(4, 'Início da orientação posológica.'),
            new KnowledgeTextBlock(5, 'Continuação da mesma orientação.'),
        ])];

        $chunks = (new KnowledgeChunker)->chunk($sections);

        $this->assertCount(1, $chunks);
        $this->assertSame(4, $chunks[0]->pageStart);
        $this->assertSame(5, $chunks[0]->pageEnd);
    }
}
