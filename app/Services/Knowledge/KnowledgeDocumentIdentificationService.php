<?php

namespace App\Services\Knowledge;

use App\Data\Knowledge\DocumentIdentification;
use App\Models\Knowledge\KnowledgeSource;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Throwable;

final class KnowledgeDocumentIdentificationService
{
    /** @var list<KnowledgeDocumentMetadataExtractor> */
    private readonly array $metadataExtractors;

    /** @param list<KnowledgeDocumentMetadataExtractor>|null $metadataExtractors */
    public function __construct(
        private readonly PdfTextExtractor $pdfExtractor,
        private readonly KnowledgeDocumentRegulatoryValidator $regulatoryValidator,
        private readonly DocumentTextExtractionService $deepExtractor,
        private readonly KnowledgeDocumentClassificationService $classification,
        ?array $metadataExtractors = null,
    ) {
        $this->metadataExtractors = $metadataExtractors ?? [app(AnvisaLeafletMetadataExtractor::class)];
    }

    public function identify(?KnowledgeSource $source, UploadedFile $file): ?DocumentIdentification
    {
        return $this->identifyPath($source, $file->getRealPath());
    }

    /**
     * @param  bool  $deep  Usa OCR (se necessário) e o classificador local. Só deve ser usado em jobs de fila,
     *                      nunca na requisição de upload.
     */
    public function identifyPath(?KnowledgeSource $source, string $path, bool $deep = false, bool $forceAi = false): ?DocumentIdentification
    {
        if ($source === null) {
            return null;
        }
        $extractor = collect($this->metadataExtractors)->first(fn (KnowledgeDocumentMetadataExtractor $candidate): bool => $candidate->supports($source));
        if ($extractor === null) {
            return null;
        }

        try {
            if ($deep) {
                $extraction = $this->deepExtractor->extract($path);
                $result = $this->regulatoryValidator->validate($extractor->identify($extraction->document));
                $result = $this->classification->refine($result, $forceAi)->with(['extraction' => $extraction->metadata()]);
            } else {
                $result = $this->regulatoryValidator->validate($extractor->identify($this->pdfExtractor->extract($path)));
            }
            Log::info('knowledge_document_preliminary_identification', [
                'source_id' => $source->id, 'extractor' => $result->extractor, 'pages_analyzed' => $result->pagesAnalyzed,
                'fields_found' => array_keys(array_filter($result->fields)), 'ambiguous_fields' => $result->ambiguousFields,
                'score' => $result->score, 'automatic_creation_eligible' => $result->automaticCreationEligible,
                'regulatory_validation' => $result->regulatoryValidation['status'] ?? 'NOT_AVAILABLE',
                'deep' => $deep, 'extraction_method' => $result->extraction['extraction_method'] ?? null,
                'classification_decision' => $result->classification['decision'] ?? null,
            ]);

            return $result;
        } catch (Throwable $exception) {
            Log::warning('knowledge_document_preliminary_identification_failed', [
                'source_id' => $source->id, 'extractor' => $extractor::class, 'exception_class' => $exception::class,
            ]);

            return new DocumentIdentification($extractor::class, [], [], [], 0, false, 0, 'TEXT_IDENTIFICATION_FAILED');
        }
    }
}
