<?php

namespace App\Services\Knowledge;

use App\Data\Knowledge\ExtractedPage;

/**
 * Regras de texto reutilizáveis pelo extrator de bulas: normalização de linhas, bloco de título da capa
 * (marca® + nome genérico + titular), linhas de "fórmula" da composição (substância ...... dose) e limpeza de nomes.
 */
final class LeafletTextHeuristics
{
    private const SALT_PREFIX = '/\b(?:cloridrato|sulfato|fosfato|acetato|maleato|tartarato|besilato|succinato|nitrato|citrato|bromidrato|mesilato|fumarato|lactato|gluconato|carbonato|[oó]xido|hidr[oó]xido|bicarbonato|brometo|cloreto|iodeto|estearato|palmitato|valerato|propionato|benzoato)\s+d[eo]s?\b/iu';

    private const SALT_SUFFIX = '/\b\p{L}+\s+(?:s[oó]dic[oa]|pot[aá]ssic[oa]|c[aá]lcic[oa]|magn[eé]sic[oa]|monoidratad[oa]|di-?hidratad[oa]|tri-?hidratad[oa]|sesqui-?hidratad[oa])\b/iu';

    private const SUBSTANCE_ENDING = '/\b\p{L}{3,}(?:ina|ol|ida|ato|ona|ila|ana|ima|ium|azol|pril|sartana|statina|mab|tida|dipino|olol|cilina|micina|floxacino|oxetina|pam|lam|pina|zida|fibrato|glitazona|gliptina|gliflozina|vir|xaban|dronato|setrona|terol|sonida|olona|fenaco|profeno|cetamol|ina[ls]?)\b/iu';

    private const NOT_SUBSTANCE = '/\b(?:comprimid|revestid|c[aá]psul|solu[cç]|suspens|embalag|apresenta|composi|informa|profission|pacient|medicament|ind[uú]stri|registr)\p{L}*|\b(?:bula|uso|oral|sa[uú]de|merck|ltda|s\.?\/?a|ms|cnpj)\b|\d/iu';

    private const NOT_BRAND = '/\b(?:comprimid|revestid|c[aá]psul|solu[cç]|suspens|embalag|apresenta|composi|informa|profission|pacient|medicament)\p{L}*|\b(?:bula|uso|oral|sa[uú]de)\b|\d{2,}/iu';

    private const COMPANY = '/\b(?:S\.?\/?A\.?|S\.A\.|Ltda\.?|Laborat[oó]rios?|Farmac[eê]utic[ao]s?|Ind[uú]stria|Pharma)\b/iu';

    private const DOSE = '(?:mg|g|mcg|µg|ug|ml|mL|UI|U\.I\.|%|mEq)';

