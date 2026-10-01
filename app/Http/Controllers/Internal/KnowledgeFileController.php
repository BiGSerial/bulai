<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\Knowledge\KnowledgeDocument;
use App\Services\Knowledge\KnowledgeFileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class KnowledgeFileController extends Controller
{
    public function __invoke(Request $request, KnowledgeDocument $document, KnowledgeFileService $service): JsonResponse
    {
        $payload = $request->validate([
            'file' => ['required', 'file', 'mimetypes:application/pdf', 'max:20480'],
        ]);

        $document = $service->attachPdf($document, $payload['file']);

        return response()->json([
            'document_id' => $document->id,
            'file_status' => $document->file_status,
            'sha256' => $document->sha256,
        ]);
    }
}
