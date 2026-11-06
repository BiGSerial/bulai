<?php

namespace App\Services\Interactions;

use App\Models\Knowledge\AnvisaMedicine;
use App\Services\Knowledge\AnvisaCatalogNormalizer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Correspondência tolerante a erros de digitação (pg_trgm) entre um termo e o catálogo ANVISA.
 *
 * Compara o termo com a primeira palavra do nome comercial, com o nome comercial inteiro, com o princípio ativo e com
 * os aliases de princípios ativos (inclusive em inglês). Devolve um produto representativo por conjunto de princípios
 * ativos, do mais parecido para o menos parecido. Determinístico e sem IA: "losartam" → Losartana, "omeprasol" → Omeprazol.
 */
final class FuzzyMedicineMatcher
{
    /** Abaixo disto a semelhança é coincidência ("dor" × "dorflex"). */
    public const MIN_SIMILARITY = 0.45;

    /** Termos curtos geram falsos positivos demais. */
    public const MIN_TERM_LENGTH = 4;

    private const CANDIDATE_LIMIT = 40;

    public function __construct(private readonly AnvisaCatalogNormalizer $normalizer) {}

    /**
     * @return list<array{medicine: AnvisaMedicine, similarity: float, matched: string}> um por grupo de princípios ativos, mais parecidos primeiro
     */
    public function match(string $term, int $limit = 5, float $minSimilarity = self::MIN_SIMILARITY): array
    {
        $normalized = $this->normalizer->text($term);
        if (mb_strlen($normalized) < self::MIN_TERM_LENGTH || ctype_digit($normalized[0])) {
            return [];
        }

        $rows = $this->productRows($normalized, $minSimilarity)->merge($this->ingredientRows($normalized, $minSimilarity));
        if ($rows->isEmpty()) {
            return [];
        }

        $medicines = AnvisaMedicine::query()->with('ingredients:id')->whereIn('id', $rows->pluck('id')->unique()->all())->get()->keyBy('id');
        // Empate: o produto genérico (nome = princípio ativo), identificado e com menos princípios ativos representa melhor o que foi digitado.
        $ranked = $rows->sortBy(function (object $row) use ($medicines): array {
            $medicine = $medicines->get((int) $row->id);
            $ingredients = $medicine?->ingredients->count() ?? 0;

            return [
                -round((float) $row->similarity, 3),
                $medicine?->normalized_product_name === $medicine?->normalized_active_ingredient ? 0 : 1,
                $ingredients === 0 ? 99 : $ingredients,
                (int) $row->id,
            ];
        });
        $groups = [];
        foreach ($ranked as $row) {
            $medicine = $medicines->get((int) $row->id);
            if ($medicine === null) {
                continue;
            }
            $key = $this->groupKey($medicine);
            if (isset($groups[$key])) {
                continue;
            }
            $groups[$key] = ['medicine' => $medicine, 'similarity' => round((float) $row->similarity, 3), 'matched' => (string) $row->matched];
            if (count($groups) >= $limit) {
                break;
            }
        }

        return array_values($groups);
    }

    /**
     * Melhor correspondência única, só quando ela se destaca das demais (evita "corrigir" um termo ambíguo).
     *
     * @return array{medicine: AnvisaMedicine, similarity: float, matched: string}|null
     */
    public function best(string $term, float $minSimilarity = self::MIN_SIMILARITY): ?array
    {
        $matches = $this->match($term, self::MAX_GROUPS_FOR_A_CLEAR_MATCH + 1, $minSimilarity);
        if ($matches === []) {
            return null;
        }
        if (count($matches) === 1 || $this->shareAnIngredient($matches)) {
            return $matches[0];
        }
        // Todos se pareceram com a MESMA palavra e ela é o nome de um produto ("omeprazol"): a correção é inequívoca,
        // ainda que haja combinações com outros princípios ativos; prefere o produto que tem exatamente esse nome.
        $words = array_unique(array_column($matches, 'matched'));
        if (count($words) === 1) {
            foreach ($matches as $match) {
                if ($match['medicine']->normalized_product_name === $words[0] || $match['medicine']->normalized_active_ingredient === $words[0]) {
                    return $match;
                }
            }
        }
        // Vários grupos sem princípio ativo em comum ("acido" × fólico/valproico/ascórbico…): só vale se um deles se destacar.
        if (count($matches) > self::MAX_GROUPS_FOR_A_CLEAR_MATCH || $matches[0]['similarity'] - $matches[1]['similarity'] < 0.05) {
            return null;
        }

        return $matches[0];
    }

