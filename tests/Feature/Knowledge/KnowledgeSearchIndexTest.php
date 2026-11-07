<?php

namespace Tests\Feature\Knowledge;

use App\Data\Ai\EmbeddingBatchRequest;
use App\Data\Ai\EmbeddingBatchResponse;
use App\Data\Ai\EmbeddingRequest;
use App\Data\Ai\EmbeddingResponse;
use App\Jobs\IndexKnowledgeDocumentEmbeddings;
use App\Livewire\Admin\Documents\Index;
use App\Livewire\Admin\Documents\Show;
use App\Models\Knowledge\KnowledgeChunk;
use App\Models\Knowledge\KnowledgeDocument;
use App\Models\Knowledge\KnowledgeProcessingEvent;
use App\Models\Knowledge\KnowledgeSource;
use App\Models\User;
use App\Services\Ai\AiProviderException;
use App\Services\Ai\AiProviderManager;
use App\Services\Ai\EmbeddingProvider;
use App\Services\Knowledge\ProcessingTimeline;
use App\Services\Retrieval\KnowledgeIndexer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * Índice de busca (embeddings) como etapa própria: lotes limitados por caracteres, lote lento refeito trecho a trecho,
 * job separado do processamento do PDF e ações "gerar/refazer o índice" na administração.
 */
final class KnowledgeSearchIndexTest extends TestCase
{
    use RefreshDatabase;

    private KnowledgeSource $source;

