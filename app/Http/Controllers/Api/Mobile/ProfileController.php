<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\PatientAllergy;
use App\Models\PatientMedication;
use App\Models\PatientPrescription;
use App\Models\User;
use App\Services\Patient\HealthProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Meus remédios" (perfil de saúde) no aplicativo nativo, com as regras do site (HealthProfileService): consentimento,
 * remédios (do catálogo ou pelo nome) com "Dose e horários", receitas e alergias. Sem consentimento, só o consentimento
 * pode ser dado (o resto responde 409 `health_consent_required`).
 */
final class ProfileController extends Controller
{
    public function __construct(private readonly HealthProfileService $profile) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json(self::payload($request->user(), $this->profile))->header('Cache-Control', 'no-store');
    }

    public function consent(Request $request): JsonResponse
    {
        $this->profile->acceptConsent($request->user(), $request->ip());

        return $this->fresh($request, message: 'Pronto! Agora você pode guardar seus remédios, receitas e alergias.');
    }

    public function deleteHealthData(Request $request): JsonResponse
    {
        $this->profile->deleteHealthData($request->user(), $request->ip());

        return response()->json(['message' => 'Seus dados de saúde (alergias, receitas, remédios e perguntas) foram apagados e o consentimento foi revogado.'] + self::payload($request->user(), $this->profile));
    }

    public function addAllergy(Request $request): JsonResponse
    {
        $this->needsConsent($request);
        $data = $request->validate(['substance' => ['required', 'string', 'max:200'], 'reaction' => ['nullable', 'string', 'max:255']], [], ['substance' => 'substância', 'reaction' => 'reação']);
        $this->profile->addAllergy($request->user(), $data['substance'], $data['reaction'] ?? null);

        return $this->fresh($request, 201, 'Alergia registrada. O BULA-IA vai avisar quando um remédio tiver essa substância.');
    }

    public function removeAllergy(Request $request, int $allergy): JsonResponse
    {
        PatientAllergy::query()->where('user_id', $request->user()->id)->whereKey($allergy)->delete();

        return $this->fresh($request, message: 'Alergia apagada.');
    }

    public function addPrescription(Request $request): JsonResponse
    {
        $this->needsConsent($request);
        $data = $request->only(['prescriber', 'registration', 'issued_at', 'notes']);
        // A data pode vir como a pessoa digitou (dd/mm/aaaa).
        if (is_string($data['issued_at'] ?? null) && preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', trim($data['issued_at']), $match) === 1) {
            $data['issued_at'] = checkdate((int) $match[2], (int) $match[1], (int) $match[3]) ? sprintf('%04d-%02d-%02d', $match[3], $match[2], $match[1]) : $data['issued_at'];
        }
        $this->profile->addPrescription($request->user(), $data);

        return $this->fresh($request, 201, 'Receita guardada.');
    }

    public function removePrescription(Request $request, int $prescription): JsonResponse
    {
        PatientPrescription::query()->where('user_id', $request->user()->id)->whereKey($prescription)->delete();

        return $this->fresh($request, message: 'Receita apagada.');
    }

    /** `{medicine_id}` (do catálogo) ou `{name}` (fora do catálogo: entra pelo nome e vira pendência). */
    public function addMedication(Request $request): JsonResponse
    {
        $this->needsConsent($request);
        $data = $request->validate(['medicine_id' => ['nullable', 'integer', 'required_without:name'], 'name' => ['nullable', 'string', 'max:200', 'required_without:medicine_id']], [], ['name' => 'nome do remédio']);
        $medication = filled($data['medicine_id'] ?? null)
            ? $this->profile->addCatalogMedication($request->user(), (int) $data['medicine_id'])
            : $this->profile->addMedicationByName($request->user(), (string) $data['name']);
        abort_if($medication === null, 404, 'Remédio não encontrado no catálogo.');
        $message = $medication->medicine_id === null
            ? "“{$medication->displayName()}” entrou na sua lista. Ainda não está no nosso catálogo: nossa equipe vai conferir."
            : $medication->displayName().' entrou na sua lista. Confira a dose e os horários.';

        return response()->json(['medication_id' => $medication->id, 'message' => $message] + self::payload($request->user(), $this->profile), 201);
    }

    public function updateMedication(Request $request, int $medication): JsonResponse
    {
        $this->needsConsent($request);
        $item = PatientMedication::query()->where('user_id', $request->user()->id)->findOrFail($medication);
        $this->profile->updatePosology($item, (array) $request->input('posology', []));

        return $this->fresh($request, message: 'Dose e horários de '.$item->displayName().' salvos.');
    }

    public function removeMedication(Request $request, int $medication): JsonResponse
    {
        $item = PatientMedication::query()->where('user_id', $request->user()->id)->find($medication);
        $item?->delete();

        return $this->fresh($request, message: $item ? $item->displayName().' saiu da sua lista.' : null);
    }

    /** O que muda no formulário quando a pessoa escreve "quando tomar" (sugestão de horários; nada é salvo). */
    public function suggestSchedule(Request $request): JsonResponse
    {
        $data = $request->validate(['frequency' => ['nullable', 'string', 'max:120'], 'posology' => ['nullable', 'array']]);

        return response()->json(['posology' => $this->profile->applyFrequency(($data['posology'] ?? []) + HealthProfileService::EMPTY_POSOLOGY, (string) ($data['frequency'] ?? ''))]);
    }

    private function fresh(Request $request, int $status = 200, ?string $message = null): JsonResponse
    {
        return response()->json(['message' => $message] + self::payload($request->user(), $this->profile), $status);
    }

    private function needsConsent(Request $request): void
    {
        if (! $this->profile->hasConsent($request->user())) {
            abort(response()->json(['message' => 'Para guardar dados de saúde, primeiro autorize no seu perfil.', 'code' => 'health_consent_required'], 409));
        }
    }

    /** @return array<string, mixed> */
    public static function payload(User $user, HealthProfileService $profile): array
    {
        $consent = $profile->hasConsent($user);
        if (! $consent) {
            return ['health_consent' => false, 'medications' => [], 'ended' => [], 'allergies' => [], 'prescriptions' => []];
        }
        $medications = PatientMedication::query()->with(['medicine', 'prescription'])->where('user_id', $user->id)->orderBy('id')->get();

        return [
            'health_consent' => true,
            'medications' => $medications->filter(fn (PatientMedication $item): bool => $item->isActive())->map(fn (PatientMedication $item): array => self::medication($item, $profile))->values()->all(),
            'ended' => $medications->reject(fn (PatientMedication $item): bool => $item->isActive())->sortByDesc('ends_at')->map(fn (PatientMedication $item): array => self::medication($item, $profile))->values()->all(),
            'allergies' => PatientAllergy::query()->where('user_id', $user->id)->orderBy('substance')->get()
                ->map(fn (PatientAllergy $allergy): array => ['id' => $allergy->id, 'substance' => $allergy->substance, 'reaction' => $allergy->reaction])->all(),
            'prescriptions' => PatientPrescription::query()->with('medications.medicine')->where('user_id', $user->id)->orderByDesc('issued_at')->orderByDesc('id')->get()
                ->map(fn (PatientPrescription $prescription): array => [
                    'id' => $prescription->id,
                    'prescriber' => $prescription->prescriber_name,
                    'registration' => $prescription->prescriber_registration,
                    'issued_at' => $prescription->issued_at?->format('Y-m-d'),
                    'issued_label' => $prescription->issued_at?->format('d/m/Y'),
                    'notes' => $prescription->notes,
                    'medications' => $prescription->medications->map(fn (PatientMedication $item): string => $item->displayName())->values()->all(),
                ])->all(),
        ];
    }

    /** @return array<string, mixed> */
    public static function medication(PatientMedication $item, HealthProfileService $profile): array
    {
        $posology = $profile->posologyOf($item);
        $interval = $posology['schedule_mode'] === PatientMedication::MODE_INTERVAL && $posology['interval_hours'] !== null;
        $when = match (true) {
            $posology['as_needed'] => 'Só quando precisar',
            $interval => "De {$posology['interval_hours']} em {$posology['interval_hours']} horas, contando de quando tomar",
            $posology['times'] !== [] => implode(' · ', $posology['times']),
            default => 'Sem horário definido',
        };
        // O cartão mostra "chips" (horários ou o intervalo) e uma nota; sem horário, um aviso.
        $schedule = match (true) {
            $posology['as_needed'] => ['chips' => ['Só quando precisar'], 'note' => null, 'missing' => false],
            $interval => ['chips' => ["A cada {$posology['interval_hours']} h"], 'note' => 'contando de quando tomar'.($posology['times'] !== [] ? ' · 1ª às '.$posology['times'][0] : ''), 'missing' => false],
            $posology['times'] !== [] => ['chips' => $posology['times'], 'note' => null, 'missing' => false],
            default => ['chips' => [], 'note' => 'Sem horário definido', 'missing' => true],
        };

        return [
            'id' => $item->id,
            'name' => $item->displayName(),
            'medicine_id' => $item->medicine_id,
            'in_catalog' => $item->medicine_id !== null,
            'active_ingredient' => $item->medicine?->active_ingredient,
            'dosage' => $item->dosage,
            'when' => $when,
            'schedule' => $schedule,
            'frequency' => $item->frequency,
            'started_label' => $item->started_at?->format('d/m/Y'),
            'ends_label' => $item->ends_at?->format('d/m/Y'),
            'prescriber' => $item->prescription?->prescriber_name,
            'notes' => $item->notes,
            'reminders_enabled' => (bool) $item->reminders_enabled,
            'posology' => $posology,
        ];
    }
}
