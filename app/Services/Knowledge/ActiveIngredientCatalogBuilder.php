<?php

namespace App\Services\Knowledge;

use App\Data\Knowledge\IngredientResolution;
use App\Models\Knowledge\ActiveIngredient;
use App\Models\Knowledge\ActiveIngredientAlias;
use App\Models\Knowledge\AnvisaMedicine;
use Illuminate\Support\Facades\DB;

/**
 * Constrói, de forma idempotente, o catálogo de princípios ativos a partir do DDInter (nomes canônicos em inglês),
 * liga os medicamentos da ANVISA a ele e preenche as pontas de `drug_interactions`.
 * Aliases por regra ortográfica e curados ficam como `pending_review`: precisam de revisão farmacêutica.
 */
final class ActiveIngredientCatalogBuilder
{
    public function __construct(
        private readonly IngredientNameNormalizer $normalizer,
        private readonly ActiveIngredientResolver $resolver,
        private readonly IngredientTranslationService $translation,
        private readonly DuplicateIngredientMerger $merger,
    ) {}

    /**
     * Segunda passada, com IA: os textos de princípio ativo que continuaram sem vínculo depois de `build()` são traduzidos
     * para o nome do DDInter (`IngredientTranslationService`); os aceitos viram aliases `pending_review` e os medicamentos
     * correspondentes são ligados. Sem provedor configurado ou com a tradução desligada, não faz nada.
     *
     * Nomes que a IA já tentou e que o DDInter não tem ficam em `ingredient_translation_misses` e não são reenviados
     * (`$retryMisses` limpa essa memória). Assim cada execução avança para os nomes ainda não tentados.
     *
     * @return array{names: int, proposed: int, accepted: int, medicines_linked: int, unknown: list<string>, error_code: string|null, skipped_known_misses?: int, reason?: string}
     */
    public function translateUnresolved(bool $retryMisses = false): array
    {
        $base = ['names' => 0, 'proposed' => 0, 'accepted' => 0, 'medicines_linked' => 0, 'unknown' => [], 'error_code' => null];
        if (! $this->translation->isEnabled() || ! $this->translation->hasCatalog()) {
            return $base + ['reason' => 'disabled'];
        }
        $texts = [];
        AnvisaMedicine::query()->select(['id', 'active_ingredient'])
            ->whereNotNull('active_ingredient')->where('active_ingredient', '!=', '')
            ->whereNotIn('id', DB::table('anvisa_medicine_ingredients')->select('medicine_id'))
            ->orderBy('id')->chunkById(2000, function ($medicines) use (&$texts): void {
                foreach ($medicines as $medicine) {
                    foreach ($this->normalizer->components($medicine->active_ingredient) as $component) {
                        $texts[$this->normalizer->base($component)] = $component;
                    }
                }
            });
        if ($retryMisses) {
            DB::table('ingredient_translation_misses')->delete();
        }
        $misses = DB::table('ingredient_translation_misses')->pluck('normalized_name')->flip()->all();
        $skipped = count(array_intersect_key($texts, $misses));
        $texts = array_diff_key($texts, $misses);
        $names = array_slice(array_values($texts), 0, (int) config('bulai.ingredients.ai_translation.max_names_per_run'));
        $result = $this->translation->translate($names);
        if ($result['unknown'] !== []) {
            $rows = array_map(fn (string $name): array => ['normalized_name' => mb_substr($name, 0, 200), 'attempts' => 1, 'created_at' => now(), 'updated_at' => now()], array_values(array_unique($result['unknown'])));
            DB::table('ingredient_translation_misses')->upsert($rows, ['normalized_name'], ['attempts' => DB::raw('ingredient_translation_misses.attempts + 1'), 'updated_at']);
        }
        $stats = ['names' => count($names), 'skipped_known_misses' => $skipped] + array_intersect_key($result, $base) + $base;
        if ($result['accepted'] > 0) {
            $this->resolver->flush();
            $stats['medicines_linked'] = $this->linkMedicines()['medicines_linked'];
        }

        return $stats;
    }

    /**
     * @return array{ingredients: int, aliases_curated: int, curated_skipped: list<string>, merged: list<array{from: string, into: string}>, components: int, components_linked: int, components_ambiguous: int, medicines_linked: int, medicines_total: int, interactions_linked: int, interactions_total: int}
     */
    public function build(?string $curatedCsvPath = null): array
    {
        $stats = ['ingredients' => $this->syncDdinterIngredients(), 'aliases_curated' => 0, 'curated_skipped' => []];
        $this->resolver->flush();

        [$stats['aliases_curated'], $stats['curated_skipped']] = $this->loadCuratedAliases($curatedCsvPath ?? database_path('data/ingredient_aliases.csv'));
        $this->resolver->flush();

        $stats['merged'] = $this->merger->merge();

        $stats += $this->linkMedicines();
        $stats += $this->linkInteractions();

        return $stats;
    }

