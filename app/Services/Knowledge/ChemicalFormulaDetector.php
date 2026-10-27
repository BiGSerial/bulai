<?php

namespace App\Services\Knowledge;

use App\Data\Knowledge\ExtractedPage;

/**
 * Localiza no texto fórmulas moleculares (ex.: C4H11N5·HCl, C17H19NO3) e o trecho em que aparecem.
 * Só aceita sequências formadas por SÍMBOLOS QUÍMICOS VÁLIDOS com contagens, para não confundir com siglas
 * (HbA1c, AUC0-t, UKPDS, TSH…). Serve para localizar a seção que descreve a substância e enviar esse trecho à IA.
 */
final class ChemicalFormulaDetector
{
    private const ELEMENTS = [
        'H', 'He', 'Li', 'Be', 'B', 'C', 'N', 'O', 'F', 'Ne', 'Na', 'Mg', 'Al', 'Si', 'P', 'S', 'Cl', 'Ar', 'K', 'Ca', 'Ti', 'V', 'Cr', 'Mn', 'Fe', 'Co', 'Ni',
        'Cu', 'Zn', 'As', 'Se', 'Br', 'Sr', 'Mo', 'Ag', 'Sn', 'I', 'Ba', 'Pt', 'Au', 'Hg', 'Pb', 'Bi', 'Li', 'Gd', 'Tc', 'Fe', 'Mg',
    ];

    /**
     * @param  list<ExtractedPage>  $pages
     * @return list<array{formula: string, page: int, context: string, labeled: bool}>
     */
    public function detect(array $pages, int $maxPages = 15, int $maxResults = 5): array
    {
        $found = [];
        foreach (array_slice($pages, 0, $maxPages) as $page) {
            $text = preg_replace('/[\x{2080}-\x{2089}]/u', '', $this->subscriptsToDigits($page->text)) ?? $page->text;

            // 1) Rótulo explícito: "Fórmula molecular: C4H11N5·HCl".
            if (preg_match_all('/f[óo]rmula\s+(?:molecular|qu[ií]mica|estrutural)\s*(?:[:\-–]|[ée])?\s*([^\r\n]{3,90})/iu', $text, $labeled, PREG_OFFSET_CAPTURE) > 0) {
                foreach ($labeled[1] as [$value, $offset]) {
                    $formula = $this->extractFormula($value);
                    if ($formula !== null) {
                        $found[$formula] ??= ['formula' => $formula, 'page' => $page->number, 'context' => $this->context($text, (int) $offset), 'labeled' => true];
                    }
                }
            }

            // 2) Sequência com cara de fórmula, mesmo sem rótulo.
            if (preg_match_all('/(?<![\p{L}\d])((?:[A-Z][a-z]?\d{0,3}){3,}(?:\s?[·•.]\s?\d*(?:HCl|H2O|Na|K|Ca|Mg|Br|[A-Z][a-z]?\d*)+)*)(?![\p{L}\d])/u', $text, $raw, PREG_OFFSET_CAPTURE) > 0) {
                foreach ($raw[1] as [$value, $offset]) {
                    $formula = $this->validate($value);
                    if ($formula !== null) {
                        $found[$formula] ??= ['formula' => $formula, 'page' => $page->number, 'context' => $this->context($text, (int) $offset), 'labeled' => false];
                    }
                }
            }
            if (count($found) >= $maxResults) {
                break;
            }
        }

        return array_slice(array_values($found), 0, $maxResults);
    }

    private function extractFormula(string $value): ?string
    {
        if (preg_match('/((?:[A-Z][a-z]?\d{0,3}){2,}(?:\s?[·•.]\s?\d*(?:HCl|H2O|[A-Z][a-z]?\d*)+)*)/u', $value, $match) === 1) {
            return $this->validate($match[1]);
        }

        return null;
    }

    /** Valida: ≥3 átomos, só símbolos reais, ao menos um número e (C ou H) presentes. */
    private function validate(string $candidate): ?string
    {
        $formula = trim(preg_replace('/\s+/', '', $candidate) ?? $candidate);
        if (preg_match_all('/([A-Z][a-z]?)(\d*)/', preg_replace('/[·•.].*$/u', '', $formula) ?? $formula, $parts, PREG_SET_ORDER) < 3) {
            return null;
        }
        $symbols = [];
        $hasNumber = false;
        foreach ($parts as $part) {
            if (! in_array($part[1], self::ELEMENTS, true)) {
                return null;
            }
            $symbols[] = $part[1];
            $hasNumber = $hasNumber || $part[2] !== '';
        }

        return $hasNumber && (in_array('C', $symbols, true) || in_array('H', $symbols, true)) && strlen($formula) <= 60 ? $formula : null;
    }

    private function context(string $text, int $offset): string
    {
        $start = max(0, $offset - 160);

        return trim(preg_replace('/\s+/u', ' ', mb_strcut($text, $start, 420)) ?? '');
    }

    private function subscriptsToDigits(string $text): string
    {
        return strtr($text, ['₀' => '0', '₁' => '1', '₂' => '2', '₃' => '3', '₄' => '4', '₅' => '5', '₆' => '6', '₇' => '7', '₈' => '8', '₉' => '9']);
    }
}
