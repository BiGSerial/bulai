<?php

namespace App\Services\Leaflet;

use App\Models\Knowledge\KnowledgeDocument;
use App\Services\Interactions\MedicationSearchService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Quais remédios têm bula simples (site: Livewire `Bulas`; aplicativo: `GET /api/mobile/v1/bulas`): a lista das bulas
 * prontas (um remédio por princípio ativo) e, numa busca no catálogo, quais resultados têm bula.
 */
final class LeafletCatalog
{
    public function __construct(private readonly MedicationSearchService $search) {}

    /**
     * Busca no catálogo marcando `tem_bula`.
     *
     * @return list<array<string, mixed>>
     */
    public function search(string $term): array
    {
        $results = $this->search->search($term);
        $withLeaflet = $this->withLeaflet(array_column($results, 'id'));

        return array_map(fn (array $item): array => $item + ['tem_bula' => in_array($item['id'], $withLeaflet, true)], $results);
    }

    /**
     * Um remédio por princípio ativo com bula (cache de 5 minutos).
     *
     * @return list<array{id: int, name: string}>
     */
    public function available(): array
    {
        return Cache::remember('consulta:bulas-prontas', now()->addMinutes(5), fn (): array => $this->computeAvailable());
    }

    /** @return list<int> */
    private function readyIngredients(): array
    {
        return DB::table('knowledge_document_ingredients as di')->join('knowledge_documents as d', 'd.id', '=', 'di.document_id')
            ->where('d.content_status', KnowledgeDocument::STATUS_READY)->distinct()->pluck('di.ingredient_id')->map(fn ($id): int => (int) $id)->all();
    }

    /**
     * Dos remédios encontrados, os que têm bula de algum dos seus princípios ativos.
     *
     * @param  list<int>  $medicineIds
     * @return list<int>
     */
    private function withLeaflet(array $medicineIds): array
    {
        if ($medicineIds === []) {
            return [];
        }

        return DB::table('anvisa_medicine_ingredients')->whereIn('medicine_id', $medicineIds)->whereIn('ingredient_id', $this->readyIngredients())
            ->distinct()->pluck('medicine_id')->map(fn ($id): int => (int) $id)->all();
    }

    /**
     * O de nome genérico (nome do produto igual ao princípio ativo), com registro ativo e de um princípio ativo só, para
     * a lista abrir a bula certa.
     *
     * @return list<array{id: int, name: string}>
     */
    private function computeAvailable(): array
    {
        $ingredients = $this->readyIngredients();
        if ($ingredients === []) {
            return [];
        }

        return DB::table('anvisa_medicine_ingredients as mi')->join('anvisa_medicines as m', 'm.id', '=', 'mi.medicine_id')
            ->whereIn('mi.ingredient_id', $ingredients)
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('anvisa_medicine_ingredients as other')->whereColumn('other.medicine_id', 'm.id')->whereColumn('other.ingredient_id', '!=', 'mi.ingredient_id'))
            ->selectRaw('distinct on (mi.ingredient_id) m.id, m.product_name')
            ->orderBy('mi.ingredient_id')
            ->orderByRaw('case when m.normalized_product_name = m.normalized_active_ingredient then 0 else 1 end')
            ->orderByRaw("case when m.registration_status = 'Ativo' then 0 else 1 end")
            ->orderBy('m.id')
            ->get()
            ->map(fn (object $row): array => ['id' => (int) $row->id, 'name' => (string) $row->product_name])
            ->sortBy('name')->values()->all();
    }
}
