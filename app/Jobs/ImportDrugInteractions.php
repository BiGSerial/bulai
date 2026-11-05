<?php

namespace App\Jobs;

use App\Models\Knowledge\DrugInteractionImport;
use App\Services\Knowledge\DrugInteractionCsvImporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Importa um CSV de interações medicamentosas enviado pelo Admin e apaga o arquivo ao final.
 * O conteúdo é tratado apenas como dado; falhas ficam registradas com um código de referência.
 */
class ImportDrugInteractions implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public int $timeout;

    public function __construct(public readonly int $importId)
    {
        $this->timeout = (int) config('knowledge.drug_interactions.job_timeout_seconds');
    }

    public function handle(DrugInteractionCsvImporter $importer): void
    {
        $import = DrugInteractionImport::query()->findOrFail($this->importId);
        $disk = Storage::disk(config('knowledge.filesystem_disk'));
        if ($import->stored_path === null || ! $disk->exists($import->stored_path)) {
            throw new RuntimeException('INTERACTIONS_FILE_NOT_READABLE');
        }

        try {
            $importer->import($disk->path($import->stored_path), $import);
        } finally {
            $this->discardStoredFile($import->stored_path);
            DrugInteractionImport::query()->whereKey($import->id)->update(['stored_path' => null]);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $import = DrugInteractionImport::query()->find($this->importId);
        if ($import === null) {
            return;
        }

        $reference = Str::uuid()->toString();
        Log::error('drug_interactions_import_failed', ['error_reference' => $reference, 'import_id' => $import->id, 'exception' => $exception]);
        $this->discardStoredFile($import->stored_path);
        $import->forceFill([
            'status' => 'FAILED', 'error_reference' => $reference, 'stored_path' => null, 'completed_at' => now(),
            'metadata' => [...($import->metadata ?? []), 'error_code' => $import->metadata['error_code'] ?? 'INTERACTIONS_IMPORT_FAILED'],
        ])->save();
    }

    private function discardStoredFile(?string $path): void
    {
        if ($path !== null) {
            Storage::disk(config('knowledge.filesystem_disk'))->delete($path);
        }
    }
}
