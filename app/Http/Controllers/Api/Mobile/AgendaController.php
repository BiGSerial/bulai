<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\MedicationDoseEvent;
use App\Models\PatientMedication;
use App\Services\Treatment\DeviceAgendaService;
use App\Services\Treatment\TreatmentScheduleService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Agenda das doses para os alarmes do aparelho e marcação "Tomei"/"Pulei" feita pela notificação, alarme ou widget. */
final class AgendaController extends Controller
{
    /** Quanto uma marcação pode estar longe de agora: cobre o aparelho que ficou sem rede e envia depois. */
    private const MARK_WINDOW_HOURS = 48;

    public function index(Request $request, DeviceAgendaService $agenda): JsonResponse
    {
        return response()->json($agenda->payload($request->user()))->header('Cache-Control', 'no-store');
    }

    public function mark(Request $request, TreatmentScheduleService $schedule, DeviceAgendaService $agenda): JsonResponse
    {
        $data = $request->validate([
            'medication_id' => ['required', 'integer'],
            'scheduled_for' => ['required', 'date'],
            'status' => ['required', 'in:taken,skipped'],
            'recorded_at' => ['nullable', 'date'],
        ]);
        $user = $request->user();
        $now = CarbonImmutable::now();
        $scheduledFor = CarbonImmutable::parse($data['scheduled_for']);
        $recordedAt = isset($data['recorded_at']) ? CarbonImmutable::parse($data['recorded_at']) : $now;

        if ($scheduledFor->diffInHours($now, true) > self::MARK_WINDOW_HOURS) {
            throw ValidationException::withMessages(['scheduled_for' => 'Horário fora da janela de marcação.']);
        }
        if ($recordedAt->greaterThan($now->addMinutes(5)) || $recordedAt->lessThan($now->subHours(self::MARK_WINDOW_HOURS))) {
            throw ValidationException::withMessages(['recorded_at' => 'Horário da marcação inválido.']);
        }

        $medication = PatientMedication::query()->where('user_id', $user->id)->findOrFail((int) $data['medication_id']);
        $status = $data['status'] === 'skipped' ? MedicationDoseEvent::STATUS_SKIPPED : MedicationDoseEvent::STATUS_TAKEN;
        $schedule->mark($user, $medication, $scheduledFor->setTimezone($user->treatmentTimezone()), $status, $recordedAt);

        return response()->json($agenda->payload($user))->header('Cache-Control', 'no-store');
    }
}
