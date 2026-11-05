<?php

namespace App\Livewire\Consulta;

use App\Models\Knowledge\AnvisaMedicine;
use App\Models\PatientMedication;
use App\Services\Interactions\InteractionConsultationService;
use App\Services\Interactions\MedicationSearchService;
use App\Services\Patient\AllergyChecker;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Component;

/** Busca de medicamentos, seleção de dois ou mais e disparo da consulta de interações. */
class Pesquisa extends Component
{
    public string $busca = '';

    /** @var list<array{id: int, name: string, ingredients: string, identified: bool, alerts: list<string>}> */
    public array $selecionados = [];

    public ?string $mensagem = null;

    public function mount(): void
    {
        if (request()->boolean('usar') && auth()->user()->hasHealthConsent()) {
            $ids = PatientMedication::query()->matched()->where('user_id', auth()->id())->limit((int) config('bulai.consultation.max_medicines'))->pluck('medicine_id');
            foreach ($ids as $id) {
                $this->adicionar((int) $id);
            }
        }
    }

    public function adicionar(int $id): void
    {
        $this->mensagem = null;
        if (collect($this->selecionados)->contains('id', $id)) {
            $this->mensagem = 'Este medicamento já está na lista.';

            return;
        }
        if (count($this->selecionados) >= (int) config('bulai.consultation.max_medicines')) {
            $this->mensagem = 'Limite de '.config('bulai.consultation.max_medicines').' medicamentos por consulta.';

            return;
        }

        $medicine = AnvisaMedicine::query()->with('ingredients:id')->find($id);
        if ($medicine === null) {
            $this->mensagem = 'Medicamento não encontrado no catálogo.';

            return;
        }

        $this->selecionados[] = [
            'alerts' => $this->alertasDeAlergia($medicine),
            'id' => $medicine->id,
            'name' => $medicine->product_name,
            'ingredients' => (string) ($medicine->active_ingredient ?: 'Princípio ativo não informado'),
            'identified' => $medicine->ingredients->isNotEmpty(),
        ];
        $this->busca = '';
    }

    /** @return list<string> */
    private function alertasDeAlergia(AnvisaMedicine $medicine): array
    {
        $user = auth()->user();
        if (! $user->hasHealthConsent()) {
            return [];
        }

        return array_map(
            fn (array $match): string => 'Você registrou alergia a '.$match['allergy'].($match['via'] === 'classe' ? ' (mesma classe de '.$match['ingredient'].')' : ' (contém '.$match['ingredient'].')'),
            app(AllergyChecker::class)->check($user->allergies()->get(), $medicine),
        );
    }

    public function remover(int $id): void
    {
        $this->selecionados = array_values(array_filter($this->selecionados, fn (array $item): bool => $item['id'] !== $id));
        $this->mensagem = null;
    }

    public function verificar(InteractionConsultationService $service): ?RedirectResponse
    {
        $this->mensagem = null;
        if (count($this->selecionados) < 2) {
            $this->mensagem = 'Selecione pelo menos dois medicamentos.';

            return null;
        }

        $userId = (int) auth()->id();
        $key = 'consulta:'.$userId;
        if (RateLimiter::tooManyAttempts($key, (int) config('bulai.consultation.rate_limit_per_hour'))) {
            $this->mensagem = 'Você atingiu o limite de consultas por hora. Tente novamente mais tarde.';

            return null;
        }

        $lock = Cache::lock('consulta-iniciar:'.$userId, 10);
        if (! $lock->get()) {
            return null;
        }

        try {
            RateLimiter::hit($key, 3600);
            $consultation = $service->start(auth()->user(), array_column($this->selecionados, 'id'));
        } finally {
            $lock->release();
        }

        return redirect()->route('consulta.resultado', $consultation);
    }

    public function render(MedicationSearchService $search): View
    {
        $selectedIds = array_column($this->selecionados, 'id');
        $limitado = strlen(trim($this->busca)) >= 3 && RateLimiter::tooManyAttempts('busca:'.auth()->id(), (int) config('bulai.consultation.search_limit_per_minute'));
        if (strlen(trim($this->busca)) >= 3 && ! $limitado) {
            RateLimiter::hit('busca:'.auth()->id(), 60);
        }
        $resultados = array_values(array_filter(
            $limitado ? [] : $search->search($this->busca),
            fn (array $item): bool => ! in_array($item['id'], $selectedIds, true),
        ));

        return view('livewire.consulta.pesquisa', [
            'resultados' => $resultados,
            'buscou' => strlen(trim($this->busca)) >= 3,
            'limitado' => $limitado,
            'maximo' => (int) config('bulai.consultation.max_medicines'),
            'meusRemedios' => auth()->user()->hasHealthConsent() ? PatientMedication::query()->matched()->where('user_id', auth()->id())->count() : 0,
        ]);
    }
}
