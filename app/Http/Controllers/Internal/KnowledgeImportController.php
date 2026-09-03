<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Services\Knowledge\KnowledgeImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class KnowledgeImportController extends Controller
{
    public function __invoke(Request $request, KnowledgeImportService $service): JsonResponse
    {
        $payload = $request->validate([
            'external_key' => ['required', 'string', 'max:255'],
            'title' => ['required', 'string', 'max:255'],
            'document_type' => ['required', 'string', 'max:80'],
            'language' => ['nullable', 'string', 'max:16'],
            'source' => ['required', 'array'],
            'source.name' => ['required', 'string', 'max:255'],
            'source.type' => ['nullable', 'string', 'max:80'],
            'source.organization' => ['nullable', 'string', 'max:255'],
            'source.url' => ['nullable', 'url', 'max:2048'],
            'source.description' => ['nullable', 'string', 'max:2000'],
            'source.priority' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'metadata' => ['nullable', 'array'],
            'batch' => ['nullable', 'array'],
            'batch.name' => ['required_with:batch', 'string', 'max:255'],
            'batch.metadata' => ['nullable', 'array'],
            'chunks' => ['required', 'array', 'min:1', 'max:500'],
            'chunks.*.index' => ['required', 'integer', 'min:0', 'distinct'],
            'chunks.*.section' => ['nullable', 'string', 'max:255'],
            'chunks.*.page_start' => ['nullable', 'integer', 'min:1'],
            'chunks.*.page_end' => ['nullable', 'integer', 'min:1'],
            'chunks.*.content' => ['required', 'string', 'min:1', 'max:20000'],
            'chunks.*.token_count' => ['nullable', 'integer', 'min:1'],
            'chunks.*.metadata' => ['nullable', 'array'],
        ]);

        $document = $service->import($payload);

        return response()->json([
            'document_id' => $document->id,
            'external_key' => $document->external_key,
            'content_status' => $document->content_status,
            'file_status' => $document->file_status,
            'embedding_status' => $document->embedding_status,
            'chunks_count' => $document->chunks()->count(),
        ], 201);
    }
}
