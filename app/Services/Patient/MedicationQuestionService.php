<?php

namespace App\Services\Patient;

use App\Data\Knowledge\EvidenceReference;
use App\Data\Knowledge\RetrievalQuery;
use App\Data\Knowledge\RetrievalResult;
use App\Models\Consultation\MedicationQuestion;
use App\Models\Knowledge\AnvisaMedicine;
use App\Models\PatientMedication;
use App\Models\User;
use App\Services\Interactions\ExplanationGenerator;
use App\Services\Interactions\InteractionLookup;
use App\Services\Knowledge\LeafletEvidenceService;
use App\Services\Knowledge\MedicineLeafletFinder;
use App\Services\Retrieval\KnowledgeRetriever;
use App\Services\Safety\GuardVerdict;
use App\Services\Safety\InputGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Responde perguntas livres sobre medicamentos usando só as bulas cadastradas e, quando o usuário consentiu, os fatos
 * determinísticos do seu tratamento (medicamentos em uso, interações DDInter e alergias). Nunca responde "de memória".
 * Cada pergunta de usuário autenticado é registrada em `medication_questions` com evidências, contexto, triagem e métricas.
 *
 * Fluxo de resposta (`askFreeform`):
 * 1. triagem (`InputGuard`), detecção dos medicamentos (`MedicationMentionDetector`) e localização das bulas de cada um
 *    (`MedicineLeafletFinder`: princípio ativo ligado, ligando o catálogo na hora se preciso, ou nome do produto);
 * 2. intenção da pergunta (`QuestionIntentDetector`: efeitos colaterais, como tomar, interações…) e evidências: as já
 *    extraídas da bula inteira para esse assunto, depois a busca por trechos restrita às seções adequadas (bula do
 *    paciente primeiro) e só então sem restrição de seção;
 * 3. com evidência da seção adequada, o modelo responde em linguagem simples, afirmação a afirmação, cada uma citando
 *    a evidência; a tela mostra fonte, seção e página;
 * 4. sem evidência adequada ou com o modelo declarando insuficiência, a bula inteira é consultada
 *    (`LeafletEvidenceService`): trecho literal, seção, página e confiança, conferidos contra o texto do documento e
 *    gravados como evidência auditável, reaproveitada nas próximas perguntas;
 * 5. se nem assim houver evidência, a resposta diz isso com clareza, nunca preenche com trechos irrelevantes, nunca
 *    trata ausência como segurança, e a pergunta entra na fila de revisão humana (`review_status`) para a equipe
 *    adquirir fontes.
 */
final class MedicationQuestionService
{
    public const NOT_IDENTIFIED = 'not_identified';

    public const NO_LEAFLET = 'no_leaflet';

    public const NO_EVIDENCE = 'no_evidence';

    public const ANSWERED = 'answered';

    public const EXCERPTS_ONLY = 'excerpts_only';

    public const BLOCKED = MedicationQuestion::STATUS_BLOCKED;

    public const NO_MEDICINE = 'no_medicine';

    /** Sem fatos para afirmar, mas há o que perguntar à pessoa (qual é o outro remédio, alergias…) ou o que não se pode confirmar. */
    public const NEEDS_INFO = 'needs_info';

    /** O assistente (modelo de texto) não pôde ser consultado agora; nenhum trecho bruto é mostrado no lugar. */
    public const UNAVAILABLE = 'unavailable';

    private const MAX_EVIDENCES = 8;

    /** Trocas anteriores consideradas e tamanho máximo do resumo entregue ao modelo. */
    public const CONVERSATION_TURNS = 3;

    private const CONVERSATION_MAX_CHARS = 1200;

    private const CONVERSATION_TURN_CHARS = 380;

    public const CONVERSATION_LABEL = 'Conversa anterior';

    public function __construct(
        private readonly KnowledgeRetriever $retriever,
        private readonly ExplanationGenerator $generator,
        private readonly TreatmentContextService $treatment,
        private readonly InputGuard $guard,
        private readonly MedicationMentionDetector $detector,
        private readonly InteractionLookup $lookup,
        private readonly MedicineLeafletFinder $leaflets,
        private readonly QuestionIntentDetector $intents,
        private readonly LeafletEvidenceService $evidence,
        private readonly UrgencyDetector $urgencies,
    ) {}

