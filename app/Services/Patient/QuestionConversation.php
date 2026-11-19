<?php

namespace App\Services\Patient;

use App\Models\Knowledge\AnvisaMedicine;
use App\Models\Knowledge\KnowledgeDocument;
use App\Models\PatientMedication;
use App\Models\User;
use App\Services\Knowledge\External\ExternalEvidenceService;
use App\Services\Safety\GuardVerdict;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * A conversa do "Perguntar", compartilhada pela tela do site (Livewire) e pelo aplicativo nativo (`/api/mobile/v1/perguntar`):
 * limite por hora, contexto das trocas anteriores, a troca enxuta guardada na conversa e o que o BULA-IA diz no balão
 * (fala, alerta, destaques, fontes). A conversa vive no cliente (componente ou aplicativo); o histórico fica no banco.
 */
final class QuestionConversation
{
    public const EXAMPLES = [
        'Posso tomar ibuprofeno com losartana?',
        'Omeprazol deve ser tomado em jejum?',
        'Quais os efeitos colaterais mais comuns da sinvastatina?',
    ];

    /** Trocas anteriores guardadas na conversa (as mais antigas saem). */
    public const MAX_TURNS = 6;

    /** Modelos de reformulação sugeridos quando a bula não respondeu (determinísticos, sem IA). */
    public const REPHRASINGS = [
        '{nome} dá sono?',
        'Como devo tomar {nome}?',
        '{nome} pode ser tomado com comida?',
        'Quais os efeitos mais comuns de {nome}?',
    ];

    /** Palavras que marcam a frase da resposta que fala da interação (quando os dois remédios foram citados na pergunta). */
    private const INTERACTION_TERMS = ['interação', 'interações', 'interagir', 'interage', 'interagem'];

    public function __construct(private MedicationQuestionService $questions, private TreatmentContextService $treatment) {}

    /** Limite de perguntas por hora, o mesmo no site e no aplicativo. Conta a tentativa quando ainda há vaga. */
    public static function attempt(User $user): bool
    {
        $key = 'pergunta:'.$user->id;
        if (RateLimiter::tooManyAttempts($key, (int) config('bulai.consultation.rate_limit_per_hour'))) {
            return false;
        }
        RateLimiter::hit($key, 3600);

        return true;
    }

    /**
     * Responde a pergunta com as últimas trocas da conversa como contexto.
     *
     * @param  list<int>  $fixed  remédios escolhidos explicitamente (link de entrada ou correção)
     * @param  list<array{question: string, status: string, answer: string|null, topics?: list<string>, medicines: list<array{id: int, name: string}>, uuid: string|null}>  $turns
     * @param  list<int>  $removed  remédios que a pessoa mandou tirar (detecção errada)
     * @return array<string, mixed>
     */
    public function answer(User $user, string $question, array $fixed = [], array $turns = [], array $removed = []): array
    {
        $context = array_map(fn (array $turn): array => [
            'question' => $turn['question'],
            'answer' => $turn['answer'],
            'medicine_ids' => array_map('intval', array_column($turn['medicines'], 'id')),
            'topics' => $turn['topics'] ?? [],
        ], array_slice(array_values($turns), -MedicationQuestionService::CONVERSATION_TURNS));

        $answer = $this->questions->askFreeform($user, $question, $fixed, $context, $removed) + ['pergunta' => $question];
        $answer['medicamento'] = implode(' + ', array_column($answer['medicines'], 'name'));

        return $answer;
    }

    /** Depois de responder, os remédios escolhidos (link ou correção) deixam de valer: a conversa passa a guiar as próximas. */
    public static function releasesFixed(array $answer): bool
    {
        return ! in_array($answer['status'], [MedicationQuestionService::NO_MEDICINE, MedicationQuestionService::BLOCKED, MedicationQuestionService::GUIDANCE, MedicationQuestionService::SUPPORT], true);
    }