    /** @return list<string> Linhas limpas; símbolos soltos (®, ™) são anexados à linha anterior. */
    public function lines(ExtractedPage $page): array
    {
        $lines = [];
        foreach (preg_split('/\R/u', $page->text) ?: [] as $raw) {
            $line = trim(preg_replace('/\s+/u', ' ', $raw) ?? $raw);
            if ($line === '') {
                continue;
            }
            if (preg_match('/^[®™©]+$/u', $line) === 1 && $lines !== []) {
                $lines[array_key_last($lines)] .= $line;

                continue;
            }
            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * Bloco de título da capa/primeira página: marca (com ® ou ™, ou 1ª linha curta) + nome genérico logo abaixo + titular.
     *
     * @return array{brand: string, generic: ?string, holder: ?string}|null
     */
    public function titleBlock(ExtractedPage $page): ?array
    {
        $brand = $generic = $holder = null;
        $brandIndex = null;
        foreach (array_slice($this->lines($page), 0, 12) as $index => $line) {
            if (preg_match('/^(?:APRESENTA|COMPOSI|IDENTIFICA|USO\b|INFORMA|\d+\.\s)/iu', $line) === 1) {
                break;
            }
            if ($brand === null && $this->looksLikeBrand($line, $index === 0)) {
                $brand = $line;
                $brandIndex = $index;

                continue;
            }
            if ($brand !== null && $generic === null && $index > $brandIndex && $this->looksLikeSubstanceName($line)) {
                $generic = $line;

                continue;
            }
            if ($brand !== null && $holder === null && mb_strlen($line) <= 80 && preg_match(self::COMPANY, $line) === 1 && ! $this->looksLikeSubstanceName($line)) {
                $holder = $line;
            }
        }

        return $brand === null || $generic === null ? null : ['brand' => $brand, 'generic' => $generic, 'holder' => $holder];
    }

    public function looksLikeBrand(string $line, bool $isFirstLine): bool
    {
        $symbol = preg_match('/[®™]$/u', $line) === 1;
        $name = trim(preg_replace('/[®™]+$/u', '', $line) ?? $line);
        if ($name === '' || mb_strlen($name) > 40 || str_word_count($name, 0, 'ÀÁÂÃÇÉÊÍÓÔÕÚàáâãçéêíóôõú') > 3 || preg_match(self::NOT_BRAND, $name) === 1) {
            return false;
        }

        return ($symbol || $isFirstLine) && preg_match('/^[\p{L}][\p{L}\- ]*$/u', $name) === 1;
    }

    public function looksLikeSubstanceName(string $line): bool
    {
        $line = trim(preg_replace('/[®™*]+$/u', '', $line) ?? $line);
        if ($line === '' || mb_strlen($line) > 90 || preg_match(self::NOT_SUBSTANCE, $line) === 1 || preg_match('/^[\p{L}][\p{L}\s,+\/\-]*$/u', $line) !== 1) {
            return false;
        }

        return preg_match(self::SALT_PREFIX, $line) === 1 || preg_match(self::SALT_SUFFIX, $line) === 1 || preg_match(self::SUBSTANCE_ENDING, $line) === 1;
    }

    /** Linha de composição, não nome: "cloridrato de metformina* ..500 mg", "(*equivalente a 390 mg de metformina)". */
    public function isCompositionLine(string $line): bool
    {
        return preg_match('/\.{2,}|\d+(?:[.,]\d+)?\s*'.self::DOSE.'\b|\*\s*$|^\W*equivalente\b|\bequivalente\s+a\b/iu', $line) === 1;
    }

    /** Remove líderes pontilhados, doses finais e marcas de nota ("metformina* ..500 mg" → "metformina"). */
    public function sanitizeSubstance(string $value): string
    {
        $value = preg_replace('/\s*\.{2,}.*$/u', '', $value) ?? $value;
        $value = preg_replace('/\s+\d+(?:[.,]\d+)?\s*'.self::DOSE.'\b.*$/iu', '', $value) ?? $value;
        $value = preg_replace('/[*†‡]+/u', '', $value) ?? $value;

        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value, " \t\n\r\0\x0B-,;:");
    }

    /**
     * "Fórmula" da composição: linhas "substância ..... dose" e a base equivalente "(*equivalente a 390 mg de metformina)".
     *
     * @return array{formula_lines: list<string>, equivalent_bases: list<string>}
     */
    public function compositionFormula(ExtractedPage $page): array
    {
        $lines = $this->lines($page);
        $start = null;
        foreach ($lines as $index => $line) {
            if (preg_match('/^COMPOSI[CÇ][AÃ]O\b/iu', $line) === 1) {
                $start = $index;
                break;
            }
        }
        if ($start === null) {
            return ['formula_lines' => [], 'equivalent_bases' => []];
        }

        $formula = $bases = [];
        foreach (array_slice($lines, $start + 1, 40) as $line) {
            if (preg_match('/^(?:INFORMA[CÇ][OÕ]ES|\d+\.\s|APRESENTA)/iu', $line) === 1) {
                break;
            }
            if (preg_match('/^([\p{L}][\p{L}\s,\/\-]{2,80}?)\s*\*{0,2}\s*\.{2,}\s*[\d.,]+\s*'.self::DOSE.'/iu', $line, $match) === 1) {
                $name = $this->sanitizeSubstance($match[1]);
                if ($name !== '' && ! preg_match('/^excipientes?\b/iu', $name)) {
                    $formula[] = $name;
                }
            }
            if (preg_match('/equivalente\s+a\s+[\d.,]+\s*'.self::DOSE.'\s+de\s+([\p{L}\s\-]{3,60}?)\s*\)?\s*$/iu', $line, $match) === 1) {
                $bases[] = $this->sanitizeSubstance($match[1]);
            }
        }

        return ['formula_lines' => array_values(array_unique($formula)), 'equivalent_bases' => array_values(array_unique($bases))];
    }
}
