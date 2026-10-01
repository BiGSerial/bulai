<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\Knowledge\IngestionBatch;
use App\Models\Knowledge\IngestionBatchItem;
use App\Services\Knowledge\KnowledgePendingItemAssociationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class KnowledgePendingItemAssociationController extends Controller
{
    public function __invoke(
        Request $request,
        IngestionBatch $batch,
        IngestionBatchItem $item,
        KnowledgePendingItemAssociationService $service,
    ): JsonResponse {
        $payload = $request->validate([
            'document_id' => ['required', 'integer', 'exists:knowledge_documents,id'],
        ]);

        $result = $service->associate($batch->id, $item->id, $payload['document_id']);

        return response()->json([
            'batch_id' => $batch->id,
            'item_id' => $item->id,
            'document_id' => $result['document_id'],
            'status' => $result['status'],
            'file_attached' => $result['file_attached'],
            'error_code' => $result['error_code'],
            'error_id' => $result['error_id'],
        ], $result['http_status']);
    }
}
