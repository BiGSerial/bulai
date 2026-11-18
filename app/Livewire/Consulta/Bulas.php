<?php

namespace App\Livewire\Consulta;

use App\Services\Leaflet\LeafletCatalog;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Component;

/**
 * Bula simples: um lugar só para achar a bula humanizada de um remédio. A lista dos remédios que já têm bula filtra no
 * aparelho enquanto a pessoa digita; com 3 letras ou mais, a busca no catálogo diz também quais têm bula simples pronta.
 */
class Bulas extends Component
{
    public string $busca = '';

    public function render(LeafletCatalog $catalog): View
    {
        $buscou = strlen(trim($this->busca)) >= 3;
        $limitado = $buscou && RateLimiter::tooManyAttempts('busca:'.auth()->id(), (int) config('bulai.consultation.search_limit_per_minute'));
        if ($buscou && ! $limitado) {
            RateLimiter::hit('busca:'.auth()->id(), 60);
        }

        return view('livewire.consulta.bulas', [
            'resultados' => $buscou && ! $limitado ? $catalog->search($this->busca) : [],
            'buscou' => $buscou,
            'limitado' => $limitado,
            // A lista de bulas prontas vai sempre: o filtro dela roda no aparelho, a cada letra, sem ida ao servidor.
            'disponiveis' => $catalog->available(),
        ]);
    }
}
