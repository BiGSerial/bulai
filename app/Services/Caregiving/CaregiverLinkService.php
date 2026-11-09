<?php

namespace App\Services\Caregiving;

use App\Models\CaregiverAuditEvent;
use App\Models\CaregiverLink;
use App\Models\MedicationDoseEvent;
use App\Models\PatientMedication;
use App\Models\User;
use App\Notifications\CaregiverRequestNotification;
use App\Services\Treatment\TreatmentScheduleService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Modo cuidador com handshake: convite de uso único (48 h) gerado pelo paciente → quem cuida informa o código (vínculo
 * pendente) → o paciente aprova escolhendo as permissões. Revogar (paciente) ou sair (quem cuida) corta o acesso na hora.
 *
 * Cada passo e cada ação de quem cuida sobre os dados do paciente vai para `caregiver_audit_events`. A autorização
 * (policies `CaregiverLinkPolicy` e `PatientMedicationPolicy`) é conferida por quem chama, antes de cada operação.
 */
final class CaregiverLinkService
{
    public const INVITE_HOURS = 48;

    public const CODE_LENGTH = 8;

    /** Tentativas erradas de código por pessoa antes do bloqueio temporário. */
    public const MAX_CODE_ATTEMPTS = 5;

    public const CODE_ATTEMPTS_DECAY_SECONDS = 900;

    public function __construct(private readonly TreatmentScheduleService $schedule) {}

    /**
     * Novo convite do paciente (o anterior ainda não usado é cancelado: só um código vale por vez).
     * Exige o consentimento de saúde: sem ele não há dado de saúde para compartilhar.
     */
    public function createInvite(User $patient): CaregiverLink
    {
        if (! $patient->hasHealthConsent()) {
            throw new CaregiverInviteException('Para convidar alguém, primeiro autorize guardar seus dados de saúde em Meus remédios.');
        }

        return DB::transaction(function () use ($patient): CaregiverLink {
            $this->cancelOpenInvites($patient);
            do {
                $code = str_pad((string) random_int(0, 10 ** self::CODE_LENGTH - 1), self::CODE_LENGTH, '0', STR_PAD_LEFT);
                $hash = CaregiverLink::hashCode($code);
            } while (CaregiverLink::query()->where('invite_code_hash', $hash)->exists());

            $link = CaregiverLink::query()->create([
                'patient_id' => $patient->id,
                'status' => CaregiverLink::STATUS_INVITED,
                'invite_code' => $code,
                'invite_code_hash' => $hash,
                'invite_expires_at' => now()->addHours(self::INVITE_HOURS),
            ]);
            $this->audit($link, $patient, CaregiverAuditEvent::EVENT_INVITE_CREATED, ['expires_at' => $link->invite_expires_at->toIso8601String()]);

            return $link;
        });
    }

    /** Cancela os convites ainda não usados do paciente (o código deixa de valer). */
    public function cancelOpenInvites(User $patient): void
    {
        CaregiverLink::query()->where('patient_id', $patient->id)->where('status', CaregiverLink::STATUS_INVITED)->get()
            ->each(function (CaregiverLink $link) use ($patient): void {
                $link->update(['status' => CaregiverLink::STATUS_CANCELED, 'invite_code' => null, 'ended_at' => now(), 'ended_by' => $patient->id]);
                $this->audit($link, $patient, CaregiverAuditEvent::EVENT_INVITE_CANCELED);
            });
    }