    /**
     * A resposta vira uma troca enxuta da conversa (sem evidências, para a tela ficar leve e o contexto curto).
     *
     * @param  array<string, mixed>  $answer
     * @return array{question: string, status: string, answer: string|null, topics: list<string>, medicines: list<array{id: int, name: string}>, uuid: string|null}
     */
    public static function turn(array $answer): array
    {
        return [
            'question' => (string) ($answer['pergunta'] ?? $answer['question'] ?? ''),
            'status' => (string) $answer['status'],
            'answer' => in_array($answer['status'], [MedicationQuestionService::ANSWERED, MedicationQuestionService::NEEDS_INFO], true) ? $answer['answer'] : null,
            'topics' => array_values(array_filter((array) ($answer['intent'] ?? []), 'is_string')),
            'medicines' => array_map(fn (array $m): array => ['id' => (int) $m['id'], 'name' => (string) $m['name']], $answer['medicines'] ?? []),
            'uuid' => $answer['uuid'] ?? null,
        ];
    }

    /**
     * Nomes dos remédios em uso (atalhos da primeira mensagem e "outros remédios seus").
     *
     * @return list<string>
     */
    public function inUse(User $user): array
    {
        if (! $user->hasHealthConsent()) {
            return [];
        }

        return PatientMedication::query()->with('medicine:id,product_name')->where('user_id', $user->id)->orderBy('id')->get()
            ->filter(fn (PatientMedication $item): bool => $item->isActive() && $item->medicine !== null)
            ->map(fn (PatientMedication $item): string => $item->medicine->product_name)->unique()->values()->take(6)->all();
    }

    /**
     * Painel "No seu tratamento" antes da primeira pergunta, quando a conversa começa por um remédio (link da bula ou do perfil).
     *
     * @return array{medicine: string, context: array<string, mixed>}|null
     */
    public function preview(User $user, int $medicineId): ?array
    {
        $medicine = AnvisaMedicine::query()->find($medicineId);

        return $medicine === null ? null : ['medicine' => $medicine->product_name, 'context' => $this->treatment->for($user, $medicine)];
    }

    /**
     * Blocos de ajuda da resposta, cada um identificável pela situação: título, texto curto, tom (`acolhimento`, `urgencia`,
     * `cuidado`) e os telefones como botões de ligar. Na crise são três blocos (momento difícil, já tomou remédio a mais,
     * continuar se cuidando); sem remédio e com uma necessidade reconhecida, os blocos de SupportChannels; nos outros casos, um bloco sem título com os telefones do caso (superdose, sintoma grave,
     * pedido recusado por risco). O aplicativo recebe a mesma lista em `help_groups`.
     *
     * @param  array<string, mixed>  $resposta
     * @return list<array{title: string|null, text: string|null, tone: string, lines: list<array{tel: string, number: string, name: string, detail: string}>}>
     */
    public static function helpGroups(array $resposta): array
    {
        $category = $resposta['guard']['category'] ?? null;
        $blocked = ($resposta['status'] ?? null) === MedicationQuestionService::BLOCKED;
        $kinds = is_array($resposta['urgency'] ?? null) ? (array) ($resposta['urgency']['kinds'] ?? []) : null;
        $lines = fn (string ...$keys): array => array_map(fn (string $key): array => SupportChannels::LINES[$key], $keys);
        $need = ($resposta['status'] ?? null) === MedicationQuestionService::SUPPORT ? ($resposta['support'] ?? $resposta['retrieval']['support'] ?? null) : null;
        $only = fn (string ...$keys): array => [['title' => null, 'text' => null, 'tone' => 'urgencia', 'lines' => $lines(...$keys)]];

        return match (true) {
            $blocked && $category === GuardVerdict::HARM_RISK => [
                ['title' => 'Está passando por um momento difícil?', 'tone' => 'acolhimento', 'lines' => $lines('cvv'),
                    'text' => 'Você não precisa enfrentar isso sem apoio. No CVV há pessoas prontas para ouvir você agora, de graça, em sigilo e a qualquer hora. Se preferir escrever, use o chat em cvv.org.br.'],
                ['title' => 'Já tomou remédio a mais ou está em perigo?', 'tone' => 'urgencia', 'lines' => $lines('samu', 'intoxicacao'),
                    'text' => 'Ligue agora ou vá ao pronto-socorro mais próximo. O Disque-Intoxicação também orienta o que fazer.'],
                ['title' => 'Para continuar se cuidando', 'tone' => 'cuidado', 'lines' => [],
                    'text' => 'Se puder, chame alguém de confiança para ficar com você. O CAPS e a Unidade Básica de Saúde acompanham você com calma.'],
            ],
            is_string($need) => SupportChannels::groups($need),
            $blocked && $category === GuardVerdict::HARM_OTHERS => $only('samu', 'policia'),
            $blocked && in_array($category, [GuardVerdict::MISUSE, GuardVerdict::SENSITIVE], true) => $only('samu'),
            $kinds !== null && array_intersect($kinds, UrgencyDetector::OVERDOSE_KINDS) !== [] => $only('intoxicacao', 'samu'),
            $kinds !== null => $only('samu'),
            default => [],
        };
    }

