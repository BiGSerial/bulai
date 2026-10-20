<?php

namespace App\Data\Knowledge;

final readonly class AnvisaMedicineRecord
{
    public function __construct(
        public string $registrationNumber,
        public string $productName,
        public ?string $activeIngredient,
        public string $registrationHolder,
        public string $registrationStatus,
        public ?string $processNumber,
        public string $datasetName,
        public string $datasetSha256,
        public int $catalogImportId,
        public ?string $regulatoryCategory = null,
        public ?string $therapeuticClass = null,
        public ?string $registrationExpiresOn = null,
    ) {}

    /** @return array<string, int|string|null> */
    public function provenance(): array
    {
        return ['dataset' => $this->datasetName, 'dataset_sha256' => $this->datasetSha256,
            'catalog_import_id' => $this->catalogImportId, 'registration_number' => $this->registrationNumber];
    }
}
