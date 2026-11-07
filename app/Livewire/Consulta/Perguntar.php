<?php

namespace App\Livewire\Consulta;

use App\Models\Knowledge\AnvisaMedicine;
use App\Models\Knowledge\KnowledgeDocument;
use App\Models\PatientMedication;
use App\Services\Interactions\MedicationSearchService;
use App\Services\Patient\MedicationQuestionService;
use App\Services\Patient\TreatmentContextService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
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
     * @var list<array{pergunta: string, status: string, resposta: string|null, assuntos?: list<string>, medicamentos: list<array{id: int, name: string}>, uuid: string|null}>
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
            'topics' => $troca['assuntos'] ?? [],
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
            'resposta' => in_array($this->resposta['status'], [MedicationQuestionService::ANSWERED, MedicationQuestionService::NEEDS_INFO], true) ? $this->resposta['answer'] : null,
            'assuntos' => array_values(array_filter((array) ($this->resposta['intent'] ?? []), 'is_string')),
            'medicamentos' => array_map(fn (array $m): array => ['id' => (int) $m['id'], 'name' => (string) $m['name']], $this->resposta['medicines'] ?? []),
            'uuid' => $this->resposta['uuid'] ?? null,
        ];
        $this->conversa = array_slice($this->conversa, -self::MAX_TROCAS);
        $this->resposta = null;
    }

    /** Modelos de reformulação sugeridos quando a bula não respondeu (determinísticos, sem IA). */
    public const REFORMULACOES = [
        '{nome} dá sono?',
        'Como devo tomar {nome}?',
        '{nome} pode ser tomado com comida?',
        'Quais os efeitos mais comuns de {nome}?',
    ];

    private const TRECHO_MAXIMO = 300;

    /**
     * O que o BULA-IA diz no balão, por situação, em linguagem de conversa (nada de "limitações", "evidências", "catálogo").
     *
     * @param  array<string, mixed>  $resposta
     * @param  list<string>  $emUso
     * @return array{intro: string|null, urgencia: string|null, texto: string, fonte: string|null, trechos: list<array{titulo: string, texto: string}>, sugestoes: list<string>, remedios: list<string>, tone: string}
     */
    public static function fala(array $resposta, array $emUso = []): array
    {
        $nomes = array_values(array_unique(array_column($resposta['medicines'] ?? [], 'name')));
        $semBula = array_values(array_unique([...($resposta['missing']['without_leaflet'] ?? []), ...($resposta['missing']['unidentified'] ?? [])]));
        $comBula = array_values(array_diff($nomes, $semBula));
        $de = fn (array $lista): string => self::listar($lista === [] ? $nomes : $lista);
        $pergunta = (string) ($resposta['pergunta'] ?? $resposta['question'] ?? '');
        $sugestoes = self::reformulacoes($comBula !== [] ? $comBula : $nomes, $pergunta);
        $assunto = (string) (($resposta['intent'] ?? [])[0] ?? '');
        $assuntoLabel = $assunto !== '' ? mb_strtolower((string) (config('bulai.questions.intents.'.$assunto.'.label') ?? config('bulai.leaflet.sections.'.$assunto.'.label') ?? $assunto)) : '';
        $efeitos = $assunto === 'efeitos_colaterais';
        $consultouInteira = (bool) ($resposta['retrieval']['fallback_consulted'] ?? false);
        $revisao = ' Registrei sua pergunta para a equipe buscar mais fontes.';
        $fala = ['intro' => null, 'urgencia' => null, 'texto' => '', 'fonte' => null, 'trechos' => [], 'sugestoes' => [], 'remedios' => [], 'tone' => 'neutral'];

        $fala['urgencia'] = is_array($resposta['urgency'] ?? null) ? (string) ($resposta['urgency']['message'] ?? '') : null;

        switch ($resposta['status']) {
            case MedicationQuestionService::ANSWERED:
            case MedicationQuestionService::NEEDS_INFO:
                // A resposta já é uma conversa (fatos, o que não dá para confirmar e perguntas); a tela só acrescenta de onde veio.
                $fala['texto'] = (string) $resposta['answer'];
                $fala['fonte'] = self::fonte($resposta, $comBula !== [] ? $comBula : $nomes, $semBula, $comBula);
                break;
            case MedicationQuestionService::UNAVAILABLE:
                $fala['texto'] = 'Não consegui consultar o assistente agora, e prefiro não responder sem conferir a bula. Tente de novo em instantes; se for urgente, fale com um farmacêutico ou médico.';
                $fala['tone'] = 'warning';
                break;
            case MedicationQuestionService::NO_EVIDENCE:
                $secoes = array_values(array_filter((array) ($resposta['retrieval']['found_sections'] ?? []), 'is_string'));
                $texto = $consultouInteira
                    ? 'Procurei na bula inteira de '.$de($comBula).', mas não localizei uma informação suficientemente clara sobre '.($assuntoLabel !== '' ? $assuntoLabel : 'isso').'. Prefiro não completar a resposta com suposições.'
                    : 'Procurei na bula de '.$de($comBula).' e não achei uma parte que responda a isso com segurança. Prefiro não completar a resposta com suposições.';
                if ($secoes !== []) {
                    $texto .= ' O que encontrei fala de '.mb_strtolower(self::listar(array_map(fn (string $s): string => Str::limit($s, 40, ''), $secoes))).', e isso não responde à sua pergunta.';
                }
                $texto .= ' Isso não quer dizer que seja seguro: a bula consultada só não permitiu responder.';
                // Quando o modelo explicou especificamente o que não deu para confirmar, vale a explicação dele (nunca trechos soltos).
                $fala['texto'] = filled($resposta['answer'] ?? null) ? (string) $resposta['answer'] : $texto;
                $fala['fonte'] = ($semBula !== [] ? 'Ainda não tenho a bula de '.self::listar($semBula).' comigo. ' : '')
                    .($efeitos ? 'Se você estiver sentindo algum sintoma ou teve uma reação depois de usar o remédio, vale falar com um farmacêutico ou médico.' : 'Na dúvida, fale com um farmacêutico ou médico.').$revisao;
                $fala['sugestoes'] = $sugestoes;
                break;
            case MedicationQuestionService::NO_LEAFLET:
                $fala['texto'] = 'Ainda não tenho a bula de '.$de($semBula).' comigo. Posso ajudar com outro remédio, ou você confere na bula que vem na caixa.'.$revisao;
                $fala['remedios'] = array_slice(array_values(array_diff($emUso, $nomes)), 0, 3);
                $fala['tone'] = 'warning';
                break;
            case MedicationQuestionService::NOT_IDENTIFIED:
                $fala['texto'] = 'Conheço o '.$de($semBula).', mas ainda não tenho a bula dele comigo. Posso ajudar com outro remédio, ou você confere na bula que vem na caixa. Se não era esse, toque em “Não é esse remédio?”.'.$revisao;
                $fala['remedios'] = array_slice(array_values(array_diff($emUso, $nomes)), 0, 3);
                $fala['tone'] = 'warning';
                break;
            case MedicationQuestionService::NO_MEDICINE:
                $fala['texto'] = ($resposta['guesses'] ?? []) !== []
                    ? 'Não reconheci o nome do remédio. Você quis dizer um destes? Toque nele, ou escreva o nome como está na caixa.'
                    : 'Sobre qual remédio você quer saber? Escreva o nome como está na caixa, por exemplo “Losartana”.';
                $fala['tone'] = 'warning';
                break;
            case MedicationQuestionService::BLOCKED:
                $fala['texto'] = (string) ($resposta['message'] ?? 'Não posso ajudar com essa pergunta.');
                $fala['tone'] = ($resposta['guard']['category'] ?? null) === 'harm_risk' ? 'danger' : 'neutral';
                break;
        }

        return $fala;
    }

    /**
     * Linha curta de procedência abaixo da resposta ("Baseado na bula do paciente de LOSARTANA."); os trechos, seções e páginas
     * ficam recolhidos em "Fontes utilizadas". Depois, o que mais foi levado em conta (perfil, conversa, bulas que faltam).
     *
     * @param  array<string, mixed>  $resposta
     * @param  list<string>  $nomes
     * @param  list<string>  $semBula
     * @param  list<string>  $comBula
     */
    public static function fonte(array $resposta, array $nomes, array $semBula, array $comBula): string
    {
        $evidencias = collect($resposta['evidences'] ?? []);
        $bulas = $evidencias->filter(fn ($e) => ! in_array($e['document_type'] ?? null, ['treatment_context', 'conversation_context'], true) && ! empty($e['supports_claims']));
        $tipos = $bulas->pluck('document_type')->unique()->map(fn ($tipo): string => match ($tipo) {
            'bula_paciente' => 'do paciente', 'bula_profissional' => 'do profissional de saúde', default => ''
        })->filter()->values();
        $fonte = $tipos->isEmpty() ? 'Baseado na bula de '.self::listar($nomes).'.' : 'Baseado na bula '.$tipos->join(' e na bula ').' de '.self::listar($nomes).'.';
        if ($semBula !== []) {
            $fonte .= ' Ainda não tenho a bula de '.self::listar($semBula).', então respondi só sobre '.self::listar($comBula).'.';
        }
        if ($evidencias->contains(fn ($e) => ($e['document_type'] ?? null) === 'treatment_context' && ! empty($e['supports_claims']))) {
            $fonte .= ' Também olhei os remédios e as alergias do seu perfil.';
        }
        if (! empty($resposta['generation']['conversation_context'])) {
            $fonte .= ' Leva em conta o que você perguntou antes nesta conversa.';
        }

        return $fonte;
    }

    /** "Losartana", "Losartana e Omeprazol", "A, B e C". @param list<string> $nomes */
    public static function listar(array $nomes): string
    {
        $nomes = array_values(array_filter($nomes, fn ($n): bool => is_string($n) && $n !== ''));
        if ($nomes === []) {
            return 'esse remédio';
        }
        if (count($nomes) === 1) {
            return $nomes[0];
        }
        $ultimo = array_pop($nomes);

        return implode(', ', $nomes).' e '.$ultimo;
    }

    /** @param list<string> $nomes @return list<string> até 3 reformulações diferentes da pergunta atual */
    public static function reformulacoes(array $nomes, string $perguntaAtual): array
    {
        $nome = $nomes[0] ?? null;
        if ($nome === null) {
            return [];
        }
        $normal = fn (string $t): string => mb_strtolower(trim(preg_replace('/[^\p{L}\p{N} ]+/u', ' ', $t) ?? ''));
        $atual = $normal($perguntaAtual);
        $sugestoes = [];
        foreach (self::REFORMULACOES as $modelo) {
            $texto = str_replace('{nome}', $nome, $modelo);
            if ($normal($texto) !== $atual && count($sugestoes) < 3) {
                $sugestoes[] = $texto;
            }
        }

        return $sugestoes;
    }

    /** @param list<array<string, mixed>> $evidencias @return list<array{titulo: string, texto: string}> até 2 trechos curtos */
    private static function trechos(array $evidencias): array
    {
        $trechos = [];
        foreach ($evidencias as $evidencia) {
            if (in_array($evidencia['document_type'] ?? null, ['treatment_context', 'conversation_context'], true) || empty($evidencia['excerpt'])) {
                continue;
            }
            $texto = trim(preg_replace('/\s+/u', ' ', (string) $evidencia['excerpt']) ?? '');
            $trechos[] = [
                'titulo' => (string) ($evidencia['document_title'] ?? 'Bula'),
                'texto' => mb_strlen($texto) > self::TRECHO_MAXIMO ? rtrim(mb_substr($texto, 0, self::TRECHO_MAXIMO)).'…' : $texto,
            ];
            if (count($trechos) === 2) {
                break;
            }
        }

        return $trechos;
    }

    /** Frase curta para as trocas antigas da conversa (sem a resposta completa guardada). */
    public static function falaCurta(string $status, array $medicamentos): string
    {
        $nome = self::listar(array_column($medicamentos, 'name'));

        return match ($status) {
            MedicationQuestionService::NO_LEAFLET => 'Ainda não tenho a bula de '.$nome.' comigo.',
            MedicationQuestionService::NOT_IDENTIFIED => 'Ainda não tenho a bula de '.$nome.' comigo.',
            MedicationQuestionService::NO_EVIDENCE => 'Não achei nada sobre isso na bula de '.$nome.'.',
            MedicationQuestionService::EXCERPTS_ONLY => 'Mostrei as partes da bula de '.$nome.' que falam disso.',
            MedicationQuestionService::NEEDS_INFO => 'Preciso de mais informações para explicar sobre '.$nome.'.',
            MedicationQuestionService::UNAVAILABLE => 'Não consegui consultar o assistente nessa hora.',
            MedicationQuestionService::NO_MEDICINE => 'Perguntei sobre qual remédio você queria saber.',
            MedicationQuestionService::BLOCKED => 'Não pude ajudar com essa pergunta.',
            default => 'Respondi com base na bula de '.$nome.'.',
        };
    }

    /** @return list<array{document_title: string, section: string|null, page_start: int|null, excerpt: string, processed: string|null}> */
    private function fontes(): array
    {
        $evidencias = collect($this->resposta['evidences'] ?? [])
            ->filter(fn ($e) => ! in_array($e['document_type'] ?? null, ['treatment_context', 'conversation_context'], true) && ! empty($e['excerpt']) && ! empty($e['supports_claims']));
        $processados = KnowledgeDocument::query()->whereIn('id', $evidencias->pluck('document_id')->filter()->unique()->all())->pluck('processed_at', 'id');

        return $evidencias
            ->unique(fn ($e) => ($e['document_id'] ?? '').'|'.trim((string) ($e['section'] ?? '')).'|'.($e['page_start'] ?? ''))
            ->map(fn ($e): array => [
                'document_title' => (string) ($e['document_title'] ?? 'Bula'),
                'section' => filled($e['section'] ?? null) ? trim((string) $e['section']) : null,
                'page_start' => $e['page_start'] ?? null,
                'excerpt' => Str::limit(trim(preg_replace('/\s+/u', ' ', (string) $e['excerpt']) ?? ''), 420, '…'),
                'processed' => ($processados[$e['document_id'] ?? 0] ?? null)?->timezone(config('app.timezone'))->format('d/m/Y'),
            ])->values()->all();
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
            'fala' => $this->resposta !== null ? self::fala($this->resposta, $emUso) : null,
            // "Fontes utilizadas": uma entrada por documento, seção e página (sem repetir), só dos trechos que sustentam alguma
            // afirmação. Mostra o trecho que fundamentou, a seção, a página e a data de processamento da bula.
            'fontes' => $this->fontes(),
            'retentionDays' => (int) config('bulai.questions.retention_days'),
        ]);
    }
}