    /**
     * O que o BULA-IA diz no balão, por situação, em linguagem de conversa (nada de "limitações", "evidências", "catálogo").
     *
     * @param  array<string, mixed>  $resposta
     * @param  list<string>  $emUso
     * @return array{intro: string|null, urgencia: string|null, texto: string, fonte: string|null, trechos: list<array{titulo: string, texto: string}>, sugestoes: list<string>, remedios: list<string>, opcoes: list<string>, tone: string}
     */
    public static function speech(array $resposta, array $emUso = []): array
    {
        $nomes = array_values(array_unique(array_column($resposta['medicines'] ?? [], 'name')));
        $semBula = array_values(array_unique([...($resposta['missing']['without_leaflet'] ?? []), ...($resposta['missing']['unidentified'] ?? [])]));
        $comBula = array_values(array_diff($nomes, $semBula));
        $de = fn (array $lista): string => self::listNames($lista === [] ? $nomes : $lista);
        $pergunta = (string) ($resposta['pergunta'] ?? $resposta['question'] ?? '');
        $sugestoes = self::rephrasings($comBula !== [] ? $comBula : $nomes, $pergunta);
        $assunto = (string) (($resposta['intent'] ?? [])[0] ?? '');
        $assuntoLabel = $assunto !== '' ? mb_strtolower((string) (config('bulai.questions.intents.'.$assunto.'.label') ?? config('bulai.leaflet.sections.'.$assunto.'.label') ?? $assunto)) : '';
        $efeitos = $assunto === 'efeitos_colaterais';
        $revisao = ' Registrei sua pergunta para a equipe buscar mais fontes.';
        $fala = ['intro' => null, 'urgencia' => null, 'texto' => '', 'fonte' => null, 'trechos' => [], 'sugestoes' => [], 'remedios' => [], 'opcoes' => [], 'tone' => 'neutral'];

        $fala['urgencia'] = is_array($resposta['urgency'] ?? null) ? (string) ($resposta['urgency']['message'] ?? '') : null;

        switch ($resposta['status']) {
            case MedicationQuestionService::ANSWERED:
            case MedicationQuestionService::NEEDS_INFO:
                // A resposta já é uma conversa (fatos, o que não dá para confirmar e perguntas); a tela só acrescenta de onde veio.
                $fala['texto'] = (string) $resposta['answer'];
                $fala['fonte'] = self::source($resposta, $comBula !== [] ? $comBula : $nomes, $semBula, $comBula);
                break;
            case MedicationQuestionService::UNAVAILABLE:
                $fala['texto'] = 'Não consegui preparar a resposta agora. Tente de novo em instantes; se for urgente, fale com um farmacêutico ou médico.';
                $fala['tone'] = 'warning';
                break;
            case MedicationQuestionService::NO_EVIDENCE:
                // A bula humanizada não narra a busca ("procurei", "encontrei"): diz o que falta, sem suposições.
                $texto = 'Não tenho uma orientação clara sobre '.($assuntoLabel !== '' ? $assuntoLabel : 'isso').' para '.$de($comBula).', e não quero responder com suposições.'
                    .' Não ter essa orientação não quer dizer que seja seguro.';
                // Quando o modelo explicou especificamente o que não deu para confirmar, vale a explicação dele (nunca trechos soltos).
                $fala['texto'] = filled($resposta['answer'] ?? null) ? (string) $resposta['answer'] : $texto;
                $fala['fonte'] = ($semBula !== [] ? 'Ainda não tenho a bula de '.self::listNames($semBula).' comigo. ' : '')
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
                $crise = ($resposta['guard']['category'] ?? null) === GuardVerdict::HARM_RISK;
                // Na crise, o balão traz só a recusa; o acolhimento e os contatos vêm nos blocos de ajuda (helpGroups).
                $fala['texto'] = $crise ? GuardVerdict::crisisOpening($pergunta) : (string) ($resposta['message'] ?? 'Não posso ajudar com essa pergunta.');
                $fala['tone'] = ($resposta['guard']['category'] ?? null) === GuardVerdict::HARM_OTHERS ? 'danger' : 'neutral';
                break;
            case MedicationQuestionService::GUIDANCE:
                $fala['texto'] = (string) ($resposta['answer'] ?? '');
                break;
            case MedicationQuestionService::SUPPORT:
                $necessidade = (string) ($resposta['support'] ?? $resposta['retrieval']['support'] ?? SupportChannels::OTHER);
                $fala['texto'] = SupportChannels::speech($necessidade);
                // Pedido vago: respostas rápidas que já seguem a conversa ("Não estou me sentindo bem"...).
                $fala['opcoes'] = $necessidade === SupportChannels::VAGUE ? SupportChannels::VAGUE_OPTIONS : [];
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
    public static function source(array $resposta, array $nomes, array $semBula, array $comBula): string
    {
        $evidencias = collect($resposta['evidences'] ?? []);
        $bulas = $evidencias->filter(fn ($e) => ! in_array($e['document_type'] ?? null, ['treatment_context', 'conversation_context'], true) && ! empty($e['supports_claims']));
        $tipos = $bulas->pluck('document_type')->unique()->map(fn ($tipo): string => match ($tipo) {
            'bula_paciente' => 'do paciente', 'bula_profissional' => 'do profissional de saúde', default => ''
        })->filter()->values();
        $fonte = $tipos->isEmpty() ? 'Baseado na bula de '.self::listNames($nomes).'.' : 'Baseado na bula '.$tipos->join(' e na bula ').' de '.self::listNames($nomes).'.';
        // Bula dos EUA (FDA) como complemento: sempre com o crédito e o aviso de que a bula brasileira não traz a informação.
        $estrangeira = $bulas->contains(fn ($e): bool => ($e['document_type'] ?? null) === ExternalEvidenceService::DOCUMENT_TYPE);
        // Sem bula brasileira de nenhum dos remédios, a da FDA respondeu sozinha: o aviso diz isso, e não "respondi só sobre".
        $soEstrangeira = $estrangeira && $comBula === [] && $semBula !== [];
        if ($estrangeira) {
            $credito = 'traduzida automaticamente. Origem: FDA, via openFDA e DailyMed (U.S. National Library of Medicine).'
                .($soEstrangeira ? ' Ainda não tenho a bula brasileira de '.self::listNames($semBula).'.' : ' A bula brasileira cadastrada não traz essa informação.');
            $fonte = $tipos->isEmpty() ? 'Baseado na bula dos EUA (FDA) de '.self::listNames($nomes).', '.$credito : $fonte.' Parte vem da bula dos EUA (FDA), '.$credito;
        }
        if ($semBula !== [] && ! $soEstrangeira) {
            $fonte .= ' Ainda não tenho a bula de '.self::listNames($semBula).', então respondi só sobre '.self::listNames($comBula).'.';
        }
        if ($evidencias->contains(fn ($e) => ($e['document_type'] ?? null) === 'treatment_context' && ! empty($e['supports_claims']))) {
            $fonte .= ' Considera também os remédios e as alergias do seu perfil.';
        }
        if (! empty($resposta['generation']['conversation_context'])) {
            $fonte .= ' Leva em conta o que você perguntou antes nesta conversa.';
        }

        return $fonte;
    }

    /**
     * Alerta da resposta, a partir do motor estruturado (nunca do modelo): alergia registrada que atinge o remédio (vermelho,
     * com aviso em destaque) ou interação, seja com um remédio em uso, seja entre os remédios citados na pergunta (amarelo).
     * `itens` são as linhas determinísticas mostradas no balão; `termos` marcam as frases da resposta que falam do alerta.
     *
     * @param  array<string, mixed>  $resposta
     * @return array{tipo: 'alergia'|'interacao', itens: list<array{texto: string, severidade: string|null}>, termos: list<string>, popup: string|null}|null
     */
    public static function alert(array $resposta): ?array
    {
        if (in_array($resposta['status'] ?? null, [MedicationQuestionService::BLOCKED, MedicationQuestionService::NO_MEDICINE, MedicationQuestionService::GUIDANCE, MedicationQuestionService::SUPPORT], true)) {
            return null;
        }
        $alergias = [];
        $interacoes = [];
        $termos = [];
        $popup = null;
        foreach ($resposta['treatments'] ?? [] as $tratamento) {
            $nome = (string) ($tratamento['medicine'] ?? 'este remédio');
            foreach ($tratamento['context']['allergy_alerts'] ?? [] as $alergia) {
                $relacao = ($alergia['via'] ?? null) === 'classe' ? 'é da mesma classe de' : 'contém';
                $alergias[] = ['texto' => "{$nome} {$relacao} {$alergia['ingredient']}. Você registrou alergia a {$alergia['allergy']}.", 'severidade' => null];
                $termos[] = (string) $alergia['allergy'];
                $popup ??= '<strong>'.e($nome).'</strong> '.e($relacao).' <strong>'.e((string) $alergia['ingredient']).'</strong>, e você registrou alergia a <strong>'.e((string) $alergia['allergy']).'</strong>. Não use sem falar antes com seu médico ou farmacêutico.';
            }
            foreach ($tratamento['context']['interactions'] ?? [] as $interacao) {
                $interacoes[] = $interacao['severity'] === 'duplicate'
                    ? ['texto' => "{$nome} tem a mesma substância de {$interacao['medicine']}, que você usa.", 'severidade' => 'duplicate']
                    : ['texto' => "{$nome} + {$interacao['medicine']}, que você usa", 'severidade' => (string) $interacao['severity']];
                $termos[] = (string) $interacao['medicine'];
            }
        }
        foreach ($resposta['pair_interactions'] ?? [] as $par) {
            if ($par['severity'] === null || $par['severity'] === 'unverified') {
                continue;
            }
            $interacoes[] = $par['severity'] === 'duplicate'
                ? ['texto' => "{$par['a']} e {$par['b']} têm a mesma substância.", 'severidade' => 'duplicate']
                : ['texto' => "{$par['a']} + {$par['b']}", 'severidade' => (string) $par['severity']];
            array_push($termos, ...self::INTERACTION_TERMS);
        }
        if ($alergias === [] && $interacoes === []) {
            return null;
        }

        return [
            'tipo' => $alergias !== [] ? 'alergia' : 'interacao',
            'itens' => [...$alergias, ...$interacoes],
            'termos' => array_values(array_unique([...$termos, ...($alergias !== [] ? ['alergia', 'alérgica', 'alérgico'] : [])])),
            'popup' => $popup,
        ];
    }

    /**
     * A fala do BULA-IA em parágrafos, com remédios e componentes em negrito e, havendo alerta, as frases que falam dele.
     *
     * @param  array<string, mixed>  $resposta
     * @param  array{termos: list<string>}|null  $alerta
     * @return list<HtmlString>
     */
    public static function paragraphs(string $texto, array $resposta, ?array $alerta = null): array
    {
        $destaque = app(AnswerHighlighter::class);
        $nomes = self::highlightedNames($resposta);

        return array_map(fn (string $paragrafo): HtmlString => $destaque->html($paragrafo, $nomes, $alerta['termos'] ?? []), self::splitParagraphs($texto));
    }

    /**
     * Os mesmos parágrafos em pedaços de texto puro para o aplicativo nativo (cada pedaço diz se vai em negrito).
     *
     * @param  array<string, mixed>  $resposta
     * @param  array{termos: list<string>}|null  $alerta
     * @return list<list<array{text: string, bold: bool}>>
     */
    public static function paragraphSegments(string $texto, array $resposta, ?array $alerta = null): array
    {
        $destaque = app(AnswerHighlighter::class);
        $nomes = self::highlightedNames($resposta);

        return array_map(fn (string $paragrafo): array => $destaque->segments($paragrafo, $nomes, $alerta['termos'] ?? []), self::splitParagraphs($texto));
    }

    /**
     * Remédios, componentes, remédios que interagem e alergias citados na resposta: vão em negrito.
     *
     * @param  array<string, mixed>  $resposta
     * @return list<string>
     */
    private static function highlightedNames(array $resposta): array
    {
        $nomes = [];
        foreach ($resposta['medicines'] ?? [] as $item) {
            array_push($nomes, (string) $item['name'], (string) ($item['ingredients'] ?? ''));
        }
        foreach ($resposta['treatments'] ?? [] as $tratamento) {
            foreach ($tratamento['context']['interactions'] ?? [] as $interacao) {
                $nomes[] = (string) $interacao['medicine'];
            }
            foreach ($tratamento['context']['allergy_alerts'] ?? [] as $alergia) {
                array_push($nomes, (string) $alergia['ingredient'], (string) $alergia['allergy']);
            }
        }

        return array_values(array_filter($nomes));
    }

    /** @return list<string> */
    private static function splitParagraphs(string $texto): array
    {
        return array_values(array_filter(preg_split('/\n{2,}/u', trim($texto)) ?: [], fn (string $p): bool => trim($p) !== ''));
    }

    /** "Losartana", "Losartana e Omeprazol", "A, B e C". @param list<string> $nomes */
    public static function listNames(array $nomes): string
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
    public static function rephrasings(array $nomes, string $perguntaAtual): array
    {
        $nome = $nomes[0] ?? null;
        if ($nome === null) {
            return [];
        }
        $normal = fn (string $t): string => mb_strtolower(trim(preg_replace('/[^\p{L}\p{N} ]+/u', ' ', $t) ?? ''));
        $atual = $normal($perguntaAtual);
        $sugestoes = [];
        foreach (self::REPHRASINGS as $modelo) {
            $texto = str_replace('{nome}', $nome, $modelo);
            if ($normal($texto) !== $atual && count($sugestoes) < 3) {
                $sugestoes[] = $texto;
            }
        }

        return $sugestoes;
    }

    /** Frase curta para as trocas antigas da conversa (sem a resposta completa guardada). */
    public static function shortSpeech(string $status, array $medicamentos): string
    {
        $nome = self::listNames(array_column($medicamentos, 'name'));

        return match ($status) {
            MedicationQuestionService::NO_LEAFLET => 'Ainda não tenho a bula de '.$nome.' comigo.',
            MedicationQuestionService::NOT_IDENTIFIED => 'Ainda não tenho a bula de '.$nome.' comigo.',
            MedicationQuestionService::NO_EVIDENCE => 'Não tive uma orientação clara sobre isso para '.$nome.'.',
            MedicationQuestionService::EXCERPTS_ONLY => 'Mostrei as partes da bula de '.$nome.' que falam disso.',
            MedicationQuestionService::NEEDS_INFO => 'Preciso de mais informações para explicar sobre '.$nome.'.',
            MedicationQuestionService::UNAVAILABLE => 'Não consegui consultar o assistente nessa hora.',
            MedicationQuestionService::NO_MEDICINE => 'Perguntei sobre qual remédio você queria saber.',
            MedicationQuestionService::BLOCKED => 'Não pude ajudar com essa pergunta.',
            MedicationQuestionService::GUIDANCE => 'Expliquei como o sistema funciona nesse ponto.',
            MedicationQuestionService::SUPPORT => 'Indiquei onde encontrar apoio.',
            default => 'Respondi com base na bula de '.$nome.'.',
        };
    }

    /**
     * "Fontes utilizadas": uma entrada por documento, seção e página (sem repetir), só dos trechos que sustentam alguma
     * afirmação, com o trecho, a seção, a página e a data de processamento da bula.
     *
     * @param  array<string, mixed>  $answer
     * @return list<array{document_title: string, section: string|null, page_start: int|null, excerpt: string, processed: string|null}>
     */
    public static function sources(array $answer): array
    {
        $evidencias = collect($answer['evidences'] ?? [])
            ->filter(fn ($e) => ! in_array($e['document_type'] ?? null, ['treatment_context', 'conversation_context'], true) && ! empty($e['excerpt']) && ! empty($e['supports_claims']));
        $processados = KnowledgeDocument::query()->whereIn('id', $evidencias->pluck('document_id')->filter()->unique()->all())->pluck('processed_at', 'id');

        return $evidencias
            ->unique(fn ($e) => ($e['document_id'] ?? '').'|'.trim((string) ($e['section'] ?? '')).'|'.($e['page_start'] ?? ''))
            ->map(fn ($e): array => [
                'document_title' => (string) ($e['document_title'] ?? 'Bula'),
                'section' => filled($e['section'] ?? null) ? self::readableSection((string) $e['section']) : null,
                'page_start' => $e['page_start'] ?? null,
                'excerpt' => self::readableExcerpt((string) $e['excerpt']),
                'processed' => ($processados[$e['document_id'] ?? 0] ?? null)?->timezone(config('app.timezone'))->format('d/m/Y'),
            ])->values()->all();
    }

    /** Prefixos que levam hífen de verdade ("pós-comercialização"); nos outros, o hífen é só a quebra de linha do PDF. */
    private const HYPHENATED_PREFIXES = ['pos', 'pre', 'pro', 'anti', 'auto', 'semi', 'sub', 'super', 'ex', 'vice', 'recem', 'bem', 'mal', 'nao', 'co', 'micro', 'macro', 'hiper', 'hipo', 'pseudo', 'infra', 'ultra', 'intra', 'extra'];

    /** "REAÇÕES ADVERSAS" → "Reações adversas": título de seção em maiúsculas é mais difícil de ler. */
    public static function readableSection(string $section): string
    {
        $section = trim((string) preg_replace('/\s+/u', ' ', $section));
        if ($section !== '' && mb_strtoupper($section) === $section && preg_match('/\p{Lu}{3}/u', $section) === 1) {
            $section = Str::ucfirst(mb_strtolower($section));
        }

        return $section;
    }

    /**
     * Trecho da bula para ler na tela: junta palavras que o PDF quebrou com hífen ("medica- mento" → "medicamento",
     * mas "pós- comercialização" → "pós-comercialização"), tira espaços repetidos e corta no fim de uma palavra.
     */
    public static function readableExcerpt(string $excerpt, int $limit = 420): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $excerpt));
        $text = (string) preg_replace_callback('/(\p{L}+)- (\p{Ll})/u', function (array $m): string {
            return in_array(Str::lower(Str::ascii($m[1])), self::HYPHENATED_PREFIXES, true) ? $m[1].'-'.$m[2] : $m[1].$m[2];
        }, $text);

        return Str::limit($text, $limit, '…', preserveWords: true);
    }
}
