<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Services\Knowledge\KnowledgeBatchIngestionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class KnowledgeBatchController extends Controller
{
    public function __invoke(Request $request, KnowledgeBatchIngestionService $service): JsonResponse
    {
        $maxFiles = (int) config('knowledge.ingestion.max_files');
        $maxFileKilobytes = (int) config('knowledge.ingestion.max_file_kilobytes');
        $payload = $request->validate([
            'batch' => ['required', 'array'],
            'batch.name' => ['required', 'string', 'max:255'],
            'batch.description' => ['nullable', 'string', 'max:2000'],
            'batch.source_id' => ['nullable', 'integer', Rule::exists('knowledge_sources', 'id')->where('active', true)],
            'items' => ['required', 'array', 'min:1', "max:{$maxFiles}"],
            'items.*.file' => ['required', 'file', "max:{$maxFileKilobytes}"],
            'items.*.external_key' => ['nullable', 'string', 'max:255'],
            'items.*.source_document_id' => ['nullable', 'string', 'max:255'],
            'items.*.original_filename' => ['nullable', 'string', 'max:255'],
            'items.*.metadata' => ['nullable', 'array'],
        ]);

        $batch = $service->ingest($payload['batch'], $payload['items']);

        return response()->json([
            'batch_id' => $batch->id,
            'name' => $batch->name,
            'status' => $batch->status,
            'total_items' => $batch->total_items,
            'associated_items' => $batch->associated_items,
            'duplicate_items' => $batch->duplicate_items,
            'pending_items' => $batch->pending_items,
            'error_items' => $batch->error_items,
            'items' => $batch->items->map(fn ($item) => [
                'id' => $item->id,
                'external_key' => $item->external_key,
                'source_document_id' => $item->source_document_id,
                'original_filename' => $item->original_filename,
                'status' => $item->status,
                'resolution_method' => $item->resolution_method,
                'reason' => $item->reason,
                'knowledge_document_id' => $item->knowledge_document_id,
                'document_id' => $item->knowledge_document_id,
                'file_attached' => $item->status === 'ASSOCIATED',
                'sha256' => $item->sha256,
                'error_id' => $item->metadata['processing_error_id'] ?? null,
            ])->all(),
        ], 201);
    }
}
