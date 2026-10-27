<?php

namespace App\Data\Ai;

use App\Services\Ai\AiProviderException;

final readonly class DocumentClassificationResponse
{
    public const DOCUMENT_TYPES = ['bula_paciente', 'bula_profissional'];

    public const EVIDENCE_MAX_CHARACTERS = 400;

    private const KEYS = ['product_name', 'active_ingredient', 'document_type', 'confidence', 'evidence'];

    /**
     * @param  list<string>  $evidence
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public ?string $productName,
        public ?string $activeIngredient,
        public ?string $documentType,
        public float $confidence,
        public array $evidence,
        public string $model,
        public array $metadata = [],
    ) {}

    /**
     * Validação rígida: JSON malformado, incompleto, com campos extras ou valores fora do schema é rejeitado (sem "consertos").
     *
     * @param  array<string, mixed>  $metadata
     *
     * @throws AiProviderException
     */
    public static function fromModelOutput(string $content, string $model, array $metadata = []): self
    {
        $data = json_decode($content, true);
        if (! is_array($data) || array_is_list($data) || count($data) !== count(self::KEYS) || array_diff(array_keys($data), self::KEYS) !== []) {
            throw new AiProviderException(AiProviderException::INVALID_RESPONSE);
        }

        foreach (['product_name', 'active_ingredient'] as $key) {
            if ($data[$key] !== null && (! is_string($data[$key]) || trim($data[$key]) === '' || mb_strlen($data[$key]) > 160)) {
                throw new AiProviderException(AiProviderException::INVALID_RESPONSE);
            }
        }

        $confidence = $data['confidence'];
        $evidence = $data['evidence'];
        if (($data['document_type'] !== null && ! in_array($data['document_type'], self::DOCUMENT_TYPES, true))
            || (! is_int($confidence) && ! is_float($confidence)) || $confidence < 0 || $confidence > 1
            || ! is_array($evidence) || ! array_is_list($evidence) || count($evidence) > 5
            || array_filter($evidence, fn (mixed $item): bool => ! is_string($item)) !== []) {
            throw new AiProviderException(AiProviderException::INVALID_RESPONSE);
        }

        // Marcas de nota de rodapé ("amoxicilina*") copiadas da bula não fazem parte do nome.
        $clean = fn (?string $value): ?string => $value === null ? null : (trim(preg_replace('/\s+/', ' ', str_replace('*', '', $value)) ?? $value) ?: null);

        // As citações são só apoio para o revisor (nunca decidem nada): o modelo pequeno tende a copiar blocos inteiros, então
        // cada uma é encurtada em vez de invalidar uma resposta correta. Formato e tipos continuam estritos.
        $evidence = array_map(fn (string $item): string => mb_strlen($item) > self::EVIDENCE_MAX_CHARACTERS ? rtrim(mb_substr($item, 0, self::EVIDENCE_MAX_CHARACTERS - 1)).'…' : $item, $evidence);

        return new self($clean($data['product_name']), $clean($data['active_ingredient']), $data['document_type'], (float) $confidence, $evidence, $model, $metadata);
    }

    /** @return array<string, mixed> */
    public static function jsonSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => self::KEYS,
            'properties' => [
                'product_name' => ['type' => ['string', 'null'], 'maxLength' => 160],
                'active_ingredient' => ['type' => ['string', 'null'], 'maxLength' => 160],
                'document_type' => ['enum' => [...self::DOCUMENT_TYPES, null]],
                'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                'evidence' => ['type' => 'array', 'maxItems' => 5, 'items' => ['type' => 'string', 'maxLength' => self::EVIDENCE_MAX_CHARACTERS]],
            ],
        ];
    }
}
