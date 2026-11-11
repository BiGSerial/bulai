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

    /**
     * A resposta é a bula humanizada: não narra a leitura ("li na bula", "a bula diz", "segundo a bula") nem fala do funcionamento
     * interno ("trechos", "evidências", "chunks", "o sistema"). Comparado no texto normalizado (sem acentos, minúsculo).
     */
    public const NARRATION = '/\b(li na bula|li a bula|eu li|consultei|consultado|consultados|consultadas|pesquisei|procurei|trecho|trechos|evidencia|evidencias|chunk|chunks|recuperad\w*|o sistema|documento|documentos|segundo a bula|de acordo com a bula|conforme a bula|pela bula|a bula (tambem )?(diz|dizem|informa|indica|explica|orienta|recomenda|contraindica|menciona|cita|traz|fala|afirma|descreve|nao (diz|fala|traz|menciona|informa|cita)))\b/';

    /** Abertura que só narra a fonte ("Segundo a bula, …", "A bula diz que …"): é retirada e a frase começa pelo assunto. */
    private const NARRATED_OPENING = '/^\s*(?:(?:segundo|de acordo com|conforme|pelo que diz) a bula(?: do paciente| do profissional(?: de saúde)?)?\s*,?\s*|(?:a bula|na bula)(?: do paciente| do profissional(?: de saúde)?)? (?:diz|informa|indica|explica|orienta|menciona|traz|fala|afirma|descreve)(?: que)?\s*,?\s*|(?:eu )?li na bula que\s*)/iu';

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
    /**
     * @param  array<string, string>  $evidenceTexts
     * @param  string  $askedText  pergunta e nome do remédio: palavras que a afirmação repete da própria pergunta ("dor de
     *                             cabeça", "dipirona") não contam contra a ancoragem; o restante continua tendo de estar no trecho
     */
    private function rejectionReason(mixed $claim, array $evidenceTexts, string $askedText = ''): ?string
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

        $asked = array_flip(array_map($this->stem(...), $this->significantTerms($askedText)));
        $terms = array_values(array_filter($this->significantTerms($claim['text']), fn (string $term): bool => ! isset($asked[$this->stem($term)])));
        // Se só sobram palavras da pergunta ("pode dar tosse?" → "pode dar tosse seca"), elas mesmas são verificadas no trecho.
        $terms = $terms !== [] ? $terms : $this->significantTerms($claim['text']);
        if ($terms === []) {
            return 'no_verifiable_terms';
        }
        // Compara pelo radical (6 letras): "diarreias"/"diarreia", "causar"/"causa" contam como o mesmo termo. Continua exigindo
        // que a maior parte do vocabulário da afirmação apareça nos trechos citados.
        $citedStems = array_flip(array_map($this->stem(...), $this->significantTerms($cited)));
        $overlap = count(array_filter($terms, fn (string $term): bool => isset($citedStems[$this->stem($term)]))) / count($terms);

        if ($overlap < self::MIN_GROUNDING_OVERLAP) {
            return 'not_grounded_in_cited_evidence';
        }

        return $this->doseNumbersReason($claim['text'], $cited) ?? $this->polarityReason($claim['text'], $cited);
    }

    /**
     * Quantidade de dose na afirmação (número com unidade: mg, ml, gotas, comprimidos, vezes, horas…) que não aparece nos
     * trechos citados. A sobreposição de palavras não percebe "20 a 40 gotas" trocado por "20 a 60 gotas".
     */
    private function doseNumbersReason(string $claim, string $cited): ?string
    {
        $numbers = fn (string $text): array => array_map(
            fn (string $number): string => str_replace(',', '.', $number),
            preg_match_all('/(?<![\d.,])(\d+(?:[.,]\d+)?)(?=(?:\s*(?:a|e|ou|-)\s*\d+(?:[.,]\d+)?)*\s*(?:mg|mcg|ml|g|ui|%|gotas?|comprimidos?|capsulas?|drageas?|vezes|horas?|h)\b)/u', Str::lower(Str::ascii($text)), $matches) > 0 ? $matches[1] : [],
        );
        $inClaim = $numbers($claim);
        if ($inClaim === []) {
            return null;
        }
        preg_match_all('/\d+(?:[.,]\d+)?/u', $cited, $all);
        $available = array_flip(array_map(fn (string $number): string => str_replace(',', '.', $number), $all[0]));
        foreach ($inClaim as $number) {
            if (! isset($available[$number])) {
                return 'dose_number_not_in_evidence';
            }
        }

        return null;
    }

    /**
     * Polaridade: a afirmação diz "deve/pode ser usado" e os trechos só dizem "não deve/pode ser usado" (ou o contrário), ou
     * a afirmação nega algo e nenhum trecho citado tem negação. Heurística: não prova a correção, só barra a inversão mais comum.
     */
    private function polarityReason(string $claim, string $cited): ?string
    {
        $claimText = ' '.$this->normalize($claim).' ';
        $citedText = ' '.$this->normalize($cited).' ';
        $negation = '/\b(nao|nunca|jamais|nenhum|nenhuma)\b/';
        if (preg_match($negation, $claimText) === 1 && preg_match($negation, $citedText) !== 1) {
            return 'negation_not_in_evidence';
        }
        $modal = '/\b(nao\s+)?(?:\w+\s+)?(deve|devem|pode|podem|devera|deverao)\s+(?:ser\s+)?(usad|utilizad|tomad|administrad|aplicad|ingerid)\w*/';
        $polarities = function (string $text) use ($modal): array {
            preg_match_all($modal, $text, $matches);

            return array_values(array_unique(array_map(fn (string $negated): string => $negated !== '' ? 'neg' : 'pos', $matches[1])));
        };
        $inClaim = $polarities($claimText);
        $inCited = $polarities($citedText);
        if (count($inClaim) === 1 && $inCited !== [] && ! in_array($inClaim[0], $inCited, true)) {
            return 'negation_inverted';
        }

        return null;
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
     * @param  array{allow_asks?: bool, known_text?: string, max_fact_words?: int, drop_limits_when_full?: bool, subject?: string, single_medicine?: bool, asked_text?: string}  $options
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
            $text = is_array($segment) && is_string($segment['text'] ?? null) ? $this->humanize($segment['text']) : '';
            if (is_array($segment) && $text !== '') {
                $segment['text'] = $text;
            }
            $reason = $text !== '' && preg_match(self::NARRATION, $this->normalize($text)) === 1 ? 'narrates_source' : null;
            if ($reason !== null) {
                $result['rejected'][] = ['kind' => $kind ?: 'unknown', 'reason' => $reason];

                continue;
            }
            if ($kind === 'fact') {
                $reason = count($result['facts']) >= $maxFacts ? 'too_many_facts' : ($this->rejectionReason($segment, $evidenceTexts, (string) ($options['asked_text'] ?? '')) ?? $this->denseness($text));
                if ($reason === null) {
                    $result['facts'][] = ['text' => $text, 'evidence_ids' => array_values(array_unique($segment['evidence_ids']))];
                }
            } elseif ($kind === 'limit') {
                $reason = $this->plainReason($text, 10, 320) ?? (preg_match('/\d\s?(mg|mcg|ml|g|ui|%|comprimidos?|gotas)\b/u', Str::lower($text)) === 1 ? 'limit_with_dose' : null);
                // Pergunta sobre um remédio só, que não é de interação: ressalva genérica de "combinação" é ruído (o alerta do
                // perfil, quando existe, sai à parte, nomeando o remédio).
                if ($reason === null && ($options['single_medicine'] ?? false) && preg_match('/\b(combinac\w+|usar (os dois|cada um) junt\w*|juntos?)\b/', $this->normalize($text)) === 1) {
                    $reason = 'combination_off_topic';
                }
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
        // Pergunta informativa respondida por inteiro ("serve para dor de cabeça?"): ressalvas só alongam a resposta.
        if (($options['drop_limits_when_full'] ?? false) && $result['coverage'] === 'full') {
            foreach ($result['limits'] as $unused) {
                $result['rejected'][] = ['kind' => 'limit', 'reason' => 'limit_not_needed'];
            }
            $result['limits'] = [];
        }

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
        // A primeira afirmação recusada deixa a seguinte abrindo a resposta com um pronome ("Ela é um analgésico…"): o pronome
        // vira o nome do remédio, no gênero do próprio pronome ("A dipirona é um analgésico…").
        if ($kept !== [] && ($options['subject'] ?? '') !== '') {
            $kept[0]['text'] = $this->nameSubject($kept[0]['text'], (string) $options['subject']);
        }
        $result['facts'] = $kept;
        // Abertura solta ("Entre eles estão…", "Também…"): o primeiro fato precisa fazer sentido sozinho.
        $result['dangling_opening'] = $kept !== [] && preg_match('/^(entre (eles|elas)|eles|elas|ela|ele|isso|essa|esse|tambem|alem disso|porem|mas|ainda|por isso|nesse caso|nesses casos|alem|ja)\b/', $this->normalize($kept[0]['text'])) === 1;

        return $result;
    }

    /** "Ela é…" / "Ele pode…" no início vira "A dipirona é…" / "O ibuprofeno pode…". */
    public function nameSubject(string $text, string $subject): string
    {
        if (preg_match('/^(Ela|Ele)\b\s*/u', $text, $match) !== 1) {
            return $text;
        }
        $name = mb_strtoupper($subject) === $subject ? mb_strtolower($subject) : $subject;

        return ($match[1] === 'Ela' ? 'A ' : 'O ').$name.' '.mb_substr($text, mb_strlen($match[0]));
    }

    /** Tira a abertura que narra a fonte e devolve a frase começando pelo assunto, com inicial maiúscula. */
    public function humanize(string $text): string
    {
        $text = trim($text);
        $stripped = trim((string) preg_replace(self::NARRATED_OPENING, '', $text, 1));
        if ($stripped === '' || $stripped === $text) {
            return $text;
        }

        return mb_strtoupper(mb_substr($stripped, 0, 1)).mb_substr($stripped, 1);
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

    /** Palavras que não afirmam nada sobre o remédio ("tem como princípio ativo", "em geral", "o que inclui"). */
    private const GENERIC_TERMS = ['principio', 'principios', 'ativo', 'ativos', 'geral', 'gerais', 'inclui', 'incluindo', 'remedio', 'remedios', 'medicamento', 'medicamentos', 'porque', 'tambem'];

    /** @return list<string> */
    private function significantTerms(string $text): array
    {
        return array_values(array_unique(array_filter(explode(' ', $this->normalize($text)), fn (string $term): bool => strlen($term) >= 5 && ! in_array($term, self::GENERIC_TERMS, true))));
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