    private function syncDdinterIngredients(): int
    {
        $names = DB::table('drug_interactions')->select(['drug_a_name as raw', 'normalized_drug_a_name as normalized'])
            ->union(DB::table('drug_interactions')->select(['drug_b_name as raw', 'normalized_drug_b_name as normalized']))
            ->distinct()->get();

        $canonical = [];
        foreach ($names as $row) {
            $key = $this->normalizer->withoutQualifiers($row->normalized);
            $raw = trim((string) preg_replace('/\s*\([^)]*\)/', '', $row->raw));
            $canonical[$key]['name'] ??= $raw;
            $canonical[$key]['full'][$row->normalized] = true;
        }

        foreach ($canonical as $key => $entry) {
            $ingredient = ActiveIngredient::query()->firstOrCreate(['normalized_name' => $key], ['canonical_name' => $entry['name']]);
            $aliases = array_keys($entry['full']);
            $aliases[] = $key;
            foreach (array_unique(array_filter($aliases)) as $alias) {
                ActiveIngredientAlias::query()->firstOrCreate(
                    ['ingredient_id' => $ingredient->id, 'normalized_alias' => $alias],
                    ['alias' => $alias, 'language' => 'en', 'origin' => ActiveIngredientAlias::ORIGIN_DDINTER, 'review_status' => 'reviewed'],
                );
            }
        }

        return count($canonical);
    }

    /** @return array{0: int, 1: list<string>} */
    private function loadCuratedAliases(string $path): array
    {
        if (! is_readable($path)) {
            return [0, []];
        }

        $created = 0;
        $skipped = [];
        $handle = fopen($path, 'rb');
        fgetcsv($handle, escape: '');
        while (($row = fgetcsv($handle, escape: '')) !== false) {
            [$pt, $en] = [$this->normalizer->normalize($row[0] ?? ''), $this->normalizer->normalize($row[1] ?? '')];
            $ingredient = $en === '' ? null : ActiveIngredient::query()->where('normalized_name', $en)->first();
            if ($pt === '' || $ingredient === null) {
                $skipped[] = trim(($row[0] ?? '').' → '.($row[1] ?? ''));

                continue;
            }
            $alias = ActiveIngredientAlias::query()->firstOrCreate(
                ['ingredient_id' => $ingredient->id, 'normalized_alias' => $pt],
                ['alias' => $row[0], 'language' => 'pt', 'origin' => ActiveIngredientAlias::ORIGIN_CURATED, 'review_status' => 'pending_review'],
            );
            $created += $alias->wasRecentlyCreated ? 1 : 0;
        }
        fclose($handle);

        return [$created, $skipped];
    }

    /** @return array{components: int, components_linked: int, components_ambiguous: int, medicines_linked: int, medicines_total: int} */
    private function linkMedicines(): array
    {
        $cache = [];
        $components = $linked = $ambiguous = 0;
        $medicinesLinked = 0;
        $rows = [];

        AnvisaMedicine::query()->select(['id', 'product_name', 'active_ingredient'])->orderBy('id')->chunkById(2000, function ($medicines) use (&$cache, &$components, &$linked, &$ambiguous, &$medicinesLinked, &$rows): void {
            foreach ($medicines as $medicine) {
                $fromProductName = blank($medicine->active_ingredient);
                $texts = $fromProductName ? [$medicine->product_name] : $this->normalizer->components($medicine->active_ingredient);
                $found = false;
                foreach ($texts as $text) {
                    $components++;
                    $cache[$text] ??= $this->resolver->resolveAndLearn($text);
                    $resolution = $cache[$text];
                    $ambiguous += $resolution->status === IngredientResolution::AMBIGUOUS ? 1 : 0;
                    if (! $resolution->isResolved()) {
                        continue;
                    }
                    $linked++;
                    $found = true;
                    $rows[$medicine->id.':'.$resolution->ingredientId] = [
                        'medicine_id' => $medicine->id,
                        'ingredient_id' => $resolution->ingredientId,
                        'component_text' => mb_substr($text, 0, 250),
                        'method' => $fromProductName ? 'product_name' : $resolution->method,
                        'confidence' => $fromProductName ? min(0.6, $resolution->confidence) : $resolution->confidence,
                        'created_at' => now(), 'updated_at' => now(),
                    ];
                }
                $medicinesLinked += $found ? 1 : 0;
            }
            $this->flushLinks($rows);
        });
        $this->flushLinks($rows);

        return [
            'components' => $components, 'components_linked' => $linked, 'components_ambiguous' => $ambiguous,
            'medicines_linked' => $medicinesLinked, 'medicines_total' => AnvisaMedicine::query()->count(),
        ];
    }

    /** @param array<string, array<string, mixed>> $rows */
    private function flushLinks(array &$rows): void
    {
        if ($rows !== []) {
            DB::table('anvisa_medicine_ingredients')->upsert(array_values($rows), ['medicine_id', 'ingredient_id'], ['component_text', 'method', 'confidence', 'updated_at']);
            $rows = [];
        }
    }

    /** @return array{interactions_linked: int, interactions_total: int} */
    private function linkInteractions(): array
    {
        foreach (['a', 'b'] as $side) {
            DB::statement("
                UPDATE drug_interactions di SET drug_{$side}_ingredient_id = a.ingredient_id
                FROM active_ingredient_aliases a
                WHERE a.origin = 'ddinter' AND a.normalized_alias = di.normalized_drug_{$side}_name
            ");
        }

        return [
            'interactions_linked' => DB::table('drug_interactions')->whereNotNull('drug_a_ingredient_id')->whereNotNull('drug_b_ingredient_id')->count(),
            'interactions_total' => DB::table('drug_interactions')->count(),
        ];
    }
}
