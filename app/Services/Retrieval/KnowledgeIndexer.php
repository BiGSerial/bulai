<?php

namespace App\Services\Retrieval;

use App\Data\Ai\EmbeddingBatchRequest;
use App\Models\Knowledge\KnowledgeChunk;
use App\Models\Knowledge\KnowledgeDocument;
use App\Models\Knowledge\KnowledgeProcessingEvent;
use App\Services\Ai\AiProviderException;
use App\Services\Ai\AiProviderManager;
use App\Services\Ai\EmbeddingProvider;
use App\Services\Knowledge\ProcessingTimeline;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Gera e grava embeddings dos chunks de documentos READY. Incremental por padrão: só processa chunks sem vetor, com
 * conteúdo alterado (hash) ou de outro modelo. O modelo e a dimensão ficam gravados em cada chunk, impedindo misturar
 * vetores incompatíveis na consulta.
 *
 * O serviço local de embeddings (bge-m3 em CPU) calcula um texto por vez: o tempo de uma requisição é a soma dos
 * trechos enviados. Por isso cada requisição é limitada por quantidade (`ai.embedding.batch_size`) E por caracteres
 * (`ai.embedding.batch_characters`), e um lote que estoura o limite de tempo é refeito trecho a trecho.
 */
final class KnowledgeIndexer
{
    /** Lotes com falha que interrompem a indexação (evita martelar um serviço fora do ar). */
    private const MAX_FAILED_BATCHES = 3;

    public function __construct(private readonly AiProviderManager $providers, private readonly ProcessingTimeline $timeline) {}

    /**
     * Com `$documentId`, a etapa `embed` da linha do tempo recebe o progresso por lote (a indexação global não registra nada)
     * e a situação do índice do documento é sempre atualizada ao fim.
     *
     * @return array{indexed: int, skipped: int, failed_batches: int, split_batches: int, model: string|null, dimensions: int|null, error_code: string|null}
     */
    public function index(?int $batchSize = null, ?int $limit = null, bool $reindex = false, ?string $expectedModel = null, ?int $documentId = null): array
    {
        $batchSize = max(1, $batchSize ?? (int) config('ai.embedding.batch_size', 4));
        $batchCharacters = max(1, (int) config('ai.embedding.batch_characters', 6_000));
        $summary = ['indexed' => 0, 'skipped' => 0, 'failed_batches' => 0, 'split_batches' => 0, 'model' => null, 'dimensions' => null, 'error_code' => null];
        $touchedDocuments = [];

        $query = KnowledgeChunk::query()
            ->whereHas('document', fn ($documents) => $documents->where('content_status', KnowledgeDocument::STATUS_READY))
            ->when($documentId !== null, fn ($chunks) => $chunks->where('document_id', $documentId))
            ->orderBy('id');

        $event = null;
        $progress = ['batch' => 0, 'batches' => 0, 'chunks_done' => 0, 'chunks' => 0, 'batch_size' => $batchSize, 'batch_characters' => $batchCharacters];
        if ($documentId !== null) {
            $progress['chunks'] = (clone $query)->count();
            $progress['batches'] = (int) ceil($progress['chunks'] / $batchSize);
            $event = $this->timeline->start($documentId, KnowledgeProcessingEvent::STEP_EMBED, $progress);
        }

        try {
            $provider = $this->providers->embedding();
        } catch (AiProviderException $exception) {
            $this->timeline->fail($event, $exception->errorCode);

            throw $exception;
        }

        $query->chunkById($batchSize, function ($chunks) use (&$summary, &$touchedDocuments, &$progress, $event, $provider, $reindex, $limit, $expectedModel, $batchCharacters): bool {
            $progress['batch']++;
            $pending = $chunks->filter(fn (KnowledgeChunk $chunk): bool => $reindex || $this->needsEmbedding($chunk, $expectedModel));
            $summary['skipped'] += $chunks->count() - $pending->count();
            if ($limit !== null) {
                $pending = $pending->take(max(0, $limit - $summary['indexed']));
            }

            foreach ($this->groupByCharacters($pending->values(), $batchCharacters) as $group) {
                $this->embedGroup($provider, $group, $expectedModel, $summary, $touchedDocuments);
                if ($summary['failed_batches'] >= self::MAX_FAILED_BATCHES) {
                    break;
                }
            }
            $progress['chunks_done'] += $chunks->count();
            $this->timeline->progress($event, $progress);

            return $summary['failed_batches'] < self::MAX_FAILED_BATCHES && ($limit === null || $summary['indexed'] < $limit);
        });

        $this->refreshDocumentStatus($documentId !== null ? array_unique([...array_keys($touchedDocuments), $documentId]) : array_keys($touchedDocuments));

        if ($event !== null) {
            $detail = ['indexed' => $summary['indexed'], 'skipped' => $summary['skipped'], 'model' => $summary['model'], 'batch' => $progress['batch'], 'chunks_done' => $progress['chunks_done'], 'split_batches' => $summary['split_batches']];
            $summary['error_code'] !== null || $summary['failed_batches'] > 0
                ? $this->timeline->fail($event, $summary['error_code'] ?? 'EMBEDDING_INCOMPLETE', [...$detail, 'failed_batches' => $summary['failed_batches']])
                : $this->timeline->finish($event, $detail);
        }

        return $summary;
    }

