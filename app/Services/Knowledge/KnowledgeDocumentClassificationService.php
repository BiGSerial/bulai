<?php

namespace App\Services\Knowledge;

use App\Data\Ai\DocumentClassificationRequest;
use App\Data\Ai\DocumentClassificationResponse;
use App\Data\Knowledge\DocumentIdentification;
use App\Services\Ai\AiProviderException;
use App\Services\Ai\AiProviderManager;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Camada 2 da ingestão: a heurística decide primeiro; o classificador local só atua na faixa intermediária
 * e nunca promove um documento sozinho (exige confirmação regulatória e ausência de conflito).
 */
final class KnowledgeDocumentClassificationService
{
    public const ACCEPTED_HEURISTIC = 'ACCEPTED_HEURISTIC';

    public const AI_ACCEPTED = 'AI_ACCEPTED';

    public const AI_LOW_CONFIDENCE = 'AI_LOW_CONFIDENCE';

    public const AI_SUGGESTED = 'AI_SUGGESTED';

    public const AI_REJECTED = 'AI_REJECTED';

    public const AI_UNAVAILABLE = 'AI_UNAVAILABLE';

    public const AI_DISABLED = 'AI_DISABLED';

    public const MANUAL_REVIEW = 'MANUAL_REVIEW';

    public function __construct(
        private readonly AiProviderManager $providers,
        private readonly KnowledgeDocumentRegulatoryValidator $regulatoryValidator,
    ) {}

    /**
     * @param  DocumentIdentification  $identification  Resultado da heurística já validado regulatoriamente.
     * @param  bool  $forceAi  Consulta a IA mesmo fora da faixa de pontuação (pedido explícito de um administrador).
     */
    public function refine(DocumentIdentification $identification, bool $forceAi = false): DocumentIdentification
    {
        $score = $identification->score;
        $base = ['heuristic_score' => $score, 'ai_used' => false, 'forced' => $forceAi,
            // Somente o que o PDF trouxe: o que o catálogo completou aparece na coluna própria.
            'heuristic' => array_filter(array_diff_key(array_intersect_key($identification->fields, array_flip(['product_name', 'active_ingredient', 'document_type', 'registration_number_normalized', 'title'])), array_flip((array) ($identification->regulatoryValidation['filled_from_catalog'] ?? []))), fn (mixed $value): bool => $value !== null && $value !== ''),
            'thresholds' => [
                'auto_accept_score' => (int) config('document_classification.auto_accept_score'),
                'ai_min_score' => (int) config('document_classification.ai_min_score'),
                'ai_min_confidence' => (float) config('document_classification.ai_min_confidence'),
            ]];

        if ($identification->errorCode !== null) {
            return $identification;
        }

        // O princípio ativo é sempre necessário: se faltar (mesmo após o catálogo), a IA é consultada em qualquer pontuação.
        $missingIngredient = blank($identification->fields['active_ingredient'] ?? null);
        $autoAccept = $score >= (int) config('document_classification.auto_accept_score');
        $tooLow = $score < (int) config('document_classification.ai_min_score');
        $gapFill = ! $forceAi && $missingIngredient && ($autoAccept || $tooLow);
        $fallback = $autoAccept ? self::ACCEPTED_HEURISTIC : self::MANUAL_REVIEW;
        $base['gap_fill'] = $gapFill;
        // Na consulta só para completar o princípio ativo, o resultado da IA não muda a decisão já tomada pela pontuação.
        $conclude = fn (DocumentIdentification $result, array $data): DocumentIdentification => $this->finish($result, $gapFill && ! in_array($data['decision'], [self::AI_ACCEPTED], true)
            ? [...$data, 'decision' => $fallback, 'gap_fill_outcome' => $data['decision'], 'reason' => $data['reason'] ?? ($tooLow ? 'heuristic_score_too_low' : null)]
            : $data);

        if (! $forceAi && ! $gapFill && $autoAccept) {
            return $this->finish($identification, [...$base, 'decision' => self::ACCEPTED_HEURISTIC]);
        }

        if (! $forceAi && ! $gapFill && $tooLow) {
            return $this->finish($identification, [...$base, 'decision' => self::MANUAL_REVIEW, 'reason' => 'heuristic_score_too_low']);
        }

        if (! $this->providers->isConfigured(AiProviderManager::CLASSIFIER)) {
            return $conclude($identification, [...$base, 'decision' => self::AI_DISABLED]);
        }

        $startedAt = hrtime(true);
        $request = DocumentClassificationRequest::fromIdentification($identification);
        try {
            $response = $this->providers->classifier()->classify($request);
        } catch (AiProviderException $exception) {
            return $conclude($identification, [...$base, 'ai_used' => true, 'decision' => self::AI_UNAVAILABLE, 'error_code' => $exception->errorCode, 'duration_ms' => $this->elapsed($startedAt),
                ...$this->promptSnapshot($request, null)]);
        }

        $ai = [...$base, 'ai_used' => true, 'model' => $response->model, 'confidence' => $response->confidence, 'duration_ms' => $this->elapsed($startedAt),
            'result' => ['product_name' => $response->productName, 'active_ingredient' => $response->activeIngredient, 'document_type' => $response->documentType, 'evidence' => $response->evidence],
            'checks' => $this->fieldChecks($identification, $response), ...$this->promptSnapshot($request, $response)];

        if ($response->confidence < (float) config('document_classification.ai_min_confidence')) {
            return $conclude($identification, [...$ai, 'decision' => self::AI_LOW_CONFIDENCE]);
        }

        $rejection = $this->rejectionReason($identification, $response);
        if ($rejection !== null) {
            return $conclude($this->withIngredientFromAi($identification, $response, $ai['checks']), [...$ai, 'decision' => self::AI_REJECTED, 'reason' => $rejection, 'ingredient_filled_by_ai' => $this->canFillIngredient($identification, $response, $ai['checks'])]);
        }

        $merged = $this->merge($identification, $response, $ai);
        if (! $merged->automaticCreationEligible) {
            // Sem confirmação regulatória a sugestão fica só registrada; campos e ambiguidades originais são mantidos.
            return $conclude($this->withIngredientFromAi($identification, $response, $ai['checks']), [...$ai, 'decision' => self::AI_SUGGESTED, 'reason' => 'regulatory_confirmation_missing', 'ingredient_filled_by_ai' => $this->canFillIngredient($identification, $response, $ai['checks'])]);
        }

        return $this->finish($merged, null);
    }

