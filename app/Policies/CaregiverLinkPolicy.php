<?php

namespace App\Policies;

use App\Models\CaregiverLink;
use App\Models\User;

/**
 * Gestão do vínculo de cuidado: só o paciente aprova, recusa, muda permissões, revoga e vê o histórico; quem cuida só
 * pode sair do vínculo e abrir as telas do paciente conforme as permissões (sempre conferidas no servidor).
 */
class CaregiverLinkPolicy
{
    /** Aprovar, recusar, mudar permissões, revogar, cancelar o convite e ver o histórico. */
    public function manage(User $user, CaregiverLink $link): bool
    {
        return $link->patient_id === $user->id;
    }

    /** Quem cuida deixa de acompanhar o paciente. */
    public function leave(User $user, CaregiverLink $link): bool
    {
        return $link->caregiver_id === $user->id && in_array($link->status, [CaregiverLink::STATUS_PENDING, CaregiverLink::STATUS_ACTIVE], true);
    }

    /** Abrir os remédios e horários do paciente ("cuidando de Fulano"). */
    public function viewPatient(User $user, CaregiverLink $link): bool
    {
        return $link->caregiver_id === $user->id && $link->allows(CaregiverLink::PERMISSION_VIEW);
    }

    /** Marcar doses e editar remédios do paciente. */
    public function managePatient(User $user, CaregiverLink $link): bool
    {
        return $this->viewPatient($user, $link) && $link->allows(CaregiverLink::PERMISSION_MANAGE);
    }
}
