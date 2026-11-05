<?php

namespace Tests\Feature\Retrieval;

use App\Data\Knowledge\RetrievalQuery;
use App\Models\Knowledge\KnowledgeChunk;
use App\Models\Knowledge\KnowledgeDocument;
use App\Models\Knowledge\KnowledgeSource;
use App\Services\Ai\AiProviderManager;
use App\Services\Ai\Fake\FakeEmbeddingProvider;
use App\Services\Retrieval\KnowledgeIndexer;
use App\Services\Retrieval\PostgresKnowledgeRetriever;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class KnowledgeRetrievalTest extends TestCase
{
    use RefreshDatabase;

    private KnowledgeSource $source;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai.embedding.default' => 'fake']);
        $this->source = KnowledgeSource::query()->create(['name' => 'ANVISA', 'type' => 'document', 'active' => true]);
    }

    /** @param list<array{0: string, 1: string}> $chunks pares [seção, conteúdo] */
    private function document(string $title, array $chunks, string $status = 'READY', ?KnowledgeSource $source = null): KnowledgeDocument
    {
        $document = KnowledgeDocument::query()->create([
            'source_id' => ($source ?? $this->source)->id, 'external_key' => uniqid('doc_'), 'title' => $title, 'document_type' => 'bula_profissional',
            'language' => 'pt-BR', 'metadata' => [], 'content_status' => $status, 'file_status' => 'READY', 'embedding_status' => 'PENDING',
        ]);
        foreach ($chunks as $index => [$section, $content]) {
            $document->chunks()->create(['chunk_index' => $index, 'section' => $section, 'page_start' => $index + 1, 'page_end' => $index + 1, 'content' => $content, 'metadata' => []]);
        }

        return $document;
    }

    private function retriever(): PostgresKnowledgeRetriever
    {
        return app(PostgresKnowledgeRetriever::class);
    }

    public function test_lexical_retrieval_returns_traceable_evidence_ordered_by_relevance(): void
    {
        $document = $this->document('Sinvastatina — Bula', [
            ['INTERAÇÕES MEDICAMENTOSAS', 'O uso concomitante de varfarina e sinvastatina pode aumentar o efeito anticoagulante.'],
            ['COMPOSIÇÃO', 'Cada comprimido contém sinvastatina 20 mg e excipientes.'],
        ]);

        $result = $this->retriever()->retrieve(new RetrievalQuery('varfarina anticoagulante', limit: 3));

        $this->assertCount(1, $result->references);
        $reference = $result->references[0];
        $this->assertSame($document->id, $reference->documentId);
        $this->assertSame('INTERAÇÕES MEDICAMENTOSAS', $reference->section);
        $this->assertSame(1, $reference->pageStart);
        $this->assertSame('ANVISA', $reference->sourceName);
        $this->assertSame('lexical', $reference->method);
        $this->assertGreaterThan(0, $reference->score);
    }

    public function test_invalid_or_inactive_documents_never_participate(): void
    {
        $this->document('Pendente', [['INTERAÇÕES', 'varfarina interage com este fármaco']], 'PENDING');
        $this->document('Falhou', [['INTERAÇÕES', 'varfarina interage com este fármaco']], 'FAILED');
        $inactive = KnowledgeSource::query()->create(['name' => 'Desativada', 'type' => 'document', 'active' => false]);
        $this->document('Fonte inativa', [['INTERAÇÕES', 'varfarina interage com este fármaco']], 'READY', $inactive);

        $this->assertTrue($this->retriever()->retrieve(new RetrievalQuery('varfarina'))->isEmpty());
    }

    public function test_filters_by_document_and_accent_insensitive_section(): void
    {
        $a = $this->document('A', [['INTERAÇÕES MEDICAMENTOSAS', 'varfarina e ibuprofeno'], ['REAÇÕES ADVERSAS', 'varfarina causa sangramento']]);
        $b = $this->document('B', [['INTERAÇÕES MEDICAMENTOSAS', 'varfarina e omeprazol']]);

        $bySection = $this->retriever()->retrieve(new RetrievalQuery('varfarina', limit: 10, sectionPatterns: ['interacoes']));
        $this->assertCount(2, $bySection->references);
        $this->assertEqualsCanonicalizing(['INTERAÇÕES MEDICAMENTOSAS'], array_unique(array_map(fn ($r) => $r->section, $bySection->references)));

        $byDocument = $this->retriever()->retrieve(new RetrievalQuery('varfarina', limit: 10, documentIds: [$b->id]));
        $this->assertSame([$b->id], array_unique(array_map(fn ($r) => $r->documentId, $byDocument->references)));
        $this->assertTrue($this->retriever()->retrieve(new RetrievalQuery('varfarina', documentIds: []))->isEmpty());
        $this->assertNotNull($a);
    }

    public function test_query_text_is_never_interpreted_as_tsquery_operators(): void
    {
        $this->document('A', [['X', 'varfarina e ibuprofeno']]);

        $result = $this->retriever()->retrieve(new RetrievalQuery("varfarina' & !(; drop table knowledge_chunks --"));

        $this->assertCount(1, $result->references);
        $this->assertSame(1, KnowledgeChunk::count());
    }

    public function test_indexer_is_incremental_stores_model_and_reindexes_when_model_changes(): void
    {
        $document = $this->document('Doc', [['A', 'primeiro trecho de teste'], ['B', 'segundo trecho de teste']]);
        $indexer = app(KnowledgeIndexer::class);

        $first = $indexer->index();
        $this->assertSame(2, $first['indexed']);
        $this->assertSame('fake-embedding', $first['model']);
        $this->assertSame(8, $first['dimensions']);
        $this->assertSame('READY', $document->fresh()->embedding_status);
        $chunk = KnowledgeChunk::query()->first();
        $this->assertSame('fake-embedding', $chunk->embedding_model);
        $this->assertSame(8, $chunk->embedding_dimensions);

        $second = $indexer->index();
        $this->assertSame(0, $second['indexed']);
        $this->assertSame(2, $second['skipped']);

        $third = $indexer->index(expectedModel: 'outro-modelo');
        $this->assertSame(2, $third['indexed']);
        $this->assertSame('outro-modelo', KnowledgeChunk::query()->first()->embedding_model);
    }

    public function test_vector_and_hybrid_retrieval_use_only_vectors_of_the_same_model(): void
    {
        $this->document('Doc', [['A', 'exatamente este texto para busca vetorial'], ['B', 'conteúdo completamente diferente do anterior']]);
        app(KnowledgeIndexer::class)->index();

        $vector = $this->retriever()->retrieve(new RetrievalQuery('exatamente este texto para busca vetorial', RetrievalQuery::VECTOR, 2));
        $this->assertSame('A', $vector->references[0]->section);
        $this->assertEqualsWithDelta(1.0, $vector->references[0]->score, 0.0001);
        $this->assertSame('fake-embedding', $vector->configuration['embedding_model']);

        $hybrid = $this->retriever()->retrieve(new RetrievalQuery('exatamente este texto para busca vetorial', RetrievalQuery::HYBRID, 2));
        $this->assertSame('A', $hybrid->references[0]->section);
        $this->assertSame('lexical+vector', $hybrid->references[0]->method);
        $this->assertSame(PostgresKnowledgeRetriever::RRF_K, $hybrid->configuration['rrf_k']);

        KnowledgeChunk::query()->update(['embedding_model' => 'incompativel']);
        $this->assertTrue($this->retriever()->retrieve(new RetrievalQuery('exatamente este texto para busca vetorial', RetrievalQuery::VECTOR))->isEmpty());
    }

    public function test_embedding_provider_failure_degrades_hybrid_to_lexical_and_reports_the_error(): void
    {
        $this->document('Doc', [['A', 'varfarina interage com ibuprofeno']]);
        config(['ai.embedding.default' => null]);

        $hybrid = $this->retriever()->retrieve(new RetrievalQuery('varfarina', RetrievalQuery::HYBRID));

        $this->assertCount(1, $hybrid->references);
        $this->assertSame('lexical', $hybrid->references[0]->method);
        $this->assertSame('AI_NOT_CONFIGURED', $hybrid->configuration['embedding_error']);
        $this->assertInstanceOf(AiProviderManager::class, app(AiProviderManager::class));
        $this->assertInstanceOf(FakeEmbeddingProvider::class, app(AiProviderManager::class)->embedding('fake'));
    }
}
