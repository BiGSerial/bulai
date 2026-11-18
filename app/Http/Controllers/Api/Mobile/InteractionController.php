<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Consultation\InteractionConsultation;
use App\Models\Consultation\InteractionPairResult;
use App\Models\Knowledge\AnvisaMedicine;
use App\Models\PatientMedication;
use App\Models\User;
use App\Models\UserAuditEvent;
use App\Services\Interactions\InteractionConsultationService;
use App\Services\Interactions\InteractionRequestException;
use App\Services\Interactions\InteractionResultPresenter;
use App\Services\Leaflet\LeafletCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Interações e lista de bulas no aplicativo nativo, com as regras do site: os remédios em uso já entram na lista; a
 * consulta respeita o limite por hora; o resultado traz, por par, a mesma faixa e frase do site
 * (`InteractionResultPresenter::band`) e é acompanhado por consulta repetida (`GET /interacoes/{uuid}`) até terminar.
 */
final class InteractionController extends Controller
{
    public function __construct(private readonly InteractionResultPresenter $presenter) {}

    /** Ponto de partida: os remédios em uso (com alerta de alergia), o máximo por consulta e as consultas anteriores. */
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $max = (int) config('bulai.consultation.max_medicines');
        $mine = $user->hasHealthConsent()
            ? PatientMedication::query()->matched()->where('user_id', $user->id)
                ->where(fn ($query) => $query->whereNull('ends_at')->orWhereDate('ends_at', '>=', today()))
                ->orderBy('id')->pluck('medicine_id')->unique()->take($max)->map(fn ($id): int => (int) $id)->values()->all()
            : [];

