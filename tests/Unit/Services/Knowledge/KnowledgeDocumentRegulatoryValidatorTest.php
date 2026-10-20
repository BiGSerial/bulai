<?php

namespace Tests\Unit\Services\Knowledge;

use App\Data\Knowledge\AnvisaMedicineRecord;
use App\Data\Knowledge\DocumentIdentification;
use App\Services\Knowledge\AnvisaCatalogNormalizer;
use App\Services\Knowledge\AnvisaRegulatoryCatalog;
use App\Services\Knowledge\KnowledgeDocumentRegulatoryValidator;
use Tests\TestCase;

final class KnowledgeDocumentRegulatoryValidatorTest extends TestCase
{
    public function test_confirms_real_atrovent_identity_with_independent_catalog_evidence(): void
    {
        $result = $this->validator($this->record())->validate($this->identification());

        $this->assertSame('CONFIRMED', $result->regulatoryValidation['status']);
        $this->assertSame(['product_name' => 'CONFIRMED', 'active_ingredient' => 'CONFIRMED', 'holder' => 'CONFIRMED'], $result->regulatoryValidation['comparisons']);
        $this->assertSame('Ativo', $result->regulatoryValidation['catalog']['registration_status']);
        $this->assertTrue($result->automaticCreationEligible);
    }

    public function test_conflict_blocks_automatic_creation(): void
    {
        $record = new AnvisaMedicineRecord('103670004', 'BUSCOPAN', 'DIPIRONA', 'OUTRA EMPRESA', 'Ativo', null, 'ANVISA', 'checksum', 1);

        $result = $this->validator($record)->validate($this->identification());

        $this->assertSame('CONFLICT', $result->regulatoryValidation['status']);
        $this->assertSame(['registration_product_mismatch', 'active_ingredient_mismatch'], $result->regulatoryValidation['conflicts']);
        $this->assertFalse($result->automaticCreationEligible);
    }

    public function test_missing_or_incomplete_catalog_does_not_break_documentary_fallback(): void
    {
        $unavailable = $this->validator(null, false)->validate($this->identification());
        $notFound = $this->validator(null)->validate($this->identification());

        $this->assertSame('NOT_AVAILABLE', $unavailable->regulatoryValidation['status']);
        $this->assertTrue($unavailable->automaticCreationEligible);
        $this->assertSame('NOT_FOUND', $notFound->regulatoryValidation['status']);
        $this->assertTrue($notFound->automaticCreationEligible);
    }

    public function test_catalog_fills_the_active_ingredient_when_the_pdf_has_none_and_records_it(): void
    {
        $identification = new DocumentIdentification('extractor', ['product_name' => 'Atrovent', 'active_ingredient' => null, 'registration_number_normalized' => '103670004'], [], [], 90, true, 3);

        $result = $this->validator($this->record())->validate($identification);

        $this->assertSame('BROMETO DE IPRATRÓPIO', $result->fields['active_ingredient']);
        $this->assertSame(['active_ingredient'], $result->regulatoryValidation['filled_from_catalog']);
        $this->assertSame('NOT_PROVIDED', $result->regulatoryValidation['comparisons']['active_ingredient']);
    }

    public function test_an_ambiguous_active_ingredient_does_not_block_the_catalog_lookup_but_an_ambiguous_registration_does(): void
    {
        $ambiguousIngredient = new DocumentIdentification('extractor', ['product_name' => 'Atrovent', 'active_ingredient' => null, 'registration_number_normalized' => '103670004'], [], ['active_ingredient'], 90, true, 3);
        $ambiguousRegistration = new DocumentIdentification('extractor', ['product_name' => 'Atrovent', 'active_ingredient' => null, 'registration_number_normalized' => '103670004'], [], ['registration_number'], 90, true, 3);

        $this->assertSame('BROMETO DE IPRATRÓPIO', $this->validator($this->record())->validate($ambiguousIngredient)->fields['active_ingredient']);
        $this->assertSame('INCONCLUSIVE', $this->validator($this->record())->validate($ambiguousRegistration)->regulatoryValidation['status']);
    }

    public function test_catalog_exposes_category_class_and_expiry_and_never_overwrites_a_pdf_value(): void
    {
        $record = new AnvisaMedicineRecord('103670004', 'ATROVENT', 'BROMETO DE IPRATRÓPIO', 'BOEHRINGER', 'Ativo', null, 'ANVISA', 'checksum', 1, 'Novo', 'ANTICOLINERGICOS', '012033');

        $result = $this->validator($record)->validate($this->identification());

        $this->assertSame('brometo de ipratrópio', $result->fields['active_ingredient']);
        $this->assertSame([], $result->regulatoryValidation['filled_from_catalog']);
        $this->assertSame(['Novo', 'ANTICOLINERGICOS', '012033'], [$result->regulatoryValidation['catalog']['regulatory_category'], $result->regulatoryValidation['catalog']['therapeutic_class'], $result->regulatoryValidation['catalog']['registration_expires_on']]);
    }

    private function validator(?AnvisaMedicineRecord $record, bool $available = true): KnowledgeDocumentRegulatoryValidator
    {
        $catalog = new class($record, $available) implements AnvisaRegulatoryCatalog
        {
            public function __construct(private readonly ?AnvisaMedicineRecord $record, private readonly bool $available) {}

            public function isAvailable(): bool
            {
                return $this->available;
            }

            public function findByRegistrationNumber(string $registrationNumber): ?AnvisaMedicineRecord
            {
                return $this->record;
            }
        };

        return new KnowledgeDocumentRegulatoryValidator($catalog, new AnvisaCatalogNormalizer);
    }

    private function identification(): DocumentIdentification
    {
        return new DocumentIdentification('extractor', ['product_name' => 'Atrovent® N e Atrovent®', 'active_ingredient' => 'brometo de ipratrópio', 'registration_number_normalized' => '103670004', 'holder' => 'Boehringer Ingelheim do Brasil Quím. e Farm. Ltda.'], [], [], 100, true, 3);
    }

    private function record(): AnvisaMedicineRecord
    {
        return new AnvisaMedicineRecord('103670004', 'ATROVENT', 'BROMETO DE IPRATRÓPIO', '60831658000177 - BOEHRINGER INGELHEIM DO BRASIL QUÍMICA E FARMACÊUTICA LTDA.', 'Ativo', '2599100347279', 'ANVISA - Dados Abertos de Medicamentos', 'checksum', 1);
    }
}
