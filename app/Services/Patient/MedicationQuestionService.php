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
use App\Services\Knowledge\External\ExternalEvidenceService;
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
        private readonly QuestionInterpreter $interpreter,
        private readonly ExternalEvidenceService $external,
    ) {}

    /**
     * @return array{status: string, answer: string|null, evidences: list<array<string, mixed>>, generation: array<string, mixed>, retrieval: array<string, mixed>, treatment: array<string, mixed>|null, uuid: string|null}
     */
    public function ask(AnvisaMedicine $medicine, string $question, ?User $user = null): array
    {
        $started = hrtime(true);
        $treatment = $user !== null ? $this->treatment->for($user, $medicine) : null;
        $facts = $treatment !== null ? $this->treatment->alertsText($treatment, $medicine->product_name, in_array('como_usar', $this->intents->topics($question), true)) : null;
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
        $previousIds = array_values(array_diff(array_map('intval', $conversation !== [] ? ($conversation[array_key_last($conversation)]['medicine_ids'] ?? []) : []), $excludedMedicineIds));

        // 1. A IA interpreta a pergunta com a conversa (remédios, pergunta completa, intenção). Só orienta a busca.
        $interpretation = $this->interpreter->interpret($verdict->sanitized, $this->interpretationTurns($conversation));
        $base['retrieval'] = ['interpretation' => $interpretation];
        if ($base['urgency'] === null && ($interpretation['urgency'] ?? null) !== null) {
            $base['urgency'] = $this->urgencies->fromInterpretation($interpretation['urgency']);
        }
        // Com interpretação, a detecção procura os remédios que a IA apontou (e conferiu na pergunta/conversa), não as palavras
        // soltas da pergunta: "e na versão gotas?" não vira o remédio VERSA. Sem remédio apontado e com conversa, vale a conversa.
        $named = $interpretation['medicines'] ?? [];
        $detectionText = $named !== [] ? implode(' e ', $named) : $verdict->sanitized;
        $medicines = $interpretation !== null && $named === [] && $previousIds !== [] && $forcedMedicineIds === []
            ? []
            : $excluded($this->detector->detect($detectionText, $user, $forcedMedicineIds, verifiedNames: $named !== []));
        if ($interpretation !== null && $named !== [] && $medicines !== []) {
            $literal = $this->normalizerText($verdict->sanitized);
            $medicines = array_map(fn (array $m): array => in_array($m['id'], $previousIds, true) && ! str_contains($literal, $this->normalizerText((string) $m['matched_text'])) ? ['matched_text' => 'conversa'] + $m : $m, $medicines);
        }
        // Volta ao remédio da conversa só quando a pergunta não citou outro: se a IA apontou um remédio que o catálogo não tem,
        // a resposta não pode falar do remédio anterior no lugar dele.
        if ($medicines === [] && $previousIds !== [] && $named === []) {
            $medicines = array_map(fn (array $m): array => in_array($m['id'], $previousIds, true) && ! in_array($m['id'], $forcedMedicineIds, true) ? ['matched_text' => 'conversa'] + $m : $m, $excluded($this->detector->detect($interpretation !== null ? '' : $verdict->sanitized, $user, $previousIds)));
        }
        if ($medicines === []) {
            $suggestions = $user->hasHealthConsent()
                ? PatientMedication::query()->with('medicine:id,product_name')->where('user_id', $user->id)->get()
                    ->filter(fn (PatientMedication $item): bool => $item->isActive() && $item->medicine !== null)
                    ->map(fn (PatientMedication $item): array => ['id' => $item->medicine_id, 'name' => $item->medicine->product_name])->values()->all()
                : [];

            // Nada reconhecido: nomes parecidos com o que foi escrito ("você quis dizer…?") e os remédios em uso, para a pessoa tocar.
            $guesses = array_values(array_filter($this->detector->guesses($detectionText), fn (array $g): bool => ! in_array($g['id'], $excludedMedicineIds, true)));

            return $this->finish($user, ['status' => self::NO_MEDICINE, 'suggestions' => $suggestions, 'guesses' => $guesses] + $base, $started);
        }

        $models = AnvisaMedicine::query()->with('ingredients')->whereIn('id', array_column($medicines, 'id'))->get()->keyBy('id');
        $ordered = array_values(array_filter(array_map(fn (array $m) => $models[$m['id']] ?? null, $medicines)));

        $treatments = [];
        foreach ($ordered as $medicine) {
            $treatments[] = ['medicine' => $medicine->product_name, 'context' => $this->treatment->for($user, $medicine)];
        }
        $facts = $this->freeformFacts($ordered, $treatments, ($interpretation['intent'] ?? null) === 'DOSAGE' || in_array('como_usar', $this->intents->topics($interpretation['question'] ?? $verdict->sanitized, count($ordered)), true));

        $lastTurn = $conversation !== [] ? $conversation[array_key_last($conversation)] : [];
        // A pessoa trocou de remédio ("e o ibuprofeno?" depois de falar da dipirona): a conversa anterior era sobre outro remédio e
        // não entra no contexto, para o modelo não misturar os dois.
        $currentIds = array_map(fn (array $m): int => (int) $m['id'], $medicines);
        $switched = $previousIds !== [] && array_intersect($currentIds, $previousIds) === [];
        $result = $this->answerMany($ordered, $verdict->sanitized, $facts, $switched ? null : $this->conversationText($conversation), [
            'previous_topics' => $switched ? [] : array_values(array_filter((array) ($lastTurn['topics'] ?? []), 'is_string')),
            'previous_question' => isset($lastTurn['question']) ? (string) $lastTurn['question'] : null,
            'follow_up' => $medicines !== [] && collect($medicines)->every(fn (array $m): bool => $m['matched_text'] === 'conversa'),
            'urgency' => $base['urgency'],
            'medicine_switched' => $switched,
            'interpretation' => $interpretation,
        ]) + [
            'treatment' => $treatments[0]['context'] + ['others' => array_slice($treatments, 1)],
            'treatments' => $treatments, 'medicines' => $medicines,
        ] + $base;
        // A resposta usou a seção de superdose da bula sem a pergunta ter sido reconhecida como urgência: a orientação de
        // socorro (Disque-Intoxicação/SAMU) entra mesmo assim.
        $result['urgency'] = $base['urgency'] ?? $this->urgencies->fromEvidence($result['evidences'] ?? []);
        $result['retrieval']['interpretation'] = $interpretation;
        // Verificação do perfil sempre registrada (auditoria), mesmo quando nada foi alertado.
        $result['retrieval']['treatment_check'] = [
            'consented' => $user->hasHealthConsent(),
            'medications' => (int) ($treatments[0]['context']['medications_count'] ?? 0),
            'interactions' => array_sum(array_map(fn (array $t): int => count($t['context']['interactions']), $treatments)),
            'allergy_alerts' => array_sum(array_map(fn (array $t): int => count($t['context']['allergy_alerts']), $treatments)),
            'alerted' => $facts !== null,
        ];
        $result['generation'] += ['conversation_turns' => count($conversation)] + ($result['urgency'] !== null ? ['urgency' => $result['urgency']['kinds']] : []);

        return $this->finish($user, $result, $started);
    }

    /**
     * Trechos da bula dos EUA para a pergunta: efeitos adversos de cada remédio; interação de um par só com trechos que
     * citam o outro remédio; interação de um remédio só (sem o outro dito), os primeiros trechos da seção.
     *
     * @param  list<AnvisaMedicine>  $medicines
     * @return list<EvidenceReference>
     */
    private function foreignEvidence(array $medicines, string $intent): array
    {
        $ids = fn (AnvisaMedicine $medicine): array => $medicine->ingredients->pluck('id')->map(fn ($id): int => (int) $id)->all();
        if ($intent === 'ADVERSE_EFFECT') {
            return $this->external->adverseReactions(array_merge(...array_map($ids, $medicines)));
        }

        return count($medicines) >= 2 ? $this->external->forPair($ids($medicines[0]), $ids($medicines[1])) : $this->external->interactions($ids($medicines[0]));
    }

    /**
     * Trocas anteriores no formato do intérprete, com os nomes dos remédios de cada uma (a conversa guarda só os ids).
     *
     * @param  list<array<string, mixed>>  $conversation
     * @return list<array{question: string, answer: string|null, medicines: list<string>}>
     */
    private function interpretationTurns(array $conversation): array
    {
        $ids = array_values(array_unique(array_merge(...array_map(fn (array $turn): array => array_map('intval', (array) ($turn['medicine_ids'] ?? [])), $conversation ?: [[]]))));
        $names = $ids === [] ? collect() : AnvisaMedicine::query()->whereIn('id', $ids)->pluck('product_name', 'id');

        return array_map(fn (array $turn): array => [
            'question' => (string) ($turn['question'] ?? ''),
            'answer' => isset($turn['answer']) ? (string) $turn['answer'] : null,
            'medicines' => array_values(array_filter(array_map(fn ($id): ?string => $names[(int) $id] ?? null, (array) ($turn['medicine_ids'] ?? [])))),
        ], $conversation);
    }

    private function normalizerText(string $text): string
    {
        return ' '.trim((string) preg_replace('/[^a-z0-9]+/', ' ', Str::lower(Str::ascii($text)))).' ';
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
     * Fatos determinísticos: alertas do tratamento de cada medicamento citado e, entre os citados, a interação registrada na base.
     *
     * O perfil (remédios em uso e alergias) é sempre verificado, mas só vira fato para o modelo quando há achado: interação
     * registrada com um remédio em uso, duplicidade ou alergia. Sem achado, a resposta não fala do tratamento. Entre os remédios
     * citados na própria pergunta, o resultado da base entra sempre, porque é a combinação que a pessoa perguntou.
     *
     * @param  list<AnvisaMedicine>  $medicines
     * @param  list<array{medicine: string, context: array<string, mixed>}>  $treatments
     */
    private function freeformFacts(array $medicines, array $treatments, bool $withPosology = false): ?string
    {
        $lines = [];
        foreach ($treatments as $treatment) {
            $text = $this->treatment->alertsText($treatment['context'], $treatment['medicine'], $withPosology);
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
        // Com a interpretação da IA, a pergunta completa ("Como usar o Berotec em gotas?") orienta tudo daqui em diante e a
        // intenção apontada vem primeiro; o assunto da troca anterior não é herdado (a reescrita já carrega o contexto).
        $interpretation = is_array($context['interpretation'] ?? null) ? $context['interpretation'] : null;
        $asked = $question;
        $question = $interpretation['question'] ?? $question;
        $preferred = QuestionIntentDetector::INTENTS[$interpretation['intent'] ?? ''] ?? null;
        $plan = $this->intents->plan($question, count($medicines), $interpretation !== null ? [] : (array) ($context['previous_topics'] ?? []), $preferred);
        $topics = $plan['topics'];
        $sections = $plan['sections'];
        $canonical = $this->intents->canonical($topics, ! empty($context['follow_up']) || $plan['inherited'] || ! empty($interpretation['follow_up']), ! empty($context['urgency']));
        if (($interpretation['intent'] ?? null) !== null && empty($context['urgency'])) {
            $canonical = ['primary' => $interpretation['intent'], 'intents' => array_values(array_unique([$interpretation['intent'], ...$canonical['intents']]))];
        }
        $classification = $canonical + [
            'topics' => $topics,
            'symptom' => $this->intents->symptom($question),
            'presentation' => $this->intents->presentation($question) ?? $interpretation['presentation'] ?? null,
            'unnamed_other_medicine' => count($medicines) === 1 && $this->intents->mentionsUnnamedMedicine($question),
            'combination' => count($medicines) === 1 ? $this->intents->partialCombination($question, $medicines[0]->loadMissing('ingredients')->ingredients->pluck('canonical_name')->map(fn ($name): string => (string) $name)->all()) : null,
            'conversation_used' => $conversation !== null,
            'medicine_switched' => ! empty($context['medicine_switched']),
        ];
        $unidentified = [];
        $withoutLeaflet = [];
        $streams = [];
        $coverage = [];
        $sectionMatched = false;
        $primaryMatched = false;
        $retrieval = ['mode' => $mode, 'duration_ms' => 0, 'per_medicine' => [], 'intent' => $topics, 'sections' => $sections, 'ambiguous' => $plan['ambiguous'], 'inherited_intent' => $plan['inherited'], 'section_matched' => false, 'leaflet_fallback' => null, 'classification' => $classification];
        // O usuário pergunta pelo nome comercial; as bulas falam pelo princípio ativo. Os nomes dos ativos citados
        // entram na consulta de recuperação para que a bula de um cite o outro.
        $ingredientNames = collect($medicines)->flatMap(fn (AnvisaMedicine $m) => $m->ingredients->pluck('normalized_name'))->unique()->values()->all();
        $names = implode(' ', $ingredientNames);
        // Pergunta de acompanhamento ("mas por quê?"): a busca usa também a pergunta anterior, que diz o assunto.
        $subject = trim($question.' '.(! empty($context['follow_up']) && $interpretation === null ? (string) ($context['previous_question'] ?? '') : ''));

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
            $documentIds = $this->presentationFirst($this->patientFirst($found['document_ids']), $classification['presentation']);
            $coverage[] = ['medicine' => $medicine, 'documents' => $documentIds];

            // 1. Evidências já extraídas da bula inteira para este assunto (perguntas anteriores, mesma versão do documento).
            $stored = $this->evidence->stored($documentIds, array_values(array_unique([...$topics, ...$sections])), $question);
            if ($stored !== []) {
                $streams[] = $stored;
            }
            // 2. Cada seção do plano tem a sua busca (em palavras de bula, não a pergunta inteira): seção do assunto na bula do
            // paciente, depois em todas as bulas, depois sem restrição de seção (os rótulos de seção das bulas nem sempre são bons).
            $perMedicine = ['medicine' => $medicine->product_name, 'documents' => count($documentIds), 'stored_facts' => count($stored), 'found_by' => $found['method'], 'linked_now' => $found['linked_now'], 'sections' => []];
            foreach ($sections as $position => $section) {
                $text = trim($this->intents->sectionQuery($section).' '.$names.' '.($plan['ambiguous'] ? $subject : $this->intents->searchTerms($subject)));
                // A seção da intenção principal vem primeiro na intercalação e traz um trecho a mais que as demais.
                [$result, $matched] = $this->retrieve($text, $mode, $position === 0 ? $perSection + 1 : $perSection, $documentIds, [$section]);
                $retrieval['duration_ms'] += $result->durationMs;
                $retrieval += $result->configuration;
                $sectionMatched = $sectionMatched || $matched;
                $primaryMatched = $primaryMatched || ($position === 0 && $matched);
                $perMedicine['sections'][$section] = ['references' => count($result->references), 'section_label_matched' => $matched];
                if ($result->references !== []) {
                    $streams[] = $result->references;
                }
            }
            $perMedicine['references'] = array_sum(array_column($perMedicine['sections'], 'references'));
            $retrieval['per_medicine'][] = $perMedicine;
            $sectionMatched = $sectionMatched || $stored !== [];
            $primaryMatched = $primaryMatched || $stored !== [];
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
        // Sem bula brasileira de nenhum deles: só interações e efeitos adversos seguem, para a bula dos EUA (nível 2b); sem ela
        // também, a resposta continua sendo "ainda não há bula".
        $noLeaflet = count($unidentified) + count($withoutLeaflet) === count($medicines);
        $foreignOnly = $noLeaflet && $withoutLeaflet !== [] && in_array($canonical['primary'], ['ADVERSE_EFFECT', 'DRUG_INTERACTION'], true) && $this->external->enabled();
        if ($noLeaflet && ! $foreignOnly) {
            return ['status' => self::NO_LEAFLET, 'limitations' => $limitations] + $empty;
        }

        $references = $this->interleave($streams);
        $retrieval['section_matched'] = $sectionMatched;
        $retrieval['sufficiency'] = ['primary_section_found' => $primaryMatched || $plan['ambiguous'], 'references' => count($references), 'verdict' => 'pending', 'fallback' => 'none'];
        $label = implode(' + ', array_map(fn (AnvisaMedicine $m): string => $m->product_name, $medicines));
        $extra = ['limitations' => $limitations, 'missing' => $empty['missing'], 'intent' => $topics];
        // Tamanho e tom pela intenção PRINCIPAL: "serve para dor de cabeça?" pede 1 a 3 frases, sem perguntas e sem ressalvas;
        // interação e uso pessoal pedem mais. A pergunta geral tem o seu próprio orçamento.
        $primary = $classification['primary'];
        $byIntent = (array) config('bulai.questions.conversation.fact_words_by_intent');
        $factWords = $plan['ambiguous']
            ? (int) config('bulai.questions.conversation.fact_words_general')
            : (int) ($byIntent[$primary] ?? config('bulai.questions.conversation.max_fact_words'));
        $guides = (array) config('bulai.questions.response_guides');
        $genContext = ['conversation' => $conversation, 'topics' => $plan['labels'], 'ambiguous' => $plan['ambiguous'], 'urgency' => ! empty($context['urgency']), 'fact_words' => $factWords,
            'primary_intent' => $primary,
            'guides' => array_values(array_filter(array_map(fn (string $intent): ?string => isset($guides[$intent]) ? (string) $guides[$intent] : null, $classification['intents']))),
            'presentation' => $classification['presentation'],
            'unnamed_other_medicine' => $classification['unnamed_other_medicine'],
            'combination' => $classification['combination'],
            'asked_question' => $asked !== $question ? $asked : null,
            // Marca e princípio ativo são o mesmo assunto: "a Novalgina (dipirona) é analgésico" ancora num trecho da bula de dipirona.
            'subject_terms' => collect($medicines)->flatMap(fn (AnvisaMedicine $m) => $m->ingredients->pluck('canonical_name'))->filter()->unique()->implode(' '),
            'allow_asks' => $classification['unnamed_other_medicine'] || ! in_array($primary, (array) config('bulai.questions.conversation.no_ask_intents'), true),
            'drop_limits_when_full' => in_array($primary, (array) config('bulai.questions.conversation.no_limit_intents'), true)];

        // 3. Primeira geração com o que a busca trouxe; é o modelo quem julga se as evidências cobrem a pergunta. Uma reformulação
        // controlada (nível 1) acontece dentro do gerador quando a validação reprova tudo. Sem nenhum trecho da seção da intenção
        // principal (critério determinístico de suficiência), a bula inteira é consultada antes, em vez de gastar uma geração com
        // trechos de outro assunto.
        $best = null;
        $last = null;
        // Urgência e "outro remédio" sem nome não esperam a bula inteira: a orientação e a pergunta vêm primeiro.
        $readWholeFirst = ! $retrieval['sufficiency']['primary_section_found'] && empty($context['urgency']) && ! $classification['unnamed_other_medicine'];
        if ($references !== [] && ! $readWholeFirst) {
            $last = $this->generate($label, $question, $references, $facts, $retrieval, $genContext);
            $best = $this->usable($last) ? $last : null;
            if ($best !== null && $last['coverage'] === 'full' && $last['status'] === self::ANSWERED) {
                $last['retrieval']['sufficiency']['verdict'] = 'sufficient';

                return $last + $extra;
            }
        }

        // 4. Nível 2: a bula inteira de cada medicamento, na seção compatível com a intenção, quando a cobertura não é completa
        // (inclusive sem nenhum fato). Se o texto indexado não bastar e o índice estiver incompleto ou com extração ruim, o PDF
        // original é relido. A segunda geração só substitui a primeira se for melhor.
        $fallback = $this->consultWholeLeaflets($coverage, $question, $topics !== [] ? $topics : $sections, $plan['labels'][0] ?? '');
        $retrieval['leaflet_fallback'] = $fallback['extraction'];
        $retrieval['sufficiency']['fallback'] = $fallback['consulted'] ? (($fallback['extraction']['original_pdf'] ?? false) ? 'original_pdf' : 'leaflet_text') : 'none';
        if ($fallback['references'] === [] && $last === null && $references !== []) {
            // A bula inteira não acrescentou nada: os trechos encontrados, mesmo de outra seção, ainda podem responder.
            $last = $this->generate($label, $question, $references, $facts, $retrieval, $genContext);
            $best = $this->usable($last) ? $last : null;
        }
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

        // Nível 2b: bula dos EUA (FDA, via openFDA/DailyMed), traduzida, como complemento. Só para interações e efeitos
        // adversos, em que as bulas brasileiras costumam ser deficientes, e só quando elas não cobriram a pergunta por inteiro.
        if (in_array($primary, ['ADVERSE_EFFECT', 'DRUG_INTERACTION'], true) && ($best === null || ($best['coverage'] ?? 'none') !== 'full') && $this->external->enabled()) {
            $foreign = $this->foreignEvidence($medicines, $primary);
            $retrieval['external'] = ['consulted' => true, 'references' => count($foreign)];
            if ($foreign !== []) {
                $third = $this->generate($label, $question, [...$foreign, ...array_slice($references, 0, self::MAX_EVIDENCES - count($foreign))], $facts, $retrieval, $genContext);
                $last = $third;
                if ($this->usable($third) && ($best === null || $this->rank($third) > $this->rank($best))) {
                    $best = $third;
                }
            }
        }

        // Nível 3: o que há é parcial (fatos com cobertura parcial, ou só o que perguntar ou não confirmar): a conversa continua com isso.
        if ($best !== null) {
            $best['retrieval'] = $retrieval;
            $best['retrieval']['sufficiency']['verdict'] = match (true) {
                $best['status'] === self::ANSWERED && $best['coverage'] === 'full' => 'sufficient',
                $best['status'] === self::ANSWERED => 'partial',
                default => 'insufficient',
            };

            return $best + $extra;
        }
        $retrieval['sufficiency']['verdict'] = 'insufficient';
        if ($foreignOnly) {
            return ['status' => self::NO_LEAFLET, 'limitations' => $limitations, 'retrieval' => $retrieval] + $empty;
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
            $extraction['original_pdf'] = ($extraction['original_pdf'] ?? false) || ! empty($result['extraction']['original_pdf']);
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

    /**
     * Pergunta sobre uma apresentação ("quantas gotas…"): as bulas cujo título cita essa apresentação vêm primeiro, mantendo a
     * ordem relativa (bula do paciente antes). Sem apresentação na pergunta, a ordem não muda.
     *
     * @param  list<int>  $documentIds
     * @return list<int>
     */
    private function presentationFirst(array $documentIds, ?string $presentation): array
    {
        if ($presentation === null || count($documentIds) < 2) {
            return $documentIds;
        }
        $needle = Str::lower(Str::ascii($presentation));
        $titles = DB::table('knowledge_documents')->whereIn('id', $documentIds)->pluck('title', 'id');
        $matching = array_values(array_filter($documentIds, fn (int $id): bool => str_contains(Str::lower(Str::ascii((string) ($titles[$id] ?? ''))), $needle)));

        return [...$matching, ...array_values(array_diff($documentIds, $matching))];
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
