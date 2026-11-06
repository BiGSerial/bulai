<?php

namespace App\Services\Ai;

use App\Data\Ai\DocumentClassificationRequest;
use App\Data\Ai\DocumentClassificationResponse;
use Illuminate\Support\Facades\Log;

/**
 * Encadeia dois classificadores: o primário (IA local) responde sempre que consegue; a retaguarda (ex.: Claude) só é
 * chamada quando o primário falha, responde com confiança abaixo do mínimo ou não encontra o princípio ativo que
 * faltava. A resposta devolvida registra em `metadata['fallback']` o que aconteceu, para auditoria e revisão.
 */
final class FallbackDocumentClassificationProvider implements DocumentClassificationProvider
{
    public const LOW_CONFIDENCE = 'low_confidence';

    public const MISSING_ACTIVE_INGREDIENT = 'missing_active_ingredient';

    public function __construct(
        private readonly DocumentClassificationProvider $primary,
        private readonly DocumentClassificationProvider $secondary,
        private readonly string $primaryDriver,
        private readonly string $secondaryDriver,
        private readonly float $minConfidence,
    ) {}

    public function classify(DocumentClassificationRequest $request): DocumentClassificationResponse
    {
        try {
            $primary = $this->primary->classify($request);
        } catch (AiProviderException $exception) {
            return $this->secondary($request, 'exception:'.$exception->errorCode, null);
        }

        $reason = match (true) {
            $primary->confidence < $this->minConfidence => self::LOW_CONFIDENCE,
            $primary->activeIngredient === null && in_array('active_ingredient', $request->ambiguousFields, true) => self::MISSING_ACTIVE_INGREDIENT,
            default => null,
        };
        if ($reason === null) {
            return $this->with($primary, ['used' => false, 'primary' => $this->primaryDriver]);
        }

        return $this->secondary($request, $reason, $primary->confidence);
    }

    /** @throws AiProviderException */
    private function secondary(DocumentClassificationRequest $request, string $reason, ?float $primaryConfidence): DocumentClassificationResponse
    {
        Log::info('document_classifier_fallback', ['primary' => $this->primaryDriver, 'secondary' => $this->secondaryDriver, 'reason' => $reason, 'primary_confidence' => $primaryConfidence]);
        $response = $this->secondary->classify($request);
        $fallback = ['used' => true, 'primary' => $this->primaryDriver, 'secondary' => $this->secondaryDriver, 'reason' => $reason];
        if ($primaryConfidence !== null) {
            $fallback['primary_confidence'] = $primaryConfidence;
        }

        return $this->with($response, $fallback);
    }

    /** @param array<string, mixed> $fallback */
    private function with(DocumentClassificationResponse $response, array $fallback): DocumentClassificationResponse
    {
        return new DocumentClassificationResponse(
            $response->productName, $response->activeIngredient, $response->documentType, $response->confidence,
            $response->evidence, $response->model, [...$response->metadata, 'fallback' => $fallback],
        );
    }
}
