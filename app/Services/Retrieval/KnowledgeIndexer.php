<?php

namespace App\Services\Retrieval;

use App\Data\Ai\EmbeddingBatchRequest;
use App\Models\Knowledge\KnowledgeChunk;
use App\Models\Knowledge\KnowledgeDocument;
use App\Models\Knowledge\KnowledgeProcessingEvent;
use App\Services\Ai\AiProviderException;
use App\Services\Ai\AiProviderManager;
use App\Services\Knowledge\ProcessingTimeline;
use Illuminate\Support\Facades\DB;

/**
 * Gera e grava embeddings dos chunks de documentos READY. Incremental por padrão: só processa chunks sem vetor, com
 * conteúdo alterado (hash) ou de outro modelo. O modelo e a dimensão ficam gravados em cada chunk, impedindo misturar
 * vetores incompatíveis na consulta.
 */
final class KnowledgeIndexer
{
    public function __construct(private readonly AiProviderManager $providers, private readonly ProcessingTimeline $timeline) {}

    /**
     * Com `$documentId`, a etapa `embed` da linha do tempo recebe o progresso por lote (a indexação global não registra nada).
     *
     * @return array{indexed: int, skipped: int, failed_batches: int, model: string|null, dimensions: int|null, error_code: string|null}
     */
    public function index(int $batchSize = 16, ?int $limit = null, bool $reindex = false, ?string $expectedModel = null, ?int $documentId = null): array
    {
        $summary = ['indexed' => 0, 'skipped' => 0, 'failed_batches' => 0, 'model' => null, 'dimensions' => null, 'error_code' => null];
        $touchedDocuments = [];

        $query = KnowledgeChunk::query()
            ->whereHas('document', fn ($documents) => $documents->where('content_status', KnowledgeDocument::STATUS_READY))
            ->when($documentId !== null, fn ($chunks) => $chunks->where('document_id', $documentId))
            ->orderBy('id');

        $event = null;
        $progress = ['batch' => 0, 'batches' => 0, 'chunks_done' => 0, 'chunks' => 0];
        if ($documentId !== null) {
            $progress['chunks'] = (clone $query)->count();
            $progress['batches'] = (int) ceil($progress['chunks'] / max(1, $batchSize));
            $event = $this->timeline->start($documentId, KnowledgeProcessingEvent::STEP_EMBED, $progress);
        }

        try {
            $provider = $this->providers->embedding();
        } catch (AiProviderException $exception) {
            $this->timeline->fail($event, $exception->errorCode);

            throw $exception;
        }

        $query->chunkById($batchSize, function ($chunks) use (&$summary, &$touchedDocuments, &$progress, $event, $provider, $reindex, $limit, $expectedModel): bool {
            $progress['batch']++;
            $pending = $chunks->filter(fn (KnowledgeChunk $chunk): bool => $reindex || $this->needsEmbedding($chunk, $expectedModel));
            $summary['skipped'] += $chunks->count() - $pending->count();
            if ($pending->isEmpty()) {
                $progress['chunks_done'] += $chunks->count();
                $this->timeline->progress($event, $progress);

                return true;
            }
            if ($limit !== null) {
                $pending = $pending->take(max(0, $limit - $summary['indexed']));
            }

            try {
                $response = $provider->embedMany(new EmbeddingBatchRequest($pending->pluck('content')->values()->all(), $expectedModel));
            } catch (AiProviderException $exception) {
                $summary['failed_batches']++;
                $summary['error_code'] = $exception->errorCode;
                $pending->each(fn (KnowledgeChunk $chunk) => $touchedDocuments[$chunk->document_id] = false);

                return $summary['failed_batches'] < 3;
            }

            foreach ($pending->values() as $position => $chunk) {
                $embedding = $response->embeddings[$position] ?? null;
                if ($embedding === null) {
                    $summary['failed_batches']++;
                    $touchedDocuments[$chunk->document_id] = false;

                    continue;
                }
                $this->store($chunk, $embedding->vector, $response->model);
                $summary['model'] = $response->model;
                $summary['dimensions'] = $embedding->dimensions();
                $summary['indexed']++;
                $touchedDocuments[$chunk->document_id] ??= true;
            }
            $progress['chunks_done'] += $chunks->count();
            $this->timeline->progress($event, $progress);

            return $limit === null || $summary['indexed'] < $limit;
        });

        $this->refreshDocumentStatus(array_keys($touchedDocuments));

        if ($event !== null) {
            $detail = ['indexed' => $summary['indexed'], 'skipped' => $summary['skipped'], 'model' => $summary['model'], 'batch' => $progress['batch'], 'chunks_done' => $progress['chunks_done']];
            $summary['error_code'] !== null || $summary['failed_batches'] > 0
                ? $this->timeline->fail($event, $summary['error_code'] ?? 'EMBEDDING_INCOMPLETE', [...$detail, 'failed_batches' => $summary['failed_batches']])
                : $this->timeline->finish($event, $detail);
        }

        return $summary;
    }

    /** @param list<float> $vector */
    private function store(KnowledgeChunk $chunk, array $vector, string $model): void
    {
        DB::table('knowledge_chunks')->where('id', $chunk->id)->update([
            'embedding' => DB::raw("'[".implode(',', array_map(fn (float $value): string => (string) $value, $vector))."]'::vector"),
            'embedding_model' => $model,
            'embedding_dimensions' => count($vector),
            'embedding_content_hash' => hash('sha256', $chunk->content),
            'embedded_at' => now(),
        ]);
    }

    private function needsEmbedding(KnowledgeChunk $chunk, ?string $expectedModel): bool
    {
        $stored = DB::table('knowledge_chunks')->where('id', $chunk->id)->first(['embedding_model', 'embedding_content_hash']);

        return $stored->embedding_model === null
            || $stored->embedding_content_hash !== hash('sha256', $chunk->content)
            || ($expectedModel !== null && $stored->embedding_model !== $expectedModel);
    }

    /** @param list<int|string> $documentIds */
    private function refreshDocumentStatus(array $documentIds): void
    {
        foreach ($documentIds as $documentId) {
            $missing = DB::table('knowledge_chunks')->where('document_id', $documentId)->whereNull('embedding_model')->exists();
            KnowledgeDocument::query()->whereKey($documentId)->update([
                'embedding_status' => $missing ? KnowledgeDocument::STATUS_FAILED : KnowledgeDocument::STATUS_READY,
            ]);
        }
    }
}
