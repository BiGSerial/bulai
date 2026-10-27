<?php

namespace App\Services\Knowledge;

use App\Data\Knowledge\DocumentExtractionResult;
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

    /** Heurística sem confiança suficiente: pontuação abaixo do aceite automático, produto/princípio ativo ausentes ou ambiguidade. */
    private function isWeak(DocumentIdentification $identified): bool
    {
        return $identified->errorCode === null
            && ($identified->score < (int) config('document_classification.auto_accept_score')
                || blank($identified->fields['product_name'] ?? null) || blank($identified->fields['active_ingredient'] ?? null)
                || $identified->ambiguousFields !== []);
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
                $identified = $extractor->identify($extraction->document);
                $extractionMetadata = $extraction->metadata();
                if ($this->isWeak($identified) && $extraction->method !== DocumentExtractionResult::METHOD_OCR) {
                    // Mesmo com texto nativo, a heurística não achou candidatos confiáveis: tenta o OCR das primeiras páginas
                    // (capa, marca em imagem) e só adota o resultado se ele não for pior.
                    $supplement = $this->deepExtractor->supplementFirstPages($extraction->document, $path, (int) config('document_ocr.supplement_pages', 3));
                    if ($supplement !== null) {
                        $second = $extractor->identify($supplement['document']);
                        $adopted = $second->score >= $identified->score;
                        $extractionMetadata['ocr_supplement'] = ['pages' => $supplement['document']->metadata['ocr_supplement_pages'] ?? null, 'added_lines' => $supplement['added_lines'],
                            'engine' => $supplement['engine'], 'score_before' => $identified->score, 'score_after' => $second->score, 'adopted' => $adopted];
                        $identified = $adopted ? $second : $identified;
                    }
                }
                $result = $this->regulatoryValidator->validate($identified);
                $result = $this->classification->refine($result, $forceAi)->with(['extraction' => $extractionMetadata]);
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
