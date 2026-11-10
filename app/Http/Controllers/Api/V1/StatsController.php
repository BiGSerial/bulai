<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Knowledge\IngestionToken;
use App\Models\User;
use App\Services\Analytics\AiConsumptionService;
use App\Services\Analytics\AnalyticsService;
use App\Services\Analytics\KnowledgeInventoryService;
use App\Services\Evaluation\EvaluationAccount;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Estatísticas de uso e de consumo de IA (docs/api.md). Os mesmos números do painel Analytics, sem identificar pessoas:
 * usuários aparecem só pelo id interno.
 */
class StatsController extends Controller
{
    /** Quem é o token e qual conta as avaliações usam (para separar as perguntas de teste das reais). */
    public function me(Request $request): JsonResponse
    {
        /** @var IngestionToken $token */
        $token = $request->attributes->get('ingestion_token');
        $evaluationUserId = User::query()->where('email', EvaluationAccount::EMAIL)->value('id');

        return response()->json([
            'token' => ['id' => $token->id, 'name' => $token->name, 'scope' => $token->scope, 'expires_at' => $token->expires_at->toIso8601String()],
            'evaluation_user_id' => $evaluationUserId,
            'timezone' => config('app.timezone'),
        ]);
    }

    public function overview(Request $request, AnalyticsService $analytics): JsonResponse
    {
        [$from, $to] = $this->period($request);

        return response()->json($analytics->overview($from, $to));
    }

    public function aiUsage(Request $request, AiConsumptionService $consumption): JsonResponse
    {
        [$from, $to] = $this->period($request);
        $provider = $request->validate(['provider' => ['nullable', 'string', 'max:60']])['provider'] ?? null;
        $users = $consumption->byUser($from, $to, $provider, perPage: 100);

        return response()->json([
            'period' => ['from' => $from?->toIso8601String(), 'to' => $to?->toIso8601String()],
            'providers' => $consumption->byProvider($from, $to, $provider),
            'averages' => $consumption->averages($from, $to, $provider),
            'users' => $users->getCollection()->map(fn (object $row): array => [
                'user_id' => $row->user_id,
                'active_days' => $row->active_days,
                'last_used_at' => $row->last_used_at->toIso8601String(),
                ...array_diff_key((array) $row, array_flip(['user_id', 'name', 'email', 'active_days', 'last_used_at'])),
            ])->values()->all(),
            'users_total' => $users->total(),
        ]);
    }

    /** Tamanho da base de conhecimento deste ambiente: bulas, princípios ativos, catálogo, interações e bulas dos EUA. */
    public function knowledge(KnowledgeInventoryService $inventory): JsonResponse
    {
        return response()->json(['generated_at' => now()->toIso8601String()] + $inventory->inventory());
    }

    /**
     * `from`/`to` em AAAA-MM-DD (dia inteiro, fuso da aplicação). Sem `from`: últimos 30 dias.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable|null}
     */
    private function period(Request $request): array
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $from = isset($data['from']) ? CarbonImmutable::parse($data['from'])->startOfDay() : CarbonImmutable::now()->subDays(29)->startOfDay();
        $to = isset($data['to']) ? CarbonImmutable::parse($data['to'])->endOfDay() : null;

        return [$from, $to];
    }
}
