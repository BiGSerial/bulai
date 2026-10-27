<?php

namespace App\Services\Knowledge;

use App\Data\Knowledge\ExtractedPdf;

/** OCR que consegue processar só as primeiras páginas (capa/identificação), sem custo do documento inteiro. */
interface PageLimitedDocumentOcrClient extends DocumentOcrClient
{
    /** @throws DocumentOcrException */
    public function parseFirstPages(string $localFile, int $pages): ExtractedPdf;
}
