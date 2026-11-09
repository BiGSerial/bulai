<?php

namespace App\Livewire\Consulta\Concerns;

use App\Models\CaregiverLink;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;

/**
 * Telas que mostram os dados de "eu" ou de quem a pessoa cuida (modo cuidador). O vínculo vem da rota
 * (`/cuidando/{vinculo}/...`) e é conferido de novo a CADA requisição: revogado, sem permissão ou sem consentimento de
 * saúde do paciente, a próxima ação já recebe 403 (`CaregiverLinkPolicy::viewPatient`).
 */
trait AtuaComoCuidador
{
    /** Vínculo de cuidado em uso nesta tela; nulo = os dados da própria pessoa. */
    #[Locked]
    public ?int $vinculoId = null;

    /** Vínculo já conferido nesta requisição (não vai para o navegador). */
    private ?CaregiverLink $vinculoConferido = null;

    protected function entrarNoVinculo(?CaregiverLink $vinculo): void
    {
        if ($vinculo === null) {
            return;
        }
        Gate::authorize('viewPatient', $vinculo);
        $this->vinculoId = $vinculo->id;
    }

    /** Vínculo atual, conferido; nulo quando a tela mostra os dados da própria pessoa. */
    protected function vinculo(): ?CaregiverLink
    {
        if ($this->vinculoId === null) {
            return null;
        }
        if ($this->vinculoConferido === null) {
            $vinculo = CaregiverLink::query()->with('patient.patientProfile')->find($this->vinculoId);
            abort_if($vinculo === null, 404);
            Gate::authorize('viewPatient', $vinculo);
            $this->vinculoConferido = $vinculo;
        }

        return $this->vinculoConferido;
    }

    /** De quem são os dados mostrados: o paciente cuidado ou a própria pessoa. */
    protected function paciente(): User
    {
        return $this->vinculo()?->patient ?? auth()->user();
    }

    /** Quem cuida pode marcar doses e editar remédios deste paciente (sempre verdadeiro para a própria pessoa). */
    protected function podeGerenciar(): bool
    {
        $vinculo = $this->vinculo();

        return $vinculo === null || Gate::allows('managePatient', $vinculo);
    }
}
