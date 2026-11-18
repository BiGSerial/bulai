<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\PatientPrescription;
use App\Models\User;
use App\Services\Patient\PrescriptionReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Receita por foto no aplicativo nativo, com as mesmas regras das telas do site (PrescriptionReviewService): envio da foto,
 * acompanhamento da leitura, horários sugeridos ao corrigir "quando tomar", confirmação e descarte.
 */
final class PrescriptionController extends Controller
{
    public function store(Request $request, PrescriptionReviewService $service): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        if (! $user->hasHealthConsent()) {
            return response()->json(['message' => 'Para ler uma receita, aceite primeiro guardar seus dados de saúde no perfil.', 'code' => 'health_consent_required'], 409);
        }
        $request->validate(['photo' => PrescriptionReviewService::photoRules()], [], ['photo' => 'foto']);
        if (! PrescriptionReviewService::attempt($user)) {
            return response()->json(['message' => 'Você atingiu o limite de receitas lidas por hora. Tente novamente mais tarde.'], 429);
        }

        $photo = $request->file('photo');
        $prescription = $service->start($user, $photo->getRealPath(), $photo->getClientOriginalExtension() ?: ($photo->guessExtension() ?? 'jpg'));

        return response()->json(['prescription' => $this->prescription($prescription, $service)], 201);
    }

    /** O aplicativo consulta a cada poucos segundos enquanto `reading` for verdadeiro. */
    public function show(Request $request, PatientPrescription $prescription, PrescriptionReviewService $service): JsonResponse
    {
        $this->authorizeOwner($request, $prescription);

        return response()->json(['prescription' => $this->prescription($service->expireIfStale($prescription), $service)])->header('Cache-Control', 'no-store');
    }

    /** Horários sugeridos para um novo "quando tomar" (o mesmo intérprete do site). */
    public function schedule(Request $request, PrescriptionReviewService $service): JsonResponse
    {
        $data = $request->validate(['frequency' => ['nullable', 'string', 'max:120']]);

        return response()->json(['schedule' => $service->suggestedSchedule($data['frequency'] ?? null)]);
    }

    public function confirm(Request $request, PatientPrescription $prescription, PrescriptionReviewService $service): JsonResponse
    {
        $this->authorizeOwner($request, $prescription);
        if ($prescription->status !== PatientPrescription::STATUS_REVIEW) {
            return response()->json(['message' => 'Esta receita não está aguardando conferência.', 'code' => 'not_in_review'], 409);
        }
        $data = $request->validate(['header' => ['required', 'array'], 'items' => ['present', 'array']]);
        $result = $service->confirm($request->user(), $prescription, $data['header'], array_values($data['items']));

        return response()->json($result);
    }

    public function destroy(Request $request, PatientPrescription $prescription): JsonResponse
    {
        $this->authorizeOwner($request, $prescription);
        abort_if($prescription->status === PatientPrescription::STATUS_CONFIRMED, 409, 'Receita já confirmada: remova pelo perfil.');
        $prescription->delete();

        return response()->json(['message' => 'Receita descartada. Nada foi salvo.']);
    }

    private function authorizeOwner(Request $request, PatientPrescription $prescription): void
    {
        abort_unless($prescription->user_id === $request->user()->id, 404);
    }

    /** @return array<string, mixed> */
    private function prescription(PatientPrescription $prescription, PrescriptionReviewService $service): array
    {
        $review = $prescription->status === PatientPrescription::STATUS_REVIEW;

        return [
            'id' => $prescription->id,
            'status' => $prescription->status,
            'reading' => PrescriptionReviewService::isReading($prescription),
            'error_code' => $prescription->status === PatientPrescription::STATUS_FAILED ? $prescription->error_code : null,
            'error_message' => $prescription->status === PatientPrescription::STATUS_FAILED ? PrescriptionReviewService::failureMessage($prescription->error_code) : null,
            'legibility' => $prescription->extraction['legibility'] ?? null,
            'ignored_text' => ($prescription->extraction['injection'] ?? []) !== [],
            'header' => $review ? $service->header($prescription) : null,
            'items' => $review ? array_map(fn (array $item): array => ['candidates' => array_map(
                fn (array $c): array => ['id' => (int) $c['id'], 'name' => (string) $c['name'], 'ingredients' => (string) ($c['ingredients'] ?? '')],
                array_slice($item['candidates'], 0, 3),
            )] + $item, $service->items($prescription)) : [],
        ];
    }
}
