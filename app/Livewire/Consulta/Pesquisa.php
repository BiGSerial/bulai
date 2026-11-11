<?php

namespace App\Livewire\Consulta;

use App\Models\Knowledge\AnvisaMedicine;
use App\Models\PatientMedication;
use App\Services\Interactions\InteractionConsultationService;
use App\Services\Interactions\MedicationSearchService;
use App\Services\Patient\AllergyChecker;
use Illuminate\Contracts\View\View;
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

    /** @var list<int> Remédios em uso do próprio paciente, que já entram na lista ("posso tomar junto com os meus?"). */
    public array $meus = [];

    /**
     * Os remédios em uso (tratamento não encerrado) entram sozinhos; `com` acrescenta o remédio que a pessoa quer tomar junto
     * (vindo da bula simples); `usar=1` ("Verificar interações entre eles") já verifica e abre o resultado.
     */
    public function mount(InteractionConsultationService $service): void
    {
        foreach ($this->emUso() as $id) {
            $this->adicionar($id);
        }
        $this->meus = array_column($this->selecionados, 'id');
        if (request()->integer('com') > 0) {
            $this->adicionar(request()->integer('com'));
        }
        $this->mensagem = null;
        if (request()->boolean('usar') && count($this->selecionados) >= 2) {
            $this->verificar($service);
        }
    }

    /** @return list<int> */
    private function emUso(): array
    {
        if (! auth()->user()->hasHealthConsent()) {
            return [];
        }

        return PatientMedication::query()->matched()->where('user_id', auth()->id())
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhereDate('ends_at', '>=', today()))
            ->orderBy('id')->pluck('medicine_id')->unique()->take((int) config('bulai.consultation.max_medicines'))
            ->map(fn ($id): int => (int) $id)->values()->all();
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

    public function verificar(InteractionConsultationService $service): void
    {
        $this->mensagem = null;
        if (count($this->selecionados) < 2) {
            $this->mensagem = 'Selecione pelo menos dois medicamentos.';

            return;
        }

        $userId = (int) auth()->id();
        $key = 'consulta:'.$userId;
        if (RateLimiter::tooManyAttempts($key, (int) config('bulai.consultation.rate_limit_per_hour'))) {
            $this->mensagem = 'Você atingiu o limite de consultas por hora. Tente novamente mais tarde.';

            return;
        }

        $lock = Cache::lock('consulta-iniciar:'.$userId, 10);
        if (! $lock->get()) {
            return;
        }

        try {
            RateLimiter::hit($key, 3600);
            $consultation = $service->start(auth()->user(), array_column($this->selecionados, 'id'));
        } finally {
            $lock->release();
        }

        $this->redirectRoute('consulta.resultado', $consultation);
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
        ]);
    }
}
