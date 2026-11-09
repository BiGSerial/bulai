<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Consultation\MedicationQuestion;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Perguntas feitas ao Perguntar com o rastreio de cada resposta (classificação, suficiência, leitura da bula inteira ou do
 * PDF original, validação). Mesma exposição da tela Analytics › Perguntas: o autor aparece só pelo id interno.
 */
class QuestionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'status' => ['nullable', 'string', 'max:40'],
            'user_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = MedicationQuestion::query()
            ->when($data['from'] ?? null, fn ($query, string $from) => $query->where('created_at', '>=', CarbonImmutable::parse($from)->startOfDay()))
            ->when($data['to'] ?? null, fn ($query, string $to) => $query->where('created_at', '<=', CarbonImmutable::parse($to)->endOfDay()))
            ->when($data['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($data['user_id'] ?? null, fn ($query, int $userId) => $query->where('user_id', $userId))
            ->latest('id')
            ->paginate((int) ($data['per_page'] ?? 25));

        return response()->json([
            'data' => $page->getCollection()->map(fn (MedicationQuestion $question): array => $this->summary($question))->values()->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function show(string $uuid): JsonResponse
    {
        $question = MedicationQuestion::query()->where('uuid', $uuid)->firstOrFail();

        return response()->json($this->summary($question) + [
            'medicines' => $question->medicines,
            'guard' => $question->guard,
            'treatment_context' => $question->treatment_context,
            'generation' => $question->generation,
            'retrieval' => $question->retrieval,
            'pipeline' => $question->pipeline,
            'evidences' => $question->evidences,
        ]);
    }

    /** @return array<string, mixed> */
    private function summary(MedicationQuestion $question): array
    {
        $retrieval = $question->retrieval ?? [];
        $generation = $question->generation ?? [];

        return [
            'uuid' => $question->uuid,
            'created_at' => $question->created_at?->toIso8601String(),
            'user_id' => $question->user_id,
            'medicine_name' => $question->medicine_name,
            'question' => $question->question,
            'answer' => $question->answer,
            'status' => $question->status,
            'duration_ms' => $question->duration_ms,
            'primary_intent' => $retrieval['classification']['primary'] ?? null,
            'sufficiency' => $retrieval['sufficiency'] ?? null,
            'leaflet_fallback_used' => ! empty($retrieval['leaflet_fallback']),
            'generation_status' => $generation['status'] ?? null,
            'claims_accepted' => $generation['claims_accepted'] ?? null,
            'claims_rejected' => $generation['claims_rejected'] ?? null,
            'evidence_count' => count($question->evidences ?? []),
            'review_status' => $question->review_status,
        ];
    }
}
