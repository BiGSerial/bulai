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
        // Compara pelo radical (6 letras): "diarreias"/"diarreia", "causar"/"causa" contam como o mesmo termo. Continua exigindo
        // que a maior parte do vocabulário da afirmação apareça nos trechos citados.
        $citedStems = array_flip(array_map($this->stem(...), $this->significantTerms($cited)));
        $overlap = count(array_filter($terms, fn (string $term): bool => isset($citedStems[$this->stem($term)]))) / count($terms);

        return $overlap < self::MIN_GROUNDING_OVERLAP ? 'not_grounded_in_cited_evidence' : null;
    }

    private function stem(string $term): string
    {
        return substr($term, 0, 6);
    }

    /**
     * Valida a saída conversacional do Perguntar: segmentos tipados.
     *
     * - `fact`: afirmação sobre o remédio; exige referência a evidências existentes e ancoragem nelas (mesmas regras de `validate`);
     * - `limit`: o que não pôde ser confirmado ou depende de avaliação profissional; não pode conter afirmação clínica numérica
     *   (dose) nem conduta proibida;
     * - `ask`: pergunta de esclarecimento (termina em "?"), no máximo `max_asks`, nunca repetida da conversa.
     *
     * Aceita também o formato antigo `{"insufficient":bool,"claims":[…]}` (cada claim vira um `fact`).
     *
     * @param  array<string, string>  $evidenceTexts
     * @param  array{allow_asks?: bool, known_text?: string, max_fact_words?: int}  $options
     * @return array{malformed: bool, declared_none: bool, dangling_opening: bool, coverage: string, facts: list<array{text: string, evidence_ids: list<string>}>, limits: list<string>, asks: list<string>, rejected: list<array{kind: string, reason: string}>}
     */
    public function validateSegments(string $raw, array $evidenceTexts, array $options = []): array
    {
        $empty = ['malformed' => true, 'declared_none' => false, 'dangling_opening' => false, 'coverage' => 'none', 'facts' => [], 'limits' => [], 'asks' => [], 'rejected' => []];
        $payload = json_decode($this->stripFences($raw), true);
        if (! is_array($payload)) {
            return $empty;
        }
        if (is_array($payload['segments'] ?? null)) {
            $segments = $payload['segments'];
        } elseif (is_array($payload['claims'] ?? null)) {
            $segments = array_map(fn ($claim): array => ['kind' => 'fact'] + (is_array($claim) ? $claim : []), $payload['claims']);
        } else {
            return $empty;
        }

        $result = ['malformed' => false, 'declared_none' => (($payload['coverage'] ?? null) === 'none') || ! empty($payload['insufficient']), 'coverage' => 'none', 'facts' => [], 'limits' => [], 'asks' => [], 'rejected' => []];
        $maxAsks = (int) config('bulai.questions.conversation.max_asks');
        $maxFacts = (int) config('bulai.questions.conversation.max_facts');
        $known = $this->normalize((string) ($options['known_text'] ?? ''));
        foreach ($segments as $segment) {
            $kind = is_array($segment) ? (string) ($segment['kind'] ?? 'fact') : '';
            $text = is_array($segment) && is_string($segment['text'] ?? null) ? trim($segment['text']) : '';
            $reason = null;
            if ($kind === 'fact') {
                $reason = count($result['facts']) >= $maxFacts ? 'too_many_facts' : ($this->rejectionReason($segment, $evidenceTexts) ?? $this->denseness($text));
                if ($reason === null) {
                    $result['facts'][] = ['text' => $text, 'evidence_ids' => array_values(array_unique($segment['evidence_ids']))];
                }
            } elseif ($kind === 'limit') {
                $reason = $this->plainReason($text, 10, 320) ?? (preg_match('/\d\s?(mg|mcg|ml|g|ui|%|comprimidos?|gotas)\b/u', Str::lower($text)) === 1 ? 'limit_with_dose' : null);
                if ($reason === null) {
                    $result['limits'][] = $text;
                }
            } elseif ($kind === 'ask') {
                $normalized = $this->normalize($text);
                $reason = ! ($options['allow_asks'] ?? true) ? 'asks_disabled' : (count($result['asks']) >= $maxAsks ? 'too_many_asks' : ($this->plainReason($text, 8, 240) ?? (! str_ends_with($text, '?') ? 'ask_without_question_mark' : ($normalized !== '' && str_contains($known, $normalized) ? 'ask_already_answered_or_asked' : null))));
                if ($reason === null) {
                    $result['asks'][] = $text;
                }
            } else {
                $reason = 'unknown_segment_kind';
            }
            if ($reason !== null) {
                $result['rejected'][] = ['kind' => $kind ?: 'unknown', 'reason' => $reason];
            }
        }

        // Cobertura = as evidências cobrem o que a pergunta pede sobre o remédio. O que depende de dados da pessoa vira limit/ask e
        // não a reduz. "none" declarado com fatos escritos: os fatos eram preenchimento irrelevante e são descartados.
        $declared = is_string($payload['coverage'] ?? null) ? $payload['coverage'] : null;
        if ($declared === 'none' && $result['facts'] !== []) {
            foreach ($result['facts'] as $unused) {
                $result['rejected'][] = ['kind' => 'fact', 'reason' => 'declared_irrelevant'];
            }
            $result['facts'] = [];
        }
        $result['coverage'] = $result['facts'] === [] ? 'none' : ($declared === 'partial' ? 'partial' : 'full');

        // Orçamento de palavras: pergunta simples não pede despejo de bula; ficam os primeiros fatos (o modelo os ordena por importância).
        $budget = (int) ($options['max_fact_words'] ?? config('bulai.questions.conversation.max_fact_words'));
        $used = 0;
        $kept = [];
        foreach ($result['facts'] as $fact) {
            $words = count(preg_split('/\s+/u', $fact['text']) ?: []);
            if ($kept !== [] && $used + $words > $budget) {
                $result['rejected'][] = ['kind' => 'fact', 'reason' => 'over_word_budget'];

                continue;
            }
            $used += $words;
            $kept[] = $fact;
        }
        $result['facts'] = $kept;
        // Abertura solta ("Entre eles estão…", "Também…"): o primeiro fato precisa fazer sentido sozinho.
        $result['dangling_opening'] = $kept !== [] && preg_match('/^(entre (eles|elas)|eles|elas|isso|essa|esse|tambem|alem disso|porem|mas|ainda|por isso|nesse caso|nesses casos|alem|ja)\b/', $this->normalize($kept[0]['text'])) === 1;

        return $result;
    }

    private function plainReason(string $text, int $min, int $max): ?string
    {
        $length = mb_strlen($text);
        if ($length < $min || $length > $max) {
            return 'invalid_length';
        }

        return preg_match(self::FORBIDDEN, $this->normalize($text)) === 1 ? 'forbidden_recommendation' : null;
    }

    /** Frases longas demais são difíceis de ler (principalmente por idosos): média acima do limite reprova a afirmação. */
    private function denseness(string $text): ?string
    {
        $sentences = array_filter(preg_split('/(?<=[.!?])\s+/u', $text) ?: []);
        if ($sentences === []) {
            return null;
        }
        $words = array_sum(array_map(fn (string $sentence): int => count(preg_split('/\s+/u', trim($sentence)) ?: []), $sentences));

        return ($words / count($sentences)) > (int) config('bulai.questions.conversation.max_avg_sentence_words') ? 'too_dense' : null;
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