    /**
     * Divide os trechos pendentes em grupos cujo total de caracteres cabe no orçamento de uma requisição; um trecho
     * maior que o orçamento vai sozinho.
     *
     * @param  Collection<int, KnowledgeChunk>  $chunks
     * @return list<Collection<int, KnowledgeChunk>>
     */
    private function groupByCharacters(Collection $chunks, int $batchCharacters): array
    {
        $groups = [];
        $current = [];
        $characters = 0;
        foreach ($chunks as $chunk) {
            $length = mb_strlen($chunk->content);
            if ($current !== [] && $characters + $length > $batchCharacters) {
                $groups[] = new Collection($current);
                $current = [];
                $characters = 0;
            }
            $current[] = $chunk;
            $characters += $length;
        }
        if ($current !== []) {
            $groups[] = new Collection($current);
        }

        return $groups;
    }

    /**
     * Envia um grupo ao provedor e grava os vetores. Se o grupo estourar o limite de tempo (ou o serviço cair no meio),
     * os trechos são refeitos um a um: um lote lento não derruba os trechos que caberiam no tempo.
     *
     * @param  Collection<int, KnowledgeChunk>  $group
     * @param  array{indexed: int, skipped: int, failed_batches: int, split_batches: int, model: string|null, dimensions: int|null, error_code: string|null}  $summary
     * @param  array<int|string, bool>  $touchedDocuments
     */
    private function embedGroup(EmbeddingProvider $provider, Collection $group, ?string $expectedModel, array &$summary, array &$touchedDocuments): void
    {
        if ($group->isEmpty()) {
            return;
        }

        try {
            $response = $provider->embedMany(new EmbeddingBatchRequest($group->pluck('content')->values()->all(), $expectedModel));
        } catch (AiProviderException $exception) {
            if ($group->count() > 1 && in_array($exception->errorCode, [AiProviderException::TIMEOUT, AiProviderException::UNAVAILABLE], true)) {
                $summary['split_batches']++;
                foreach ($group as $chunk) {
                    $this->embedGroup($provider, new Collection([$chunk]), $expectedModel, $summary, $touchedDocuments);
                    if ($summary['failed_batches'] >= self::MAX_FAILED_BATCHES) {
                        return;
                    }
                }

                return;
            }

            $summary['failed_batches']++;
            $summary['error_code'] = $exception->errorCode;
            $group->each(fn (KnowledgeChunk $chunk) => $touchedDocuments[$chunk->document_id] = false);

            return;
        }

        foreach ($group->values() as $position => $chunk) {
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
