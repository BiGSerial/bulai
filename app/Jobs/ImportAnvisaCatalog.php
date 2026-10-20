<?php

namespace App\Jobs;

use App\Models\Knowledge\AnvisaCatalogImport;
use App\Services\Knowledge\AnvisaMedicineCsvImporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Importa o CSV do catálogo ANVISA enviado pelo Admin e apaga o arquivo ao final.
 * O conteúdo do CSV é tratado apenas como dado; falhas ficam registradas com um código de referência.
 */
class ImportAnvisaCatalog implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public int $timeout;

    public function __construct(public readonly int $catalogImportId)
    {
        $this->timeout = (int) config('knowledge.anvisa_catalog.job_timeout_seconds');
    }

    public function handle(AnvisaMedicineCsvImporter $importer): void
    {
        $import = AnvisaCatalogImport::query()->findOrFail($this->catalogImportId);
        $disk = Storage::disk(config('knowledge.filesystem_disk'));
        if ($import->stored_path === null || ! $disk->exists($import->stored_path)) {
            throw new RuntimeException('CATALOG_FILE_NOT_READABLE');
        }

        try {
            $importer->import($disk->path($import->stored_path), null, $import->encoding, $import);
        } finally {
            $this->discardStoredFile($import->stored_path);
            AnvisaCatalogImport::query()->whereKey($import->id)->update(['stored_path' => null]);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $import = AnvisaCatalogImport::query()->find($this->catalogImportId);
        if ($import === null) {
            return;
        }

        $reference = Str::uuid()->toString();
        Log::error('anvisa_catalog_import_failed', ['error_reference' => $reference, 'catalog_import_id' => $import->id, 'exception' => $exception]);
        $this->discardStoredFile($import->stored_path);
        $import->forceFill([
            'status' => 'FAILED', 'error_reference' => $reference, 'stored_path' => null, 'completed_at' => now(),
            'metadata' => [...($import->metadata ?? []), 'error_code' => 'CATALOG_IMPORT_FAILED'],
        ])->save();
    }

    private function discardStoredFile(?string $path): void
    {
        if ($path !== null) {
            Storage::disk(config('knowledge.filesystem_disk'))->delete($path);
        }
    }
}
