<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Knowledge\KnowledgeDocument;
use App\Services\Knowledge\KnowledgeFileService;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** PDF guardado de um documento, para o Admin conferir a bula ao identificar o medicamento (só a área administrativa). */
final class KnowledgeDocumentFileController extends Controller
{
    public function __invoke(KnowledgeDocument $document, KnowledgeFileService $files): StreamedResponse|Response
    {
        $storage = $files->documentStorage($document);
        if ($storage === null || ! $files->objectExists($storage['disk'], $storage['key'])) {
            Log::warning('knowledge_document_file_missing', ['document_id' => $document->id]);

            return response('O PDF desta bula não está disponível.', 404);
        }
        $filename = preg_replace('/[^A-Za-z0-9._ -]/', '_', Str::ascii(basename((string) ($document->original_filename ?: 'bula.pdf')))) ?: 'bula.pdf';

        return response()->stream(function () use ($files, $storage): void {
            $stream = $files->disk($storage['disk'])->readStream($storage['key']);
            if ($stream !== false) {
                try {
                    fpassthru($stream);
                } finally {
                    fclose($stream);
                }
            }
        }, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => HeaderUtils::makeDisposition('inline', $filename), 'X-Content-Type-Options' => 'nosniff']);
    }
}
