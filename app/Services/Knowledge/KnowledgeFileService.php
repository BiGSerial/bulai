<?php

namespace App\Services\Knowledge;

use App\Models\Knowledge\KnowledgeDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class KnowledgeFileService
{
    public function attachPdf(KnowledgeDocument $document, UploadedFile $file): KnowledgeDocument
    {
        $sha256 = hash_file('sha256', $file->getRealPath());

        $duplicate = KnowledgeDocument::query()
            ->where('sha256', $sha256)
            ->whereKeyNot($document->getKey())
            ->first();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'file' => "PDF already attached to document {$duplicate->id}.",
            ]);
        }

        $path = "knowledge/originals/{$document->id}/original.pdf";
        Storage::disk('local')->putFileAs("knowledge/originals/{$document->id}", $file, 'original.pdf');

        $document->forceFill([
            'original_filename' => $file->getClientOriginalName(),
            'storage_path' => $path,
            'mime_type' => $file->getMimeType(),
            'file_size' => $file->getSize(),
            'sha256' => $sha256,
            'file_status' => KnowledgeDocument::STATUS_READY,
        ])->save();

        return $document->refresh();
    }
}