    /**
     * @return array{status: string, answer: string|null, evidences: list<array<string, mixed>>, generation: array<string, mixed>, retrieval: array<string, mixed>, treatment: array<string, mixed>|null, uuid: string|null}
     */
    public function ask(AnvisaMedicine $medicine, string $question, ?User $user = null): array
    {
        $started = hrtime(true);
        $treatment = $user !== null ? $this->treatment->for($user, $medicine) : null;
        $facts = $treatment !== null ? $this->treatment->asEvidenceText($treatment, $medicine->product_name) : null;
        $result = $this->answerMany([$medicine->loadMissing('ingredients')], $question, $facts) + ['treatment' => $treatment, 'uuid' => null];
        $durationMs = (int) ((hrtime(true) - $started) / 1_000_000);

        if ($user !== null) {
            $result['uuid'] = $this->record($user, $medicine, $question, $result, $durationMs)->uuid;
        }

        return $result;
    }

    /**
     * Pergunta livre: triagem, detecção dos medicamentos citados, evidências de todas as bulas envolvidas, fatos do
     * tratamento de cada um e entre eles, geração validada e registro.
     *
     * Conversa: quando a nova pergunta não cita remédio ("e com café?"), os medicamentos da última troca são reaproveitados
     * (marcados com `matched_text = 'conversa'`) e um resumo curto das últimas trocas entra como contexto rotulado
     * "Conversa anterior" junto aos fatos do tratamento (evidência T1), nunca como instrução.
     *
     * @param  list<int>  $forcedMedicineIds  medicamentos escolhidos pelo usuário (correção ou link), além dos detectados
     * @param  list<array{question: string, answer: string|null, medicine_ids: list<int>}>  $conversation  últimas trocas desta sessão, da mais antiga para a mais nova
     * @param  list<int>  $excludedMedicineIds  medicamentos que o usuário mandou remover da pergunta (detecção errada)
     * @return array{status: string, message: string|null, question: string, answer: string|null, evidences: list<array<string, mixed>>, generation: array<string, mixed>, retrieval: array<string, mixed>, treatment: array<string, mixed>|null, treatments: list<array{medicine: string, context: array<string, mixed>}>, medicines: list<array{id: int, name: string, ingredients: string, identified: bool, matched_text: string, fuzzy: bool, corrected: string|null}>, suggestions: list<array{id: int, name: string}>, guesses: list<array{id: int, name: string, ingredients: string, matched: string, term: string}>, limitations: list<string>, guard: array{category: string, flags: list<string>}, uuid: string|null}
     */
    public function askFreeform(User $user, string $question, array $forcedMedicineIds = [], array $conversation = [], array $excludedMedicineIds = []): array
    {
        $started = hrtime(true);
        $verdict = $this->guard->screen($question);
        $conversation = array_slice(array_values($conversation), -self::CONVERSATION_TURNS);
        $base = [
            'message' => null, 'question' => $verdict->sanitized, 'answer' => null, 'evidences' => [], 'generation' => [], 'retrieval' => [], 'treatment' => null,
            'treatments' => [], 'medicines' => [], 'suggestions' => [], 'guesses' => [], 'follow_up' => [], 'limits' => [], 'coverage' => 'none', 'intent' => [], 'urgency' => $this->urgencies->detect($verdict->sanitized), 'limitations' => [], 'missing' => ['unidentified' => [], 'without_leaflet' => []], 'guard' => $verdict->toArray(), 'uuid' => null,
        ];

        if (! $verdict->allowed()) {
            return $this->finish($user, ['status' => self::BLOCKED, 'message' => $verdict->userMessage()] + $base, $started);
        }

        $excluded = fn (array $items): array => array_values(array_filter($items, fn (array $m): bool => ! in_array($m['id'], $excludedMedicineIds, true)));
        $medicines = $excluded($this->detector->detect($verdict->sanitized, $user, $forcedMedicineIds));
        $previousIds = array_values(array_diff(array_map('intval', $conversation !== [] ? ($conversation[array_key_last($conversation)]['medicine_ids'] ?? []) : []), $excludedMedicineIds));
        if ($medicines === [] && $previousIds !== []) {
            $medicines = array_map(fn (array $m): array => in_array($m['id'], $previousIds, true) && ! in_array($m['id'], $forcedMedicineIds, true) ? ['matched_text' => 'conversa'] + $m : $m, $excluded($this->detector->detect($verdict->sanitized, $user, $previousIds)));
        }
        if ($medicines === []) {
            $suggestions = $user->hasHealthConsent()
                ? PatientMedication::query()->with('medicine:id,product_name')->where('user_id', $user->id)->get()
                    ->filter(fn (PatientMedication $item): bool => $item->isActive() && $item->medicine !== null)
                    ->map(fn (PatientMedication $item): array => ['id' => $item->medicine_id, 'name' => $item->medicine->product_name])->values()->all()
                : [];

            // Nada reconhecido: nomes parecidos com o que foi escrito ("você quis dizer…?") e os remédios em uso, para a pessoa tocar.
            $guesses = array_values(array_filter($this->detector->guesses($verdict->sanitized), fn (array $g): bool => ! in_array($g['id'], $excludedMedicineIds, true)));

            return $this->finish($user, ['status' => self::NO_MEDICINE, 'suggestions' => $suggestions, 'guesses' => $guesses] + $base, $started);
        }

        $models = AnvisaMedicine::query()->with('ingredients')->whereIn('id', array_column($medicines, 'id'))->get()->keyBy('id');
        $ordered = array_values(array_filter(array_map(fn (array $m) => $models[$m['id']] ?? null, $medicines)));

        $treatments = [];
        foreach ($ordered as $medicine) {
            $treatments[] = ['medicine' => $medicine->product_name, 'context' => $this->treatment->for($user, $medicine)];
        }
        $facts = $this->freeformFacts($ordered, $treatments);

        $lastTurn = $conversation !== [] ? $conversation[array_key_last($conversation)] : [];
        $result = $this->answerMany($ordered, $verdict->sanitized, $facts, $this->conversationText($conversation), [
            'previous_topics' => array_values(array_filter((array) ($lastTurn['topics'] ?? []), 'is_string')),
            'previous_question' => isset($lastTurn['question']) ? (string) $lastTurn['question'] : null,
            'follow_up' => $medicines !== [] && collect($medicines)->every(fn (array $m): bool => $m['matched_text'] === 'conversa'),
            'urgency' => $base['urgency'],
        ]) + [
            'treatment' => $treatments[0]['context'] + ['others' => array_slice($treatments, 1)],
            'treatments' => $treatments, 'medicines' => $medicines,
        ] + $base;
        $result['urgency'] = $base['urgency'];
        $result['generation'] += ['conversation_turns' => count($conversation)] + ($base['urgency'] !== null ? ['urgency' => $base['urgency']['kinds']] : []);

        return $this->finish($user, $result, $started);
    }

