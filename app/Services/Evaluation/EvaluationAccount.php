<?php

namespace App\Services\Evaluation;

use App\Models\PatientProfile;
use App\Models\User;
use App\UserRole;
use Illuminate\Support\Str;

/**
 * Conta técnica usada nas avaliações do Perguntar (`evaluation:conversation` e `POST /api/v1/evaluation/ask`). Não entra:
 * fica inativa, com senha aleatória que ninguém conhece, e com consentimento de saúde para o fluxo completo rodar. Perguntas
 * e consumo de IA das avaliações ficam atribuídos a ela, separados dos pacientes.
 */
final class EvaluationAccount
{
    public const EMAIL = 'avaliacao-bulai@example.test';

    public function get(): User
    {
        $user = User::query()->where('email', self::EMAIL)->first();
        if ($user === null) {
            $user = new User;
            $user->forceFill([
                'name' => 'Avaliação BULA-IA',
                'email' => self::EMAIL,
                'password' => Str::random(64),
                'role' => UserRole::User,
                'active' => false,
                'must_change_password' => false,
            ])->save();
        }
        $profile = PatientProfile::query()->firstOrCreate(['user_id' => $user->id], ['health_consent_at' => now()]);
        if ($profile->health_consent_at === null) {
            $profile->forceFill(['health_consent_at' => now()])->save();
        }

        return $user->load('patientProfile');
    }
}
