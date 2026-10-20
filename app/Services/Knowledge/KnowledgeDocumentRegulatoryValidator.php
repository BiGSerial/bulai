<?php

namespace App\Services\Knowledge;

use App\Data\Knowledge\DocumentIdentification;

final class KnowledgeDocumentRegulatoryValidator
{
    public function __construct(
        private readonly AnvisaRegulatoryCatalog $catalog,
        private readonly AnvisaCatalogNormalizer $normalizer,
    ) {}

    public function validate(DocumentIdentification $identification): DocumentIdentification
    {
        // Só a ambiguidade do próprio registro impede a consulta: um campo ambíguo ou ausente (ex.: princípio ativo) não deve bloquear a busca no catálogo.
        $registration = in_array('registration_number', $identification->ambiguousFields, true) ? null : ($identification->fields['registration_number_normalized'] ?? null);
        if ($registration === null) {
            return $this->withValidation($identification, ['status' => 'INCONCLUSIVE', 'reason' => 'registration_not_identified', 'conflicts' => [], 'comparisons' => []]);
        }
        if (! $this->catalog->isAvailable()) {
            return $this->withValidation($identification, ['status' => 'NOT_AVAILABLE', 'reason' => 'catalog_not_imported', 'conflicts' => [], 'comparisons' => []]);
        }
        $record = $this->catalog->findByRegistrationNumber($registration);
        if ($record === null) {
            return $this->withValidation($identification, ['status' => 'NOT_FOUND', 'reason' => 'registration_not_found', 'conflicts' => [], 'comparisons' => []]);
        }

        $product = $this->compareProduct($identification->fields['product_name'] ?? null, $record->productName);
        $ingredient = $this->compareIngredient($identification->fields['active_ingredient'] ?? null, $record->activeIngredient);
        $holder = $this->compareHolder($identification->fields['holder'] ?? null, $record->registrationHolder);
        $conflicts = [];
        if ($product === 'CONFLICT') {
            $conflicts[] = 'registration_product_mismatch';
        }
        if ($ingredient === 'CONFLICT') {
            $conflicts[] = 'active_ingredient_mismatch';
        }
        $status = $conflicts !== [] ? 'CONFLICT' : ($product === 'CONFIRMED' ? 'CONFIRMED' : 'INCONCLUSIVE');

        // O princípio ativo é indispensável (interações medicamentosas): se o PDF não o trouxe, o catálogo oficial, achado pelo registro, o completa.
        $filledFromCatalog = [];
        if (blank($identification->fields['active_ingredient'] ?? null) && filled($record->activeIngredient)) {
            $identification = $identification->with([
                'fields' => [...$identification->fields, 'active_ingredient' => $record->activeIngredient],
                'ambiguousFields' => array_values(array_diff($identification->ambiguousFields, ['active_ingredient'])),
            ]);
            $filledFromCatalog[] = 'active_ingredient';
        }

        return $this->withValidation($identification, [
            'status' => $status, 'reason' => $status === 'CONFIRMED' ? 'registration_and_product_converge' : ($status === 'CONFLICT' ? 'regulatory_data_diverges' : 'insufficient_comparable_fields'),
            'conflicts' => $conflicts, 'comparisons' => ['product_name' => $product, 'active_ingredient' => $ingredient, 'holder' => $holder],
            'catalog' => ['registration_number' => $record->registrationNumber, 'product_name' => $record->productName,
                'active_ingredient' => $record->activeIngredient, 'registration_holder' => $record->registrationHolder,
                'registration_status' => $record->registrationStatus, 'process_number' => $record->processNumber,
                'regulatory_category' => $record->regulatoryCategory, 'therapeutic_class' => $record->therapeuticClass, 'registration_expires_on' => $record->registrationExpiresOn],
            'filled_from_catalog' => $filledFromCatalog,
            'provenance' => $record->provenance(),
        ]);
    }

    private function compareProduct(?string $documentary, string $regulatory): string
    {
        if ($documentary === null || trim($documentary) === '') {
            return 'NOT_PROVIDED';
        }
        $left = str_replace(' ', '', $this->normalizer->text($documentary));
        $right = str_replace(' ', '', $this->normalizer->text($regulatory));
        $matches = $left === $right || (strlen($left) >= 5 && strlen($right) >= 5 && (str_contains($left, $right) || str_contains($right, $left)));

        return $matches ? 'CONFIRMED' : 'CONFLICT';
    }

    private function compareIngredient(?string $documentary, ?string $regulatory): string
    {
        $left = $this->normalizer->activeIngredientSet($documentary);
        $right = $this->normalizer->activeIngredientSet($regulatory);
        if ($left === [] || $right === []) {
            return 'NOT_PROVIDED';
        }

        return $left === $right ? 'CONFIRMED' : 'CONFLICT';
    }

    private function compareHolder(?string $documentary, string $regulatory): string
    {
        $left = $this->normalizer->holderTokens($documentary);
        $right = $this->normalizer->holderTokens($regulatory);
        if ($left === [] || $right === []) {
            return 'NOT_PROVIDED';
        }
        $shared = count(array_intersect($left, $right));

        return $shared / min(count($left), count($right)) >= 0.6 ? 'CONFIRMED' : 'DIVERGENT';
    }

    /** @param array<string, mixed> $validation */
    private function withValidation(DocumentIdentification $identification, array $validation): DocumentIdentification
    {
        return $identification->with([
            'automaticCreationEligible' => $identification->automaticCreationEligible && ($validation['status'] ?? null) !== 'CONFLICT',
            'regulatoryValidation' => $validation,
        ]);
    }
}