    /**
     * Resumo das últimas trocas, da mais antiga para a mais nova, limitado em tamanho. As perguntas anteriores são texto do
     * usuário (não são fatos); as respostas anteriores já foram validadas contra as bulas.
     *
     * @param  list<array{question: string, answer: string|null, medicine_ids: list<int>}>  $conversation
     */
    public function conversationText(array $conversation): ?string
    {
        $turns = [];
        foreach ($conversation as $turn) {
            $question = Str::limit(trim(preg_replace('/\s+/', ' ', (string) ($turn['question'] ?? ''))), self::CONVERSATION_TURN_CHARS, '…');
            $answer = Str::limit(trim(preg_replace('/\s+/', ' ', (string) ($turn['answer'] ?? ''))), self::CONVERSATION_TURN_CHARS, '…');
            if ($question === '') {
                continue;
            }
            $turns[] = 'Paciente perguntou: "'.$question.'"'.($answer !== '' ? ' Resposta dada: "'.$answer.'"' : ' (sem resposta)');
        }
        if ($turns === []) {
            return null;
        }
        $text = self::CONVERSATION_LABEL.' (apenas para entender a pergunta atual; não é fonte de fatos sobre o remédio): '.implode(' ', $turns);

        return Str::limit($text, self::CONVERSATION_MAX_CHARS, '…');
    }

