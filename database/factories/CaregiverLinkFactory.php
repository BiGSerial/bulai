<?php

namespace Database\Factories;

use App\Models\CaregiverLink;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Vínculo de cuidado. O padrão é um vínculo ATIVO com as três permissões; os estados cobrem o convite e o pedido pendente.
 *
 * @extends Factory<CaregiverLink>
 */
class CaregiverLinkFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'patient_id' => User::factory(),
            'caregiver_id' => User::factory(),
            'status' => CaregiverLink::STATUS_ACTIVE,
            'can_receive_alerts' => true,
            'can_view_medications' => true,
            'can_manage_medications' => true,
            'accepted_at' => now()->subDay(),
            'approved_at' => now()->subDay(),
        ];
    }

    /** Convite ainda não usado, válido por 48 horas, com o código informado. */
    public function invited(string $code = '12345678'): static
    {
        return $this->state(fn (): array => [
            'caregiver_id' => null,
            'status' => CaregiverLink::STATUS_INVITED,
            'invite_code' => $code,
            'invite_code_hash' => CaregiverLink::hashCode($code),
            'invite_expires_at' => now()->addHours(48),
            'accepted_at' => null,
            'approved_at' => null,
        ]);
    }

    /** Código já usado por quem cuida, aguardando a aprovação do paciente. */
    public function pending(): static
    {
        return $this->state(fn (): array => ['status' => CaregiverLink::STATUS_PENDING, 'accepted_at' => now(), 'approved_at' => null]);
    }

    /** @param array<string, bool> $permissions */
    public function withPermissions(array $permissions): static
    {
        return $this->state(fn (): array => $permissions);
    }
}
