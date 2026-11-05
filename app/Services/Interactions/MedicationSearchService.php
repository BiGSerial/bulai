<?php

namespace App\Services\Interactions;

use App\Models\Knowledge\AnvisaMedicine;
use App\Services\Knowledge\AnvisaCatalogNormalizer;

/** Busca medicamentos do catálogo ANVISA por nome comercial ou princípio ativo, agrupando registros equivalentes. */
final class MedicationSearchService
{
    private const MIN_TERM_LENGTH = 3;

    private const CANDIDATE_LIMIT = 80;

    public function __construct(private readonly AnvisaCatalogNormalizer $normalizer) {}

    /**
     * @return list<array{id: int, name: string, ingredients: string, holder: string, identified: bool, inactive: bool}>
     */
    public function search(string $term, int $limit = 8): array
    {
        $normalized = $this->normalizer->text($term);
        if (strlen($normalized) < self::MIN_TERM_LENGTH) {
            return [];
        }

        $medicines = AnvisaMedicine::query()
            ->with('ingredients:id')
            ->where(fn ($query) => $query
                ->where('normalized_product_name', 'like', $normalized.'%')
                ->orWhere('normalized_product_name', 'like', '% '.$normalized.'%')
                ->orWhere('normalized_active_ingredient', 'like', $normalized.'%')
                ->orWhere('normalized_active_ingredient', 'like', '% '.$normalized.'%'))
            ->orderByRaw("case when registration_status = 'Ativo' then 0 else 1 end")
            ->orderByRaw('case when normalized_product_name like ? then 0 else 1 end', [$normalized.'%'])
            ->orderBy('normalized_product_name')
            ->orderBy('id')
            ->limit(self::CANDIDATE_LIMIT)
            ->get();

        $groups = [];
        foreach ($medicines as $medicine) {
            $key = $medicine->normalized_product_name.'|'.$medicine->normalized_active_ingredient.'|'.$medicine->normalized_registration_holder;
            $groups[$key] ??= [
                'id' => $medicine->id,
                'name' => $medicine->product_name,
                'ingredients' => (string) ($medicine->active_ingredient ?: 'Princípio ativo não informado no catálogo'),
                'holder' => $this->holder($medicine->registration_holder),
                'identified' => $medicine->ingredients->isNotEmpty(),
                'inactive' => $medicine->registration_status !== 'Ativo',
            ];
        }

        return array_slice(array_values($groups), 0, $limit);
    }

    private function holder(string $raw): string
    {
        return trim((string) preg_replace('/^\d{14}\s*-\s*/', '', $raw));
    }
}