    /**
     * Fatos determinísticos: contexto de cada medicamento citado e, entre os citados, a interação registrada na base.
     *
     * @param  list<AnvisaMedicine>  $medicines
     * @param  list<array{medicine: string, context: array<string, mixed>}>  $treatments
     */
    private function freeformFacts(array $medicines, array $treatments): ?string
    {
        $lines = [];
        foreach ($treatments as $treatment) {
            $text = $this->treatment->asEvidenceText($treatment['context'], $treatment['medicine']);
            if ($text !== null) {
                $lines[] = $text;
            }
        }
        for ($i = 0; $i < count($medicines); $i++) {
            for ($j = $i + 1; $j < count($medicines); $j++) {
                $idsA = $medicines[$i]->ingredients->pluck('id')->map(fn ($id): int => (int) $id)->all();
                $idsB = $medicines[$j]->ingredients->pluck('id')->map(fn ($id): int => (int) $id)->all();
                if ($idsA === [] || $idsB === []) {
                    continue;
                }
                if (array_intersect($idsA, $idsB) !== []) {
                    $lines[] = sprintf('%s e %s têm o mesmo princípio ativo (possível duplicidade; não avaliada por este sistema).', $medicines[$i]->product_name, $medicines[$j]->product_name);

                    continue;
                }
                $structured = $this->lookup->between($idsA, $idsB);
                $lines[] = $structured === null
                    ? sprintf('A base de interações (DDInter) não registra interação entre %s e %s; isso não comprova que a combinação seja segura.', $medicines[$i]->product_name, $medicines[$j]->product_name)
                    : sprintf('A base de interações (DDInter) registra interação de gravidade %s entre %s e %s.', mb_strtolower($structured->severity->label()), $medicines[$i]->product_name, $medicines[$j]->product_name);
            }
        }

        return $lines === [] ? null : implode(' ', $lines);
    }

