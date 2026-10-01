<?php

namespace App\Services\Knowledge;

use App\Models\Knowledge\IngestionBatchItem;
use App\Models\Knowledge\KnowledgeDocument;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class KnowledgeFileService
{
    public function attachPdf(KnowledgeDocument $document, UploadedFile $file): KnowledgeDocument
    {
        $sha256 = $this->hashUploadedFile($file);
        $ownedPath = null;

        try {
            return DB::transaction(function () use ($document, $file, $sha256, &$ownedPath): KnowledgeDocument {
                $lockedDocument = KnowledgeDocument::query()
                    ->whereKey($document->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                $this->assertDocumentCanReceivePdf($lockedDocument, $sha256);

                $ownedPath = $this->writeDefinitivePdf($lockedDocument, $file);

                return $this->persistAttachment($lockedDocument, $file, $sha256, $ownedPath);
            });
        } catch (Throwable $exception) {
            $this->deleteOwnedFile($ownedPath);

            throw $exception;
        }
    }

    public function assertDocumentCanReceivePdf(KnowledgeDocument $document, string $sha256): void
    {
        if ($document->storage_key !== null || $document->storage_path !== null) {
            throw ValidationException::withMessages([
                'file' => "Document {$document->id} already has an attached PDF.",
            ]);
        }

        $duplicate = KnowledgeDocument::query()
            ->where('sha256', $sha256)
            ->whereKeyNot($document->getKey())
            ->first();

        if ($duplicate !== null) {
            throw ValidationException::withMessages([
                'file' => "PDF already attached to document {$duplicate->id}.",
            ]);
        }
    }

    public function writeDefinitivePdf(KnowledgeDocument $document, UploadedFile $file): string
    {
        $operationId = Str::uuid()->toString();
        $stagingDirectory = "knowledge/staging/{$operationId}";
        $stagingPath = "{$stagingDirectory}/original.pdf";
        $directory = "knowledge/originals/{$document->id}";
        $filename = "{$operationId}.pdf";
        $path = "{$directory}/{$filename}";
        $diskName = $this->diskName();
        $disk = $this->disk($diskName);
        $writtenPath = $disk->putFileAs($stagingDirectory, $file, 'original.pdf');

        if ($writtenPath === false) {
            throw new RuntimeException("Failed to stage PDF for document {$document->id}.");
        }

        try {
            $this->copyAndVerify($diskName, $stagingPath, $diskName, $path, $this->hashUploadedFile($file));
            $disk->delete($stagingPath);
        } catch (Throwable $exception) {
            $disk->delete($stagingPath);

            throw $exception;
        }

        return $path;
    }

    public function promotePendingPdf(KnowledgeDocument $document, string $pendingPath): string
    {
        return $this->promotePendingObject($document, $this->diskName(), $pendingPath);
    }

    public function promotePendingObject(KnowledgeDocument $document, string $pendingDiskName, string $pendingPath): string
    {
        $operationId = Str::uuid()->toString();
        $stagingDirectory = "knowledge/staging/{$operationId}";
        $stagingPath = "{$stagingDirectory}/original.pdf";
        $definitivePath = "knowledge/originals/{$document->id}/{$operationId}.pdf";
        $targetDiskName = $this->diskName();
        $targetDisk = $this->disk($targetDiskName);

        try {
            $expectedHash = $this->hashObject($pendingDiskName, $pendingPath);
            $this->copyAndVerify($pendingDiskName, $pendingPath, $targetDiskName, $stagingPath, $expectedHash);
            $this->copyAndVerify($targetDiskName, $stagingPath, $targetDiskName, $definitivePath, $expectedHash);
            $targetDisk->delete($stagingPath);
        } catch (Throwable $exception) {
            $targetDisk->delete([$stagingPath, $definitivePath]);

            throw $exception;
        }

        return $definitivePath;
    }

    public function persistAttachment(KnowledgeDocument $document, UploadedFile $file, string $sha256, string $path): KnowledgeDocument
    {
        $document->forceFill([
            'original_filename' => $file->getClientOriginalName(),
            'normalized_filename' => self::normalizeFilename($file->getClientOriginalName()),
            'storage_path' => $path,
            'storage_disk' => $this->diskName(),
            'storage_key' => $path,
            'mime_type' => $file->getMimeType(),
            'file_size' => $file->getSize(),
            'sha256' => $sha256,
            'file_status' => KnowledgeDocument::STATUS_READY,
        ])->save();

        return $document->refresh();
    }

    public function persistPendingAttachment(KnowledgeDocument $document, IngestionBatchItem $item, string $path): KnowledgeDocument
    {
        $document->forceFill([
            'original_filename' => $item->original_filename,
            'normalized_filename' => $item->normalized_filename,
            'storage_path' => $path,
            'storage_disk' => $this->diskName(),
            'storage_key' => $path,
            'mime_type' => $item->mime_type,
            'file_size' => $item->file_size,
            'sha256' => $item->sha256,
            'file_status' => KnowledgeDocument::STATUS_READY,
        ])->save();

        return $document->refresh();
    }

    public function deleteOwnedFile(?string $path, ?string $diskName = null): void
    {
        if ($path !== null) {
            $this->disk($diskName)->delete($path);
        }
    }

    public function diskName(): string
    {
        return (string) config('knowledge.filesystem_disk', 'knowledge');
    }

    public function disk(?string $diskName = null): FilesystemAdapter
    {
        return Storage::disk($diskName ?? $this->diskName());
    }

    /** @return array{disk: string, key: string}|null */
    public function documentStorage(KnowledgeDocument $document): ?array
    {
        $key = $document->storage_key ?? $document->storage_path;
        if ($key === null || str_contains($key, '..') || ! str_starts_with($key, "knowledge/originals/{$document->id}/")) {
            return null;
        }

        return ['disk' => $document->storage_disk ?? $this->diskName(), 'key' => $key];
    }

    /** @return array{disk: string, key: string}|null */
    public function itemStorage(IngestionBatchItem $item): ?array
    {
        $key = $item->storage_key ?? $item->storage_path;
        if ($key === null || str_contains($key, '..') || ! str_starts_with($key, "knowledge/pending/{$item->ingestion_batch_id}/")) {
            return null;
        }

        return ['disk' => $item->storage_disk ?? $this->diskName(), 'key' => $key];
    }

    public function hashUploadedFile(UploadedFile $file): string
    {
        $stream = fopen($file->getRealPath(), 'rb');
        if ($stream === false) {
            throw new RuntimeException('Failed to read uploaded PDF.');
        }

        try {
            $context = hash_init('sha256');
            hash_update_stream($context, $stream);

            return hash_final($context);
        } finally {
            fclose($stream);
        }
    }

    public function hashObject(string $diskName, string $key): string
    {
        $stream = $this->disk($diskName)->readStream($key);
        if ($stream === false) {
            throw new RuntimeException('Failed to read stored PDF.');
        }

        try {
            $context = hash_init('sha256');
            hash_update_stream($context, $stream);

            return hash_final($context);
        } finally {
            fclose($stream);
        }
    }

    public function objectExists(string $diskName, string $key): bool
    {
        return $this->disk($diskName)->exists($key);
    }

    public function objectSize(string $diskName, string $key): int
    {
        return $this->disk($diskName)->size($key);
    }

    public function quarantinePendingPdf(IngestionBatchItem $item, UploadedFile $file): string
    {
        $key = "knowledge/pending/{$item->ingestion_batch_id}/".Str::uuid()->toString().'.pdf';
        $written = $this->disk()->putFileAs(dirname($key), $file, basename($key));
        if ($written === false) {
            throw new RuntimeException("Failed to quarantine pending PDF for batch item {$item->id}.");
        }

        return $key;
    }

    /** @template T @param callable(string): T $callback @return T */
    public function withLocalCopy(string $diskName, string $key, callable $callback): mixed
    {
        $source = $this->disk($diskName)->readStream($key);
        if ($source === false) {
            throw new RuntimeException('DOCUMENT_FILE_MISSING');
        }

        $temporaryPath = tempnam(sys_get_temp_dir(), 'bulai-knowledge-');
        if ($temporaryPath === false) {
            fclose($source);
            throw new RuntimeException('TEMPORARY_FILE_FAILED');
        }

        try {
            $target = fopen($temporaryPath, 'wb');
            if ($target === false) {
                throw new RuntimeException('TEMPORARY_FILE_FAILED');
            }

            try {
                if (stream_copy_to_stream($source, $target) === false) {
                    throw new RuntimeException('TEMPORARY_FILE_FAILED');
                }
            } finally {
                fclose($target);
            }

            return $callback($temporaryPath);
        } finally {
            fclose($source);
            @unlink($temporaryPath);
        }
    }

    private function copyAndVerify(
        string $sourceDiskName,
        string $source,
        string $destinationDiskName,
        string $destination,
        string $expectedHash,
    ): void {
        $sourceStream = $this->disk($sourceDiskName)->readStream($source);
        if ($sourceStream === false) {
            throw new RuntimeException('Failed to promote stored PDF.');
        }

        $destinationDisk = $this->disk($destinationDiskName);

        try {
            if (! $destinationDisk->put($destination, $sourceStream) || ! $destinationDisk->exists($destination)) {
                throw new RuntimeException('Failed to promote stored PDF.');
            }
        } catch (Throwable $exception) {
            $destinationDisk->delete($destination);

            throw $exception;
        } finally {
            fclose($sourceStream);
        }

        if ($this->hashObject($destinationDiskName, $destination) !== $expectedHash) {
            $destinationDisk->delete($destination);
            throw new RuntimeException('Stored PDF verification failed.');
        }
    }

    public static function normalizeFilename(string $filename): string
    {
        $filename = Str::ascii(mb_strtolower($filename));
        $filename = str_replace(['_', ' '], '-', $filename);
        $filename = preg_replace('/[^a-z0-9.\-]/', '', $filename) ?? '';
        $filename = preg_replace('/-+/', '-', $filename) ?? '';

        return trim($filename, '-');
    }
}
