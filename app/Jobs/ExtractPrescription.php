<?php

namespace App\Jobs;

use App\Models\PatientPrescription;
use App\Services\Patient\PrescriptionExtractionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

/** Lê a foto de uma receita (área transitória) e grava a extração para revisão do paciente. A imagem é apagada ao final. */
class ExtractPrescription implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 150;

    public function __construct(public int $prescriptionId, public string $storedPath) {}

    public function handle(PrescriptionExtractionService $service): void
    {
        $prescription = PatientPrescription::query()->find($this->prescriptionId);
        if ($prescription === null) {
            Storage::disk((string) config('knowledge.filesystem_disk'))->delete($this->storedPath);

            return;
        }
        $service->extract($prescription, $this->storedPath);
    }

    public function failed(?Throwable $exception): void
    {
        Storage::disk((string) config('knowledge.filesystem_disk'))->delete($this->storedPath);
        PatientPrescription::query()->whereKey($this->prescriptionId)
            ->whereIn('status', [PatientPrescription::STATUS_PENDING, PatientPrescription::STATUS_PROCESSING])
            ->update(['status' => PatientPrescription::STATUS_FAILED, 'error_code' => 'PRESCRIPTION_EXTRACTION_FAILED']);
    }
}
