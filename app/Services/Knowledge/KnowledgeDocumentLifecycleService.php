<?php

namespace App\Services\Knowledge;

use App\Models\Knowledge\IngestionBatch;
use App\Models\Knowledge\KnowledgeDocument;
use App\Models\Knowledge\KnowledgeSource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class KnowledgeDocumentLifecycleService
{
    /** @param array<string, mixed> $metadata */
    public function createAutomatically(IngestionBatch $batch, ?string $externalKey, ?string $sourceDocumentId, array $metadata): ?KnowledgeDocument
    {
        $identification = $metadata['preliminary_identification'] ?? null;
        if (is_array($identification) && ($identification['used_for_lifecycle'] ?? false) === true && ($identification['automatic_creation_eligible'] ?? false) !== true) {
            return null;
        }

        $title = $this->string($metadata['title'] ?? null);
        $documentType = $this->string($metadata['document_type'] ?? null);
        if ($batch->source_id === null || $title === null || $documentType === null || ($externalKey === null && $sourceDocumentId === null)) {
            return null;
        }

        return $this->create($batch->source_id, ['external_key' => $externalKey, 'source_document_id' => $sourceDocumentId,
            'title' => $title, 'document_type' => $documentType, 'language' => $this->string($metadata['language'] ?? null) ?? 'pt-BR',
            'metadata' => $metadata, 'ingestion_batch_id' => $batch->id], true);
    }

    /** @param array<string, mixed> $attributes */
    public function createManual(int $sourceId, array $attributes): KnowledgeDocument
    {
        return $this->create($sourceId, $attributes, false);
    }

    /** @param array<string, mixed> $attributes */
    private function create(int $sourceId, array $attributes, bool $automatic): KnowledgeDocument
    {
        return DB::transaction(function () use ($sourceId, $attributes, $automatic): KnowledgeDocument {
            KnowledgeSource::query()->whereKey($sourceId)->lockForUpdate()->firstOrFail();
            $externalKey = $this->string($attributes['external_key'] ?? null);
            $sourceDocumentId = $this->string($attributes['source_document_id'] ?? null);
            $existing = $this->findExisting($sourceId, $externalKey, $sourceDocumentId);
            if ($existing !== null) {
                if ($automatic) {
                    return $existing;
                }

                throw ValidationException::withMessages([
                    'newDocumentTitle' => 'Já existe um documento com o identificador informado. Associe o documento existente.',
                ]);
            }

            if (! $automatic) {
                $sameTitle = KnowledgeDocument::query()->where('source_id', $sourceId)
                    ->whereRaw('LOWER(title) = ?', [mb_strtolower(trim((string) $attributes['title']))])
                    ->where('document_type', $attributes['document_type'])->first();
                if ($sameTitle !== null) {
                    throw ValidationException::withMessages(['newDocumentTitle' => 'Já existe um documento com este título e tipo nesta origem.']);
                }
            }

            $externalKey ??= $sourceDocumentId === null
                ? 'manual:'.$sourceId.':'.Str::uuid()->toString()
                : 'source:'.$sourceId.':'.hash('sha256', Str::lower($sourceDocumentId));

            return KnowledgeDocument::query()->create([
                'source_id' => $sourceId, 'ingestion_batch_id' => $attributes['ingestion_batch_id'] ?? null,
                'external_key' => $externalKey, 'source_document_id' => $sourceDocumentId,
                'title' => trim((string) $attributes['title']), 'document_type' => trim((string) $attributes['document_type']),
                'language' => $attributes['language'] ?? 'pt-BR', 'metadata' => $attributes['metadata'] ?? [],
                'content_status' => KnowledgeDocument::STATUS_PENDING, 'file_status' => KnowledgeDocument::STATUS_PENDING,
                'embedding_status' => KnowledgeDocument::STATUS_PENDING,
            ]);
        });
    }

    private function findExisting(int $sourceId, ?string $externalKey, ?string $sourceDocumentId): ?KnowledgeDocument
    {
        if ($externalKey !== null && ($document = KnowledgeDocument::query()->where('external_key', $externalKey)->first()) !== null) {
            return $document;
        }

        return $sourceDocumentId === null ? null : KnowledgeDocument::query()->where('source_id', $sourceId)->where('source_document_id', $sourceDocumentId)->first();
    }

    private function string(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
