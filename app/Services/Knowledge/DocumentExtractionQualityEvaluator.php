<?php

namespace App\Services\Knowledge;

use App\Data\Knowledge\ExtractedPdf;
use App\Data\Knowledge\ExtractionQuality;

interface DocumentExtractionQualityEvaluator
{
    public function evaluate(ExtractedPdf $document): ExtractionQuality;
}