    /**
     * @param  list<AnvisaMedicine>  $medicines
     * @param  array{previous_topics?: list<string>, previous_question?: string|null, follow_up?: bool, urgency?: array<string, mixed>|null}  $context
     * @return array{status: string, answer: string|null, evidences: list<array<string, mixed>>, generation: array<string, mixed>, retrieval: array<string, mixed>, limitations: list<string>, missing: array{unidentified: list<string>, without_leaflet: list<string>}, intent: list<string>, follow_up: list<string>, limits: list<string>, coverage: string}
     */
    private function answerMany(array $medicines, string $question, ?string $facts, ?string $conversation = null, array $context = []): array
    {
        $empty = ['answer' => null, 'evidences' => [], 'generation' => [], 'retrieval' => [], 'limitations' => [], 'follow_up' => [], 'limits' => [], 'coverage' => 'none'];
        $mode = (string) config('bulai.retrieval.mode');
        $perSection = (int) config('bulai.questions.evidence_per_section');
        $plan = $this->intents->plan($question, count($medicines), (array) ($context['previous_topics'] ?? []));
        $topics = $plan['topics'];
        $sections = $plan['sections'];
        $unidentified = [];
        $withoutLeaflet = [];
        $streams = [];
        $coverage = [];
        $sectionMatched = false;
        $retrieval = ['mode' => $mode, 'duration_ms' => 0, 'per_medicine' => [], 'intent' => $topics, 'sections' => $sections, 'ambiguous' => $plan['ambiguous'], 'inherited_intent' => $plan['inherited'], 'section_matched' => false, 'leaflet_fallback' => null];
        // O usuário pergunta pelo nome comercial; as bulas falam pelo princípio ativo. Os nomes dos ativos citados
        // entram na consulta de recuperação para que a bula de um cite o outro.
        $ingredientNames = collect($medicines)->flatMap(fn (AnvisaMedicine $m) => $m->ingredients->pluck('normalized_name'))->unique()->values()->all();
        $names = implode(' ', $ingredientNames);
        // Pergunta de acompanhamento ("mas por quê?"): a busca usa também a pergunta anterior, que diz o assunto.
        $subject = trim($question.' '.(! empty($context['follow_up']) ? (string) ($context['previous_question'] ?? '') : ''));

        foreach ($medicines as $medicine) {
            // Bulas pelo princípio ativo ligado (ligando o catálogo na hora se preciso) ou, na falta dele, pelo nome.
            $found = $this->leaflets->find($medicine);
            if ($found['document_ids'] === []) {
                if ($found['ingredient_ids'] === []) {
                    $unidentified[] = $medicine->product_name;
                } else {
                    $withoutLeaflet[] = $medicine->product_name;
                }

                continue;
            }
            $documentIds = $this->patientFirst($found['document_ids']);
            $coverage[] = ['medicine' => $medicine, 'documents' => $documentIds];

            // 1. Evidências já extraídas da bula inteira para este assunto (perguntas anteriores, mesma versão do documento).
            $stored = $this->evidence->stored($documentIds, array_values(array_unique([...$topics, ...$sections])), $question);
            if ($stored !== []) {
                $streams[] = $stored;
            }
            // 2. Cada seção do plano tem a sua busca (em palavras de bula, não a pergunta inteira): seção do assunto na bula do
            // paciente, depois em todas as bulas, depois sem restrição de seção (os rótulos de seção das bulas nem sempre são bons).
            $perMedicine = ['medicine' => $medicine->product_name, 'documents' => count($documentIds), 'stored_facts' => count($stored), 'found_by' => $found['method'], 'linked_now' => $found['linked_now'], 'sections' => []];
            foreach ($sections as $section) {
                $text = trim($this->intents->sectionQuery($section).' '.$names.' '.($plan['ambiguous'] ? $subject : $this->intents->searchTerms($subject)));
                [$result, $matched] = $this->retrieve($text, $mode, $perSection, $documentIds, [$section]);
                $retrieval['duration_ms'] += $result->durationMs;
                $retrieval += $result->configuration;
                $sectionMatched = $sectionMatched || $matched;
                $perMedicine['sections'][$section] = ['references' => count($result->references), 'section_label_matched' => $matched];
                if ($result->references !== []) {
                    $streams[] = $result->references;
                }
            }
            $perMedicine['references'] = array_sum(array_column($perMedicine['sections'], 'references'));
            $retrieval['per_medicine'][] = $perMedicine;
            $sectionMatched = $sectionMatched || $stored !== [];
        }

        $limitations = [];
        // Nomes em falta, para a tela falar com o paciente em vez de listar limitações técnicas.
        $empty['missing'] = ['unidentified' => $unidentified, 'without_leaflet' => $withoutLeaflet];
        $empty['intent'] = $topics;
        if ($unidentified !== []) {
            $limitations[] = 'Princípio ativo não identificado no catálogo para: '.implode(', ', $unidentified).'.';
        }
        if ($withoutLeaflet !== []) {
            $limitations[] = 'Ainda não há bula cadastrada para: '.implode(', ', $withoutLeaflet).'.';
        }
        if (count($unidentified) === count($medicines)) {
            return ['status' => self::NOT_IDENTIFIED, 'limitations' => $limitations] + $empty;
        }
        if (count($unidentified) + count($withoutLeaflet) === count($medicines)) {
            return ['status' => self::NO_LEAFLET, 'limitations' => $limitations] + $empty;
        }

        $references = $this->interleave($streams);
        $retrieval['section_matched'] = $sectionMatched;
        $label = implode(' + ', array_map(fn (AnvisaMedicine $m): string => $m->product_name, $medicines));
        $extra = ['limitations' => $limitations, 'missing' => $empty['missing'], 'intent' => $topics];
        // Orçamento de palavras dos fatos: pergunta simples (um só assunto simples, ou geral) = resposta curta; segurança, interações e
        // comparações pedem mais. Vale o menor dos assuntos quando há mais de um assunto simples.
        $byIntent = (array) config('bulai.questions.conversation.fact_words_by_intent');
        $factWords = $plan['ambiguous']
            ? (int) config('bulai.questions.conversation.fact_words_general')
            : (count($topics) === 1 && isset($byIntent[$topics[0]]) ? (int) $byIntent[$topics[0]] : (int) config('bulai.questions.conversation.max_fact_words'));
        $genContext = ['conversation' => $conversation, 'topics' => $plan['labels'], 'ambiguous' => $plan['ambiguous'], 'urgency' => ! empty($context['urgency']), 'fact_words' => $factWords,
            'allow_asks' => ! (count($topics) === 1 && in_array($topics[0], (array) config('bulai.questions.conversation.no_ask_intents'), true))];

        // 3. Primeira geração com o que a busca trouxe; é o modelo quem julga se as evidências cobrem a pergunta. Uma reformulação
        // controlada (nível 1) acontece dentro do gerador quando a validação reprova tudo.
        $best = null;
        $last = null;
        if ($references !== []) {
            $last = $this->generate($label, $question, $references, $facts, $retrieval, $genContext);
            $best = $this->usable($last) ? $last : null;
            if ($best !== null && $last['coverage'] === 'full' && $last['status'] === self::ANSWERED) {
                return $last + $extra;
            }
        }

        // 4. Nível 2: a bula inteira de cada medicamento, na seção compatível com a intenção, quando a cobertura não é completa
        // (inclusive sem nenhum fato). A segunda geração só substitui a primeira se for melhor.
        $fallback = $this->consultWholeLeaflets($coverage, $question, $topics !== [] ? $topics : $sections, $plan['labels'][0] ?? '');
        $retrieval['leaflet_fallback'] = $fallback['extraction'];
        if ($fallback['references'] !== []) {
            $merged = $fallback['references'];
            foreach ($references as $reference) {
                if (! collect($merged)->contains(fn (EvidenceReference $r): bool => $r->chunkId === $reference->chunkId)) {
                    $merged[] = $reference;
                }
            }
            $second = $this->generate($label, $question, array_slice($merged, 0, self::MAX_EVIDENCES), $facts, $retrieval, $genContext);
            $last = $second;
            if ($this->usable($second) && ($best === null || $this->rank($second) >= $this->rank($best))) {
                $best = $second;
            }
        }

        // Nível 3: o que há é parcial (fatos com cobertura parcial, ou só o que perguntar ou não confirmar): a conversa continua com isso.
        if ($best !== null) {
            return $best + $extra;
        }

        // Nível 4: insuficiência real. O assistente indisponível não é falta de evidência; nenhum trecho bruto vira resposta.
        $unavailable = $last !== null && ($last['generation']['status'] ?? null) === ExplanationGenerator::STATUS_UNAVAILABLE;
        $found = collect($references)->map(fn (EvidenceReference $r): string => trim((string) $r->section))->filter()->unique()->take(3)->values()->all();

        return [
            'status' => $unavailable ? self::UNAVAILABLE : self::NO_EVIDENCE,
            'answer' => null,
            'evidences' => [],
            'generation' => ($last['generation'] ?? []) + ['status' => $last['generation']['status'] ?? 'not_attempted'],
            'retrieval' => $retrieval + ['found_sections' => $found, 'fallback_consulted' => $fallback['consulted']],
            'follow_up' => [], 'limits' => [], 'coverage' => 'none',
        ] + $extra;
    }