    /** Dipirona sódica, monoidratada e com cafeína são o mesmo remédio para quem digitou "dipirnoa". */
    private const MAX_GROUPS_FOR_A_CLEAR_MATCH = 3;

    /** @param  list<array{medicine: AnvisaMedicine, similarity: float, matched: string}>  $matches */
    private function shareAnIngredient(array $matches): bool
    {
        $common = null;
        foreach ($matches as $match) {
            $ids = $match['medicine']->ingredients->pluck('id')->map(fn ($id): int => (int) $id)->all();
            if ($ids === []) {
                return false;
            }
            $common = $common === null ? $ids : array_values(array_intersect($common, $ids));
            if ($common === []) {
                return false;
            }
        }

        return $common !== null && $common !== [];
    }

    /** @return Collection<int, object{id: int, similarity: float, matched: string}> */
    private function productRows(string $term, float $minSimilarity): Collection
    {
        // Compara com a primeira palavra do nome comercial e do princípio ativo (o que o paciente digita) e com os nomes
        // inteiros. `similarity` é simétrica; `word_similarity` foi evitada porque infla nomes com palavras repetidas.
        $sql = <<<'SQL'
            select id, matched, similarity from (
                select id,
                    case
                        when similarity(split_part(normalized_product_name, ' ', 1), ?) >= similarity(split_part(coalesce(normalized_active_ingredient, ''), ' ', 1), ?)
                        then split_part(normalized_product_name, ' ', 1) else split_part(coalesce(normalized_active_ingredient, ''), ' ', 1) end as matched,
                    greatest(
                        similarity(split_part(normalized_product_name, ' ', 1), ?),
                        similarity(normalized_product_name, ?),
                        similarity(split_part(coalesce(normalized_active_ingredient, ''), ' ', 1), ?),
                        similarity(coalesce(normalized_active_ingredient, ''), ?)
                    ) as similarity,
                    case when registration_status = 'Ativo' then 0 else 1 end as inactive
                from anvisa_medicines
                where split_part(normalized_product_name, ' ', 1) % ? or normalized_product_name % ?
                    or split_part(coalesce(normalized_active_ingredient, ''), ' ', 1) % ? or normalized_active_ingredient % ?
            ) ranked
            where similarity >= ?
            order by similarity desc, inactive, id
            limit ?
        SQL;

        return collect(DB::select($sql, [$term, $term, $term, $term, $term, $term, $term, $term, $term, $term, $minSimilarity, self::CANDIDATE_LIMIT]));
    }

    /** @return Collection<int, object{id: int, similarity: float, matched: string}> */
    private function ingredientRows(string $term, float $minSimilarity): Collection
    {
        $sql = <<<'SQL'
            select distinct on (ingredient_id) ingredient_id, matched, similarity from (
                select id as ingredient_id, normalized_name as matched, similarity(normalized_name, ?) as similarity from active_ingredients where normalized_name % ?
                union all
                select ingredient_id, normalized_alias as matched, similarity(normalized_alias, ?) as similarity from active_ingredient_aliases where normalized_alias % ?
            ) hits
            where similarity >= ?
            order by ingredient_id, similarity desc
        SQL;
        $hits = collect(DB::select($sql, [$term, $term, $term, $term, $minSimilarity]));
        if ($hits->isEmpty()) {
            return new Collection;
        }

        $rows = new Collection;
        foreach ($hits->sortByDesc('similarity')->take(10) as $hit) {
            $medicine = AnvisaMedicine::query()->select('anvisa_medicines.id')
                ->join('anvisa_medicine_ingredients as ami', 'ami.medicine_id', '=', 'anvisa_medicines.id')
                ->where('ami.ingredient_id', (int) $hit->ingredient_id)
                ->orderByRaw("case when registration_status = 'Ativo' then 0 else 1 end")
                ->orderByRaw('case when normalized_product_name = normalized_active_ingredient then 0 else 1 end')
                ->orderBy('anvisa_medicines.id')
                ->first();
            if ($medicine !== null) {
                $rows->push((object) ['id' => $medicine->id, 'similarity' => (float) $hit->similarity, 'matched' => (string) $hit->matched]);
            }
        }

        return $rows;
    }

    private function groupKey(AnvisaMedicine $medicine): string
    {
        $ids = $medicine->ingredients->pluck('id')->map(fn ($id): int => (int) $id)->sort()->values()->all();

        return $ids !== [] ? 'i:'.implode('-', $ids) : 'p:'.$medicine->normalized_product_name.'|'.$medicine->normalized_active_ingredient;
    }
}
