<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\CaregiverLink;
use App\Models\MedicationDoseEvent;
use App\Models\PatientMedication;
use App\Models\User;
use App\Services\Caregiving\CaregiverInviteException;
use App\Services\Caregiving\CaregiverLinkService;
use App\Services\Patient\MedicalSummaryService;
use App\Services\Treatment\PatientDayService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Modo cuidador e resumo em PDF no aplicativo nativo, com as mesmas regras das telas do site (CaregiverLinkService e
 * CaregiverLinkPolicy): o paciente gera o convite (o aplicativo mostra o QR Code com o link `/cuidar?codigo=`), aprova
 * com as permissões escolhidas, muda ou retira; quem cuida lê o QR (ou digita o código), vê e marca as doses de quem
 * acompanha conforme a permissão, e pode deixar de acompanhar.
 */
final class CaregiverController extends Controller
{
    // ---- Quem cuida de mim (lado do paciente) ----

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->patientSide($request->user()))->header('Cache-Control', 'no-store');
    }

    public function invite(Request $request, CaregiverLinkService $service): JsonResponse
    {
        try {
            $service->createInvite($request->user());
        } catch (CaregiverInviteException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'code' => 'invite_unavailable'], 409);
        }

        return response()->json($this->patientSide($request->user()), 201);
    }

    public function cancelInvite(Request $request, CaregiverLinkService $service): JsonResponse
    {
        $service->cancelOpenInvites($request->user());

        return response()->json($this->patientSide($request->user()));
    }

    public function approve(Request $request, CaregiverLink $link, CaregiverLinkService $service): JsonResponse
    {
        Gate::forUser($request->user())->authorize('manage', $link);
        abort_unless($link->isPending(), 409, 'Este pedido não está mais aguardando aprovação.');
        $service->approve($link, $request->user(), $this->permissions($request));

        return response()->json($this->patientSide($request->user()));
    }

    public function reject(Request $request, CaregiverLink $link, CaregiverLinkService $service): JsonResponse
    {
        Gate::forUser($request->user())->authorize('manage', $link);
        abort_unless($link->isPending(), 409, 'Este pedido não está mais aguardando aprovação.');
        $service->reject($link, $request->user());

        return response()->json($this->patientSide($request->user()));
    }

    public function updatePermissions(Request $request, CaregiverLink $link, CaregiverLinkService $service): JsonResponse
    {
        Gate::forUser($request->user())->authorize('manage', $link);
        abort_unless($link->isActive(), 409, 'Este vínculo não está ativo.');
        $service->updatePermissions($link, $request->user(), $this->permissions($request));

        return response()->json($this->patientSide($request->user()));
    }

    public function revoke(Request $request, CaregiverLink $link, CaregiverLinkService $service): JsonResponse
    {
        Gate::forUser($request->user())->authorize('manage', $link);
        abort_unless($link->isActive() || $link->isPending(), 409, 'Este vínculo já terminou.');
        $service->revoke($link, $request->user());

        return response()->json($this->patientSide($request->user()));
    }

    // ---- Cuidar de alguém (lado de quem cuida) ----

    public function caring(Request $request): JsonResponse
    {
        return response()->json($this->caregiverSide($request->user()))->header('Cache-Control', 'no-store');
    }

    /** Código lido do QR (o link inteiro serve: só os números contam) ou digitado. */
    public function accept(Request $request, CaregiverLinkService $service): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:300']], [], ['code' => 'código']);
        $code = preg_match('/[?&]codigo=([\d\s%20]+)/', $data['code'], $match) === 1 ? urldecode($match[1]) : $data['code'];
        try {
            $link = $service->acceptInvite($request->user(), $code);
        } catch (CaregiverInviteException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'errors' => ['code' => [$exception->getMessage()]]], 422);
        }

        return response()->json(['message' => 'Pronto! Agora falta '.CaregiverLink::firstName($link->patient).' aprovar. Avisamos no aplicativo de quem convidou.'] + $this->caregiverSide($request->user()), 201);
    }

    public function leave(Request $request, CaregiverLink $link, CaregiverLinkService $service): JsonResponse
    {
        Gate::forUser($request->user())->authorize('leave', $link);
        $service->leave($link, $request->user());

        return response()->json($this->caregiverSide($request->user()));
    }

    /** Remédios do dia de quem a pessoa acompanha (permissão "ver"); `can_mark` diz se pode marcar ("marcar e editar"). */
    public function day(Request $request, CaregiverLink $link, PatientDayService $screens): JsonResponse
    {
        Gate::forUser($request->user())->authorize('viewPatient', $link);
        $data = $request->validate(['dia' => ['nullable', 'integer', 'between:'.PatientDayService::MIN_OFFSET.','.PatientDayService::MAX_OFFSET]]);

        return response()->json($this->patientDay($link, $screens, (int) ($data['dia'] ?? 0)))->header('Cache-Control', 'no-store');
    }

    public function mark(Request $request, CaregiverLink $link, CaregiverLinkService $service, PatientDayService $screens): JsonResponse
    {
        Gate::forUser($request->user())->authorize('managePatient', $link);
        $data = $this->validateDose($request, ['status' => ['required', 'in:taken,skipped']]);
        $patient = $link->patient;
        $medication = PatientMedication::query()->where('user_id', $patient->id)->findOrFail((int) $data['medication_id']);
        $status = $data['status'] === 'skipped' ? MedicationDoseEvent::STATUS_SKIPPED : MedicationDoseEvent::STATUS_TAKEN;
        $service->markDose($link, $request->user(), $medication, CarbonImmutable::parse($data['scheduled_for'])->setTimezone($patient->treatmentTimezone()), $status);

        return response()->json($this->patientDay($link, $screens, (int) ($data['dia'] ?? 0)));
    }

    public function unmark(Request $request, CaregiverLink $link, CaregiverLinkService $service, PatientDayService $screens): JsonResponse
    {
        Gate::forUser($request->user())->authorize('managePatient', $link);
        $data = $this->validateDose($request);
        $patient = $link->patient;
        $medication = PatientMedication::query()->where('user_id', $patient->id)->findOrFail((int) $data['medication_id']);
        $service->unmarkDose($link, $request->user(), $medication, CarbonImmutable::parse($data['scheduled_for'])->setTimezone($patient->treatmentTimezone()));

        return response()->json($this->patientDay($link, $screens, (int) ($data['dia'] ?? 0)));
    }

    // ---- Resumo para o médico ----

    /** O mesmo PDF do site; sem consentimento de saúde não há o que resumir. */
    public function summary(Request $request, MedicalSummaryService $summaries): Response
    {
        /** @var User $user */
        $user = $request->user();
        if (! $user->hasHealthConsent()) {
            return response()->json(['message' => 'Para gerar o resumo, autorize guardar seus dados de saúde no perfil.', 'code' => 'health_consent_required'], 409);
        }

        return $summaries->download($user, $request->ip());
    }

    // ---- Formatos ----

    /** @return array<string, mixed> */
    private function patientSide(User $user): array
    {
        $links = CaregiverLink::query()->with('caregiver:id,name,email,preferred_name')->where('patient_id', $user->id)
            ->whereIn('status', [CaregiverLink::STATUS_PENDING, CaregiverLink::STATUS_ACTIVE, CaregiverLink::STATUS_REVOKED, CaregiverLink::STATUS_LEFT])
            ->whereNotNull('caregiver_id')->latest('id')->get();
        $invite = CaregiverLink::query()->where('patient_id', $user->id)->where('status', CaregiverLink::STATUS_INVITED)->latest('id')->first();
        $usable = $invite?->isUsableInvite() && filled($invite->invite_code);
        $person = fn (CaregiverLink $link): array => [
            'id' => $link->id,
            'name' => $link->caregiver->name,
            'first_name' => CaregiverLink::firstName($link->caregiver),
            'email' => $link->caregiver->email,
            'permissions' => $link->only(array_keys(CaregiverLink::PERMISSIONS)),
        ];

        return [
            'health_consent' => $user->hasHealthConsent(),
            'invite' => $usable ? [
                'code' => CaregiverLink::formatCode($invite->invite_code),
                'url' => route('consulta.cuidar', ['codigo' => $invite->invite_code]),
                'expires_at' => $invite->invite_expires_at->toIso8601String(),
            ] : null,
            'invite_expired' => $invite !== null && ! $usable,
            'pending' => $links->where('status', CaregiverLink::STATUS_PENDING)->map(fn (CaregiverLink $link): array => $person($link) + ['requested_at' => $link->accepted_at?->toIso8601String()])->values()->all(),
            'active' => $links->where('status', CaregiverLink::STATUS_ACTIVE)->map(fn (CaregiverLink $link): array => $person($link) + ['since' => $link->approved_at?->toIso8601String()])->values()->all(),
            'ended' => $links->whereIn('status', [CaregiverLink::STATUS_REVOKED, CaregiverLink::STATUS_LEFT])->take(10)
                ->map(fn (CaregiverLink $link): array => ['id' => $link->id, 'name' => $link->caregiver->name, 'status' => $link->status, 'ended_at' => $link->ended_at?->toIso8601String()])->values()->all(),
            'permission_labels' => collect(CaregiverLink::PERMISSIONS)->map(fn (string $label, string $key): array => ['key' => $key, 'label' => $label])->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function caregiverSide(User $user): array
    {
        $links = CaregiverLink::query()->with('patient.patientProfile')->where('caregiver_id', $user->id)
            ->whereIn('status', [CaregiverLink::STATUS_PENDING, CaregiverLink::STATUS_ACTIVE])->orderBy('id')->get();
        $phrases = [
            CaregiverLink::PERMISSION_ALERTS => ['Você recebe aviso quando um remédio atrasar', 'Sem avisos de atraso'],
            CaregiverLink::PERMISSION_VIEW => ['Você vê os remédios e horários', 'Sem ver os remédios'],
            CaregiverLink::PERMISSION_MANAGE => ['Você pode marcar doses e mudar horários', 'Sem marcar doses nem mudar horários'],
        ];

        return [
            'pending' => $links->where('status', CaregiverLink::STATUS_PENDING)->map(fn (CaregiverLink $link): array => [
                'id' => $link->id, 'name' => $link->patient->name, 'first_name' => CaregiverLink::firstName($link->patient),
            ])->values()->all(),
            'active' => $links->where('status', CaregiverLink::STATUS_ACTIVE)->map(fn (CaregiverLink $link): array => [
                'id' => $link->id,
                'name' => $link->patient->name,
                'first_name' => CaregiverLink::firstName($link->patient),
                'can_view' => $link->allows(CaregiverLink::PERMISSION_VIEW),
                'can_mark' => $link->allows(CaregiverLink::PERMISSION_MANAGE),
                'health_consent' => $link->patient->hasHealthConsent(),
                'phrases' => collect($phrases)->map(fn (array $pair, string $key): string => (bool) $link->getAttribute($key) ? $pair[0] : $pair[1])->values()->all(),
            ])->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function patientDay(CaregiverLink $link, PatientDayService $screens, int $offset): array
    {
        return $screens->day($link->patient, $offset) + [
            'caring_for' => ['link_id' => $link->id, 'first_name' => CaregiverLink::firstName($link->patient)],
            'can_mark' => $link->allows(CaregiverLink::PERMISSION_MANAGE),
        ];
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

    /** @return array<string, bool> */
    private function permissions(Request $request): array
    {
        $rules = ['permissions' => ['required', 'array']];
        foreach (array_keys(CaregiverLink::PERMISSIONS) as $key) {
            $rules['permissions.'.$key] = ['boolean'];
        }
        $data = $request->validate($rules);

        return collect(array_keys(CaregiverLink::PERMISSIONS))->mapWithKeys(fn (string $key): array => [$key => (bool) ($data['permissions'][$key] ?? false)])->all();
    }
}
