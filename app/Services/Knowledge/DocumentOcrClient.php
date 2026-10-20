<?php

namespace App\Services\Knowledge;

use App\Data\Knowledge\ExtractedPdf;

interface DocumentOcrClient
{
    /** @throws DocumentOcrException */
    public function parse(string $localFile): ExtractedPdf;
}
