<?php

namespace App\Services\Patient;

use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * Destaques da resposta do BULA-IA na tela, sem depender do modelo: nomes de remédio e de componente da fórmula em negrito,
 * expressões críticas ("não use", "procure atendimento"…) em negrito e, quando há alerta de interação ou alergia, a frase
 * inteira que fala dele. O texto é escapado antes de receber as marcações, então nada do que veio do modelo vira HTML.
 *
 * A comparação ignora maiúsculas e acentos ("DIPIRONA" destaca "dipirona"; "Losartana Potássica" destaca "losartana").
 */
final class AnswerHighlighter
{
    /** Palavras do nome de um sal ou da forma do princípio ativo, que sozinhas não identificam o componente. */
    private const GENERIC_WORDS = [
        'cloridrato', 'besilato', 'maleato', 'mesilato', 'succinato', 'tartarato', 'fumarato', 'citrato', 'sulfato', 'fosfato', 'acetato',
        'sodica', 'sodico', 'potassica', 'potassico', 'calcica', 'calcico', 'magnesica', 'monoidratada', 'monoidratado', 'hidratada',
        'hidratado', 'triidratada', 'trihidratada', 'diidratada', 'anidra', 'anidro', 'acido', 'cilexetila', 'comprimido', 'solucao',
    ];

    /** Expressões que mudam o que a pessoa deve fazer: sempre em negrito. */
    private const CRITICAL = [
        'n[aã]o (?:deve|pode|devem|podem) (?:ser )?(?:usad[oa]s?|tomad[oa]s?|usar|tomar)',
        'n[aã]o (?:use|tome|usar|tomar)',
        'procure (?:atendimento|ajuda|um m[eé]dico|o m[eé]dico|o pronto[- ]socorro|a emerg[eê]ncia)[^.;,]*',
        'contraindicad[oa]s?',
        'rea[cç](?:[aã]o|[oõ]es) al[eé]rgicas?',
        'interrompa', 'pare de tomar',
        'gravidade (?:alta|grave|moderada|leve)',
        'risco de sangramento', 'sangramentos?',
        'emerg[eê]ncia', 'intoxica[cç][aã]o', 'superdosagem', 'overdose',
    ];

    /**
     * @param  list<string>  $names  remédios e componentes a destacar
     * @param  list<string>  $alertTerms  quando uma frase cita um destes (remédio que interage, alergia), a frase inteira fica em negrito
     */
    public function html(string $text, array $names = [], array $alertTerms = []): HtmlString
    {
        $namesPattern = $this->pattern($this->terms($names));
        $alertPattern = $this->pattern($this->terms($alertTerms));
        $critical = '(?<![\p{L}\p{N}])(?:'.implode('|', self::CRITICAL).')(?![\p{L}\p{N}])';

        $html = '';
        foreach (preg_split('/(?<=[.!?])(\s+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [] as $piece) {
            if (trim($piece) === '') {
                $html .= e($piece);

                continue;
            }
            if ($alertPattern !== null && preg_match('/'.$alertPattern.'/iu', $piece) === 1) {
                $html .= '<strong>'.e($piece).'</strong>';

                continue;
            }
            $html .= $this->bold($piece, array_values(array_filter([$namesPattern, $critical])));
        }

        return new HtmlString($html);
    }

    /** @param list<string> $patterns */
    private function bold(string $sentence, array $patterns): string
    {
        if ($patterns === []) {
            return e($sentence);
        }
        $parts = preg_split('/('.implode('|', $patterns).')/iu', $sentence, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$sentence];
        $html = '';
        foreach ($parts as $index => $part) {
            $html .= $index % 2 === 1 ? '<strong>'.e($part).'</strong>' : e($part);
        }

        return $html;
    }

    /**
     * O nome inteiro e as palavras que identificam o componente ("losartana" de "LOSARTANA POTÁSSICA"), dos mais longos aos
     * mais curtos, para "losartana potássica" ganhar de "losartana".
     *
     * @param  list<string>  $names
     * @return list<string>
     */
    private function terms(array $names): array
    {
        $terms = [];
        foreach ($names as $name) {
            foreach (preg_split('/\s*(?:\+|,|;|\/)\s*/u', trim((string) $name)) ?: [] as $part) {
                $part = trim($part);
                if (mb_strlen($part) < 4) {
                    continue;
                }
                $terms[] = $part;
                foreach (preg_split('/[\s-]+/u', $part) ?: [] as $word) {
                    $plain = Str::lower(Str::ascii($word));
                    if (mb_strlen($word) >= 6 && ! in_array($plain, self::GENERIC_WORDS, true)) {
                        $terms[] = $word;
                    }
                }
            }
        }
        $terms = array_values(array_unique($terms));
        usort($terms, fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        return $terms;
    }

    /** @param list<string> $terms */
    private function pattern(array $terms): ?string
    {
        if ($terms === []) {
            return null;
        }

        return '(?<![\p{L}\p{N}])(?:'.implode('|', array_map(fn (string $term): string => $this->accentless($term), $terms)).')(?![\p{L}\p{N}])';
    }

    /** "Losartana" → "l[oóôõ]s[aáàâã]rt[aáàâã]n[aáàâã]", para casar com e sem acento. */
    private function accentless(string $term): string
    {
        $classes = ['a' => '[aáàâã]', 'e' => '[eéê]', 'i' => '[ií]', 'o' => '[oóôõ]', 'u' => '[uúü]', 'c' => '[cç]'];
        $pattern = '';
        foreach (mb_str_split(Str::lower(Str::ascii($term))) as $char) {
            $pattern .= $classes[$char] ?? (preg_match('/\s/', $char) === 1 ? '\s+' : preg_quote($char, '/'));
        }

        return $pattern;
    }
}
