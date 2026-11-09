<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Ai\AiUsageAttribution;
use App\Services\Evaluation\ConversationEvaluator;
use App\Services\Evaluation\EvaluationAccount;
use App\Services\Patient\MedicationQuestionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Avaliação do Perguntar pela API: a pergunta passa pelo fluxo completo e real (detecção, recuperação, modelo, validação),
 * como paciente da conta de avaliação, e volta com a resposta, o rastreio e a pontuação heurística das cinco dimensões do
 * `evaluation:conversation`. Consome IA de verdade; o consumo fica atribuído à conta de avaliação, origem `api:evaluation`.
 */
class EvaluationController extends Controller
{
    private const EXPECTATIONS = ['facts', 'asks', 'limit', 'medicines', 'follow_up', 'no_raw', 'honest_absence'];

    public function ask(Request $request, MedicationQuestionService $questions, ConversationEvaluator $evaluator, EvaluationAccount $account): JsonResponse
    {
        $data = $request->validate([
            'question' => ['required', 'string', 'max:2000'],
            'scenario' => ['nullable', 'string', 'max:120'],
            'medicine_ids' => ['nullable', 'array', 'max:5'],
            'medicine_ids.*' => ['integer'],
            'conversation' => ['nullable', 'array', 'max:6'],
            'conversation.*.question' => ['required', 'string', 'max:2000'],
            'conversation.*.answer' => ['nullable', 'string', 'max:6000'],
            'conversation.*.medicine_ids' => ['nullable', 'array', 'max:5'],
            'conversation.*.medicine_ids.*' => ['integer'],
            'conversation.*.topics' => ['nullable', 'array', 'max:12'],
            'conversation.*.topics.*' => ['string', 'max:40'],
            'expects' => ['nullable', 'array:'.implode(',', self::EXPECTATIONS)],
            'expects.medicines' => ['nullable', 'integer', 'min:1', 'max:5'],
            ...array_fill_keys(array_map(fn (string $key): string => 'expects.'.$key, array_diff(self::EXPECTATIONS, ['medicines'])), ['nullable', 'boolean']),
        ]);
        $conversation = array_map(fn (array $turn): array => [
            'question' => $turn['question'],
            'answer' => $turn['answer'] ?? null,
            'medicine_ids' => array_map('intval', $turn['medicine_ids'] ?? []),
            'topics' => $turn['topics'] ?? [],
        ], $data['conversation'] ?? []);
        $expects = $data['expects'] ?? [];

        $user = $account->get();
        AiUsageAttribution::remember($user->id, 'api:evaluation');
        $started = microtime(true);
        $result = $questions->askFreeform($user, $data['question'], array_map('intval', $data['medicine_ids'] ?? []), $conversation);
        $turn = $evaluator->turn($data['scenario'] ?? 'api', $data['question'], count($conversation) + 1, $expects, $result, microtime(true) - $started);

        return response()->json([
            'uuid' => $result['uuid'],
            'status' => $result['status'],
            'answer' => $result['answer'],
            'message' => $result['message'],
            'urgency' => $result['urgency'] ?? null,
            'limitations' => $result['limitations'],
            'evaluation' => $turn + ['score' => $evaluator->score($turn)],
            'retrieval' => $result['retrieval'],
            'generation' => $result['generation'],
            // Para encadear o próximo turno da mesma conversa (campo `conversation` da próxima chamada).
            'next_conversation_turn' => ['question' => $data['question'], 'answer' => $result['answer'], 'medicine_ids' => array_column($result['medicines'], 'id'), 'topics' => $result['intent'] ?? []],
        ]);
    }
}
