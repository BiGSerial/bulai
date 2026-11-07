<?php

namespace App\Services\Knowledge;

/**
 * Normaliza nomes de princípios ativos (PT/EN) e remove sais, ésteres e hidratos para aproximar
 * "cloridrato de metformina" de "metformina". É determinístico e sem IA: a ligação final é sempre auditável.
 */
final class IngredientNameNormalizer
{
    /** Sais/ésteres iniciais ("<sal> de X"). Já normalizados (sem acento, hífen vira espaço). */
    private const SALT_PREFIXES = [
        'cloridrato', 'dicloridrato', 'sulfato', 'bissulfato', 'fosfato', 'difosfato', 'mesilato', 'maleato', 'tartarato', 'bitartarato',
        'succinato', 'fumarato', 'acetato', 'citrato', 'besilato', 'brometo', 'bromidrato', 'nitrato', 'valerato', 'propionato',
        'dipropionato', 'furoato', 'cipionato', 'enantato', 'benzoato', 'lactato', 'gluconato', 'carbonato', 'oxalato', 'tosilato',
        'hidrocloreto', 'cloreto', 'iodeto', 'palmitato', 'estearato', 'undecanoato', 'decanoato', 'pamoato', 'hemifumarato',
        'hidrobrometo', 'estolato', 'etilsuccinato', 'lactobionato', 'gluceptato', 'ascorbato', 'salicilato', 'sacarato', 'cetoglutarato', 'hidrogenotartarato', 'hidroxido',
        'hemitartarato', 'hemissulfato', 'hidrogenossulfato', 'dinitrato', 'mononitrato', 'embonato', 'pivalato', 'trometamol', 'napsilato', 'hiclato', 'resinato', 'polistirex',
        'monoidrato', 'tetraidrato', 'dicloreto', 'bissuccinato', 'hemi hidrato', 'glicinato', 'orotato', 'aspartato', 'picolinato', 'bicarbonato', 'tiocianato', 'cromoglicato',
    ];

    /** Dose/concentração colada ao nome ("losartana potassica 50mg", "2,5 mg"): tokens numéricos e unidades. */
    private const STRENGTH_TOKEN = '/^(\d+([.,]\d+)?(mg|mcg|ug|g|ml|l|ui|iu|%)?|mg|mcg|ug|ml|ui|iu|mg\/ml|mcg\/ml)$/';

    /** Qualificadores finais descartáveis. */
    private const TRAILING_QUALIFIERS = [
        'sodica', 'sodico', 'potassica', 'potassico', 'calcica', 'calcico', 'magnesica', 'magnesico', 'anidro', 'anidra', 'base',
        'hidratado', 'hidratada', 'monoidratado', 'monoidratada', 'dihidratado', 'dihidratada', 'tri', 'sesqui', 'hemi', 'micronizado',
        'micronizada', 'sodium', 'potassium', 'calcium', 'hydrochloride', 'sulfate', 'sulphate', 'phosphate', 'mesylate', 'maleate',
        'tartrate', 'succinate', 'fumarate', 'acetate', 'citrate', 'besylate', 'bromide', 'nitrate', 'hydrate', 'monohydrate', 'anhydrous',
    ];

    public function __construct(private readonly AnvisaCatalogNormalizer $text) {}

    /** Nome sem qualificadores entre parênteses (via/forma do DDInter, ex.: "phenylephrine (nasal)"), já normalizado. */
    public function withoutQualifiers(?string $name): string
    {
        return $this->normalize(preg_replace('/\([^)]*\)/', ' ', (string) $name));
    }

    /** Forma completa normalizada (ascii, minúsculas, só [a-z0-9 ]). */
    public function normalize(?string $name): string
    {
        return $this->text->text($name);
    }

    /** Forma sem sais/hidratos; devolve a forma completa se a limpeza esvaziar o nome. */
    public function base(?string $name): string
    {
        $normalized = $this->normalize(preg_replace('/\([^)]*\)/', ' ', (string) $name));
        $normalized = preg_replace('/\b(?:di|mono|tri|sesqui|hemi|hexa|penta)\s+hidratad[oa]\b/', ' ', $normalized) ?? $normalized;
        $tokens = array_values(array_filter(explode(' ', $normalized), fn (string $token): bool => $token !== '' && preg_match(self::STRENGTH_TOKEN, $token) !== 1));
        if ($tokens === []) {
            return $normalized;
        }

        $prefixes = implode('|', self::SALT_PREFIXES);
        if (count($tokens) > 2 && preg_match('/^(?:'.$prefixes.')$/', $tokens[0]) === 1 && in_array($tokens[1], ['de', 'do', 'da'], true)) {
            $tokens = array_slice($tokens, 2);
        }
        while (count($tokens) > 1 && (in_array(end($tokens), self::TRAILING_QUALIFIERS, true) || preg_match('/^[a-z]*i?dratad[oa]s?$/', end($tokens)) === 1)) {
            array_pop($tokens);
        }
        $base = implode(' ', $tokens);

        return $base !== '' ? $base : $normalized;
    }

    /**
     * Componentes de uma associação ("A + B", "A, B", "A; B", "A e B"), sem normalizar.
     *
     * @return list<string>
     */
    public function components(?string $raw): array
    {
        $parts = preg_split('/\s*(?:\+|;|,|\/|\be\b)\s*/iu', (string) $raw) ?: [];

        return array_values(array_unique(array_filter(array_map('trim', $parts), fn (string $part): bool => $part !== '')));
    }
}
