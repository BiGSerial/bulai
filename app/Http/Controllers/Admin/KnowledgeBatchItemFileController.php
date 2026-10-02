<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Knowledge\IngestionBatch;
use App\Models\Knowledge\IngestionBatchItem;
use App\Services\Knowledge\KnowledgeFileService;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class KnowledgeBatchItemFileController extends Controller
{
    public function inline(IngestionBatch $batch, IngestionBatchItem $item, KnowledgeFileService $files): StreamedResponse|Response
    {
        return $this->serve($batch, $item, $files, 'inline');
    }

    public function download(IngestionBatch $batch, IngestionBatchItem $item, KnowledgeFileService $files): StreamedResponse|Response
    {
        return $this->serve($batch, $item, $files, 'attachment');
    }

    private function serve(IngestionBatch $batch, IngestionBatchItem $item, KnowledgeFileService $files, string $disposition): StreamedResponse|Response
    {
        abort_unless($item->ingestion_batch_id === $batch->id, 404);
        $item->loadMissing('knowledgeDocument');
        $storage = $files->evidenceStorage($item);
        if ($storage === null || ! $files->objectExists($storage['disk'], $storage['key'])) {
            Log::warning('knowledge_evidence_file_missing', ['batch_id' => $batch->id, 'item_id' => $item->id]);

            return response('O arquivo original não está disponível.', 404);
        }
        $filename = preg_replace('/[^A-Za-z0-9._ -]/', '_', Str::ascii(basename($item->original_filename))) ?: 'documento.pdf';
        $header = HeaderUtils::makeDisposition($disposition, $filename);

        return response()->stream(function () use ($files, $storage): void {
            $stream = $files->disk($storage['disk'])->readStream($storage['key']);
            if ($stream !== false) {
                try {
                    fpassthru($stream);
                } finally {
                    fclose($stream);
                }
            }
        }, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => $header, 'X-Content-Type-Options' => 'nosniff']);
    }
}
