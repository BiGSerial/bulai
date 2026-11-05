<?php

namespace App\Services\Interactions;

use Illuminate\Support\Str;

/**
 * Valida a saída do gerador: JSON estrito, cada afirmação com referências existentes, vocabulário ancorado nos trechos
 * citados e nenhuma orientação de suspender/substituir/ajustar dose. Afirmações reprovadas são descartadas.
 */
final class ExplanationValidator
{
    /** Fração mínima dos termos significativos da afirmação que deve aparecer nos trechos citados. */
    public const MIN_GROUNDING_OVERLAP = 0.4;

    private const FORBIDDEN = '/\b(suspend\w*|interromp\w*|substitu\w*|troque\w*|trocar|pare de|deixe de|evite\w*|nao (use|tome|utilize)\w*|nunca (use|tome)\w*|ajust\w+ (a |da |de |na )?dose|(aument|reduz|diminu|dobr)\w+ (a |da |de )?dose)\b/';

    /**
     * @param  array<string, string>  $evidenceTexts  id da evidência (ex.: "E1") => texto do trecho
     * @return array{accepted: list<array{text: string, evidence_ids: list<string>}>, rejected: list<array{reason: string}>, insufficient: bool, malformed: bool}
     */
    public function validate(string $raw, array $evidenceTexts): array
    {
        $payload = json_decode($this->stripFences($raw), true);
        if (! is_array($payload) || ! is_array($payload['claims'] ?? null)) {
            return ['accepted' => [], 'rejected' => [], 'insufficient' => false, 'malformed' => true];
        }

        $accepted = [];
        $rejected = [];
        foreach ($payload['claims'] as $claim) {
            $reason = $this->rejectionReason($claim, $evidenceTexts);
            if ($reason !== null) {
                $rejected[] = ['reason' => $reason];

                continue;
            }
            $accepted[] = ['text' => trim($claim['text']), 'evidence_ids' => array_values(array_unique($claim['evidence_ids']))];
        }

        return ['accepted' => $accepted, 'rejected' => $rejected, 'insufficient' => (bool) ($payload['insufficient'] ?? false), 'malformed' => false];
    }

    /** @param array<string, string> $evidenceTexts */
    private function rejectionReason(mixed $claim, array $evidenceTexts): ?string
    {
        if (! is_array($claim) || ! is_string($claim['text'] ?? null) || ! is_array($claim['evidence_ids'] ?? null)) {
            return 'malformed_claim';
        }
        $length = mb_strlen(trim($claim['text']));
        if ($length < 10 || $length > 600) {
            return 'invalid_length';
        }
        if ($claim['evidence_ids'] === []) {
            return 'no_evidence_reference';
        }
        $cited = '';
        foreach ($claim['evidence_ids'] as $id) {
            if (! is_string($id) || ! isset($evidenceTexts[$id])) {
                return 'unknown_evidence_reference';
            }
            $cited .= ' '.$evidenceTexts[$id];
        }
        if (preg_match(self::FORBIDDEN, $this->normalize($claim['text'])) === 1) {
            return 'forbidden_recommendation';
        }

        $terms = $this->significantTerms($claim['text']);
        if ($terms === []) {
            return 'no_verifiable_terms';
        }
        $citedTerms = array_flip($this->significantTerms($cited));
        $overlap = count(array_filter($terms, fn (string $term): bool => isset($citedTerms[$term]))) / count($terms);

        return $overlap < self::MIN_GROUNDING_OVERLAP ? 'not_grounded_in_cited_evidence' : null;
    }

    private function normalize(string $text): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', Str::lower(Str::ascii($text))));
    }

    /** @return list<string> */
    private function significantTerms(string $text): array
    {
        return array_values(array_unique(array_filter(explode(' ', $this->normalize($text)), fn (string $term): bool => strlen($term) >= 5)));
    }

    private function stripFences(string $raw): string
    {
        $raw = trim($raw);
        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $raw, $matches) === 1) {
            return $matches[1];
        }

        return $raw;
    }
}