    /**
     * Busca de uma seção em três passos: a seção na bula do paciente; a seção em todas as bulas; qualquer seção.
     * Devolve também se algum trecho veio de seção com o nome esperado.
     *
     * @param  list<int>  $documentIds  bula do paciente primeiro
     * @param  list<string>  $topics
     * @return array{0: RetrievalResult, 1: bool}
     */
    private function retrieve(string $text, string $mode, int $limit, array $documentIds, array $topics): array
    {
        if ($topics !== []) {
            $patterns = $this->intents->sectionPatterns($topics);
            $patient = $this->patientOnly($documentIds);
            foreach (array_unique([$patient === [] ? $documentIds : $patient, $documentIds], SORT_REGULAR) as $ids) {
                $result = $this->retriever->retrieve(new RetrievalQuery($text, $mode, $limit, $ids, $patterns));
                if (! $result->isEmpty()) {
                    return [$result, true];
                }
            }
        }
        $result = $this->retriever->retrieve(new RetrievalQuery($text, $mode, $limit, $documentIds));
        $matched = $topics !== [] && collect($result->references)->contains(fn (EvidenceReference $r): bool => $this->intents->sectionMatches($r->section, $topics));

        return [$result, $matched];
    }

    /**
     * @param  list<array{medicine: AnvisaMedicine, documents: list<int>}>  $coverage
     * @param  list<string>  $topics
     * @return array{references: list<EvidenceReference>, extraction: array<string, mixed>|null, consulted: bool}
     */
    private function consultWholeLeaflets(array $coverage, string $question, array $topics, string $topicLabel): array
    {
        if ($coverage === [] || ! $this->evidence->isEnabled()) {
            return ['references' => [], 'extraction' => $coverage === [] ? null : ['reason' => 'disabled'], 'consulted' => false];
        }
        $references = [];
        $extraction = ['per_medicine' => [], 'accepted' => 0, 'run_id' => (string) Str::uuid()];
        foreach ($coverage as $entry) {
            // Bula do paciente e, se ela não responder, a do profissional: no máximo duas leituras por medicamento.
            $result = $this->evidence->extract(array_slice($entry['documents'], 0, 2), $question, $topics, $entry['medicine']->id, $extraction['run_id'], $topicLabel);
            $extraction['per_medicine'][] = ['medicine' => $entry['medicine']->product_name] + $result['extraction'];
            $extraction['accepted'] += count($result['references']);
            if (isset($result['extraction']['document_id'])) {
                $extraction['document_id'] = $result['extraction']['document_id'];
            }
            $references = [...$references, ...$result['references']];
        }

        return ['references' => $references, 'extraction' => $extraction, 'consulted' => true];
    }

    /** @param list<int> $documentIds @return list<int> bulas do paciente primeiro, mantendo a ordem relativa */
    private function patientFirst(array $documentIds): array
    {
        $patient = $this->patientOnly($documentIds);

        return [...$patient, ...array_values(array_diff($documentIds, $patient))];
    }

