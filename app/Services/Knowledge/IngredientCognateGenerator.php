<?php

namespace App\Services\Knowledge;

/**
 * Gera grafias inglesas candidatas para um nome genérico em português por regras ortográficas regulares
 * (ex.: "amoxicilina" → "amoxicillin"). Os candidatos só são aceitos quando coincidem exatamente com um nome
 * de referência (DDInter); a regra nunca cria um princípio ativo novo.
 */
final class IngredientCognateGenerator
{
    /** Trocas ortográficas opcionais, aplicadas em qualquer posição. */
    private const SPELLING = [
        ['hidro', 'hydro'], ['tio', 'thio'], ['fen', 'phen'], ['cef', 'ceph'], ['ceto', 'keto'], ['teo', 'theo'], ['clor', 'chlor'],
        ['cl', 'chl'], ['ciclo', 'cyclo'], ['ci', 'cy'], ['eri', 'ery'], ['tro', 'thro'], ['anlo', 'amlo'], ['nv', 'mv'], ['fl', 'phl'],
        ['sulfa', 'sulpha'], ['ina', 'yna'], ['ico', 'ic'], ['acido ', 'acid '], ['bi', 'by'], ['fo', 'pho'], ['tri', 'tri'], ['met', 'meth'],
        ['ti', 'thi'], ['ti', 'ty'], ['to', 'tho'], ['ta', 'tha'], ['te', 'the'], ['mi', 'my'], ['ri', 'ry'], ['li', 'ly'], ['si', 'sy'], ['pi', 'py'], ['ni', 'ny'],
    ];

    /** Finais regulares: [sufixo PT, alternativas EN]. */
    private const ENDINGS = [
        ['ilina', ['illin', 'ilin']], ['cilina', ['cillin']], ['micina', ['mycin', 'micin']], ['ciclina', ['cycline', 'cyclin']],
        ['oxacino', ['oxacin']], ['prazol', ['prazole']], ['azol', ['azole']], ['sartana', ['sartan']], ['statina', ['statin']],
        ['tidina', ['tidine']], ['ipino', ['ipine']], ['ona', ['one', 'on']], ['ana', ['ane', 'an']], ['ina', ['ine', 'in']],
        ['ino', ['ine', 'in']], ['ila', ['il', 'yl', 'ile']], ['ilo', ['yl', 'il']], ['ato', ['ate']], ['ico', ['ic']], ['ida', ['ide']],
        ['ido', ['ide']], ['ano', ['ane', 'an']], ['eno', ['en', 'ene']], ['aco', ['ac']], ['ol', ['ol', 'ole']], ['a', ['', 'e']], ['o', ['', 'e']],
        ['io', ['ium']], ['ia', ['y', 'ia']], ['ose', ['ose']], ['ato de', ['ate']],
    ];

    /**
     * @return list<string>
     */
    public function candidates(string $normalizedBase): array
    {
        $spellings = $this->spellingVariants($normalizedBase);
        $out = [];
        foreach ($spellings as $variant) {
            $out[$variant] = true;
            foreach (self::ENDINGS as [$suffix, $replacements]) {
                if (str_ends_with($variant, $suffix) && strlen($variant) > strlen($suffix) + 2) {
                    $stem = substr($variant, 0, -strlen($suffix));
                    foreach ($replacements as $replacement) {
                        $out[$stem.$replacement] = true;
                    }
                }
            }
        }

        return array_keys($out);
    }

    /** @return list<string> */
    private function spellingVariants(string $word): array
    {
        $variants = [$word => true];
        if (preg_match('/^e(s[ptc])/', $word) === 1) {
            $variants[substr($word, 1)] = true;
        }
        foreach (self::SPELLING as [$from, $to]) {
            if ($from === $to) {
                continue;
            }
            foreach (array_keys($variants) as $existing) {
                if (str_contains($existing, $from) && count($variants) < 400) {
                    $variants[str_replace($from, $to, $existing)] = true;
                }
            }
        }

        return array_keys($variants);
    }
}
