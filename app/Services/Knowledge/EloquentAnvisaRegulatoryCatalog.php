<?php

namespace App\Services\Knowledge;

use App\Data\Knowledge\AnvisaMedicineRecord;
use App\Models\Knowledge\AnvisaCatalogImport;
use App\Models\Knowledge\AnvisaMedicine;

final class EloquentAnvisaRegulatoryCatalog implements AnvisaRegulatoryCatalog
{
    public function isAvailable(): bool
    {
        return AnvisaCatalogImport::query()->where('status', 'COMPLETED')->exists();
    }

    public function findByRegistrationNumber(string $registrationNumber): ?AnvisaMedicineRecord
    {
        $normalized = preg_replace('/\D/', '', $registrationNumber) ?? '';
        if ($normalized === '') {
            return null;
        }

        $medicine = AnvisaMedicine::query()->with('catalogImport:id,dataset_name,sha256')
            ->where('registration_number', $normalized)->first();
        if ($medicine === null) {
            return null;
        }

        return new AnvisaMedicineRecord(
            $medicine->registration_number,
            $medicine->product_name,
            $medicine->active_ingredient,
            $medicine->registration_holder,
            $medicine->registration_status,
            $medicine->process_number,
            $medicine->catalogImport->dataset_name,
            $medicine->catalogImport->sha256,
            $medicine->catalog_import_id,
            $medicine->regulatory_category,
            $medicine->therapeutic_class,
            $medicine->registration_expires_on,
        );
    }
}
