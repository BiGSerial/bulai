<?php

namespace Tests\Unit\Services\Knowledge;

use App\Services\Knowledge\ChunkCleaner;
use Tests\TestCase;

class ChunkCleanerTest extends TestCase
{
    private function item(string $content, int $page = 1): array
    {
        return ['content' => $content, 'page_start' => $page, 'page_end' => $page];
    }

    public function test_drops_footers_without_text_and_repeated_short_chunks(): void
    {
        $result = ChunkCleaner::clean([
            $this->item(str_repeat('Texto útil da bula sobre indicações do medicamento. ', 3)),
            $this->item('10452 -'),
            $this->item('1'),
            $this->item('Geolab Indústria Farmacêutica S/A — Comprimido 500mg'),
            $this->item(str_repeat('Outro parágrafo útil com informações de posologia. ', 3), 2),
            $this->item('geolab  indústria farmacêutica s/a — comprimido 500mg', 2),
        ]);

        $this->assertSame([1, 2, 5], $result['dropped']);
        $this->assertSame([0, 3, 4], array_column($result['kept'], 'position'));
    }

    public function test_merges_a_broken_heading_fragment_into_the_next_chunk_and_keeps_its_identity(): void
    {
        $body = str_repeat('Atua reduzindo a produção de ácido no estômago. ', 4);
        $result = ChunkCleaner::clean([$this->item('FUNCIONA?', 3), $this->item($body, 4)]);

        $this->assertCount(1, $result['kept']);
        $kept = $result['kept'][0];
        $this->assertSame(1, $kept['position']);
        $this->assertSame([0], $kept['merged']);
        $this->assertTrue($kept['changed']);
        $this->assertSame(3, $kept['page_start']);
        $this->assertStringStartsWith("FUNCIONA?\n\n", $kept['content']);
    }

    public function test_a_trailing_fragment_goes_to_the_previous_chunk_and_a_lone_fragment_is_kept(): void
    {
        $body = str_repeat('Informação de segurança relevante para o paciente. ', 4);
        $trailing = ChunkCleaner::clean([$this->item($body), $this->item('CAUSAR?', 2)]);
        $this->assertCount(1, $trailing['kept']);
        $this->assertStringEndsWith('CAUSAR?', $trailing['kept'][0]['content']);
        $this->assertSame(2, $trailing['kept'][0]['page_end']);

        $alone = ChunkCleaner::clean([$this->item('Paracetamol')]);
        $this->assertCount(1, $alone['kept']);
        $this->assertFalse($alone['kept'][0]['changed']);
    }

    public function test_long_repeated_text_is_kept_and_tokens_are_estimated(): void
    {
        $long = str_repeat('Mesmo texto legítimo repetido no PDF. ', 10);
        $this->assertCount(2, ChunkCleaner::clean([$this->item($long), $this->item($long)])['kept']);
        $this->assertSame(25, ChunkCleaner::estimateTokens(str_repeat('a', 100)));
        $this->assertSame(1, ChunkCleaner::estimateTokens('a'));
    }
}
