<?php

namespace App\Livewire\Consulta;

use App\Models\Knowledge\AnvisaMedicine;
use App\Models\PatientMedication;
use App\Services\Interactions\MedicationSearchService;
use App\Services\Patient\MedicationQuestionService;
use App\Services\Patient\TreatmentContextService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Component;

/**
 * Pergunta livre: o usuário escreve a dúvida com os nomes dos remédios no próprio texto; o sistema faz a triagem da
 * entrada, detecta os medicamentos citados, cruza com o tratamento do paciente (quando consentiu) e responde só com as
 * bulas cadastradas. Pergunta e resposta ficam no histórico do próprio usuário pelo prazo de retenção.
 */
class Perguntar extends Component
{
    public const EXEMPLOS = [
        'Posso tomar ibuprofeno com losartana?',
        'Omeprazol deve ser tomado em jejum?',
        'Quais os efeitos colaterais mais comuns da sinvastatina?',
    ];

    public string $pergunta = '';

    public ?string $aviso = null;

    /** @var array<string, mixed>|null */
    public ?array $resposta = null;

    /** @var list<int> medicamentos escolhidos explicitamente (link de entrada ou correção) */
    public array $fixos = [];

    public bool $corrigindo = false;

    public string $busca = '';

    /**
     * Entradas: `?medicamento={id}` (links da bula/perfil) pré-seleciona o medicamento e já mostra o painel do tratamento;
     * `?q={texto}` (caixa da tela Início) pré-preenche a pergunta e, se ela já estiver completa, responde na hora.
     */
    public function mount(MedicationQuestionService $service): void
    {
        $id = request()->integer('medicamento');
        if ($id > 0 && ($medicine = AnvisaMedicine::query()->find($id)) !== null) {
            $this->fixos = [$medicine->id];
            $this->pergunta = 'Sobre '.$medicine->product_name.': ';
        }

        $q = trim((string) request()->query('q', ''));
        if ($q !== '') {
            $this->pergunta = mb_substr(trim($this->pergunta.' '.$q), 0, (int) config('bulai.guard.max_length'));
            if (mb_strlen($q) >= (int) config('bulai.guard.min_length')) {
                $this->perguntar($service);
            }
        }
    }

    public function sugerir(string $texto): void
    {
        $this->pergunta = mb_substr(trim($this->pergunta.' '.$texto), 0, (int) config('bulai.guard.max_length'));
        $this->aviso = null;
    }

    public function perguntar(MedicationQuestionService $service): void
    {
        $this->aviso = null;
        $this->resposta = null;
        $this->corrigindo = false;
        $question = trim($this->pergunta);
        if (mb_strlen($question) < (int) config('bulai.guard.min_length')) {
            $this->aviso = 'Escreva sua pergunta citando o nome do remédio.';

            return;
        }

        $key = 'pergunta:'.auth()->id();
        if (RateLimiter::tooManyAttempts($key, (int) config('bulai.consultation.rate_limit_per_hour'))) {
            $this->aviso = 'Você atingiu o limite de perguntas por hora. Tente novamente mais tarde.';

            return;
        }
        RateLimiter::hit($key, 3600);

        $this->resposta = $service->askFreeform(auth()->user(), $question, $this->fixos) + ['pergunta' => $question];
        $this->resposta['medicamento'] = implode(' + ', array_column($this->resposta['medicines'], 'name'));
    }

    /** "Não é esse?": troca a detecção pelo medicamento escolhido e repete a mesma pergunta. */
    public function corrigir(int $id, MedicationQuestionService $service): void
    {
        if (! AnvisaMedicine::query()->whereKey($id)->exists()) {
            return;
        }
        $this->fixos = [$id];
        $this->busca = '';
        $this->perguntar($service);
    }

    public function novaPergunta(): void
    {
        $this->reset('pergunta', 'resposta', 'aviso', 'fixos', 'corrigindo', 'busca');
    }

    public function render(MedicationSearchService $search, TreatmentContextService $treatment): View
    {
        $user = auth()->user();
        $previo = null;
        if ($this->resposta === null && $this->fixos !== [] && ($fixo = AnvisaMedicine::query()->find($this->fixos[0])) !== null) {
            $previo = ['medicine' => $fixo->product_name, 'context' => $treatment->for($user, $fixo)];
        }
        $emUso = $user->hasHealthConsent()
            ? PatientMedication::query()->with('medicine:id,product_name')->where('user_id', $user->id)->orderBy('id')->get()
                ->filter(fn (PatientMedication $item): bool => $item->isActive() && $item->medicine !== null)
                ->map(fn (PatientMedication $item): string => $item->medicine->product_name)->unique()->values()->take(6)->all()
            : [];

        return view('livewire.consulta.perguntar', [
            'previo' => $previo,
            'emUso' => $emUso,
            'exemplos' => self::EXEMPLOS,
            'maximo' => (int) config('bulai.guard.max_length'),
            'resultados' => $this->corrigindo ? $search->search($this->busca, 6) : [],
            'buscou' => strlen(trim($this->busca)) >= 3,
            'retentionDays' => (int) config('bulai.questions.retention_days'),
        ]);
    }
}