    /**
     * Quem cuida informa o código: o vínculo fica PENDENTE até o paciente aprovar. Código errado, usado ou vencido
     * conta como tentativa errada; depois de `MAX_CODE_ATTEMPTS` erros em 15 minutos, novas tentativas esperam.
     *
     * @throws CaregiverInviteException com a frase para a tela
     */
    public function acceptInvite(User $caregiver, string $code): CaregiverLink
    {
        $key = 'caregiver-code:'.$caregiver->id;
        if (RateLimiter::tooManyAttempts($key, self::MAX_CODE_ATTEMPTS)) {
            $minutes = max(1, (int) ceil(RateLimiter::availableIn($key) / 60));

            throw new CaregiverInviteException("Muitas tentativas com código errado. Espere {$minutes} ".($minutes === 1 ? 'minuto' : 'minutos').' e tente de novo.');
        }

        $digits = preg_replace('/\D+/', '', $code) ?? '';
        if (strlen($digits) !== self::CODE_LENGTH) {
            throw new CaregiverInviteException('O código tem '.self::CODE_LENGTH.' números. Confira e digite de novo.');
        }

        $failure = null;
        $link = DB::transaction(function () use ($caregiver, $digits, &$failure): ?CaregiverLink {
            $link = CaregiverLink::query()->where('invite_code_hash', CaregiverLink::hashCode($digits))->lockForUpdate()->first();
            $failure = match (true) {
                $link === null => 'Não encontramos este código. Confira os números com quem enviou o convite.',
                $link->status !== CaregiverLink::STATUS_INVITED => 'Este código já foi usado ou cancelado. Peça um convite novo.',
                $link->invite_expires_at === null || ! $link->invite_expires_at->isFuture() => 'Este código venceu (vale por 48 horas). Peça um convite novo.',
                default => null,
            };
            if ($failure !== null) {
                return null;
            }
            if ($link->patient_id === $caregiver->id) {
                throw new CaregiverInviteException('Este convite é seu. Envie o código para a pessoa que vai cuidar de você.');
            }
            $existing = CaregiverLink::query()->where('patient_id', $link->patient_id)->where('caregiver_id', $caregiver->id)
                ->whereIn('status', [CaregiverLink::STATUS_PENDING, CaregiverLink::STATUS_ACTIVE])->first();
            if ($existing !== null) {
                throw new CaregiverInviteException($existing->isActive() ? 'Você já acompanha esta pessoa.' : 'Você já usou um código desta pessoa. Falta ela aprovar.');
            }

            $link->update(['caregiver_id' => $caregiver->id, 'status' => CaregiverLink::STATUS_PENDING, 'invite_code' => null, 'accepted_at' => now()]);
            $this->audit($link, $caregiver, CaregiverAuditEvent::EVENT_INVITE_ACCEPTED);

            return $link;
        });

        if ($link === null) {
            RateLimiter::hit($key, self::CODE_ATTEMPTS_DECAY_SECONDS);

            throw new CaregiverInviteException((string) $failure);
        }

        RateLimiter::clear($key);
        $link->patient->notify(new CaregiverRequestNotification($link->setRelation('caregiver', $caregiver)));

        return $link;
    }

    /**
     * O paciente aprova o pedido com as permissões escolhidas.
     *
     * @param  array<string, mixed>  $permissions  chaves de `CaregiverLink::PERMISSIONS`
     */
    public function approve(CaregiverLink $link, User $patient, array $permissions): CaregiverLink
    {
        abort_unless($link->patient_id === $patient->id && $link->isPending(), 403);
        $granted = self::normalizePermissions($permissions);
        $link->update(['status' => CaregiverLink::STATUS_ACTIVE, 'approved_at' => now()] + $granted);
        $this->audit($link, $patient, CaregiverAuditEvent::EVENT_APPROVED, $granted);

        return $link;
    }

    public function reject(CaregiverLink $link, User $patient): void
    {
        abort_unless($link->patient_id === $patient->id && $link->isPending(), 403);
        $link->update(['status' => CaregiverLink::STATUS_REVOKED, 'ended_at' => now(), 'ended_by' => $patient->id]);
        $this->audit($link, $patient, CaregiverAuditEvent::EVENT_REJECTED);
    }

    /** @param array<string, mixed> $permissions */
    public function updatePermissions(CaregiverLink $link, User $patient, array $permissions): CaregiverLink
    {
        abort_unless($link->patient_id === $patient->id && $link->isActive(), 403);
        $before = $link->only(array_keys(CaregiverLink::PERMISSIONS));
        $granted = self::normalizePermissions($permissions);
        if ($before === $granted) {
            return $link;
        }
        $link->update($granted);
        $this->audit($link, $patient, CaregiverAuditEvent::EVENT_PERMISSIONS_CHANGED, $granted + ['before' => $before]);

        return $link;
    }

    /** O paciente retira o acesso (vínculo ativo ou pendente). Vale na hora: toda ação seguinte é recusada. */
    public function revoke(CaregiverLink $link, User $patient): void
    {
        abort_unless($link->patient_id === $patient->id && in_array($link->status, [CaregiverLink::STATUS_ACTIVE, CaregiverLink::STATUS_PENDING], true), 403);
        $link->update(['status' => CaregiverLink::STATUS_REVOKED, 'ended_at' => now(), 'ended_by' => $patient->id]);
        $this->audit($link, $patient, CaregiverAuditEvent::EVENT_REVOKED);
    }

