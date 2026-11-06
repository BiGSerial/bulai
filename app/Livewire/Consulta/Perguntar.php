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
 * Pergunta livre em forma de conversa: o usuário escreve (ou dita) a dúvida com os nomes dos remédios no próprio texto;
 * o sistema faz a triagem da entrada, detecta os medicamentos citados (com tolerância a erros de digitação), cruza com o
 * tratamento do paciente (quando consentiu) e responde só com as bulas cadastradas. A pergunta seguinte sem nome de
 * remédio ("e com café?") reaproveita os remédios da anterior e o modelo recebe um resumo curto das últimas trocas.
 * Cada pergunta e resposta ficam no histórico do próprio usuário pelo prazo de retenção; a conversa vive só nesta tela.
 */
class Perguntar extends Component
{
    public const EXEMPLOS = [
        'Posso tomar ibuprofeno com losartana?',
        'Omeprazol deve ser tomado em jejum?',
        'Quais os efeitos colaterais mais comuns da sinvastatina?',
    ];

    /** Trocas anteriores guardadas na tela (as mais antigas saem). */
    public const MAX_TROCAS = 6;

    public string $pergunta = '';

    public ?string $aviso = null;

    /** @var array<string, mixed>|null resposta completa da última pergunta */
    public ?array $resposta = null;

    /**
     * Trocas anteriores desta conversa, da mais antiga para a mais nova (versão enxuta para a tela e para o contexto).
     *
     * @var list<array{pergunta: string, status: string, resposta: string|null, medicamentos: list<array{id: int, name: string}>, uuid: string|null}>
     */
    public array $conversa = [];

    /** @var list<int> medicamentos escolhidos explicitamente (link de entrada ou correção) */
    public array $fixos = [];

    /** @var list<int> medicamentos que o usuário mandou remover da pergunta atual (detecção errada) */
    public array $removidos = [];

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

        $this->arquivarRespostaAtual();
        $this->removidos = [];
        $this->responder($service, $question);
        $this->pergunta = '';
    }

    /** "Não é esse?": troca a detecção pelo medicamento escolhido e repete a mesma pergunta. */
    public function corrigir(int $id, MedicationQuestionService $service): void
    {
        if (! AnvisaMedicine::query()->whereKey($id)->exists()) {
            return;
        }
        $this->fixos = [$id];
        $this->busca = '';
        $this->corrigindo = false;
        $this->removidos = [];
        $this->repetir($service);
    }

    /** "Não era esse remédio": tira o medicamento detectado (por engano) e repete a mesma pergunta sem ele. */
    public function remover(int $id, MedicationQuestionService $service): void
    {
        if ($this->resposta === null) {
            return;
        }
        $this->removidos = array_values(array_unique([...$this->removidos, $id]));
        $this->fixos = array_values(array_diff($this->fixos, [$id]));
        $this->repetir($service);
    }

    public function novaPergunta(): void
    {
        $this->reset('pergunta', 'resposta', 'aviso', 'fixos', 'removidos', 'corrigindo', 'busca', 'conversa');
    }

    /** Repete a pergunta atual (correção ou remoção) sem contá-la como nova troca. */
    private function repetir(MedicationQuestionService $service): void
    {
        $question = trim((string) ($this->resposta['pergunta'] ?? $this->pergunta));
        if ($question === '') {
            return;
        }
        $this->aviso = null;
        $this->responder($service, $question);
    }

    private function responder(MedicationQuestionService $service, string $question): void
    {
        $contexto = array_map(fn (array $troca): array => [
            'question' => $troca['pergunta'],
            'answer' => $troca['resposta'],
            'medicine_ids' => array_column($troca['medicamentos'], 'id'),
        ], array_slice($this->conversa, -MedicationQuestionService::CONVERSATION_TURNS));

        $this->resposta = $service->askFreeform(auth()->user(), $question, $this->fixos, $contexto, $this->removidos) + ['pergunta' => $question];
        $this->resposta['medicamento'] = implode(' + ', array_column($this->resposta['medicines'], 'name'));
        // Depois de responder, os remédios "fixos" (link ou correção) deixam de valer: a conversa passa a guiar as próximas.
        if (! in_array($this->resposta['status'], [MedicationQuestionService::NO_MEDICINE, MedicationQuestionService::BLOCKED], true)) {
            $this->fixos = [];
        }
    }

    /** A resposta atual vira uma troca enxuta do histórico desta conversa (sem evidências, para a tela ficar leve). */
    private function arquivarRespostaAtual(): void
    {
        if ($this->resposta === null) {
            return;
        }
        $this->conversa[] = [
            'pergunta' => (string) ($this->resposta['pergunta'] ?? ''),
            'status' => (string) $this->resposta['status'],
            'resposta' => $this->resposta['status'] === MedicationQuestionService::ANSWERED ? $this->resposta['answer'] : null,
            'medicamentos' => array_map(fn (array $m): array => ['id' => (int) $m['id'], 'name' => (string) $m['name']], $this->resposta['medicines'] ?? []),
            'uuid' => $this->resposta['uuid'] ?? null,
        ];
        $this->conversa = array_slice($this->conversa, -self::MAX_TROCAS);
        $this->resposta = null;
    }

    public function render(MedicationSearchService $search, TreatmentContextService $treatment): View
    {
        $user = auth()->user();
        $previo = null;
        if ($this->resposta === null && $this->conversa === [] && $this->fixos !== [] && ($fixo = AnvisaMedicine::query()->find($this->fixos[0])) !== null) {
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
            'corrigidos' => array_values(array_filter($this->resposta['medicines'] ?? [], fn (array $m): bool => ! empty($m['fuzzy']))),
            'retentionDays' => (int) config('bulai.questions.retention_days'),
        ]);
    }
}