    /** @param list<int> $documentIds @return list<int> */
    private function patientOnly(array $documentIds): array
    {
        static $types = [];
        $missing = array_values(array_diff($documentIds, array_keys($types)));
        if ($missing !== []) {
            foreach (DB::table('knowledge_documents')->whereIn('id', $missing)->pluck('document_type', 'id') as $id => $type) {
                $types[(int) $id] = (string) $type;
            }
        }

        return array_values(array_filter($documentIds, fn (int $id): bool => ($types[$id] ?? null) === 'bula_paciente'));
    }

    /** A geração trouxe algo aproveitável: fatos, perguntas à pessoa, ou ao menos a limitação dita com as palavras do modelo. @param array<string, mixed> $generated */
    private function usable(array $generated): bool
    {
        return in_array($generated['status'], [self::ANSWERED, self::NEEDS_INFO], true) || ($generated['status'] === self::NO_EVIDENCE && ! empty($generated['answer']));
    }

    /** Ordena resultados: fatos > só perguntas > só limitação; e cobertura completa > parcial > nenhuma. @param array<string, mixed> $generated */
    private function rank(array $generated): int
    {
        $status = match ($generated['status']) {
            self::ANSWERED => 20, self::NEEDS_INFO => 10, default => 0
        };

        return $status + match ($generated['coverage'] ?? 'none') {
            'full' => 2, 'partial' => 1, default => 0
        };
    }

    /**
     * Junta as evidências de várias buscas (uma por seção e medicamento) alternando entre elas, para que cada parte da pergunta
     * seja representada, sem repetir o mesmo trecho e respeitando o limite de evidências.
     *
     * @param  list<list<EvidenceReference>>  $streams
     * @return list<EvidenceReference>
     */
    private function interleave(array $streams): array
    {
        $merged = [];
        for ($position = 0; count($merged) < self::MAX_EVIDENCES; $position++) {
            $any = false;
            foreach ($streams as $stream) {
                if (! isset($stream[$position])) {
                    continue;
                }
                $any = true;
                $reference = $stream[$position];
                if (! isset($merged[$reference->chunkId]) && count($merged) < self::MAX_EVIDENCES) {
                    $merged[$reference->chunkId] = $reference;
                }
            }
            if (! $any) {
                break;
            }
        }

        return array_values($merged);
    }

