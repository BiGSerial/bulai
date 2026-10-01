<?php

namespace Tests\Unit\Services\Knowledge;

use App\Services\Knowledge\KnowledgeTextNormalizer;
use PHPUnit\Framework\TestCase;

class KnowledgeTextNormalizerTest extends TestCase
{
    public function test_normalizes_only_technical_whitespace_and_control_characters(): void
    {
        $input = "  COMPOSIÇÃO\r\n\r\nDose:\t 500 mg\x00  \r\n20 mg/mL\n\n\n0,5 mg; 10–20 mg; 5%  ";

        $normalized = (new KnowledgeTextNormalizer)->normalizeText($input);

        $this->assertSame("COMPOSIÇÃO\n\nDose: 500 mg\n20 mg/mL\n\n0,5 mg; 10–20 mg; 5%", $normalized);
    }
}