    /** Quem cuida deixa de acompanhar o paciente. */
    public function leave(CaregiverLink $link, User $caregiver): void
    {
        abort_unless($link->caregiver_id === $caregiver->id && in_array($link->status, [CaregiverLink::STATUS_ACTIVE, CaregiverLink::STATUS_PENDING], true), 403);
        $link->update(['status' => CaregiverLink::STATUS_LEFT, 'ended_at' => now(), 'ended_by' => $caregiver->id]);
        $this->audit($link, $caregiver, CaregiverAuditEvent::EVENT_LEFT);
    }

    /** Quem cuida marca "Tomei"/"Pular" por um paciente (a autorização é conferida antes, pela policy). */
    public function markDose(CaregiverLink $link, User $caregiver, PatientMedication $medication, CarbonImmutable $scheduledFor, string $status): MedicationDoseEvent
    {
        $event = $this->schedule->mark($link->patient, $medication, $scheduledFor, $status);
        $this->audit($link, $caregiver, CaregiverAuditEvent::EVENT_DOSE_MARKED, $this->doseDetails($link, $medication, $scheduledFor) + ['status' => $event->status]);

        return $event;
    }

    public function unmarkDose(CaregiverLink $link, User $caregiver, PatientMedication $medication, CarbonImmutable $scheduledFor): void
    {
        $this->schedule->unmark($link->patient, $medication, $scheduledFor);
        $this->audit($link, $caregiver, CaregiverAuditEvent::EVENT_DOSE_UNMARKED, $this->doseDetails($link, $medication, $scheduledFor));
    }

    /** Registra que quem cuida mudou a posologia de um remédio (com o que mudou). @param array<string, mixed> $changes */
    public function medicationUpdated(CaregiverLink $link, User $caregiver, PatientMedication $medication, array $changes): void
    {
        $this->audit($link, $caregiver, CaregiverAuditEvent::EVENT_MEDICATION_UPDATED, ['medication_id' => $medication->id, 'medication' => $medication->displayName(), 'changed' => array_keys($changes)]);
    }

    /** Vínculo ativo de quem cuida com este paciente (ou nulo). */
    public static function activeLink(User $caregiver, int $patientId): ?CaregiverLink
    {
        return CaregiverLink::query()->active()->where('caregiver_id', $caregiver->id)->where('patient_id', $patientId)->first();
    }

    /**
     * As três permissões como booleanos. Marcar e editar exige também ver (não se marca o que não se vê).
     *
     * @param  array<string, mixed>  $permissions
     * @return array{can_receive_alerts: bool, can_view_medications: bool, can_manage_medications: bool}
     */
    public static function normalizePermissions(array $permissions): array
    {
        $manage = (bool) ($permissions[CaregiverLink::PERMISSION_MANAGE] ?? false);

        return [
            CaregiverLink::PERMISSION_ALERTS => (bool) ($permissions[CaregiverLink::PERMISSION_ALERTS] ?? false),
            CaregiverLink::PERMISSION_VIEW => $manage || (bool) ($permissions[CaregiverLink::PERMISSION_VIEW] ?? false),
            CaregiverLink::PERMISSION_MANAGE => $manage,
        ];
    }

    /** @param array<string, mixed> $details */
    public function audit(CaregiverLink $link, ?User $actor, string $event, array $details = []): CaregiverAuditEvent
    {
        return CaregiverAuditEvent::query()->create([
            'caregiver_link_id' => $link->id,
            'patient_id' => $link->patient_id,
            'actor_id' => $actor?->id,
            'event' => $event,
            'details' => $details,
            'ip' => request()->ip(),
        ]);
    }

    /** @return array{medication_id: int, medication: string, scheduled_for: string, time: string} */
    private function doseDetails(CaregiverLink $link, PatientMedication $medication, CarbonImmutable $scheduledFor): array
    {
        return [
            'medication_id' => $medication->id,
            'medication' => $medication->displayName(),
            'scheduled_for' => $scheduledFor->utc()->toIso8601String(),
            'time' => $scheduledFor->setTimezone($link->patient->treatmentTimezone())->format('H:i'),
        ];
    }
}
