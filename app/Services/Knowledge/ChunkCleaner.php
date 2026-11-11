<?php

namespace App\Services\Knowledge;

/**
 * Limpeza dos trechos de uma bula, a mesma para o chunker (documentos novos) e para `knowledge:repair-chunks` (os existentes).
 *
 * O PDF traz rodapés ("RDC 73/2016 - 10452 -", números de página) e títulos quebrados em duas linhas ("COMO ESTE MEDICAMENTO" /
 * "FUNCIONA?"), que viram trechos de poucos caracteres sem valor para a busca. Regras, nesta ordem:
 *  1. descarta o trecho sem texto útil (menos de 3 letras);
 *  2. descarta a repetição exata de um trecho curto já visto no mesmo documento (rodapé e cabeçalho de cada página);
 *  3. junta o trecho muito curto ao trecho seguinte (ao anterior, se for o último), pois o título pertence ao que vem depois.
 * O trecho que absorve os outros mantém a própria identidade (id e seção), de modo que citações a ele continuam válidas.
 */
final class ChunkCleaner
{
    /**
     * @param  list<array{content: string, page_start: int, page_end: int}>  $items  na ordem do documento
     * @return array{kept: list<array{position: int, content: string, page_start: int, page_end: int, merged: list<int>, changed: bool}>, dropped: list<int>}
     */
    public static function clean(array $items): array
    {
        $minimum = (int) config('knowledge.chunking.min_characters', 30);
        $duplicateLimit = (int) config('knowledge.chunking.duplicate_max_characters', 160);
        $seen = [];
        $dropped = [];
        $kept = [];
        $pending = null;

        foreach ($items as $position => $item) {
            $text = trim($item['content']);
            $key = mb_strtolower((string) preg_replace('/\s+/u', ' ', $text));
            if (preg_match_all('/\p{L}/u', $text) < 3 || (mb_strlen($text) <= $duplicateLimit && isset($seen[$key]))) {
                $dropped[] = $position;

                continue;
            }
            $seen[$key] = true;

            if (mb_strlen($text) < $minimum) {
                $pending = $pending === null
                    ? ['content' => $text, 'page_start' => $item['page_start'], 'page_end' => $item['page_end'], 'merged' => [$position]]
                    : ['content' => $pending['content']."\n\n".$text, 'page_start' => min($pending['page_start'], $item['page_start']), 'page_end' => max($pending['page_end'], $item['page_end']), 'merged' => [...$pending['merged'], $position]];

                continue;
            }

            $entry = ['position' => $position, 'content' => $item['content'], 'page_start' => $item['page_start'], 'page_end' => $item['page_end'], 'merged' => [], 'changed' => false];
            if ($pending !== null) {
                $entry = [...$entry, 'content' => $pending['content']."\n\n".$item['content'], 'page_start' => min($pending['page_start'], $item['page_start']), 'merged' => $pending['merged'], 'changed' => true];
                $pending = null;
            }
            $kept[] = $entry;
        }

        if ($pending !== null) {
            if ($kept === []) {
                $kept[] = ['position' => $pending['merged'][0], 'content' => $pending['content'], 'page_start' => $pending['page_start'], 'page_end' => $pending['page_end'], 'merged' => array_slice($pending['merged'], 1), 'changed' => count($pending['merged']) > 1];
            } else {
                $last = array_key_last($kept);
                $kept[$last] = [...$kept[$last], 'content' => $kept[$last]['content']."\n\n".$pending['content'], 'page_end' => max($kept[$last]['page_end'], $pending['page_end']), 'merged' => [...$kept[$last]['merged'], ...$pending['merged']], 'changed' => true];
            }
        }

        return ['kept' => $kept, 'dropped' => $dropped];
    }

    /** Estimativa de tokens (4 caracteres por token, a regra usual para texto latino); não é a contagem do tokenizador do modelo. */
    public static function estimateTokens(string $content): int
    {
        return max(1, (int) ceil(mb_strlen($content) / 4));
    }
}
