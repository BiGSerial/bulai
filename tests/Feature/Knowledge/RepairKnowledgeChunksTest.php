<?php

namespace Tests\Feature\Knowledge;

use App\Models\Knowledge\KnowledgeDocument;
use App\Models\Knowledge\KnowledgeSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RepairKnowledgeChunksTest extends TestCase
{
    use RefreshDatabase;

    public function test_repair_cleans_existing_chunks_preserving_ids_and_filling_token_count(): void
    {
        $source = KnowledgeSource::query()->create(['name' => 'ANVISA', 'type' => 'document', 'active' => true]);
        $document = KnowledgeDocument::query()->create(['source_id' => $source->id, 'external_key' => 'x', 'title' => 'X', 'document_type' => 'bula_paciente', 'language' => 'pt-BR', 'metadata' => [], 'content_status' => 'READY', 'file_status' => 'READY', 'embedding_status' => 'READY', 'content_hash' => 'h']);
        $body = fn (string $topic): string => str_repeat("Informação sobre {$topic} no medicamento. ", 5);
        $ids = [];
        foreach ([$body('indicações'), '10452 -', 'FUNCIONA?', $body('posologia'), $body('reações')] as $index => $content) {
            DB::table('knowledge_chunks')->insert(['document_id' => $document->id, 'chunk_index' => $index, 'section' => 'S', 'page_start' => 1, 'page_end' => 1, 'content' => $content, 'embedding_model' => 'm', 'embedding_content_hash' => hash('sha256', $content), 'created_at' => now(), 'updated_at' => now()]);
            $ids[] = (int) DB::getPdo()->lastInsertId('knowledge_chunks_id_seq');
        }

        $this->artisan('knowledge:repair-chunks', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame(5, DB::table('knowledge_chunks')->count());

        $this->artisan('knowledge:repair-chunks')->assertSuccessful();

        $rows = DB::table('knowledge_chunks')->orderBy('chunk_index')->get();
        $this->assertSame([0, 1, 2], $rows->pluck('chunk_index')->all());
        $this->assertSame([$ids[0], $ids[3], $ids[4]], $rows->pluck('id')->map(fn ($id): int => (int) $id)->all());
        $this->assertStringStartsWith('FUNCIONA?', (string) $rows[1]->content);
        $this->assertNotNull($rows->min('token_count'));
        $this->assertSame('PENDING', DB::table('knowledge_documents')->where('id', $document->id)->value('embedding_status'));
        $this->assertNotSame(hash('sha256', (string) $rows[1]->content), $rows[1]->embedding_content_hash, 'o hash antigo denuncia o trecho a reindexar');
    }
}
