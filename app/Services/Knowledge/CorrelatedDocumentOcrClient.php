<?php

namespace App\Services\Knowledge;

use App\Data\Knowledge\ExtractedPdf;

/**
 * OCR que aceita o identificador de correlação gerado por quem chama: o mesmo id vai no cabeçalho `X-Correlation-Id`
 * e é devolvido pelo serviço no `/status` com a página atual, o que permite casar o progresso com o documento.
 */
interface CorrelatedDocumentOcrClient extends DocumentOcrClient
{
    /** @throws DocumentOcrException */
    public function parse(string $localFile, ?string $correlationId = null): ExtractedPdf;
}
