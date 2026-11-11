<?php

namespace App\Services\Analytics;

use App\Models\Knowledge\ExternalLabel;
use App\Models\Knowledge\KnowledgeDocument;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Tamanho real da base de conhecimento (docs/api.md): bulas prontas e os princípios ativos que elas cobrem, o catálogo da
 * ANVISA, as interações do DDInter (todas e só entre princípios ativos com bula) e as bulas dos EUA importadas. É o retrato
 * do ambiente que responde, para os números de um relatório não virem de outra máquina.
 */
final class KnowledgeInventoryService
{
    /**
     * @return array{
     *   documents: array{total: int, by_status: array<string, int>, by_type: array<string, int>, ready: int, ready_linked: int, chunks: int, chunks_embedded: int},
     *   leaflets: list<array{id: int, title: string, type: string, ingredients: list<string>}>,
     *   ingredients: array{total: int, with_leaflet: int, with_leaflet_names: list<string>},
     *   catalog: array{medicines: int, identified: int, with_leaflet: int, fully_covered: int},
     *   interactions: array{total: int, linked: int, by_severity: array<string, int>, involving_leaflet: int, between_leaflets: int, between_leaflets_by_severity: array<string, int>},
     *   external_labels: array{by_status: array<string, int>, by_via: array<string, int>, sections_by_translation: array<string, int>, ready: list<array{ingredient: string, title: string|null, via: string|null}>}
     * }
     */
    public function inventory(): array
    {
        $withLeaflet = DB::table('knowledge_document_ingredients as di')
            ->join('knowledge_documents as d', 'd.id', '=', 'di.document_id')
            ->where('d.content_status', KnowledgeDocument::STATUS_READY)
            ->distinct()->pluck('di.ingredient_id')->map(fn ($id): int => (int) $id)->all();

        return [
            'documents' => $this->documents(),
            'leaflets' => $this->leaflets(),
            'ingredients' => [
                'total' => (int) DB::table('active_ingredients')->count(),
                'with_leaflet' => count($withLeaflet),
                'with_leaflet_names' => DB::table('active_ingredients')->whereIn('id', $withLeaflet)->orderBy('canonical_name')->pluck('canonical_name')->map(fn ($name): string => (string) $name)->all(),
            ],
            'catalog' => $this->catalog($withLeaflet),
            'interactions' => $this->interactions($withLeaflet),
            'external_labels' => $this->externalLabels(),
        ];
    }

    /** @return array{total: int, by_status: array<string, int>, by_type: array<string, int>, ready: int, ready_linked: int, chunks: int, chunks_embedded: int} */
    private function documents(): array
    {
        $ready = fn () => DB::table('knowledge_documents')->where('content_status', KnowledgeDocument::STATUS_READY);

        return [
            'total' => (int) DB::table('knowledge_documents')->count(),
            'by_status' => $this->groupCount(DB::table('knowledge_documents'), 'content_status'),
            'by_type' => $this->groupCount($ready(), 'document_type'),
            'ready' => (int) $ready()->count(),
            'ready_linked' => (int) $ready()->whereExists(fn ($query) => $query->selectRaw('1')->from('knowledge_document_ingredients as di')->whereColumn('di.document_id', 'knowledge_documents.id'))->count(),
            'chunks' => (int) DB::table('knowledge_chunks')->count(),
            'chunks_embedded' => (int) DB::table('knowledge_chunks')->whereNotNull('embedding_model')->count(),
        ];
    }

    /** @return list<array{id: int, title: string, type: string, ingredients: list<string>}> */
    private function leaflets(): array
    {
        $ingredients = DB::table('knowledge_document_ingredients as di')->join('active_ingredients as i', 'i.id', '=', 'di.ingredient_id')
            ->select(['di.document_id', 'i.canonical_name'])->orderBy('i.canonical_name')->get()->groupBy('document_id');

        return DB::table('knowledge_documents')->where('content_status', KnowledgeDocument::STATUS_READY)->orderBy('id')->get(['id', 'title', 'document_type'])
            ->map(fn (object $document): array => [
                'id' => (int) $document->id,
                'title' => (string) $document->title,
                'type' => (string) $document->document_type,
                'ingredients' => collect($ingredients->get($document->id, []))->pluck('canonical_name')->map(fn ($name): string => (string) $name)->values()->all(),
            ])->values()->all();
    }

