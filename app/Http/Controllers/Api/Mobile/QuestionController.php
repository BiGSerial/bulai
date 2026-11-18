<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Consultation\MedicationQuestion;
use App\Models\User;
use App\Models\UserAuditEvent;
use App\Services\Patient\MedicationQuestionService;
use App\Services\Patient\QuestionConversation;
use App\Services\Patient\TreatmentContextService;
use App\Services\Safety\GuardVerdict;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * "Perguntar" no aplicativo nativo: a mesma conversa do site (QuestionConversation). O aplicativo guarda só os `uuid` das
 * trocas anteriores; o contexto (pergunta, resposta, remédios, assuntos) é relido do histórico da própria pessoa, então
 * nada do que o aparelho manda vira "resposta anterior" para o modelo. Também o histórico de perguntas (ver e apagar).
 */
final class QuestionController extends Controller
{
    /** Primeira mensagem da conversa: exemplos, remédios em uso e, vindo de um remédio, o painel "No seu tratamento". */
    public function start(Request $request, QuestionConversation $conversation): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $medicineId = $request->integer('medicamento');
        $preview = $medicineId > 0 ? $conversation->preview($user, $medicineId) : null;

        return response()->json([
            'examples' => QuestionConversation::EXAMPLES,
            'in_use' => $conversation->inUse($user),
            'min_length' => (int) config('bulai.guard.min_length'),
            'max_length' => (int) config('bulai.guard.max_length'),
            'retention_days' => (int) config('bulai.questions.retention_days'),
            'preview' => $preview === null ? null : ['medicine_id' => $medicineId] + TreatmentContextService::panel($preview),
        ]);
    }

    public function ask(Request $request, QuestionConversation $conversation): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate([
            'question' => ['required', 'string', 'max:'.(int) config('bulai.guard.max_length')],
            'fixed' => ['array', 'max:5'],
            'fixed.*' => ['integer'],
            'removed' => ['array', 'max:10'],
            'removed.*' => ['integer'],
            'turns' => ['array', 'max:'.QuestionConversation::MAX_TURNS],
            'turns.*' => ['uuid'],
        ], [], ['question' => 'pergunta']);

        $question = trim($data['question']);
        if (mb_strlen($question) < (int) config('bulai.guard.min_length')) {
            throw ValidationException::withMessages(['question' => 'Escreva sua pergunta citando o nome do remédio.']);
        }
        if (! QuestionConversation::attempt($user)) {
            return response()->json(['message' => 'Você atingiu o limite de perguntas por hora. Tente novamente mais tarde.'], 429);
        }

        $answer = $conversation->answer($user, $question, array_map('intval', $data['fixed'] ?? []), $this->turns($user, $data['turns'] ?? []), array_map('intval', $data['removed'] ?? []));

        return response()->json(['answer' => $this->present($answer, $conversation->inUse($user))])->header('Cache-Control', 'no-store');
    }

    /** Histórico de perguntas da própria pessoa, das mais novas para as mais antigas. */
    public function history(Request $request): JsonResponse
    {
        $page = MedicationQuestion::query()->where('user_id', $request->user()->id)->latest()->latest('id')
            ->paginate(20, ['uuid', 'question', 'medicine_name', 'status', 'created_at']);

        return response()->json([
            'retention_days' => (int) config('bulai.questions.retention_days'),
            'items' => collect($page->items())->map(fn (MedicationQuestion $item): array => [
                'uuid' => $item->uuid,
                'question' => $item->question,
                'medicines' => $item->medicine_name === '—' ? null : $item->medicine_name,
                'status' => $item->status,
                'asked_at' => $item->created_at->toIso8601String(),
            ])->values()->all(),
            'next_page' => $page->hasMorePages() ? $page->currentPage() + 1 : null,
        ]);
    }

    /** Uma pergunta anterior, com a resposta, as fontes e o tratamento como estavam na época. */
    public function show(Request $request, MedicationQuestion $question): JsonResponse
    {
        abort_unless($question->user_id === $request->user()->id, 404);
        $answer = [
            'uuid' => $question->uuid,
            'pergunta' => $question->question,
            'status' => $question->status,
            'answer' => $question->answer,
            'evidences' => $question->evidences ?? [],
            'medicines' => $question->medicines ?? [],
            'guard' => $question->guard ?? [],
            'intent' => $question->retrieval['intent'] ?? [],
            'missing' => ['unidentified' => [], 'without_leaflet' => []],
            'generation' => $question->generation ?? [],
            'treatments' => $this->storedTreatments($question),
        ];
        if ($question->status === MedicationQuestion::STATUS_BLOCKED && ($answer['guard']['category'] ?? null) !== null) {
            $answer['message'] = (new GuardVerdict($answer['guard']['category'], ''))->userMessage();
        }

        return response()->json(['answer' => $this->present($answer, []) + ['asked_at' => $question->created_at->toIso8601String()]]);
    }

    public function destroy(Request $request, MedicationQuestion $question): JsonResponse
    {
        abort_unless($question->user_id === $request->user()->id, 404);
        $question->delete();
        UserAuditEvent::query()->create(['user_id' => $request->user()->id, 'actor_id' => $request->user()->id, 'event' => 'questions_deleted', 'details' => ['count' => 1], 'ip' => $request->ip()]);

        return response()->json(['deleted' => true]);
    }

    /**
     * Trocas anteriores relidas do histórico da própria pessoa, na ordem em que o aplicativo mandou.
     *
     * @param  list<string>  $uuids
     * @return list<array{question: string, status: string, answer: string|null, topics: list<string>, medicines: list<array{id: int, name: string}>, uuid: string|null}>
     */
    private function turns(User $user, array $uuids): array
    {
        if ($uuids === []) {
            return [];
        }
        $records = MedicationQuestion::query()->where('user_id', $user->id)->whereIn('uuid', $uuids)->get()->keyBy('uuid');

        return array_values(array_filter(array_map(function (string $uuid) use ($records): ?array {
            $record = $records[$uuid] ?? null;

            return $record === null ? null : QuestionConversation::turn([
                'pergunta' => $record->question,
                'status' => $record->status,
                'answer' => $record->answer,
                'intent' => $record->retrieval['intent'] ?? [],
                'medicines' => $record->medicines ?? [],
                'uuid' => $record->uuid,
            ]);
        }, $uuids)));
    }

    /** @return list<array{medicine: string, context: array<string, mixed>}> */
    private function storedTreatments(MedicationQuestion $question): array
    {
        $context = $question->treatment_context;
        if (! is_array($context) || ! array_key_exists('consented', $context)) {
            return [];
        }

        return [['medicine' => $question->medicines[0]['name'] ?? $question->medicine_name, 'context' => $context], ...($context['others'] ?? [])];
    }

    /**
     * O balão do BULA-IA no formato do aplicativo: as mesmas falas, alertas e fontes do site, com o texto em pedaços
     * (negrito ou não) em vez de HTML.
     *
     * @param  array<string, mixed>  $answer
     * @param  list<string>  $inUse
     * @return array<string, mixed>
     */
    private function present(array $answer, array $inUse): array
    {
        $status = (string) $answer['status'];
        $speech = QuestionConversation::speech($answer, $inUse);
        $alert = QuestionConversation::alert($answer);
        $missing = [...($answer['missing']['without_leaflet'] ?? []), ...($answer['missing']['unidentified'] ?? [])];
        $pick = fn (array $items): array => array_map(fn (array $m): array => ['id' => (int) $m['id'], 'name' => (string) $m['name']], array_slice($items, 0, 3));

        return [
            'uuid' => $answer['uuid'] ?? null,
            'question' => (string) ($answer['pergunta'] ?? ''),
            'status' => $status,
            'tone' => $speech['tone'],
            'urgency' => filled($speech['urgencia']) ? $speech['urgencia'] : null,
            'paragraphs' => QuestionConversation::paragraphSegments($speech['texto'], $answer, $alert),
            'alert' => $alert === null ? null : [
                'type' => $alert['tipo'],
                'items' => array_map(fn (array $item): array => ['text' => $item['texto'], 'severity' => $item['severidade'], 'severity_label' => TreatmentContextService::severityLabel($item['severidade'])], $alert['itens']),
                'warning' => $alert['popup'] === null ? null : html_entity_decode(strip_tags($alert['popup']), ENT_QUOTES | ENT_HTML5),
            ],
            'source' => $speech['fonte'],
            'can_listen' => in_array($status, [MedicationQuestionService::ANSWERED, MedicationQuestionService::NEEDS_INFO], true),
            'crisis' => $status === MedicationQuestionService::BLOCKED && ($answer['guard']['category'] ?? null) === GuardVerdict::HARM_RISK,
            'suggestions' => $speech['sugestoes'],
            'other_medicines' => $speech['remedios'],
            'guesses' => $status === MedicationQuestionService::NO_MEDICINE ? $pick($answer['guesses'] ?? []) : [],
            'my_medicines' => $status === MedicationQuestionService::NO_MEDICINE ? $pick($answer['suggestions'] ?? []) : [],
            'corrections' => array_values(array_map(
                fn (array $m): array => ['id' => (int) $m['id'], 'name' => (string) $m['name'], 'matched_text' => (string) ($m['matched_text'] ?? '')],
                array_filter($answer['medicines'] ?? [], fn (array $m): bool => ! empty($m['fuzzy'])),
            )),
            'medicines' => in_array($status, [MedicationQuestionService::BLOCKED, MedicationQuestionService::NO_MEDICINE], true) ? [] : array_map(
                fn (array $m): array => ['id' => (int) $m['id'], 'name' => (string) $m['name'], 'has_leaflet' => ! in_array($m['name'], $missing, true)],
                $answer['medicines'] ?? [],
            ),
            'sources' => QuestionConversation::sources($answer),
            'treatments' => array_map(fn (array $item): array => TreatmentContextService::panel($item), $answer['treatments'] ?? []),
            'releases_fixed' => QuestionConversation::releasesFixed($answer),
        ];
    }
}