    /**
     * @param  list<EvidenceReference>  $references
     * @param  array<string, mixed>  $retrieval
     * @param  array{conversation?: string|null, topics?: list<string>, ambiguous?: bool, urgency?: bool}  $context
     * @return array{status: string, answer: string|null, evidences: list<array<string, mixed>>, generation: array<string, mixed>, retrieval: array<string, mixed>, follow_up: list<string>, limits: list<string>, coverage: string}
     */
    private function generate(string $label, string $question, array $references, ?string $facts, array $retrieval, array $context = []): array
    {
        $conversation = $context['conversation'] ?? null;
        $generated = $this->generator->answer($label, $question, $references, (string) Str::uuid(), $facts, $context);
        $evidences = array_map(fn (EvidenceReference $reference): array => $reference->toArray(), $references);
        if ($facts !== null) {
            $evidences[] = [
                'chunk_id' => 0, 'document_id' => null, 'document_title' => 'Contexto do tratamento (base DDInter e perfil)', 'document_type' => 'treatment_context',
                'source' => 'DDInter + perfil do paciente', 'section' => 'tratamento', 'page_start' => null, 'page_end' => null, 'excerpt' => $facts,
                'score' => 1.0, 'method' => 'structured', 'document_version' => null,
            ];
        }
        if ($conversation !== null) {
            $evidences[] = [
                'chunk_id' => -1, 'document_id' => null, 'document_title' => self::CONVERSATION_LABEL.' (esta sessão)', 'document_type' => 'conversation_context',
                'source' => 'perguntas e respostas anteriores do próprio paciente', 'section' => 'conversa', 'page_start' => null, 'page_end' => null, 'excerpt' => $conversation,
                'score' => 1.0, 'method' => 'conversation', 'document_version' => null,
            ];
        }
        foreach ($evidences as &$evidence) {
            $evidence['supports_claims'] = array_keys(array_filter($generated['claims'], fn (array $claim): bool => in_array($evidence['chunk_id'], $claim['evidence_chunk_ids'], true)));
        }
        unset($evidence);

        // ANSWERED: há ao menos um fato sustentado. NEEDS_INFO: não há fato, mas há o que perguntar à pessoa. Só limitação dita pelo
        // modelo (a bula não trata do assunto): NO_EVIDENCE, com a explicação específica do modelo no lugar de uma frase genérica.
        $status = $generated['status'] !== ExplanationGenerator::STATUS_GENERATED
            ? ($generated['status'] === ExplanationGenerator::STATUS_UNAVAILABLE ? self::UNAVAILABLE : self::NO_EVIDENCE)
            : ($generated['claims'] !== [] ? self::ANSWERED : ($generated['follow_up'] !== [] ? self::NEEDS_INFO : self::NO_EVIDENCE));

        return [
            'status' => $status,
            'answer' => $generated['explanation'],
            'evidences' => in_array($status, [self::ANSWERED, self::NEEDS_INFO], true) ? $evidences : [],
            'generation' => $generated['generation'] + ['status' => $generated['status'], 'conversation_context' => $conversation !== null],
            'retrieval' => $retrieval,
            'follow_up' => $generated['follow_up'],
            'limits' => $generated['limits'],
            'coverage' => $generated['coverage'],
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function finish(User $user, array $result, int $started): array
    {
        $durationMs = (int) ((hrtime(true) - $started) / 1_000_000);
        $first = $result['medicines'][0] ?? null;
        $result['uuid'] = MedicationQuestion::query()->create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $user->id,
            'medicine_id' => $first['id'] ?? null,
            'medicine_name' => $first === null ? '—' : implode(' + ', array_column($result['medicines'], 'name')),
            'medicines' => $result['medicines'],
            'question' => $result['question'],
            'status' => $result['status'],
            'answer' => $result['answer'],
            'evidences' => $result['evidences'],
            'treatment_context' => $result['treatment'],
            'guard' => $result['guard'],
            'generation' => $result['generation'],
            'retrieval' => $result['retrieval'] + ($result['limitations'] !== [] ? ['limitations' => $result['limitations']] : []),
            'pipeline' => $this->pipeline(),
            'duration_ms' => $durationMs,
            'expires_at' => now()->addDays((int) config('bulai.questions.retention_days')),
            // Sem resposta mesmo depois de esgotar as fontes: fila de revisão humana (adquirir bula ou mais fontes).
            'review_status' => MedicationQuestion::needsReview($result['status']) ? MedicationQuestion::REVIEW_PENDING : null,
            'review_reason' => MedicationQuestion::needsReview($result['status']) ? $result['status'] : null,
        ])->uuid;

        return $result;
    }

    /** @param array<string, mixed> $result */
    private function record(User $user, AnvisaMedicine $medicine, string $question, array $result, int $durationMs): MedicationQuestion
    {
        return MedicationQuestion::query()->create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $user->id,
            'medicine_id' => $medicine->id,
            'medicine_name' => $medicine->product_name,
            'medicines' => [['id' => $medicine->id, 'name' => $medicine->product_name, 'ingredients' => (string) $medicine->active_ingredient, 'identified' => $medicine->ingredients()->exists(), 'matched_text' => 'escolhido', 'fuzzy' => false, 'corrected' => null]],
            'question' => $question,
            'status' => $result['status'],
            'answer' => $result['answer'],
            'evidences' => $result['evidences'],
            'treatment_context' => $result['treatment'],
            'guard' => ['category' => GuardVerdict::OK, 'flags' => []],
            'generation' => $result['generation'],
            'retrieval' => $result['retrieval'],
            'pipeline' => $this->pipeline(),
            'duration_ms' => $durationMs,
            'expires_at' => now()->addDays((int) config('bulai.questions.retention_days')),
            'review_status' => MedicationQuestion::needsReview($result['status']) ? MedicationQuestion::REVIEW_PENDING : null,
            'review_reason' => MedicationQuestion::needsReview($result['status']) ? $result['status'] : null,
        ]);
    }

    /** @return array<string, mixed> */
    private function pipeline(): array
    {
        return [
            'retrieval_mode' => config('bulai.retrieval.mode'),
            'generation_enabled' => (bool) config('bulai.generation.enabled'),
            'text_provider' => config('ai.text.default'),
            'embedding_provider' => config('ai.embedding.default'),
            'prompt_version' => config('bulai.generation.prompt_version').':qa',
            'leaflet_fallback' => $this->evidence->isEnabled() ? LeafletEvidenceService::PROMPT_VERSION : null,
            'code_version' => config('bulai.code_version'),
        ];
    }

    /** Remove perguntas vencidas; devolve quantas foram apagadas. */
    public function purgeExpired(): int
    {
        return DB::table('medication_questions')->where('expires_at', '<', now())->delete();
    }
}
