<?php

namespace Tests\Unit;

use App\Services\Treatment\TreatmentScheduleService;
use PHPUnit\Framework\TestCase;

class PrazoDoTratamentoTest extends TestCase
{
    public function test_a_written_duration_says_whether_the_use_is_continuous(): void
    {
        $this->assertTrue(TreatmentScheduleService::saysContinuous('Uso contínuo'));
        $this->assertTrue(TreatmentScheduleService::saysContinuous('por tempo indeterminado'));
        $this->assertFalse(TreatmentScheduleService::saysContinuous('por 7 dias'));
        $this->assertFalse(TreatmentScheduleService::saysContinuous(null));
    }
}
