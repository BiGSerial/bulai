<?php

namespace App\Services\Knowledge;

use App\Data\Knowledge\AnvisaMedicineRecord;

interface AnvisaRegulatoryCatalog
{
    public function isAvailable(): bool;

    public function findByRegistrationNumber(string $registrationNumber): ?AnvisaMedicineRecord;
}
