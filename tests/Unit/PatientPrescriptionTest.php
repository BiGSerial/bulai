<?php

namespace Tests\Unit;

use App\Models\PatientPrescription;
use PHPUnit\Framework\TestCase;

class PatientPrescriptionTest extends TestCase
{
    public function test_prescriber_names_read_from_a_photo_are_shown_in_title_case(): void
    {
        $this->assertSame('Evelyn Calhau Resende de Oliveira Durço', (new PatientPrescription(['prescriber_name' => 'EVELYN CALHAU RESENDE DE OLIVEIRA DURÇO']))->prescriberDisplayName());
        $this->assertSame('Dra. Ana DE Souza', (new PatientPrescription(['prescriber_name' => 'Dra. Ana DE Souza']))->prescriberDisplayName());
    }
}