    private function merge(DocumentIdentification $identification, DocumentClassificationResponse $response, array $classification): DocumentIdentification
    {
        $fields = $identification->fields;
        $ambiguous = $identification->ambiguousFields;
        foreach (['product_name' => $response->productName, 'active_ingredient' => $response->activeIngredient] as $field => $value) {
            if ($value !== null && ($fields[$field] ?? null) === null) {
                $fields[$field] = $value;
                $ambiguous = array_values(array_diff($ambiguous, [$field]));
            }
        }
        if ($response->documentType !== null && ($fields['document_type'] ?? null) === null) {
            $fields['document_type'] = $response->documentType;
            $fields['audience'] = $response->documentType === 'bula_profissional' ? 'professional' : 'patient';
            $ambiguous = array_values(array_diff($ambiguous, ['audience']));
        }
        if (($fields['product_name'] ?? null) !== null && ($fields['document_type'] ?? null) !== null) {
            $fields['title'] = $fields['product_name'].' — '.($fields['document_type'] === 'bula_paciente' ? 'Bula do paciente' : 'Bula do profissional de saúde');
        }

        $structurallyComplete = $ambiguous === [] && ($fields['title'] ?? null) !== null && ($fields['document_type'] ?? null) !== null && ($fields['registration_number_normalized'] ?? null) !== null;
        $merged = $identification->withClassification([...$classification, 'decision' => self::AI_ACCEPTED], $fields, $ambiguous, false);
        $validated = $this->regulatoryValidator->validate($merged);

        // Resolvido por IA: só é elegível à criação automática se o catálogo regulatório confirmar o produto.
        return $validated->withClassification($merged->classification, eligible: $structurallyComplete && ($validated->regulatoryValidation['status'] ?? null) === 'CONFIRMED');
    }

    /**
     * O princípio ativo é sempre necessário: quando falta no PDF e no catálogo, a IA o completa isoladamente se só ele passar em todas as
     * verificações (confiança mínima, respaldo no texto enviado, sem conflito, nome plausível), mesmo que outro campo da resposta tenha sido
     * descartado. Não altera a elegibilidade para associação automática.
     *
     * @param  array<string, array{grounded: bool, conflict: bool}>  $checks
     */
    private function canFillIngredient(DocumentIdentification $identification, DocumentClassificationResponse $response, array $checks): bool
    {
        $value = $response->activeIngredient;

        return blank($identification->fields['active_ingredient'] ?? null) && $value !== null && preg_match('/^\d/u', $value) !== 1
            && $response->confidence >= (float) config('document_classification.ai_min_confidence')
            && ($checks['active_ingredient']['grounded'] ?? false) && ! ($checks['active_ingredient']['conflict'] ?? true);
    }

    /** @param array<string, array{grounded: bool, conflict: bool}> $checks */
    private function withIngredientFromAi(DocumentIdentification $identification, DocumentClassificationResponse $response, array $checks): DocumentIdentification
    {
        if (! $this->canFillIngredient($identification, $response, $checks)) {
            return $identification;
        }

        return $identification->with([
            'fields' => [...$identification->fields, 'active_ingredient' => $response->activeIngredient],
            'ambiguousFields' => array_values(array_diff($identification->ambiguousFields, ['active_ingredient'])),
        ]);
    }

