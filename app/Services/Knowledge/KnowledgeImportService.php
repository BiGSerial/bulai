<?php

namespace App\Services\Knowledge;

use App\Models\Knowledge\IngestionBatch;
use App\Models\Knowledge\KnowledgeDocument;
use App\Models\Knowledge\KnowledgeSource;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class KnowledgeImportService
{
    public function import(array $payload): KnowledgeDocument
    {
        return DB::transaction(function () use ($payload): KnowledgeDocument {
            $sourcePayload = $payload['source'];

            $source = KnowledgeSource::query()->firstOrCreate(
                [
                    'name' => $sourcePayload['name'],
                    'organization' => $sourcePayload['organization'] ?? null,
                ],
                [
                    'type' => $sourcePayload['type'] ?? 'document',
                    'url' => $sourcePayload['url'] ?? null,
                    'description' => $sourcePayload['description'] ?? null,
                    'priority' => $sourcePayload['priority'] ?? null,
                    'active' => true,
                ],
            );

            $batch = null;
            if (isset($payload['batch'])) {
                $batch = IngestionBatch::query()->create([
                    'name' => $payload['batch']['name'],
                    'source_id' => $source->id,
                    'status' => 'READY',
                    'metadata' => $payload['batch']['metadata'] ?? [],
                ]);
            }

            $document = KnowledgeDocument::query()->updateOrCreate(
                ['external_key' => $payload['external_key']],
                [
                    'ingestion_batch_id' => $batch?->id,
                    'source_id' => $source->id,
                    'title' => $payload['title'],
                    'document_type' => $payload['document_type'],
                    'language' => $payload['language'] ?? 'pt-BR',
                    'metadata' => $payload['metadata'] ?? [],
                    'content_status' => KnowledgeDocument::STATUS_READY,
                    'embedding_status' => KnowledgeDocument::STATUS_PENDING,
                    'processed_at' => now(),
                ],
            );

            $document->chunks()->delete();

            foreach ($payload['chunks'] as $chunkPayload) {
                $document->chunks()->create([
                    'chunk_index' => $chunkPayload['index'],
                    'section' => $chunkPayload['section'] ?? null,
                    'page_start' => $chunkPayload['page_start'] ?? null,
                    'page_end' => $chunkPayload['page_end'] ?? null,
                    'content' => $chunkPayload['content'],
                    'token_count' => $chunkPayload['token_count'] ?? null,
                    'metadata' => Arr::get($chunkPayload, 'metadata', []),
                ]);
            }

            return $document->refresh()->load(['source', 'chunks']);
        });
    }
}
