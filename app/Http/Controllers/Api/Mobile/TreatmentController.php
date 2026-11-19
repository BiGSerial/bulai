<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\MedicationDoseEvent;
use App\Models\PatientMedication;
use App\Services\Treatment\PatientDayService;
use App\Services\Treatment\TreatmentScheduleService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Telas "Início" e "Remédios do dia" do aplicativo nativo: cartão do dia, linha do tempo de ontem/hoje/amanhã e as
 * marcações Tomei, Pulei e Desfazer feitas na tela (cada ação devolve o dia atualizado, para a tela se redesenhar).
 */
final class TreatmentController extends Controller
{
    public function home(Request $request, PatientDayService $screens): JsonResponse
    {
        return response()->json($screens->home($request->user()))->header('Cache-Control', 'no-store');
    }

    public function day(Request $request, PatientDayService $screens): JsonResponse
    {
        $data = $request->validate(['dia' => ['nullable', 'integer', 'between:'.PatientDayService::MIN_OFFSET.','.PatientDayService::MAX_OFFSET]]);

        return response()->json($screens->day($request->user(), (int) ($data['dia'] ?? 0)))->header('Cache-Control', 'no-store');
    }

    public function mark(Request $request, TreatmentScheduleService $schedule, PatientDayService $screens): JsonResponse
    {
        $data = $this->validateDose($request, ['status' => ['required', 'in:taken,skipped']]);
        $user = $request->user();
        $medication = PatientMedication::query()->where('user_id', $user->id)->findOrFail((int) $data['medication_id']);
        $status = $data['status'] === 'skipped' ? MedicationDoseEvent::STATUS_SKIPPED : MedicationDoseEvent::STATUS_TAKEN;
        $schedule->mark($user, $medication, CarbonImmutable::parse($data['scheduled_for'])->setTimezone($user->treatmentTimezone()), $status);

        return response()->json($screens->day($user, (int) ($data['dia'] ?? 0)))->header('Cache-Control', 'no-store');
    }

    /** "Só quando precisar": anota que tomou agora; desfazer usa o DELETE de /dia/doses com o horário devolvido. */
    public function takeAsNeeded(Request $request, TreatmentScheduleService $schedule, PatientDayService $screens): JsonResponse
    {
        $data = $request->validate(['medication_id' => ['required', 'integer']]);
        $user = $request->user();
        $medication = PatientMedication::query()->where('user_id', $user->id)->where('as_needed', true)->findOrFail((int) $data['medication_id']);
        $schedule->takeAsNeeded($user, $medication);

        return response()->json($screens->day($user))->header('Cache-Control', 'no-store');
    }

    public function unmark(Request $request, TreatmentScheduleService $schedule, PatientDayService $screens): JsonResponse
    {
        $data = $this->validateDose($request);
        $user = $request->user();
        $medication = PatientMedication::query()->where('user_id', $user->id)->findOrFail((int) $data['medication_id']);
        $schedule->unmark($user, $medication, CarbonImmutable::parse($data['scheduled_for'])->setTimezone($user->treatmentTimezone()));

        return response()->json($screens->day($user, (int) ($data['dia'] ?? 0)))->header('Cache-Control', 'no-store');
    }

    /**
     * @param  array<string, list<string>>  $extra
     * @return array<string, mixed>
     */
    private function validateDose(Request $request, array $extra = []): array
    {
        return $request->validate($extra + [
            'medication_id' => ['required', 'integer'],
            'scheduled_for' => ['required', 'date'],
            'dia' => ['nullable', 'integer', 'between:'.PatientDayService::MIN_OFFSET.','.PatientDayService::MAX_OFFSET],
        ]);
    }
}