        return response()->json([
            'mine' => $this->items($user, $mine),
            'max' => $max,
            'history' => InteractionConsultation::query()->where('user_id', $user->id)->withPairCount()->latest()->limit(30)->get()
                ->map(fn (InteractionConsultation $consultation): array => [
                    'uuid' => $consultation->uuid,
                    'status' => $consultation->status,
                    'medicines' => array_column($consultation->medicines, 'name'),
                    'pair_count' => (int) $consultation->pair_count,
                    'created_at' => $consultation->created_at->toIso8601String(),
                ])->all(),
        ])->header('Cache-Control', 'no-store');
    }

    /**
     * Um remédio escolhido na busca, com o alerta de alergia. `selected` (ids já na lista) faz o servidor recusar o
     * repetido e o que passa do máximo (422 com a frase).
     */
    public function medicine(Request $request, int $medicine): JsonResponse
    {
        abort_unless(AnvisaMedicine::query()->whereKey($medicine)->exists(), 404, 'Medicamento não encontrado no catálogo.');
        $selected = array_values(array_filter(array_map('intval', explode(',', (string) $request->query('selected', '')))));
        $refusal = $this->presenter->additionRefusal($selected, $medicine);
        if ($refusal !== null) {
            return response()->json(['message' => $refusal, 'code' => 'not_added'], 422);
        }

        return response()->json($this->items($request->user(), [$medicine])[0]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['medicine_ids' => ['required', 'array', 'min:2'], 'medicine_ids.*' => ['integer']], ['medicine_ids.min' => 'Selecione pelo menos dois medicamentos.']);
        try {
            $consultation = $this->presenter->start($request->user(), $data['medicine_ids']);
        } catch (InteractionRequestException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'code' => $exception->limited ? 'rate_limited' : 'invalid'], $exception->limited ? 429 : 422);
        }

        return response()->json($this->consultation($request->user(), $consultation), 201);
    }

    public function show(Request $request, InteractionConsultationService $service, string $uuid): JsonResponse
    {
        $consultation = $service->failStale($this->own($request, $uuid));

        return response()->json($this->consultation($request->user(), $consultation))->header('Cache-Control', 'no-store');
    }

    public function repeat(Request $request, InteractionConsultationService $service, string $uuid): JsonResponse
    {
        $consultation = $service->start($request->user(), array_column($this->own($request, $uuid)->medicines, 'id'));

        return response()->json($this->consultation($request->user(), $consultation), 201);
    }

    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $deleted = InteractionConsultation::query()->where('user_id', $request->user()->id)->where('uuid', $uuid)->delete();
        abort_if($deleted === 0, 404);
        UserAuditEvent::query()->create(['user_id' => $request->user()->id, 'actor_id' => $request->user()->id, 'event' => 'consultations_deleted', 'details' => ['count' => $deleted], 'ip' => $request->ip()]);

        return response()->json(['message' => 'Consulta apagada.']);
    }

    /** Lista de bulas simples: as prontas (filtradas no aparelho) e, com 3 letras ou mais, a busca no catálogo. */
    public function leaflets(Request $request, LeafletCatalog $catalog): JsonResponse
    {
        $term = trim((string) $request->query('q', ''));
        $searched = mb_strlen($term) >= 3;
        $limited = $searched && RateLimiter::tooManyAttempts('busca:'.$request->user()->id, (int) config('bulai.consultation.search_limit_per_minute'));
        if ($searched && ! $limited) {
            RateLimiter::hit('busca:'.$request->user()->id, 60);
        }

        return response()->json([
            'available' => $searched ? [] : $catalog->available(),
            'results' => $searched && ! $limited ? array_map(fn (array $item): array => [
                'id' => $item['id'], 'name' => $item['name'], 'ingredients' => $item['ingredients'], 'has_leaflet' => $item['tem_bula'],
            ], $catalog->search($term)) : [],
            'limited' => $limited,
        ]);
    }

    private function own(Request $request, string $uuid): InteractionConsultation
    {
        return InteractionConsultation::query()->where('user_id', $request->user()->id)->where('uuid', $uuid)->firstOrFail();
    }

    /**
     * @param  list<int>  $ids
     * @return list<array{id: int, name: string, ingredients: string, identified: bool, alerts: list<string>}>
     */
    private function items(User $user, array $ids): array
    {
        $alerts = collect($this->presenter->allergyAlerts($user, $ids))->groupBy('medicine_id');
        $medicines = AnvisaMedicine::query()->with('ingredients:id')->whereIn('id', $ids)->get()->keyBy('id');

        return collect($ids)->filter(fn (int $id): bool => $medicines->has($id))->map(fn (int $id): array => [
            'id' => $id,
            'name' => $medicines[$id]->product_name,
            'ingredients' => (string) ($medicines[$id]->active_ingredient ?: 'Princípio ativo não informado'),
            'identified' => $medicines[$id]->ingredients->isNotEmpty(),
            'alerts' => ($alerts[$id] ?? collect())->pluck('message')->values()->all(),
        ])->values()->all();
    }

    /** @return array<string, mixed> */
    private function consultation(User $user, InteractionConsultation $consultation): array
    {
        $pairs = $consultation->results()->get();
        $count = count($consultation->medicines);

        return [
            'uuid' => $consultation->uuid,
            'status' => $consultation->status,
            'running' => ! $consultation->isFinished(),
            'waiting' => $consultation->status === InteractionConsultation::PENDING,
            'failed' => $consultation->status === InteractionConsultation::FAILED,
            'failure' => $consultation->status === InteractionConsultation::FAILED
                ? ($consultation->error_code === 'CONSULTATION_TIMEOUT' ? 'A análise demorou mais que o esperado e foi interrompida.' : 'Não foi possível concluir a análise.')
                : null,
            'medicines' => array_column($consultation->medicines, 'name'),
            'total' => max(0, intdiv($count * ($count - 1), 2)),
            'done' => $pairs->count(),
            'allergy_alerts' => array_map(fn (array $alert): array => [
                'medicine' => $alert['medicine'],
                'text' => "Você registrou alergia a {$alert['allergy']}; este remédio ".($alert['via'] === 'classe' ? 'pertence à mesma classe de' : 'contém')." {$alert['ingredient']}. Fale com seu médico ou farmacêutico antes de usar.",
            ], $this->presenter->allergyAlerts($user, array_column($consultation->medicines, 'id'))),
            'pairs' => $pairs->map(fn (InteractionPairResult $result): array => $this->presenter->band($result) + [
                'id' => $result->id,
                'label_a' => $result->label_a,
                'label_b' => $result->label_b,
                'badge' => $result->severity !== null
                    ? ($result->severity->value === 'unknown' ? $result->severity->label() : 'Gravidade: '.$result->severity->label())
                    : $result->verdict?->label(),
                'explanation' => $result->explanation,
                'evidences' => collect($result->evidences ?? [])->map(fn (array $evidence): array => [
                    'title' => $evidence['document_title'] ?? 'Bula',
                    'section' => $evidence['section'] ?? 'seção não identificada',
                    'page' => $evidence['page_start'] ?? null,
                    'excerpt' => $result->explanation ? null : ($evidence['excerpt'] ?? null),
                ])->values()->all(),
                'limitations' => array_values((array) ($result->limitations ?? [])),
                'sources' => $result->structured ? collect($result->structured['provenance'] ?? [])->map(fn ($item) => $item['source'] ?? null)->filter()->unique()->join('; ') : null,
            ])->values()->all(),
        ];
    }
}