    /**
     * Produtos do catálogo: com princípio ativo identificado, com bula de pelo menos um dos ativos e com bula de todos eles.
     *
     * @param  list<int>  $withLeaflet
     * @return array{medicines: int, identified: int, with_leaflet: int, fully_covered: int}
     */
    private function catalog(array $withLeaflet): array
    {
        $covered = $withLeaflet === [] ? 0 : (int) DB::query()->fromSub(
            DB::table('anvisa_medicine_ingredients')->select('medicine_id')->groupBy('medicine_id')
                ->havingRaw('bool_and(ingredient_id in ('.implode(',', $withLeaflet).'))'),
            'covered'
        )->count();

        return [
            'medicines' => (int) DB::table('anvisa_medicines')->count(),
            'identified' => (int) DB::table('anvisa_medicine_ingredients')->distinct()->count('medicine_id'),
            'with_leaflet' => (int) DB::table('anvisa_medicine_ingredients')->whereIn('ingredient_id', $withLeaflet)->distinct()->count('medicine_id'),
            'fully_covered' => $covered,
        ];
    }

    /**
     * Interações do DDInter: todas, as ligadas aos dois princípios ativos do catálogo e as que têm bula de um ou dos dois lados.
     *
     * @param  list<int>  $withLeaflet
     * @return array{total: int, linked: int, by_severity: array<string, int>, involving_leaflet: int, between_leaflets: int, between_leaflets_by_severity: array<string, int>}
     */
    private function interactions(array $withLeaflet): array
    {
        $between = fn () => DB::table('drug_interactions')->whereIn('drug_a_ingredient_id', $withLeaflet)->whereIn('drug_b_ingredient_id', $withLeaflet);

        return [
            'total' => (int) DB::table('drug_interactions')->count(),
            'linked' => (int) DB::table('drug_interactions')->whereNotNull('drug_a_ingredient_id')->whereNotNull('drug_b_ingredient_id')->count(),
            'by_severity' => $this->groupCount(DB::table('drug_interactions'), 'severity'),
            'involving_leaflet' => (int) DB::table('drug_interactions')->where(fn ($query) => $query->whereIn('drug_a_ingredient_id', $withLeaflet)->orWhereIn('drug_b_ingredient_id', $withLeaflet))->count(),
            'between_leaflets' => (int) $between()->count(),
            'between_leaflets_by_severity' => $this->groupCount($between(), 'severity'),
        ];
    }

    /** @return array{by_status: array<string, int>, by_via: array<string, int>, sections_by_translation: array<string, int>, ready: list<array{ingredient: string, title: string|null, via: string|null}>} */
    private function externalLabels(): array
    {
        return [
            'by_status' => $this->groupCount(DB::table('external_labels'), 'status'),
            'by_via' => $this->groupCount(DB::table('external_labels')->where('status', ExternalLabel::STATUS_READY), 'retrieved_via'),
            'sections_by_translation' => $this->groupCount(DB::table('external_label_sections'), 'translation_status'),
            'ready' => DB::table('external_labels as l')->join('active_ingredients as i', 'i.id', '=', 'l.ingredient_id')
                ->where('l.status', ExternalLabel::STATUS_READY)->orderBy('i.canonical_name')->get(['i.canonical_name', 'l.title', 'l.retrieved_via'])
                ->map(fn (object $row): array => ['ingredient' => (string) $row->canonical_name, 'title' => $row->title, 'via' => $row->retrieved_via])->values()->all(),
        ];
    }

    /** @return array<string, int> */
    private function groupCount(Builder $query, string $column): array
    {
        /** @var Collection<string, int> $counts */
        $counts = $query->selectRaw("coalesce({$column}, 'indefinido') as k, count(*) as c")->groupBy('k')->orderByDesc('c')->pluck('c', 'k');

        return $counts->map(fn ($count): int => (int) $count)->all();
    }
}
