<?php

namespace App\Services\Knowledge;

use Illuminate\Support\Str;

final class AnvisaCatalogNormalizer
{
    public function registrationNumber(?string $value): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $value) ?? '';

        return in_array(strlen($digits), [9, 13], true) ? $digits : null;
    }

    public function text(?string $value): string
    {
        $normalized = Str::ascii(Str::lower(trim((string) $value)));
        $normalized = preg_replace('/[®™©]/u', '', $normalized) ?? $normalized;

        return trim(preg_replace('/[^a-z0-9]+/', ' ', $normalized) ?? $normalized);
    }

    /** @return list<string> */
    public function activeIngredientSet(?string $value): array
    {
        $normalized = $this->text($value);
        if ($normalized === '') {
            return [];
        }

        $parts = preg_split('/\s*(?:\+|;|\be\b)\s*/', $normalized) ?: [];
        $parts = array_values(array_unique(array_filter(array_map('trim', $parts))));
        sort($parts);

        return $parts;
    }

    /** @return list<string> */
    public function holderTokens(?string $value): array
    {
        $normalized = preg_replace('/^\d{14}\s*-\s*/', '', trim((string) $value)) ?? (string) $value;
        $ignored = ['de', 'da', 'do', 'das', 'dos', 'e', 'sa', 's', 'a', 'ltda', 'industria', 'farmaceutica', 'quimica', 'laboratorio', 'laboratorios'];
        $tokens = array_filter(explode(' ', $this->text($normalized)), fn (string $token): bool => strlen($token) > 1 && ! in_array($token, $ignored, true));

        return array_values(array_unique($tokens));
    }
}