    /**
     * Prompt enviado à IA, para auditoria. A parte do usuário é o payload montado só com trechos curtos já extraídos
     * (nunca o PDF); o prompt de sistema, os parâmetros e a resposta bruta existem apenas quando o provider respondeu.
     *
     * @return array{prompt?: array<string, mixed>}
     */
    private function promptSnapshot(DocumentClassificationRequest $request, ?DocumentClassificationResponse $response): array
    {
        if (! config('document_classification.store_prompt')) {
            return [];
        }
        $user = $request->toPayload();
        $recorded = is_array($response?->metadata['prompt'] ?? null) ? $response->metadata['prompt'] : [];

        return ['prompt' => [...$recorded, 'user' => $user, 'sha256' => hash('sha256', json_encode($user, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: ''),
            'raw_response' => $response?->metadata['raw_response'] ?? null]];
    }

    /**
     * Verificações por campo exibidas no quadro comparativo: o valor da IA tem respaldo no texto enviado e contradiz a heurística?
     *
     * @return array<string, array{grounded: bool, conflict: bool}>
     */
    private function fieldChecks(DocumentIdentification $identification, DocumentClassificationResponse $response): array
    {
        $checks = [];
        foreach (['product_name' => $response->productName, 'active_ingredient' => $response->activeIngredient] as $field => $value) {
            if ($value === null) {
                continue;
            }
            $existing = $identification->fields[$field] ?? null;
            $checks[$field] = [
                'grounded' => $this->isGrounded($value, $identification->candidates[$field] ?? [], $identification->excerpts),
                'conflict' => $existing !== null && $this->key($existing) !== $this->key($value),
            ];
        }
        if ($response->documentType !== null) {
            $existing = $identification->fields['document_type'] ?? null;
            $audience = $response->documentType === 'bula_profissional' ? 'professional' : 'patient';
            $checks['document_type'] = [
                'grounded' => in_array($audience, $identification->candidates['audience'] ?? [], true),
                'conflict' => $existing !== null && $existing !== $response->documentType,
            ];
        }

        return $checks;
    }

    private function rejectionReason(DocumentIdentification $identification, DocumentClassificationResponse $response): ?string
    {
        foreach (['product_name' => $response->productName, 'active_ingredient' => $response->activeIngredient] as $field => $value) {
            if ($value === null) {
                continue;
            }
            $existing = $identification->fields[$field] ?? null;
            if ($existing !== null && $this->key($existing) !== $this->key($value)) {
                return 'conflict_with_heuristic_'.$field;
            }
            if ($field === 'active_ingredient' && preg_match('/^\d/u', $value) === 1) {
                return 'implausible_active_ingredient';
            }
            if (! $this->isGrounded($value, $identification->candidates[$field] ?? [], $identification->excerpts)) {
                return 'ungrounded_'.$field;
            }
        }

        $existingType = $identification->fields['document_type'] ?? null;
        if ($response->documentType !== null && $existingType === null
            && ! in_array($response->documentType === 'bula_profissional' ? 'professional' : 'patient', $identification->candidates['audience'] ?? [], true)) {
            return 'ungrounded_document_type';
        }
        if ($existingType !== null && $response->documentType !== null && $existingType !== $response->documentType) {
            return 'conflict_with_heuristic_document_type';
        }

        $resolvesSomething = ($response->productName !== null && ($identification->fields['product_name'] ?? null) === null)
            || ($response->activeIngredient !== null && ($identification->fields['active_ingredient'] ?? null) === null)
            || ($response->documentType !== null && $existingType === null);

        return $resolvesSomething ? null : 'nothing_resolved';
    }

    /**
     * @param  list<string>  $candidates
     * @param  list<array{page: int, type: string, text: string}>  $excerpts
     */
    private function isGrounded(string $value, array $candidates, array $excerpts): bool
    {
        $needle = $this->key($value);
        if (strlen($needle) < 4) {
            return false;
        }
        foreach ($candidates as $candidate) {
            if ($this->key($candidate) === $needle) {
                return true;
            }
        }
        $words = $this->words($value);
        foreach ($excerpts as $excerpt) {
            if (preg_match('/(?<![a-z0-9])'.preg_quote($words, '/').'(?![a-z0-9])/', $this->words($excerpt['text'])) === 1) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed>|null $classification */
    private function finish(DocumentIdentification $identification, ?array $classification): DocumentIdentification
    {
        $result = $classification === null ? $identification : $identification->withClassification($classification);
        $final = $result->classification;
        Log::info('knowledge_document_classification', [
            'decision' => $final['decision'] ?? null, 'heuristic_score' => $final['heuristic_score'] ?? null, 'ai_used' => $final['ai_used'] ?? false,
            'model' => $final['model'] ?? null, 'confidence' => $final['confidence'] ?? null, 'duration_ms' => $final['duration_ms'] ?? null,
            'error_code' => $final['error_code'] ?? null, 'reason' => $final['reason'] ?? null,
        ]);

        return $result;
    }

    private function words(string $value): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', Str::ascii(Str::lower($value))) ?? '');
    }

    private function key(string $value): string
    {
        return preg_replace('/[^a-z0-9]+/', '', Str::ascii(Str::lower($value))) ?? '';
    }

    private function elapsed(int $startedAt): int
    {
        return (int) ((hrtime(true) - $startedAt) / 1_000_000);
    }
}
