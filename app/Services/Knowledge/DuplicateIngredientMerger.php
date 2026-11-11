<?php

namespace App\Services\Knowledge;

use App\Models\Knowledge\ActiveIngredient;
use App\Models\Knowledge\ActiveIngredientAlias;
use App\Models\Knowledge\KnowledgeDocument;
use Illuminate\Support\Facades\DB;

/**
 * Funde no princípio ativo do DDInter os duplicados criados a partir de bulas em português ("ibuprofeno",
 * "besilato de anlodipino") antes de o catálogo conhecer o nome. O duplicado não tem interações: enquanto existe,
 * o medicamento ligado a ele não acusa nenhuma interação do DDInter (ex.: Marevan + ibuprofeno).
 */
final class DuplicateIngredientMerger
{
    public function __construct(private readonly ActiveIngredientResolver $resolver) {}

    /** @return list<array{from: string, into: string}> */
    public function merge(): array
    {
        $merged = [];
        $this->resolver->flush();

        ActiveIngredient::query()
            ->whereDoesntHave('aliases', fn ($query) => $query->where('origin', ActiveIngredientAlias::ORIGIN_DDINTER))
            ->orderBy('id')->get()
            ->each(function (ActiveIngredient $duplicate) use (&$merged): void {
                [$resolution] = $this->resolver->resolveWithCognates($duplicate->normalized_name, excluding: $duplicate->id);
                $target = $resolution->isResolved() ? ActiveIngredient::query()->find($resolution->ingredientId) : null;
                if ($target === null) {
                    return;
                }
                DB::transaction(fn () => $this->moveInto($duplicate, $target));
                $this->resolver->flush();
                $merged[] = ['from' => $duplicate->canonical_name, 'into' => $target->canonical_name];
            });

        return $merged;
    }

    private function moveInto(ActiveIngredient $duplicate, ActiveIngredient $target): void
    {
        foreach (['knowledge_document_ingredients' => 'document_id', 'anvisa_medicine_ingredients' => 'medicine_id', 'external_labels' => 'source'] as $table => $owner) {
            DB::table($table)->where('ingredient_id', $duplicate->id)
                ->whereNotIn($owner, DB::table($table)->where('ingredient_id', $target->id)->select($owner))
                ->update(['ingredient_id' => $target->id]);
        }
        DB::table('active_ingredient_aliases')->where('ingredient_id', $duplicate->id)
            ->whereNotIn('normalized_alias', DB::table('active_ingredient_aliases')->where('ingredient_id', $target->id)->select('normalized_alias'))
            ->update(['ingredient_id' => $target->id]);

        KnowledgeDocument::query()->whereNotNull('metadata->ingredient_links->excluded')->each(function (KnowledgeDocument $document) use ($duplicate, $target): void {
            $metadata = (array) $document->metadata;
            $excluded = array_map('intval', (array) ($metadata['ingredient_links']['excluded'] ?? []));
            if (in_array($duplicate->id, $excluded, true)) {
                $metadata['ingredient_links']['excluded'] = array_values(array_unique(array_map(fn (int $id): int => $id === $duplicate->id ? $target->id : $id, $excluded)));
                $document->forceFill(['metadata' => $metadata])->save();
            }
        });

        $duplicate->delete();
    }
}
