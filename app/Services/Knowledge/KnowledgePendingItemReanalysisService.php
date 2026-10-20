<?php

namespace App\Services\Knowledge;

use App\Models\Knowledge\IngestionBatch;
use App\Models\Knowledge\IngestionBatchItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final class KnowledgePendingItemReanalysisService
{
    public function __construct(
        private readonly KnowledgeFileService $files,
        private readonly KnowledgeDocumentIdentificationService $identification,
        private readonly KnowledgeBatchIngestionService $ingestion,
        private readonly KnowledgeDocumentLifecycleService $lifecycle,
        private readonly KnowledgePendingItemAssociationService $association,
        private readonly KnowledgeIngestionAuditService $audit,
    ) {}

    public function markBatchQueued(int $batchId, ?User $actor = null): int
    {
        $itemIds = IngestionBatchItem::query()->where('ingestion_batch_id', $batchId)
            ->where('status', IngestionBatchItem::STATUS_PENDING)->pluck('id');

        $queued = 0;
        foreach ($itemIds as $itemId) {
            $didQueue = DB::transaction(function () use ($itemId, $actor): bool {
                $item = IngestionBatchItem::query()->whereKey($itemId)->lockForUpdate()->firstOrFail();
                if ($item->status !== IngestionBatchItem::STATUS_PENDING) {
                    return false;
                }
                $state = data_get($item->metadata, 'reanalysis_progress.state');
                if (in_array($state, ['queued', 'processing'], true)) {
                    return false;
                }
                $this->setProgress($item, 'queued', 5, 'Aguardando processamento');
                $this->audit->append($item, 'reanalysis_queued', $actor);

                return true;
            });
            $queued += $didQueue ? 1 : 0;
        }

        return $queued;
    }

    /** @return array{status: string, error_code: ?string, document_id: ?int} */
    public function reanalyze(int $batchId, int $itemId, ?User $actor = null, bool $forceAi = false): array
    {
        try {
            $snapshot = DB::transaction(function () use ($batchId, $itemId, $actor, $forceAi): array {
                $item = IngestionBatchItem::query()->where('ingestion_batch_id', $batchId)->whereKey($itemId)->lockForUpdate()->firstOrFail();
                if ($item->status !== IngestionBatchItem::STATUS_PENDING) {
                    return ['resolved' => true, 'document_id' => $item->knowledge_document_id];
                }
                $this->setProgress($item, 'processing', 15, 'Validando integridade do arquivo');
                $storage = $this->files->itemStorage($item);
                if ($storage === null || ! $this->files->objectExists($storage['disk'], $storage['key'])) {
                    $this->setProgress($item, 'failed', 100, 'Arquivo original não encontrado');

                    return ['error' => 'PENDING_FILE_MISSING'];
                }
                if ($item->sha256 === null || ! hash_equals($item->sha256, $this->files->hashObject($storage['disk'], $storage['key']))) {
                    $this->setProgress($item, 'failed', 100, 'Falha na verificação de integridade');
                    $this->audit->append($item, 'integrity_check_failed', $actor);

                    return ['error' => 'PENDING_FILE_INVALID'];
                }
                $this->audit->append($item, 'reanalysis_requested', $actor, $forceAi ? ['forced_ai' => true] : []);

                return ['storage' => $storage, 'sha256' => $item->sha256];
            });
            if (isset($snapshot['resolved'])) {
                return ['status' => 'ALREADY_RESOLVED', 'error_code' => null, 'document_id' => $snapshot['document_id']];
            }
            if (isset($snapshot['error'])) {
                return ['status' => 'CONFLICT', 'error_code' => $snapshot['error'], 'document_id' => null];
            }

            $batch = IngestionBatch::query()->with('source')->findOrFail($batchId);
            $this->updateProgress($itemId, 'processing', 35, 'Lendo e identificando o PDF');
            $identification = $this->files->withLocalCopy($snapshot['storage']['disk'], $snapshot['storage']['key'], fn (string $path) => $this->identification->identifyPath($batch->source, $path, deep: true, forceAi: $forceAi));
            $prepared = DB::transaction(function () use ($batchId, $itemId, $snapshot, $identification, $actor): array {
                $item = IngestionBatchItem::query()->where('ingestion_batch_id', $batchId)->whereKey($itemId)->lockForUpdate()->firstOrFail();
                if ($item->status !== IngestionBatchItem::STATUS_PENDING) {
                    return ['resolved' => true, 'document_id' => $item->knowledge_document_id];
                }
                if ($this->files->itemStorage($item) !== $snapshot['storage'] || $item->sha256 !== $snapshot['sha256']) {
                    return ['error' => 'PENDING_FILE_CHANGED'];
                }
                $metadata = $item->metadata ?? [];
                if (isset($metadata['preliminary_identification'])) {
                    $metadata['preliminary_identification_history'][] = ['superseded_at' => now()->toISOString(), 'value' => $metadata['preliminary_identification']];
                }
                unset($metadata['ai_review']);
                if ($identification !== null) {
                    $metadata['preliminary_identification'] = [...$identification->metadata(), 'used_for_lifecycle' => true];
                    foreach (['title', 'document_type', 'active_ingredient', 'product_name', 'registration_number', 'holder'] as $field) {
                        $metadata[$field] = $identification->fields[$field] ?? ($metadata[$field] ?? null);
                    }
                }
                $item->forceFill(['source_document_id' => $identification?->sourceDocumentId() ?? $item->source_document_id, 'metadata' => $metadata])->save();
                $this->setProgress($item, 'processing', 65, 'Identificação preliminar concluída');
                $this->audit->append($item, 'preliminary_identification_completed', $actor, ['score' => $identification?->score ?? 0, 'pages' => $identification?->pagesAnalyzed ?? 0, 'eligible' => $identification?->automaticCreationEligible ?? false]);
                $this->audit->append($item, 'automatic_analysis', $actor, [
                    'extraction' => $identification?->extraction ?: null,
                    'classification' => $identification?->classification ?: null,
                    'score' => $identification?->score ?? 0,
                    'fields' => $identification?->fields ?? [],
                ]);

                return ['item' => $item->refresh()];
            });
            if (isset($prepared['resolved'])) {
                return ['status' => 'ALREADY_RESOLVED', 'error_code' => null, 'document_id' => $prepared['document_id']];
            }
            if (isset($prepared['error'])) {
                return ['status' => 'CONFLICT', 'error_code' => $prepared['error'], 'document_id' => null];
            }

            $item = $prepared['item'];
            $this->updateProgress($itemId, 'processing', 80, 'Procurando Documento de Conhecimento');
            [$document, , $ambiguous] = $this->ingestion->resolveDocument($item->external_key, $item->sha256, $item->source_document_id, $item->normalized_filename ?? '', $batch->source_id);
            if ($document === null && $ambiguous === null) {
                $document = $this->lifecycle->createAutomatically($batch, $item->external_key, $item->source_document_id, $item->metadata ?? []);
            }
            if ($document === null) {
                DB::transaction(function () use ($item, $ambiguous, $actor): void {
                    $locked = IngestionBatchItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
                    if ($locked->status === IngestionBatchItem::STATUS_PENDING) {
                        $locked->forceFill(['reason' => $ambiguous ?? 'no_match_found'])->save();
                        $this->setProgress($locked, 'completed', 100, 'Reanálise concluída; revisão necessária');
                        $this->audit->append($locked, 'reanalysis_remained_pending', $actor, ['reason' => $locked->reason]);
                    }
                });

                return ['status' => IngestionBatchItem::STATUS_PENDING, 'error_code' => $ambiguous ?? 'no_match_found', 'document_id' => null];
            }
            $this->updateProgress($itemId, 'processing', 90, 'Associando documento identificado');
            $result = $this->association->associate($batchId, $itemId, $document->id, IngestionBatchItem::METHOD_REANALYSIS, $actor);

            if ($result['status'] === 'CONFLICT') {
                $this->updateProgress($itemId, 'completed', 100, 'Reanálise concluída; conflito requer revisão');
            }

            return ['status' => $result['status'], 'error_code' => $result['error_code'], 'document_id' => $result['document_id']];
        } catch (Throwable $exception) {
            $errorId = Str::uuid()->toString();
            Log::error('Knowledge pending item reanalysis failed.', ['error_id' => $errorId, 'batch_id' => $batchId, 'item_id' => $itemId, 'exception' => $exception]);
            $this->updateProgress($itemId, 'failed', 100, 'Não foi possível concluir a reanálise');

            return ['status' => 'ERROR', 'error_code' => 'PROCESSING_ERROR', 'document_id' => null];
        }
    }

    /** Marca um único item como enfileirado para a análise (usado pelo pedido explícito de análise da IA). */
    public function queueItem(int $itemId, ?User $actor = null, bool $forceAi = false): bool
    {
        return DB::transaction(function () use ($itemId, $actor, $forceAi): bool {
            $item = IngestionBatchItem::query()->whereKey($itemId)->lockForUpdate()->firstOrFail();
            if ($item->status !== IngestionBatchItem::STATUS_PENDING || in_array(data_get($item->metadata, 'reanalysis_progress.state'), ['queued', 'processing'], true)) {
                return false;
            }
            $this->setProgress($item, 'queued', 5, $forceAi ? 'Aguardando a análise da IA' : 'Aguardando processamento');
            $this->audit->append($item, 'reanalysis_queued', $actor, $forceAi ? ['forced_ai' => true] : []);

            return true;
        });
    }

    /** Chamado quando o job é interrompido (timeout/falha): libera o item para nova tentativa e revisão manual. */
    public function markFailed(int $itemId): void
    {
        $this->updateProgress($itemId, 'failed', 100, 'Não foi possível concluir a classificação');
    }

    private function updateProgress(int $itemId, string $state, int $percent, string $label): void
    {
        DB::transaction(function () use ($itemId, $state, $percent, $label): void {
            $item = IngestionBatchItem::query()->whereKey($itemId)->lockForUpdate()->first();
            if ($item !== null && $item->status === IngestionBatchItem::STATUS_PENDING) {
                $this->setProgress($item, $state, $percent, $label);
            }
        });
    }

    private function setProgress(IngestionBatchItem $item, string $state, int $percent, string $label): void
    {
        $metadata = $item->metadata ?? [];
        $metadata['reanalysis_progress'] = [
            'state' => $state,
            'percent' => max(0, min(100, $percent)),
            'label' => $label,
            'updated_at' => now()->toISOString(),
        ];
        $item->forceFill(['metadata' => $metadata])->save();
    }
}
