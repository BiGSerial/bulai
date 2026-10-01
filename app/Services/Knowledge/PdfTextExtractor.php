<?php

namespace App\Services\Knowledge;

use App\Data\Knowledge\ExtractedPdf;

interface PdfTextExtractor
{
    public function extract(string $absolutePath): ExtractedPdf;
}
