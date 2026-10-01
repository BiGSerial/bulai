<?php

namespace App\Services\Knowledge;

use App\Data\Knowledge\DocumentIdentification;
use App\Data\Knowledge\ExtractedPdf;
use App\Models\Knowledge\KnowledgeSource;

interface KnowledgeDocumentMetadataExtractor
{
    public function supports(KnowledgeSource $source): bool;

    public function identify(ExtractedPdf $pdf): DocumentIdentification;
}
