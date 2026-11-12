<?php

namespace App\Livewire\Consulta;

use App\Models\Knowledge\KnowledgeDocument;
use App\Services\Interactions\MedicationSearchService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Component;

/**
 * Bula simples: um lugar só para achar a bula humanizada de um remédio. A lista dos remédios que já têm bula filtra no
 * aparelho enquanto a pessoa digita; com 3 letras ou mais, a busca no catálogo diz também quais têm bula simples pronta.
 */
class Bulas extends Component
{
    public string $busca = '';

    public function render(MedicationSearchService $search): View
    {
        $buscou = strlen(trim($this->busca)) >= 3;
        $limitado = $buscou && RateLimiter::tooManyAttempts('busca:'.auth()->id(), (int) config('bulai.consultation.search_limit_per_minute'));
        if ($buscou && ! $limitado) {
            RateLimiter::hit('busca:'.auth()->id(), 60);
        }
        $resultados = $buscou && ! $limitado ? $search->search($this->busca) : [];
        $comBula = $this->withLeaflet(array_column($resultados, 'id'));

        return view('livewire.consulta.bulas', [
            'resultados' => array_map(fn (array $item): array => $item + ['tem_bula' => in_array($item['id'], $comBula, true)], $resultados),
            'buscou' => $buscou,
            'limitado' => $limitado,
            // A lista de bulas prontas vai sempre: o filtro dela roda no aparelho, a cada letra, sem ida ao servidor.
            'disponiveis' => Cache::remember('consulta:bulas-prontas', now()->addMinutes(5), fn (): array => $this->available()),
        ]);
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
     * Um remédio por princípio ativo com bula: o de nome genérico (nome do produto igual ao princípio ativo), com registro
     * ativo e de um princípio ativo só, para a lista abrir a bula certa.
     *
     * @return list<array{id: int, name: string}>
     */
    private function available(): array
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