    /** @var list<int> tamanho (em trechos) de cada requisição recebida pelo provedor */
    private array $requests = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->source = KnowledgeSource::query()->create(['name' => 'ANVISA', 'type' => 'document', 'active' => true]);
        config(['ai.embedding.default' => 'fake', 'ai.embedding.batch_size' => 4, 'ai.embedding.batch_characters' => 100]);
    }

    public function test_requests_are_limited_by_characters_and_a_slow_batch_is_redone_one_chunk_at_a_time(): void
    {
        // 60 + 60 > 100: dois grupos no primeiro lote de 4; o quarto trecho (120 > 100) vai sozinho.
        $document = $this->document([str_repeat('a', 60), str_repeat('b', 30), str_repeat('c', 60), str_repeat('d', 120), str_repeat('e', 10)]);
        $this->useProvider(timeoutWhenBatchHasMoreThan: 99);

        $summary = app(KnowledgeIndexer::class)->index(documentId: $document->id);

        $this->assertSame([2, 1, 1, 1], $this->requests, 'a+b (90), c (60), d (120, sozinho), e (10, segundo lote)');
        $this->assertSame([5, 0, 0, 'fake-embedding'], [$summary['indexed'], $summary['failed_batches'], $summary['split_batches'], $summary['model']]);
        $this->assertSame('READY', $document->fresh()->embedding_status);

        KnowledgeChunk::query()->update(['embedding_model' => null]);
        $this->requests = [];
        $this->useProvider(timeoutWhenBatchHasMoreThan: 1);

        $summary = app(KnowledgeIndexer::class)->index(documentId: $document->id);

        $this->assertSame([2, 1, 1, 1, 1, 1], $this->requests, 'o grupo a+b estoura o tempo e é refeito trecho a trecho');
        $this->assertSame([5, 0, 1, null], [$summary['indexed'], $summary['failed_batches'], $summary['split_batches'], $summary['error_code']]);
        $this->assertSame('READY', $document->fresh()->embedding_status);
        $event = app(ProcessingTimeline::class)->for($document->id)->last();
        $this->assertSame(['embed', 'done', 1, 5], [$event->step, $event->state, $event->detail['split_batches'], $event->detail['indexed']]);
    }

    public function test_a_chunk_that_times_out_alone_marks_the_index_as_failed_with_the_error_code(): void
    {
        $document = $this->document([str_repeat('a', 10), str_repeat('b', 200)]);
        $this->useProvider(timeoutWhenBatchHasMoreThan: 99, timeoutOnLongerThan: 150);

        $summary = app(KnowledgeIndexer::class)->index(documentId: $document->id);

        $this->assertSame([1, 1, 'AI_TIMEOUT'], [$summary['indexed'], $summary['failed_batches'], $summary['error_code']]);
        $this->assertSame('FAILED', $document->fresh()->embedding_status);
        $this->assertSame(1, $document->chunks()->whereNull('embedding_model')->count(), 'o trecho curto fica indexado; só o longo falta');
        $event = app(ProcessingTimeline::class)->for($document->id)->last();
        $this->assertSame(['embed', 'failed', 'AI_TIMEOUT'], [$event->step, $event->state, $event->detail['error_code']]);
        $this->assertSame('o serviço de IA demorou mais que o limite de tempo de uma requisição', $event->errorLabel());
    }

    public function test_index_job_redoes_only_the_embed_step_and_leaves_the_rest_of_the_timeline(): void
    {
        $document = $this->document(['trecho um', 'trecho dois'], embeddingStatus: 'FAILED');
        $timeline = app(ProcessingTimeline::class);
        $timeline->finish($timeline->start($document->id, KnowledgeProcessingEvent::STEP_CHUNK), ['chunks' => 2]);
        $timeline->fail($timeline->start($document->id, KnowledgeProcessingEvent::STEP_EMBED), 'AI_TIMEOUT');

        (new IndexKnowledgeDocumentEmbeddings($document->id))->handle(app(KnowledgeIndexer::class), $timeline);

        $this->assertSame('READY', $document->fresh()->embedding_status);
        $events = $timeline->for($document->id);
        $this->assertSame(['chunk', 'embed'], $events->pluck('step')->all(), 'a linha de embed antiga foi substituída; a de trechos permanece');
        $this->assertSame(['done', 'done'], $events->pluck('state')->all());
        $this->assertSame(0, $document->chunks()->whereNull('embedding_model')->count());
    }

    public function test_index_job_marks_the_document_as_failed_when_the_provider_is_missing_or_the_job_dies(): void
    {
        $document = $this->document(['trecho um']);
        config(['ai.embedding.default' => null]);

        (new IndexKnowledgeDocumentEmbeddings($document->id))->handle(app(KnowledgeIndexer::class), app(ProcessingTimeline::class));

        $this->assertSame('FAILED', $document->fresh()->embedding_status);
        $this->assertSame('AI_NOT_CONFIGURED', app(ProcessingTimeline::class)->for($document->id)->last()->detail['error_code']);

        $document->update(['embedding_status' => 'PROCESSING']);
        app(ProcessingTimeline::class)->start($document->id, KnowledgeProcessingEvent::STEP_EMBED);
        (new IndexKnowledgeDocumentEmbeddings($document->id))->failed(new RuntimeException('worker timeout'));

        $this->assertSame('FAILED', $document->fresh()->embedding_status);
        $this->assertSame(['failed', 'INDEX_JOB_FAILED'], [app(ProcessingTimeline::class)->for($document->id)->last()->state, app(ProcessingTimeline::class)->for($document->id)->last()->detail['error_code']]);

        $notReady = $this->document(['x'], contentStatus: 'PROCESSING');
        (new IndexKnowledgeDocumentEmbeddings($notReady->id))->handle(app(KnowledgeIndexer::class), app(ProcessingTimeline::class));
        $this->assertSame('PENDING', $notReady->fresh()->embedding_status, 'sem trechos prontos o job não faz nada');
    }

    public function test_document_page_offers_to_generate_or_redo_only_the_search_index(): void
    {
        Queue::fake([IndexKnowledgeDocumentEmbeddings::class]);
        $admin = User::factory()->admin()->create();
        $pending = $this->document(['trecho']);

        Livewire::actingAs($admin)->test(Show::class, ['document' => $pending])
            ->assertSee('O índice de busca ainda não foi gerado')
            ->assertSee('Gerar o índice de busca')
            ->assertSee('data-testid="rebuild-search-index"', false)
            ->assertSee('Refazer o índice de busca')
            ->call('rebuildSearchIndex')
            ->assertSee('Geração do índice de busca enfileirada.');
        Queue::assertPushed(IndexKnowledgeDocumentEmbeddings::class, fn (IndexKnowledgeDocumentEmbeddings $job): bool => $job->documentId === $pending->id && ! $job->reindex);

        $failed = $this->document(['trecho'], embeddingStatus: 'FAILED');
        $timeline = app(ProcessingTimeline::class);
        $timeline->fail($timeline->start($failed->id, KnowledgeProcessingEvent::STEP_EMBED, ['chunks' => 36]), 'AI_TIMEOUT');

        Livewire::actingAs($admin)->test(Show::class, ['document' => $failed])
            ->assertSee('O índice de busca falhou')
            ->assertSee('(AI_TIMEOUT)')
            ->assertSee('O serviço de IA demorou mais que o limite de tempo de uma requisição')
            ->assertSee('Refazer só o índice de busca');

        $notReady = $this->document(['trecho'], contentStatus: 'PROCESSING');
        Livewire::actingAs($admin)->test(Show::class, ['document' => $notReady])
            ->assertDontSee('data-testid="rebuild-search-index"', false)
            ->call('rebuildSearchIndex')
            ->assertSee('só pode ser gerado depois que o texto e os trechos estiverem prontos');
        Queue::assertPushed(IndexKnowledgeDocumentEmbeddings::class, 1);
    }

    public function test_documents_list_generates_every_pending_or_failed_search_index_at_once(): void
    {
        Queue::fake([IndexKnowledgeDocumentEmbeddings::class]);
        $admin = User::factory()->admin()->create();
        $pending = $this->document(['a']);
        $failed = $this->document(['b'], embeddingStatus: 'FAILED');
        $this->document(['c'], embeddingStatus: 'READY');
        $this->document(['d'], embeddingStatus: 'PROCESSING');
        $this->document(['e'], contentStatus: 'FAILED');

        Livewire::actingAs($admin)->test(Index::class)
            ->assertSee('Gerar 2 índices de busca pendentes')
            ->call('generatePendingSearchIndexes')
            ->assertSee('Índice de busca de 2 documentos enfileirado.');

        Queue::assertPushed(IndexKnowledgeDocumentEmbeddings::class, 2);
        Queue::assertPushed(IndexKnowledgeDocumentEmbeddings::class, fn (IndexKnowledgeDocumentEmbeddings $job): bool => $job->documentId === $pending->id);
        Queue::assertPushed(IndexKnowledgeDocumentEmbeddings::class, fn (IndexKnowledgeDocumentEmbeddings $job): bool => $job->documentId === $failed->id);
    }

    /** @param list<string> $chunks */
    private function document(array $chunks, string $contentStatus = 'READY', string $embeddingStatus = 'PENDING'): KnowledgeDocument
    {
        $document = KnowledgeDocument::query()->create([
            'source_id' => $this->source->id, 'external_key' => uniqid('doc_'), 'title' => 'Bula '.uniqid(), 'document_type' => 'bula_profissional',
            'language' => 'pt-BR', 'metadata' => [], 'content_status' => $contentStatus, 'file_status' => 'READY', 'embedding_status' => $embeddingStatus,
        ]);
        foreach ($chunks as $index => $content) {
            $document->chunks()->create(['chunk_index' => $index, 'section' => 'S'.$index, 'content' => $content, 'metadata' => []]);
        }

        return $document;
    }

    /** Provedor que registra o tamanho de cada requisição e "estoura o tempo" em lotes grandes ou textos longos. */
    private function useProvider(int $timeoutWhenBatchHasMoreThan, int $timeoutOnLongerThan = PHP_INT_MAX): void
    {
        $test = $this;
        app(AiProviderManager::class)->extend('embedding', 'slow', fn () => new class($test, $timeoutWhenBatchHasMoreThan, $timeoutOnLongerThan) implements EmbeddingProvider
        {
            public function __construct(private readonly KnowledgeSearchIndexTest $test, private readonly int $maxBatch, private readonly int $maxLength) {}

            public function embed(EmbeddingRequest $request): EmbeddingResponse
            {
                return $this->embedMany(new EmbeddingBatchRequest([$request->input], $request->model))->embeddings[0];
            }

            public function embedMany(EmbeddingBatchRequest $request): EmbeddingBatchResponse
            {
                $this->test->recordRequest(count($request->inputs));
                if (count($request->inputs) > $this->maxBatch || max(array_map('mb_strlen', $request->inputs)) > $this->maxLength) {
                    throw new AiProviderException(AiProviderException::TIMEOUT);
                }

                return new EmbeddingBatchResponse(
                    array_map(fn (string $input): EmbeddingResponse => new EmbeddingResponse([0.1, 0.2, 0.3], 'fake-embedding', 0, []), $request->inputs),
                    'fake-embedding',
                    0,
                    [],
                );
            }
        });
        config(['ai.embedding.default' => 'slow']);
    }

    public function recordRequest(int $size): void
    {
        $this->requests[] = $size;
    }
}
