<?php

namespace App\Policies;

use App\Models\CaregiverLink;
use App\Models\PatientMedication;
use App\Models\User;

/**
 * Quem pode ver, marcar doses e editar um remédio do tratamento: o próprio paciente ou quem cuida dele, com vínculo
 * ativo, a permissão marcada pelo paciente e o consentimento de saúde do paciente em vigor (`CaregiverLink::allows()`).
 */
class PatientMedicationPolicy
{
    public function view(User $user, PatientMedication $medication): bool
    {
        return $medication->user_id === $user->id || $this->caregiverAllows($user, $medication, [CaregiverLink::PERMISSION_VIEW]);
    }

    /** Marcar "Tomei"/"Pular" ou desfazer. Quem cuida precisa ver E marcar (não se marca o que não se vê). */
    public function markDose(User $user, PatientMedication $medication): bool
    {
        return $medication->user_id === $user->id || $this->caregiverAllows($user, $medication, [CaregiverLink::PERMISSION_VIEW, CaregiverLink::PERMISSION_MANAGE]);
    }

    /** Mudar dose, frequência e horários. */
    public function update(User $user, PatientMedication $medication): bool
    {
        return $medication->user_id === $user->id || $this->caregiverAllows($user, $medication, [CaregiverLink::PERMISSION_VIEW, CaregiverLink::PERMISSION_MANAGE]);
    }

    /** @param list<string> $permissions */
    private function caregiverAllows(User $user, PatientMedication $medication, array $permissions): bool
    {
        $link = CaregiverLink::query()->active()->with('patient.patientProfile')
            ->where('caregiver_id', $user->id)->where('patient_id', $medication->user_id)->first();
        if ($link === null) {
            return false;
        }

        foreach ($permissions as $permission) {
            if (! $link->allows($permission)) {
                return false;
            }
        }

        return true;
    }
}
